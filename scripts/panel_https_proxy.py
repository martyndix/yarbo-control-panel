#!/usr/bin/env python3
"""HTTPS reverse proxy in front of the Yarbo PHP panel (plain HTTP upstream).

Browsers need a secure context for microphone / Announce Record. Paper tablets
keep using HTTP :8080 directly.
"""

from __future__ import annotations

import argparse
import http.client
import os
import socket
import ssl
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit


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

        try:
            conn = http.client.HTTPConnection(self.upstream_host, self.upstream_port, timeout=120)
            conn.request(self.command, path, body=body, headers=headers)
            resp = conn.getresponse()
            resp_body = resp.read()
        except Exception as exc:
            self.send_error(502, f"Upstream error: {exc}")
            return

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
        try:
            conn.close()
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
