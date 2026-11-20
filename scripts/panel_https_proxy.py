#!/usr/bin/env python3
"""HTTPS reverse proxy in front of the Yarbo PHP panel (plain HTTP upstream).

Browsers need a secure context for microphone / Announce Record. Paper tablets
keep using HTTP :8080 directly.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import http.client
import json
import os
import queue
import socket
import ssl
import struct
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parent.parent


def is_camera_frames(path: str) -> bool:
    """True for the live-picture stream. That request must not sit inside PHP."""
    raw = path or "/"
    base, _, query = raw.partition("?")
    return base.rstrip("/").endswith("camera_live.php") and "action=frames" in query


def is_camera_controls(path: str) -> bool:
    """True for the quiet connection that carries a direction."""
    raw = path or "/"
    base, _, query = raw.partition("?")
    return base.rstrip("/").endswith("camera_live.php") and "action=controls" in query


def camera_agent_port() -> int:
    """Port the camera agent is actually listening on. Never the Matter port."""
    fallback = int(os.environ.get("YARBO_CAMERA_PORT") or 8767)
    if fallback == 8766:
        fallback = 8767
    try:
        data = json.loads((ROOT / "data" / "camera-live-status.json").read_text(encoding="utf-8"))
        port = int(data.get("port") or 0)
    except (OSError, ValueError, TypeError):
        return fallback
    if port <= 0 or port == 8766:
        return fallback
    return port


def read_picture_batch(sock: socket.socket, pending: bytes) -> bytes:
    """One short picture reply. An endless reply makes Safari queue drive behind it."""
    buf = bytearray(pending)
    soft = time.monotonic() + 0.25
    hard = time.monotonic() + 1.2
    sock.settimeout(0.05)

    def complete_end() -> int:
        return buf.rfind(b"\n\n")

    while time.monotonic() < hard and len(buf) < 512000:
        end = complete_end()
        if end >= 0 and (b"data:" in buf or time.monotonic() >= soft):
            del buf[end + 2 :]
            break
        try:
            part = sock.recv(16384)
        except (TimeoutError, socket.timeout):
            if end >= 0 and time.monotonic() >= soft:
                del buf[end + 2 :]
                break
            continue
        if not part:
            if end >= 0:
                del buf[end + 2 :]
            else:
                buf.clear()
            break
        buf += part
    else:
        end = complete_end()
        if end >= 0:
            del buf[end + 2 :]
        else:
            buf.clear()
    body = bytes(buf)
    if not body.startswith(b"retry:"):
        body = b"retry: 40\n\n" + body
    return body


WS_GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
WS_MAX_FRAME = 2_000_000


def websocket_accept(key: str) -> str:
    digest = hashlib.sha1((key.strip() + WS_GUID).encode("ascii")).digest()
    return base64.b64encode(digest).decode("ascii")


def pop_ws_frames(buf: bytearray) -> list[tuple[int, bytes, bool]]:
    """Take complete client frames out of buf, leaving any partial frame."""
    frames: list[tuple[int, bytes, bool]] = []
    while True:
        if len(buf) < 2:
            return frames
        first, second = buf[0], buf[1]
        masked = (second & 0x80) != 0
        length = second & 0x7F
        offset = 2
        if length == 126:
            if len(buf) < offset + 2:
                return frames
            length = struct.unpack("!H", bytes(buf[offset : offset + 2]))[0]
            offset += 2
        elif length == 127:
            if len(buf) < offset + 8:
                return frames
            length = struct.unpack("!Q", bytes(buf[offset : offset + 8]))[0]
            offset += 8
        if length > WS_MAX_FRAME:
            raise ValueError("picture frame too large")
        mask_len = 4 if masked else 0
        if len(buf) < offset + mask_len + length:
            return frames
        mask = bytes(buf[offset : offset + mask_len]) if masked else b""
        offset += mask_len
        payload = bytes(buf[offset : offset + length])
        del buf[: offset + length]
        if masked:
            payload = bytes(byte ^ mask[index % 4] for index, byte in enumerate(payload))
        frames.append((first & 0x0F, payload, (first & 0x80) != 0))


def sse_payload(block: bytes) -> bytes:
    return b"\n".join(
        line[5:].lstrip() for line in block.split(b"\n") if line.startswith(b"data:")
    )


def encode_ws_frame(opcode: int, payload: bytes) -> bytes:
    """One server frame. The browser masks the frames it sends back."""
    header = bytearray([0x80 | (opcode & 0x0F)])
    size = len(payload)
    if size < 126:
        header.append(size)
    elif size < 65536:
        header.append(126)
        header += struct.pack("!H", size)
    else:
        header.append(127)
        header += struct.pack("!Q", size)
    return bytes(header) + payload


def relay_chunked(sock: socket.socket, wfile, pending: bytes = b"") -> None:
    """Forward a picture stream as HTTP/1.1 chunks.

    An HTTP/1.0 picture reply makes Safari keep a single connection for the
    whole robot, so a direction cannot be sent until that reply ends. Chunked
    HTTP/1.1 leaves room for the press while the pictures stay open.
    """

    def write_chunk(data: bytes) -> None:
        if not data:
            return
        wfile.write(f"{len(data):X}\r\n".encode("ascii"))
        wfile.write(data)
        wfile.write(b"\r\n")
        wfile.flush()

    if pending:
        write_chunk(pending)
    try:
        sock.settimeout(None)
    except Exception:
        pass
    while True:
        try:
            chunk = sock.recv(16384)
        except Exception:
            break
        if not chunk:
            break
        write_chunk(chunk)
    try:
        wfile.write(b"0\r\n\r\n")
        wfile.flush()
    except Exception:
        pass


def relay_socket(sock: socket.socket, wfile, pending: bytes = b"") -> None:
    """Copy a picture stream until the browser or the camera closes it."""
    if pending:
        wfile.write(pending)
        wfile.flush()
    try:
        sock.settimeout(None)
    except Exception:
        pass
    while True:
        try:
            chunk = sock.recv(16384)
        except Exception:
            break
        if not chunk:
            break
        wfile.write(chunk)
        wfile.flush()


def relay_upstream(resp, wfile, chunk_size: int = 16384) -> None:
    """Copy an upstream body as it arrives. Do not wait for the stream to end."""
    while True:
        chunk = resp.read(chunk_size)
        if not chunk:
            break
        wfile.write(chunk)
        wfile.flush()


HOP_BY_HOP = {
    "connection",
    "keep-alive",
    "proxy-authenticate",
    "proxy-authorization",
    "te",
    "trailers",
    "transfer-encoding",
    "upgrade",
    "proxy-connection",
}


class PanelHttpsProxyHandler(BaseHTTPRequestHandler):
    upstream_host = "127.0.0.1"
    upstream_port = 8080
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt: str, *args) -> None:
        sys.stderr.write("%s - %s\n" % (self.address_string(), fmt % args))
        sys.stderr.flush()

    def do_GET(self) -> None:
        self._proxy()

    def do_HEAD(self) -> None:
        self._proxy()

    def do_POST(self) -> None:
        self._proxy()

    def do_PUT(self) -> None:
        self._proxy()

    def do_DELETE(self) -> None:
        self._proxy()

    def do_OPTIONS(self) -> None:
        self._proxy()

    def do_PATCH(self) -> None:
        self._proxy()

    def _proxy(self) -> None:
        length = int(self.headers.get("Content-Length", "0") or "0")
        body = self.rfile.read(length) if length > 0 else None

        path = self.path or "/"
        # Pictures stay on their own connection. Putting that stream through
        # a page worker, or leaving it open on HTTP/1.1, made Safari hand the
        # next drive press a body it could not read.
        if self.command == "GET" and is_camera_controls(path):
            # A direction on the picture connection never got an answer while
            # frames were moving. This connection carries no pictures.
            if "websocket" in (self.headers.get("Upgrade") or "").lower():
                if not self._websocket_controls():
                    self._camera_unavailable()
                return
            body = b'{"ok":true,"controls":true}'
            self.close_connection = True
            try:
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Cache-Control", "no-store")
                self.send_header("Content-Length", str(len(body)))
                self.send_header("Connection", "close")
                self.end_headers()
                self.wfile.write(body)
            except Exception:
                pass
            return

        if self.command == "GET" and is_camera_frames(path):
            # Safari will not open a second connection to this robot while a
            # picture stream is up, so a press on the page never arrives.
            # The picture socket on this same port carries the press as well.
            if "websocket" in (self.headers.get("Upgrade") or "").lower():
                if not self._websocket_pictures():
                    self._camera_unavailable()
                return
            if not self._proxy_camera_frames():
                self._camera_unavailable()
            return

        headers = {}
        for key, value in self.headers.items():
            lk = key.lower()
            if lk in HOP_BY_HOP or lk == "host":
                continue
            headers[key] = value
        # PHP built-in server is fine with the LAN host the browser used.
        headers["Host"] = self.headers.get("Host") or f"{self.upstream_host}:{self.upstream_port}"
        headers["X-Forwarded-Proto"] = "https"
        headers["X-Forwarded-For"] = self.client_address[0]
        headers["Connection"] = "close"

        conn = None
        try:
            conn = http.client.HTTPConnection(self.upstream_host, self.upstream_port, timeout=120)
            conn.request(self.command, path, body=body, headers=headers)
            resp = conn.getresponse()
            resp_body = resp.read()
        except Exception as exc:
            self.close_connection = True
            self.send_error(502, f"Upstream error: {exc}")
            return
        finally:
            if conn is not None:
                try:
                    conn.close()
                except Exception:
                    pass

        self.close_connection = True
        self.send_response(resp.status, resp.reason)
        for key, value in resp.getheaders():
            lk = key.lower()
            if lk in HOP_BY_HOP:
                continue
            # Avoid browsers caching a broken interstitial while debugging.
            if lk == "location":
                value = self._rewrite_location(value)
            self.send_header(key, value)
        self.send_header("Content-Length", str(len(resp_body)))
        self.send_header("Connection", "close")
        self.end_headers()
        if self.command != "HEAD" and resp_body:
            self.wfile.write(resp_body)

    def _proxy_camera_frames(self) -> bool:
        """Send one short batch of pictures, then close.

        Safari queues lights, dock, and drive behind a picture reply that
        never ends. Those presses only ran after the cameras were disconnected.
        """
        port = camera_agent_port()
        sock = None
        try:
            sock = socket.create_connection(("127.0.0.1", port), timeout=5)
            sock.sendall(
                (
                    f"GET /frames HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\n"
                    f"Accept: text/event-stream\r\nConnection: close\r\n\r\n"
                ).encode("ascii")
            )
            head = b""
            while b"\r\n\r\n" not in head:
                part = sock.recv(4096)
                if not part:
                    break
                head += part
                if len(head) > 65536:
                    break
        except Exception:
            if sock is not None:
                try:
                    sock.close()
                except Exception:
                    pass
            return False

        header, _, pending = head.partition(b"\r\n\r\n")
        status = 200
        first = header.split(b"\r\n", 1)[0].split()
        if len(first) >= 2 and first[1].isdigit():
            status = int(first[1])
        try:
            body = read_picture_batch(sock, pending) if status == 200 else pending[:4096]
        finally:
            try:
                sock.close()
            except Exception:
                pass
        self.close_connection = True
        try:
            self.send_response(status)
            if status == 200:
                self.send_header("Content-Type", "text/event-stream")
            else:
                self.send_header("Content-Type", "application/json")
            self.send_header("Cache-Control", "no-cache, no-store")
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Connection", "close")
            self.end_headers()
            if body:
                self.wfile.write(body)
        except Exception:
            pass
        return True

    def _camera_unavailable(self) -> None:
        body = b'{"ok":false,"error":"Live view is not running. Press Start live view."}'
        self.close_connection = True
        try:
            self.send_response(503)
            self.send_header("Content-Type", "application/json")
            self.send_header("Cache-Control", "no-store")
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Connection", "close")
            self.end_headers()
            self.wfile.write(body)
        except Exception:
            pass

    def _rewrite_location(self, location: str) -> str:
        """Keep redirects on the HTTPS listener when upstream points at itself."""
        try:
            parts = urlsplit(location)
        except Exception:
            return location
        if not parts.scheme and not parts.netloc:
            return location
        host = (parts.hostname or "").lower()
        if host in {"127.0.0.1", "localhost"} and (parts.port in {None, self.upstream_port}):
            listen_host = self.headers.get("Host") or f"localhost:{self.server.server_port}"
            return f"https://{listen_host}{parts.path or '/'}{('?' + parts.query) if parts.query else ''}"
        return location


class PictureProxyHandler(PanelHttpsProxyHandler):
    """Pictures only, on their own port, so buttons keep the panel connection."""

    def do_GET(self) -> None:  # noqa: N802
        if is_camera_frames(self.path or "/"):
            upgrade = (self.headers.get("Upgrade") or "").lower()
            if "websocket" in upgrade:
                if not self._websocket_pictures():
                    self._camera_unavailable()
                return
            if not self._relay_pictures():
                self._camera_unavailable()
            return
        self.close_connection = True
        self.send_error(404)

    def do_POST(self) -> None:  # noqa: N802
        self.close_connection = True
        self.send_error(404)

    def _relay_pictures(self) -> bool:
        port = camera_agent_port()
        sock = None
        try:
            sock = socket.create_connection(("127.0.0.1", port), timeout=5)
            sock.sendall(
                (
                    f"GET /frames HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\n"
                    f"Accept: text/event-stream\r\nConnection: close\r\n\r\n"
                ).encode("ascii")
            )
            head = b""
            while b"\r\n\r\n" not in head:
                part = sock.recv(4096)
                if not part:
                    break
                head += part
                if len(head) > 65536:
                    break
        except Exception:
            if sock is not None:
                try:
                    sock.close()
                except Exception:
                    pass
            return False

        _header, _, pending = head.partition(b"\r\n\r\n")
        self.close_connection = True
        self.protocol_version = "HTTP/1.1"
        try:
            self.send_response(200)
            self.send_header("Content-Type", "text/event-stream")
            self.send_header("Cache-Control", "no-cache, no-store")
            self.send_header("Connection", "close")
            self.send_header("Transfer-Encoding", "chunked")
            # The page is on the panel port. Safari will not draw a cross-port
            # picture stream without this.
            origin = self.headers.get("Origin")
            self.send_header("Access-Control-Allow-Origin", origin or "*")
            self.end_headers()
            relay_chunked(sock, self.wfile, pending)
        except Exception:
            pass
        finally:
            try:
                sock.close()
            except Exception:
                pass
        return True

    def _websocket_pictures(self) -> bool:
        """Pictures down, drive and other presses back up, on one connection.

        Safari will not open a second connection to this robot while a picture
        stream is up, even on another port. Closing the pictures for a press
        made the view drop for the whole time a direction was held. A two-way
        picture connection carries the press itself, so the view stays up.
        """
        key = (self.headers.get("Sec-WebSocket-Key") or "").strip()
        if not key:
            return False
        port = camera_agent_port()
        sock = None
        try:
            sock = socket.create_connection(("127.0.0.1", port), timeout=5)
            sock.sendall(
                (
                    f"GET /frames HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\n"
                    f"Accept: text/event-stream\r\nConnection: close\r\n\r\n"
                ).encode("ascii")
            )
            head = b""
            while b"\r\n\r\n" not in head:
                part = sock.recv(4096)
                if not part:
                    break
                head += part
                if len(head) > 65536:
                    break
        except Exception:
            if sock is not None:
                try:
                    sock.close()
                except Exception:
                    pass
            return False
        if b"\r\n\r\n" not in head:
            try:
                sock.close()
            except Exception:
                pass
            return False

        _header, _, pending = head.partition(b"\r\n\r\n")
        self.close_connection = True
        try:
            self.send_response(101, "Switching Protocols")
            self.send_header("Upgrade", "websocket")
            self.send_header("Connection", "Upgrade")
            self.send_header("Sec-WebSocket-Accept", websocket_accept(key))
            self.end_headers()
        except Exception:
            try:
                sock.close()
            except Exception:
                pass
            return True

        try:
            self.wfile.flush()
        except Exception:
            pass
        self._websocket_session(sock, pending)
        return True

    def _websocket_session(self, camera: socket.socket, pending: bytes) -> None:
        """Keep sending pictures while a direction is handled.

        One thread owns the browser socket. The direction is forwarded on
        another. Only one picture sits on that socket at a time. Holding every
        picture and copying the whole pile on each send filled the machine, so
        another device could not open the panel and a direction could not get
        out while the pictures were moving.
        """
        client = self.connection
        try:
            client.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
            # A few pictures of room. A multi-megabyte buffer let this loop
            # accept frames the browser had not read, and that work crowded
            # out every other request.
            client.setsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF, 262144)
        except Exception:
            pass
        stop = threading.Event()
        pictures: queue.Queue[bytes] = queue.Queue(maxsize=4)
        replies: queue.Queue[bytes] = queue.Queue()
        jobs: queue.Queue[bytes | None] = queue.Queue()

        def pump() -> None:
            buf = bytearray(pending)
            try:
                camera.settimeout(0.5)
                while not stop.is_set():
                    split = buf.find(b"\n\n")
                    while split >= 0:
                        block = bytes(buf[:split])
                        del buf[: split + 2]
                        payload = sse_payload(block)
                        split = buf.find(b"\n\n")
                        if not payload:
                            continue
                        frame = encode_ws_frame(0x1, payload)
                        while not stop.is_set():
                            try:
                                pictures.put(frame, timeout=0.2)
                                break
                            except queue.Full:
                                try:
                                    pictures.get_nowait()
                                except queue.Empty:
                                    pass
                        # Parsing a burst of pictures must not keep the whole
                        # panel from accepting another browser.
                        time.sleep(0)
                    try:
                        chunk = camera.recv(16384)
                    except (TimeoutError, socket.timeout):
                        continue
                    if not chunk:
                        break
                    buf += chunk
            except Exception:
                pass
            finally:
                stop.set()

        def push_status() -> None:
            """One status request at a time.

            Safari reads this picture connection and does not deliver a status
            card that arrives on the quiet one. A full status page holds a PHP
            worker for the whole dashboard, so this asks only for the robot
            card, and only one of those requests runs at a time.
            """
            first = True
            while not stop.is_set():
                if not first and stop.wait(5):
                    return
                first = False
                conn = None
                body = b""
                try:
                    conn = http.client.HTTPConnection(
                        self.upstream_host, self.upstream_port, timeout=8
                    )
                    conn.request(
                        "GET",
                        "/api/status.php?card=1",
                        headers={
                            "Host": self.headers.get("Host")
                            or f"{self.upstream_host}:{self.upstream_port}",
                            "Connection": "close",
                            "X-Forwarded-Proto": "https",
                        },
                    )
                    resp = conn.getresponse()
                    body = resp.read(262144)
                except Exception:
                    body = b""
                finally:
                    if conn is not None:
                        try:
                            conn.close()
                        except Exception:
                            pass
                if not body.startswith(b"{"):
                    continue
                try:
                    text = body.decode("utf-8")
                except UnicodeError:
                    continue
                replies.put(
                    encode_ws_frame(
                        0x1,
                        json.dumps({"op": "status", "body": text}, separators=(",", ":")).encode("utf-8"),
                    )
                )

        threading.Thread(target=pump, daemon=True).start()
        threading.Thread(
            target=self._websocket_jobs, args=(jobs, replies, stop), daemon=True
        ).start()
        threading.Thread(target=push_status, daemon=True).start()

        incoming = bytearray()
        reply_out = bytearray()
        picture: bytes | None = None
        picture_off = 0
        fragments = bytearray()
        opcode = None

        def send_ready(buf: bytes | bytearray, offset: int) -> int:
            """Bytes the browser accepted. 0 means it would block. -1 means it closed."""
            if offset >= len(buf):
                return 0
            # A blocking send of one picture held this thread until the browser
            # caught up, so a press and the status card never left the queue.
            try:
                client.settimeout(0)
            except Exception:
                pass
            try:
                return client.send(memoryview(buf)[offset:])
            except (TimeoutError, socket.timeout, BlockingIOError, ssl.SSLWantWriteError, ssl.SSLWantReadError):
                return 0
            except Exception:
                return -1

        def newest_picture() -> bytes | None:
            """The latest picture. Older ones are dropped so the socket stays small."""
            newest = None
            while True:
                try:
                    newest = pictures.get_nowait()
                except queue.Empty:
                    return newest

        def pull_client(wait: bool) -> bytes | None:
            """Bytes from the browser. None means the browser hung up.

            Waiting here between pictures would hold the next frame for the
            whole timeout, so a direction is read without pausing the view
            when another picture is ready.
            """
            try:
                client.settimeout(0.05 if wait else 0)
            except Exception:
                pass
            try:
                return client.recv(65536)
            except (TimeoutError, socket.timeout, BlockingIOError, ssl.SSLWantReadError, ssl.SSLWantWriteError):
                return b""
            except Exception:
                return None

        try:
            while not stop.is_set():
                while True:
                    try:
                        reply_out += replies.get_nowait()
                    except queue.Empty:
                        break
                sent = 0
                # A picture already partly on the wire has to finish. A reply
                # goes out before the next picture, so a direction is not stuck
                # behind a pile of frames.
                if picture is not None and picture_off > 0:
                    sent = send_ready(picture, picture_off)
                    if sent < 0:
                        break
                    if sent:
                        picture_off += sent
                        if picture_off >= len(picture):
                            picture = None
                            picture_off = 0
                elif reply_out:
                    sent = send_ready(reply_out, 0)
                    if sent < 0:
                        break
                    if sent:
                        del reply_out[:sent]
                else:
                    if picture is None:
                        picture = newest_picture()
                        picture_off = 0
                    if picture is not None:
                        sent = send_ready(picture, picture_off)
                        if sent < 0:
                            break
                        if sent:
                            picture_off += sent
                            if picture_off >= len(picture):
                                picture = None
                                picture_off = 0
                more = bool(reply_out) or picture is not None or not pictures.empty()
                data = pull_client(wait=sent == 0 or not more)
                if data is None:
                    break
                if not data:
                    # A successful send used to spin straight into the next
                    # picture. That held the panel, so another device hung
                    # while the cameras were on.
                    time.sleep(0.001 if sent else 0.01)
                    continue
                incoming += data
                try:
                    frames = pop_ws_frames(incoming)
                except ValueError:
                    break
                for op, payload, fin in frames:
                    if op == 0x8:
                        reply_out += encode_ws_frame(0x8, payload[:125])
                        stop.set()
                        break
                    if op == 0x9:
                        reply_out += encode_ws_frame(0xA, payload[:125])
                        continue
                    if op == 0xA:
                        continue
                    if op == 0x0:
                        fragments += payload
                    elif op == 0x1:
                        opcode = op
                        fragments = bytearray(payload)
                    else:
                        opcode = None
                        fragments = bytearray()
                        continue
                    if not fin or opcode != 0x1:
                        continue
                    jobs.put(bytes(fragments))
                    fragments = bytearray()
                    opcode = None
        finally:
            stop.set()
            jobs.put(None)
            try:
                camera.close()
            except Exception:
                pass

    def _websocket_jobs(self, jobs: "queue.Queue[bytes | None]", replies: "queue.Queue[bytes]", stop: threading.Event) -> None:
        while not stop.is_set():
            try:
                message = jobs.get(timeout=0.2)
            except queue.Empty:
                continue
            if message is None:
                return
            if self._message_op(message) == "drive":
                while True:
                    try:
                        nxt = jobs.get_nowait()
                    except queue.Empty:
                        break
                    if nxt is None:
                        message = None
                        break
                    if self._message_op(nxt) == "drive":
                        message = nxt
                        continue
                    frame = self._websocket_command(message)
                    if frame:
                        replies.put(frame)
                    message = nxt
                    break
                if message is None:
                    return
            frame = self._websocket_command(message)
            if frame:
                replies.put(frame)

    def _message_op(self, message: bytes) -> str:
        try:
            incoming = json.loads(message.decode("utf-8"))
        except (UnicodeError, json.JSONDecodeError, TypeError):
            return ""
        if not isinstance(incoming, dict):
            return ""
        op = incoming.get("op")
        return op if isinstance(op, str) else ""

    def _websocket_command(self, message: bytes) -> bytes | None:
        if len(message) > 65536:
            return
        try:
            incoming = json.loads(message.decode("utf-8"))
        except (UnicodeError, json.JSONDecodeError, TypeError):
            return
        if not isinstance(incoming, dict):
            return
        op = incoming.get("op")
        request_id = incoming.get("id")
        if op == "drive":
            path = "/api/drive.php"
            body = json.dumps(incoming.get("json") if isinstance(incoming.get("json"), dict) else {}).encode("utf-8")
            content_type = "application/json"
        elif op == "command":
            path = "/api/command.php"
            raw = incoming.get("body") if isinstance(incoming.get("body"), str) else ""
            body = raw.encode("utf-8")[:65536]
            content_type = "application/x-www-form-urlencoded"
        else:
            return
        status = 502
        reply = b'{"ok":false,"error":"The robot did not answer. Press it again."}'
        conn = None
        try:
            conn = http.client.HTTPConnection(self.upstream_host, self.upstream_port, timeout=120)
            host = self.headers.get("Host") or f"{self.upstream_host}:{self.upstream_port}"
            conn.request(
                "POST",
                path,
                body=body,
                headers={
                    "Host": host,
                    "Content-Type": content_type,
                    "Content-Length": str(len(body)),
                    "Connection": "close",
                    "X-Forwarded-Proto": "https",
                    "X-Forwarded-For": self.client_address[0],
                },
            )
            resp = conn.getresponse()
            status = resp.status
            reply = resp.read(65536)
        except Exception:
            pass
        finally:
            if conn is not None:
                try:
                    conn.close()
                except Exception:
                    pass
        try:
            text = reply.decode("utf-8")
        except UnicodeError:
            text = ""
        return encode_ws_frame(
            0x1,
            json.dumps({"op": "reply", "id": request_id, "status": status, "body": text}).encode("utf-8"),
        )

    def _websocket_controls(self) -> bool:
        """Drive and other presses, with no pictures on this connection.

        Sending a direction on the picture connection left Safari waiting, and
        the page then said the drive did not answer. This connection stays
        quiet so the press can leave the browser while the pictures keep moving.
        """
        key = (self.headers.get("Sec-WebSocket-Key") or "").strip()
        if not key:
            return False
        self.close_connection = True
        try:
            self.send_response(101, "Switching Protocols")
            self.send_header("Upgrade", "websocket")
            self.send_header("Connection", "Upgrade")
            self.send_header("Sec-WebSocket-Accept", websocket_accept(key))
            self.end_headers()
            self.wfile.flush()
        except Exception:
            return True
        self._websocket_control_session()
        return True

    def _websocket_control_session(self) -> None:
        client = self.connection
        try:
            client.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        except Exception:
            pass
        stop = threading.Event()
        replies: queue.Queue[bytes] = queue.Queue()
        jobs: queue.Queue[bytes | None] = queue.Queue()
        threading.Thread(
            target=self._websocket_jobs, args=(jobs, replies, stop), daemon=True
        ).start()
        incoming = bytearray()
        reply_out = bytearray()
        fragments = bytearray()
        opcode = None
        try:
            client.settimeout(0.2)
        except Exception:
            pass
        try:
            while not stop.is_set():
                while True:
                    try:
                        reply_out += replies.get_nowait()
                    except queue.Empty:
                        break
                if reply_out:
                    try:
                        sent = client.send(reply_out)
                    except (TimeoutError, socket.timeout, BlockingIOError, ssl.SSLWantWriteError, ssl.SSLWantReadError):
                        sent = 0
                    except Exception:
                        break
                    if sent:
                        del reply_out[:sent]
                try:
                    data = client.recv(65536)
                except (TimeoutError, socket.timeout, ssl.SSLWantReadError, ssl.SSLWantWriteError):
                    continue
                except Exception:
                    break
                if not data:
                    break
                incoming += data
                try:
                    frames = pop_ws_frames(incoming)
                except ValueError:
                    break
                for op, payload, fin in frames:
                    if op == 0x8:
                        reply_out += encode_ws_frame(0x8, payload[:125])
                        stop.set()
                        break
                    if op == 0x9:
                        reply_out += encode_ws_frame(0xA, payload[:125])
                        continue
                    if op == 0xA:
                        continue
                    if op == 0x0:
                        fragments += payload
                    elif op == 0x1:
                        opcode = op
                        fragments = bytearray(payload)
                    else:
                        opcode = None
                        fragments = bytearray()
                        continue
                    if not fin or opcode != 0x1:
                        continue
                    jobs.put(bytes(fragments))
                    fragments = bytearray()
                    opcode = None
        finally:
            stop.set()
            jobs.put(None)


# The page talks to the panel port. The same picture socket has to live there,
# or Safari keeps the buttons queued behind a stream they cannot share.
for _ws_name in (
    "_websocket_pictures",
    "_websocket_session",
    "_websocket_jobs",
    "_message_op",
    "_websocket_command",
    "_websocket_controls",
    "_websocket_control_session",
):
    setattr(PanelHttpsProxyHandler, _ws_name, getattr(PictureProxyHandler, _ws_name))


class DualStackServer(ThreadingHTTPServer):
    address_family = socket.AF_INET
    daemon_threads = True
    allow_reuse_address = True


def wait_for_upstream(host: str, port: int, tries: int = 50) -> bool:
    for _ in range(tries):
        try:
            with socket.create_connection((host, port), timeout=0.2):
                return True
        except OSError:
            threading.Event().wait(0.1)
    return False


def main() -> int:
    parser = argparse.ArgumentParser(description="Yarbo panel HTTPS reverse proxy")
    parser.add_argument("--listen-host", default="0.0.0.0")
    parser.add_argument("--listen-port", type=int, default=8443)
    parser.add_argument("--frames-port", type=int, default=0)
    parser.add_argument("--upstream-host", default="127.0.0.1")
    parser.add_argument("--upstream-port", type=int, default=8080)
    parser.add_argument("--cert", required=True)
    parser.add_argument("--key", required=True)
    parser.add_argument("--wait-upstream", action="store_true", default=True)
    parser.add_argument("--no-wait-upstream", action="store_false", dest="wait_upstream")
    args = parser.parse_args()

    PanelHttpsProxyHandler.upstream_host = args.upstream_host
    PanelHttpsProxyHandler.upstream_port = args.upstream_port

    if args.wait_upstream:
        print(
            f"panel_https_proxy: waiting for upstream http://{args.upstream_host}:{args.upstream_port}",
            flush=True,
        )
        if not wait_for_upstream(args.upstream_host, args.upstream_port):
            print("panel_https_proxy: WARNING upstream not ready yet; continuing anyway", flush=True)

    cert_path = args.cert
    key_path = args.key
    if not os.path.isfile(cert_path):
        print(f"panel_https_proxy: cert file missing: {cert_path!r}", file=sys.stderr, flush=True)
        return 1
    if not os.path.isfile(key_path):
        print(f"panel_https_proxy: key file missing: {key_path!r}", file=sys.stderr, flush=True)
        return 1

    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    if hasattr(ssl, "TLSVersion"):
        context.minimum_version = ssl.TLSVersion.TLSv1_2
    try:
        context.load_cert_chain(certfile=cert_path, keyfile=key_path)
    except Exception as exc:
        print(
            f"panel_https_proxy: failed to load cert {cert_path!r} / key {key_path!r}: {exc}",
            file=sys.stderr,
            flush=True,
        )
        return 1

    try:
        httpd = DualStackServer((args.listen_host, args.listen_port), PanelHttpsProxyHandler)
    except OSError as exc:
        print(f"panel_https_proxy: bind failed on {args.listen_host}:{args.listen_port}: {exc}", file=sys.stderr, flush=True)
        return 1

    httpd.socket = context.wrap_socket(httpd.socket, server_side=True)
    frames_port = args.frames_port or (args.listen_port + 1)
    frames = None
    try:
        frames = DualStackServer((args.listen_host, frames_port), PictureProxyHandler)
        frames.socket = context.wrap_socket(frames.socket, server_side=True)
    except OSError as exc:
        print(
            f"panel_https_proxy: picture port bind failed on {args.listen_host}:{frames_port}: {exc}",
            file=sys.stderr,
            flush=True,
        )
        frames = None
    print(
        f"panel_https_proxy: https://{args.listen_host}:{args.listen_port} → "
        f"http://{args.upstream_host}:{args.upstream_port}",
        flush=True,
    )
    if frames is not None:
        print(
            f"panel_https_proxy: pictures on https://{args.listen_host}:{frames_port}",
            flush=True,
        )
        threading.Thread(target=frames.serve_forever, kwargs={"poll_interval": 0.5}, daemon=True).start()
    try:
        httpd.serve_forever(poll_interval=0.5)
    except KeyboardInterrupt:
        pass
    finally:
        httpd.server_close()
        if frames is not None:
            frames.shutdown()
            frames.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
