#!/usr/bin/env python3
"""HTTPS reverse proxy in front of the Yarbo PHP panel (plain HTTP upstream).

Browsers need a secure context for microphone / Announce Record. Paper tablets
keep using HTTP :8080 directly.
"""

from __future__ import annotations

import argparse
import http.client
import json
import os
import socket
import ssl
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parent.parent


def is_camera_frames(path: str) -> bool:
    """True for the live-picture stream. That request must not sit inside PHP."""
    raw = path or "/"
    base, _, query = raw.partition("?")
    return base.rstrip("/").endswith("camera_live.php") and "action=frames" in query


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
        if self.command == "GET" and is_camera_frames(path):
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
        """Stream pictures from the camera agent. False if it is not listening."""
        port = camera_agent_port()
        conn = None
        try:
            conn = http.client.HTTPConnection("127.0.0.1", port, timeout=20)
            conn.request(
                "GET",
                "/frames",
                headers={"Host": f"127.0.0.1:{port}", "Connection": "close"},
            )
            resp = conn.getresponse()
        except Exception:
            if conn is not None:
                try:
                    conn.close()
                except Exception:
                    pass
            return False

        # A 20s read limit was closing the pictures, and HTTP/1.1 let Safari
        # reuse this endless response for the next drive press.
        try:
            if conn.sock is not None:
                conn.sock.settimeout(None)
        except Exception:
            pass
        self.close_connection = True
        self.protocol_version = "HTTP/1.0"
        self.send_response(resp.status, resp.reason)
        for key, value in resp.getheaders():
            lk = key.lower()
            if lk in HOP_BY_HOP or lk == "content-length":
                continue
            self.send_header(key, value)
        self.send_header("Cache-Control", "no-cache, no-store")
        self.send_header("X-Accel-Buffering", "no")
        self.send_header("Connection", "close")
        self.end_headers()
        try:
            relay_upstream(resp, self.wfile)
        except Exception:
            pass
        finally:
            try:
                conn.close()
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
    print(
        f"panel_https_proxy: https://{args.listen_host}:{args.listen_port} → "
        f"http://{args.upstream_host}:{args.upstream_port}",
        flush=True,
    )
    try:
        httpd.serve_forever(poll_interval=0.5)
    except KeyboardInterrupt:
        pass
    finally:
        httpd.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
