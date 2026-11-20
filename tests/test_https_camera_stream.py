#!/usr/bin/env python3
"""Live pictures must stream through HTTPS without waiting for the stream to end."""

from __future__ import annotations

import importlib.util
import io
import json
import socket
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def load():
    path = ROOT / "scripts" / "panel_https_proxy.py"
    spec = importlib.util.spec_from_file_location("panel_https_proxy_test", path)
    assert spec and spec.loader
    mod = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = mod
    spec.loader.exec_module(mod)
    return mod


class SlowBody:
    def __init__(self) -> None:
        self.calls = 0

    def read(self, _size: int) -> bytes:
        self.calls += 1
        if self.calls == 1:
            return b"data: hi\n\n"
        time.sleep(5)
        return b""


class Sink:
    def __init__(self) -> None:
        self.buf = io.BytesIO()

    def write(self, data: bytes) -> None:
        self.buf.write(data)

    def flush(self) -> None:
        return None


def main() -> None:
    proxy = load()
    assert proxy.is_camera_frames("/api/camera_live.php?action=frames")
    assert not proxy.is_camera_frames("/api/camera_live.php?action=status")
    assert not proxy.is_camera_frames("/api/status.php")
    assert proxy.camera_agent_port() != 8766

    sink = Sink()
    worker = threading.Thread(target=proxy.relay_upstream, args=(SlowBody(), sink), daemon=True)
    worker.start()
    deadline = time.time() + 0.8
    while time.time() < deadline and sink.buf.getvalue() != b"data: hi\n\n":
        time.sleep(0.02)
    assert sink.buf.getvalue() == b"data: hi\n\n", "first picture packet must arrive before the stream ends"
    assert stream_reaches_client(proxy), "a picture must reach the browser while the stream stays open"
    assert picture_port_stays_open(proxy), "pictures stay open on their own port while a button gets a reply"
    assert proxy.websocket_accept("dGhlIHNhbXBsZSBub25jZQ==") == "s3pPLMBiTxaQ9kYGzzhZRbK+xOo="
    assert drive_on_picture_socket(proxy), "pictures keep arriving while a direction is still being handled"
    assert drive_on_picture_socket(proxy, proxy.PanelHttpsProxyHandler), "the panel port carries a direction on the same picture connection"
    assert pictures_stay_small(proxy), "a live view keeps the newest picture so another device can still open the panel"
    assert drive_on_quiet_socket(proxy), "a direction on the quiet connection is handled while pictures keep arriving"
    print("ok")


class _Agent(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt: str, *args) -> None:
        return

    def do_GET(self) -> None:  # noqa: N802
        self.send_response(200)
        self.send_header("Content-Type", "text/event-stream")
        self.send_header("Cache-Control", "no-cache")
        self.send_header("Connection", "close")
        self.end_headers()
        # The working picture path flushes once a full block is ready. A real
        # camera frame is larger than that block, and the stream stays open.
        body = b'data: {"view":"middle","d":"' + (b"A" * 20000) + b'"}\n\n'
        self.wfile.write(body)
        self.wfile.flush()
        time.sleep(2)


class _Upstream(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt: str, *args) -> None:
        return

    def do_GET(self) -> None:  # noqa: N802
        body = b'{"ok":true,"note":"moved"}'
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)


def stream_reaches_client(proxy) -> bool:
    agent = ThreadingHTTPServer(("127.0.0.1", 0), _Agent)
    upstream = ThreadingHTTPServer(("127.0.0.1", 0), _Upstream)
    port = agent.server_address[1]
    threading.Thread(target=agent.serve_forever, daemon=True).start()
    threading.Thread(target=upstream.serve_forever, daemon=True).start()
    status = ROOT / "data" / "camera-live-status.json"
    status.parent.mkdir(exist_ok=True)
    previous = status.read_text(encoding="utf-8") if status.is_file() else None
    status.write_text(json.dumps({"port": port, "state": "live"}), encoding="utf-8")
    previous_upstream = proxy.PanelHttpsProxyHandler.upstream_port
    proxy.PanelHttpsProxyHandler.upstream_port = upstream.server_address[1]
    httpd = ThreadingHTTPServer(("127.0.0.1", 0), proxy.PanelHttpsProxyHandler)
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    buf = b""
    other_buf = b""
    client = None
    try:
        client = socket.create_connection(("127.0.0.1", httpd.server_address[1]), timeout=3)
        client.sendall(b"GET /api/camera_live.php?action=frames HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
        client.settimeout(1.0)
        deadline = time.time() + 1.0
        while time.time() < deadline and b"middle" not in buf:
            try:
                part = client.recv(4096)
            except socket.timeout:
                break
            if not part:
                break
            buf += part
        # The picture reply must finish while the camera is still sending,
        # so a drive press is not left waiting on that connection.
        while time.time() < deadline:
            try:
                part = client.recv(4096)
            except socket.timeout:
                break
            if not part:
                break
            buf += part
        other = socket.create_connection(("127.0.0.1", httpd.server_address[1]), timeout=3)
        other.sendall(b"GET /api/drive.php HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
        other.settimeout(1.0)
        deadline = time.time() + 1.0
        while time.time() < deadline and b"moved" not in other_buf:
            try:
                part = other.recv(4096)
            except socket.timeout:
                break
            if not part:
                break
            other_buf += part
        other.close()
    finally:
        if client is not None:
            client.close()
        httpd.shutdown()
        agent.shutdown()
        upstream.shutdown()
        proxy.PanelHttpsProxyHandler.upstream_port = previous_upstream
        if previous is None:
            status.unlink(missing_ok=True)
        else:
            status.write_text(previous, encoding="utf-8")
    pictures = (
        b"HTTP/1.1 200" in buf
        and b"Content-Length:" in buf
        and b"middle" in buf
        and b"retry: 40" in buf
        and b"text/event-stream" in buf
        and b"Transfer-Encoding:" not in buf
    )
    return pictures and b'"ok":true' in other_buf and b"moved" in other_buf


def picture_port_stays_open(proxy) -> bool:
    agent = ThreadingHTTPServer(("127.0.0.1", 0), _Agent)
    upstream = ThreadingHTTPServer(("127.0.0.1", 0), _Upstream)
    threading.Thread(target=agent.serve_forever, daemon=True).start()
    threading.Thread(target=upstream.serve_forever, daemon=True).start()
    status = ROOT / "data" / "camera-live-status.json"
    status.parent.mkdir(exist_ok=True)
    previous = status.read_text(encoding="utf-8") if status.is_file() else None
    status.write_text(json.dumps({"port": agent.server_address[1], "state": "live"}), encoding="utf-8")
    previous_upstream = proxy.PanelHttpsProxyHandler.upstream_port
    proxy.PanelHttpsProxyHandler.upstream_port = upstream.server_address[1]
    pictures = ThreadingHTTPServer(("127.0.0.1", 0), proxy.PictureProxyHandler)
    buttons = ThreadingHTTPServer(("127.0.0.1", 0), proxy.PanelHttpsProxyHandler)
    threading.Thread(target=pictures.serve_forever, daemon=True).start()
    threading.Thread(target=buttons.serve_forever, daemon=True).start()
    seen = b""
    other = b""
    client = None
    try:
        client = socket.create_connection(("127.0.0.1", pictures.server_address[1]), timeout=3)
        client.sendall(
            b"GET /api/camera_live.php?action=frames HTTP/1.1\r\n"
            b"Host: localhost\r\nOrigin: https://panel.local:8443\r\nConnection: close\r\n\r\n"
        )
        client.settimeout(1.0)
        deadline = time.time() + 1.0
        while time.time() < deadline and b"middle" not in seen:
            part = client.recv(4096)
            if not part:
                break
            seen += part
        drive = socket.create_connection(("127.0.0.1", buttons.server_address[1]), timeout=3)
        drive.sendall(b"GET /api/drive.php HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
        drive.settimeout(1.0)
        deadline = time.time() + 1.0
        while time.time() < deadline and b"moved" not in other:
            part = drive.recv(4096)
            if not part:
                break
            other += part
        drive.close()
        # The picture connection is still the open stream, not a finished reply.
        client.settimeout(0.2)
        try:
            extra = client.recv(4096)
        except socket.timeout:
            extra = b"open"
        else:
            if extra:
                seen += extra
                extra = b"open"
    finally:
        if client is not None:
            client.close()
        pictures.shutdown()
        buttons.shutdown()
        agent.shutdown()
        upstream.shutdown()
        proxy.PanelHttpsProxyHandler.upstream_port = previous_upstream
        if previous is None:
            status.unlink(missing_ok=True)
        else:
            status.write_text(previous, encoding="utf-8")
    return (
        b"HTTP/1.1 200" in seen
        and b"Transfer-Encoding: chunked" in seen
        and b"middle" in seen
        and b"Access-Control-Allow-Origin: https://panel.local:8443" in seen
        and extra == b"open"
        and b"moved" in other
    )


class _Pictures(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt: str, *args) -> None:
        return

    def do_GET(self) -> None:  # noqa: N802
        self.send_response(200)
        self.send_header("Content-Type", "text/event-stream")
        self.send_header("Cache-Control", "no-cache")
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(b": live\n\n")
        self.wfile.flush()
        try:
            for index in range(30):
                payload = json.dumps({"view": "middle", "n": index, "d": "QQ"}).encode()
                self.wfile.write(b"data: " + payload + b"\n\n")
                self.wfile.flush()
                time.sleep(0.1)
        except (BrokenPipeError, ConnectionResetError):
            return


class _Drive(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    seen = b""
    started = 0.0
    finished = 0.0

    def log_message(self, fmt: str, *args) -> None:
        return

    def do_POST(self) -> None:  # noqa: N802
        length = int(self.headers.get("Content-Length", "0") or "0")
        _Drive.seen = self.rfile.read(length)
        _Drive.started = time.time()
        # A real direction takes long enough that the pictures used to freeze
        # until it returned. They have to keep arriving during this wait.
        time.sleep(0.45)
        _Drive.finished = time.time()
        body = b'{"ok":true,"via":"picture"}'
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)


def _client_frame(payload: bytes) -> bytes:
    mask = b"\x01\x02\x03\x04"
    masked = bytes(byte ^ mask[index % 4] for index, byte in enumerate(payload))
    return bytes([0x81, 0x80 | len(payload)]) + mask + masked


def drive_on_picture_socket(proxy, handler=None) -> bool:
    pictures_upstream = ThreadingHTTPServer(("127.0.0.1", 0), _Pictures)
    buttons = ThreadingHTTPServer(("127.0.0.1", 0), _Drive)
    threading.Thread(target=pictures_upstream.serve_forever, daemon=True).start()
    threading.Thread(target=buttons.serve_forever, daemon=True).start()
    status = ROOT / "data" / "camera-live-status.json"
    status.parent.mkdir(exist_ok=True)
    previous = status.read_text(encoding="utf-8") if status.is_file() else None
    status.write_text(json.dumps({"port": pictures_upstream.server_address[1], "state": "live"}), encoding="utf-8")
    previous_upstream = proxy.PanelHttpsProxyHandler.upstream_port
    proxy.PanelHttpsProxyHandler.upstream_port = buttons.server_address[1]
    _Drive.seen = b""
    _Drive.started = 0.0
    _Drive.finished = 0.0
    pictures = ThreadingHTTPServer(("127.0.0.1", 0), handler or proxy.PictureProxyHandler)
    threading.Thread(target=pictures.serve_forever, daemon=True).start()
    client = None
    frames = []
    frame_at = []
    opened = False
    try:
        client = socket.create_connection(("127.0.0.1", pictures.server_address[1]), timeout=3)
        key = "dGhlIHNhbXBsZSBub25jZQ=="
        client.sendall(
            (
                "GET /api/camera_live.php?action=frames HTTP/1.1\r\n"
                "Host: panel.local:8444\r\n"
                "Origin: https://panel.local:8443\r\n"
                "Upgrade: websocket\r\n"
                "Connection: Upgrade\r\n"
                f"Sec-WebSocket-Key: {key}\r\n"
                "Sec-WebSocket-Version: 13\r\n\r\n"
            ).encode("ascii")
        )
        client.settimeout(2.0)
        head = b""
        while b"\r\n\r\n" not in head:
            part = client.recv(4096)
            if not part:
                break
            head += part
        opened = b"101" in head.split(b"\r\n", 1)[0] and b"s3pPLMBiTxaQ9kYGzzhZRbK+xOo=" in head
        pending = head.split(b"\r\n\r\n", 1)[1]
        # Frames can arrive in the same packet as the handshake.
        blob = pending
        deadline = time.time() + 2.0
        sent = False
        while time.time() < deadline:
            length = None
            offset = 2
            if len(blob) >= 2:
                length = blob[1] & 0x7F
                if length == 126 and len(blob) >= 4:
                    length = int.from_bytes(blob[2:4], "big")
                    offset = 4
                elif length == 126:
                    length = None
            if length is None or len(blob) < offset + length:
                try:
                    part = client.recv(4096)
                except socket.timeout:
                    break
                if not part:
                    break
                blob += part
                continue
            payload = blob[offset : offset + length]
            blob = blob[offset + length :]
            frames.append(payload)
            frame_at.append(time.time())
            if not sent and (b'"view":"middle"' in payload or b'"view": "middle"' in payload):
                client.sendall(_client_frame(b'{"op":"drive","id":7,"json":{"linear":0.2,"angular":0,"enter_manual":true}}'))
                sent = True
            if sent and any(b'"reply"' in frame for frame in frames):
                break
    finally:
        if client is not None:
            client.close()
        pictures.shutdown()
        pictures_upstream.shutdown()
        buttons.shutdown()
        proxy.PanelHttpsProxyHandler.upstream_port = previous_upstream
        if previous is None:
            status.unlink(missing_ok=True)
        else:
            status.write_text(previous, encoding="utf-8")
    during = [
        stamp
        for stamp, frame in zip(frame_at, frames)
        if b"middle" in frame and _Drive.started and _Drive.started + 0.12 <= stamp <= _Drive.finished
    ]
    reply_at = next((index for index, frame in enumerate(frames) if b'"reply"' in frame), -1)
    reply = frames[reply_at] if reply_at >= 0 else b""
    drove = b"linear" in _Drive.seen and b"0.2" in _Drive.seen
    # The drive result is a JSON string inside the picture frame, so its quotes are escaped.
    answered = b"picture" in reply and b"true" in reply
    same_press = b'"id": 7' in reply or b'"id":7' in reply
    return bool(opened and drove and answered and same_press and during)


class _QuietPanel(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt: str, *args) -> None:
        return

    def do_GET(self) -> None:  # noqa: N802
        body = b"panel"
        self.send_response(200)
        self.send_header("Content-Type", "text/plain")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)


class _Flood(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt: str, *args) -> None:
        return

    def do_GET(self) -> None:  # noqa: N802
        self.send_response(200)
        self.send_header("Content-Type", "text/event-stream")
        self.send_header("Cache-Control", "no-cache")
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(b": live\n\n")
        self.wfile.flush()
        blob = "A" * 50000
        try:
            for index in range(40):
                payload = json.dumps({"view": "middle", "n": index, "d": blob}).encode()
                self.wfile.write(b"data: " + payload + b"\n\n")
                self.wfile.flush()
            # Stay open. Closing here used to look like the browser had every
            # picture, when the relay had only started the first one.
            while True:
                time.sleep(0.2)
                self.wfile.write(b": ping\n\n")
                self.wfile.flush()
        except (BrokenPipeError, ConnectionResetError, TimeoutError, OSError):
            return


def _read_ws_payloads(client: socket.socket, blob: bytes, deadline: float) -> list[bytes]:
    frames: list[bytes] = []
    while time.time() < deadline:
        length = None
        offset = 2
        if len(blob) >= 2:
            length = blob[1] & 0x7F
            if length == 126 and len(blob) >= 4:
                length = int.from_bytes(blob[2:4], "big")
                offset = 4
            elif length == 126:
                length = None
            elif length == 127 and len(blob) >= 10:
                length = int.from_bytes(blob[2:10], "big")
                offset = 10
            elif length == 127:
                length = None
        if length is None or len(blob) < offset + length:
            try:
                part = client.recv(65536)
            except socket.timeout:
                break
            if not part:
                break
            blob += part
            continue
        frames.append(blob[offset : offset + length])
        blob = blob[offset + length :]
    return frames


def pictures_stay_small(proxy) -> bool:
    """A viewer that falls behind must not keep every picture or block the panel."""
    pictures_upstream = ThreadingHTTPServer(("127.0.0.1", 0), _Flood)
    panel = ThreadingHTTPServer(("127.0.0.1", 0), _QuietPanel)
    threading.Thread(target=pictures_upstream.serve_forever, daemon=True).start()
    threading.Thread(target=panel.serve_forever, daemon=True).start()
    status = ROOT / "data" / "camera-live-status.json"
    status.parent.mkdir(exist_ok=True)
    previous = status.read_text(encoding="utf-8") if status.is_file() else None
    status.write_text(json.dumps({"port": pictures_upstream.server_address[1], "state": "live"}), encoding="utf-8")
    previous_upstream = proxy.PanelHttpsProxyHandler.upstream_port
    proxy.PanelHttpsProxyHandler.upstream_port = panel.server_address[1]
    pictures = ThreadingHTTPServer(("127.0.0.1", 0), proxy.PanelHttpsProxyHandler)
    threading.Thread(target=pictures.serve_forever, daemon=True).start()
    client = None
    other = None
    frames: list[bytes] = []
    other_body = b""
    other_wait = 99.0
    raw = b""
    try:
        client = socket.create_connection(("127.0.0.1", pictures.server_address[1]), timeout=3)
        key = "dGhlIHNhbXBsZSBub25jZQ=="
        client.sendall(
            (
                "GET /api/camera_live.php?action=frames HTTP/1.1\r\n"
                "Host: panel.local:8443\r\n"
                "Upgrade: websocket\r\n"
                "Connection: Upgrade\r\n"
                f"Sec-WebSocket-Key: {key}\r\n"
                "Sec-WebSocket-Version: 13\r\n\r\n"
            ).encode("ascii")
        )
        client.settimeout(0.3)
        # Leave the pictures unread so a backlog would form, then open the panel.
        time.sleep(0.45)
        started = time.time()
        other = socket.create_connection(("127.0.0.1", pictures.server_address[1]), timeout=2)
        other.settimeout(1.5)
        other.sendall(b"GET / HTTP/1.1\r\nHost: panel.local\r\nConnection: close\r\n\r\n")
        while time.time() < started + 1.5:
            try:
                part = other.recv(4096)
            except socket.timeout:
                break
            if not part:
                break
            other_body += part
            if b"panel" in other_body:
                break
        other_wait = time.time() - started
        client.settimeout(0.2)
        read_until = time.time() + 1.0
        while time.time() < read_until:
            try:
                part = client.recv(65536)
            except socket.timeout:
                continue
            if not part:
                break
            raw += part
        _head, _, rest = raw.partition(b"\r\n\r\n")
        frames = _read_ws_payloads(client, rest, time.time() + 0.4)
    finally:
        if client is not None:
            client.close()
        if other is not None:
            other.close()
        pictures.shutdown()
        pictures_upstream.shutdown()
        panel.shutdown()
        proxy.PanelHttpsProxyHandler.upstream_port = previous_upstream
        if previous is None:
            status.unlink(missing_ok=True)
        else:
            status.write_text(previous, encoding="utf-8")
    numbers = []
    for frame in frames:
        if b'"n":' not in frame and b'"n": ' not in frame:
            continue
        try:
            numbers.append(int(json.loads(frame.decode("utf-8"))["n"]))
        except (UnicodeError, json.JSONDecodeError, KeyError, TypeError, ValueError):
            continue
    return bool(b"panel" in other_body and other_wait < 0.5 and len(numbers) >= 1)


def _open_socket(port: int, path: str) -> socket.socket:
    client = socket.create_connection(("127.0.0.1", port), timeout=3)
    key = "dGhlIHNhbXBsZSBub25jZQ=="
    client.sendall(
        (
            f"GET {path} HTTP/1.1\r\n"
            "Host: panel.local:8443\r\n"
            "Upgrade: websocket\r\n"
            "Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\n"
            "Sec-WebSocket-Version: 13\r\n\r\n"
        ).encode("ascii")
    )
    client.settimeout(2.0)
    head = b""
    while b"\r\n\r\n" not in head:
        part = client.recv(4096)
        if not part:
            break
        head += part
    if b"101" not in head.split(b"\r\n", 1)[0]:
        raise RuntimeError(head[:200])
    return client


def drive_on_quiet_socket(proxy) -> bool:
    """Pictures stay on one connection. The direction goes out on another."""
    pictures_upstream = ThreadingHTTPServer(("127.0.0.1", 0), _Pictures)
    buttons = ThreadingHTTPServer(("127.0.0.1", 0), _Drive)
    threading.Thread(target=pictures_upstream.serve_forever, daemon=True).start()
    threading.Thread(target=buttons.serve_forever, daemon=True).start()
    status = ROOT / "data" / "camera-live-status.json"
    status.parent.mkdir(exist_ok=True)
    previous = status.read_text(encoding="utf-8") if status.is_file() else None
    status.write_text(json.dumps({"port": pictures_upstream.server_address[1], "state": "live"}), encoding="utf-8")
    previous_upstream = proxy.PanelHttpsProxyHandler.upstream_port
    proxy.PanelHttpsProxyHandler.upstream_port = buttons.server_address[1]
    _Drive.seen = b""
    _Drive.started = 0.0
    _Drive.finished = 0.0
    panel = ThreadingHTTPServer(("127.0.0.1", 0), proxy.PanelHttpsProxyHandler)
    threading.Thread(target=panel.serve_forever, daemon=True).start()
    pictures = None
    controls = None
    saw_picture = False
    try:
        pictures = _open_socket(panel.server_address[1], "/api/camera_live.php?action=frames")
        controls = _open_socket(panel.server_address[1], "/api/camera_live.php?action=controls")
        pictures.settimeout(0.5)
        deadline = time.time() + 1.5
        blob = b""
        while time.time() < deadline and not saw_picture:
            try:
                part = pictures.recv(4096)
            except socket.timeout:
                continue
            if not part:
                break
            blob += part
            saw_picture = b"middle" in blob
        controls.sendall(_client_frame(b'{"op":"drive","id":9,"json":{"linear":0.2,"angular":0,"enter_manual":true}}'))
        reply = b""
        controls.settimeout(2.0)
        deadline = time.time() + 2.0
        while time.time() < deadline and b'"reply"' not in reply:
            try:
                part = controls.recv(4096)
            except socket.timeout:
                break
            if not part:
                break
            reply += part
        later = b""
        pictures.settimeout(0.6)
        try:
            later = pictures.recv(65536)
        except socket.timeout:
            later = b""
    finally:
        if pictures is not None:
            pictures.close()
        if controls is not None:
            controls.close()
        panel.shutdown()
        pictures_upstream.shutdown()
        buttons.shutdown()
        proxy.PanelHttpsProxyHandler.upstream_port = previous_upstream
        if previous is None:
            status.unlink(missing_ok=True)
        else:
            status.write_text(previous, encoding="utf-8")
    drove = b"linear" in _Drive.seen and b"0.2" in _Drive.seen
    answered = b"picture" in reply and b"true" in reply and (b'"id": 9' in reply or b'"id":9' in reply)
    return bool(saw_picture and drove and answered and b"middle" in later)


if __name__ == "__main__":
    main()
