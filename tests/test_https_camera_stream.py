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
    assert proxy.read_http_head(_FakeSock(b"HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n\r\ndata: hi\n\n"))[2] == b"data: hi\n\n"

    sink = Sink()
    worker = threading.Thread(target=proxy.relay_upstream, args=(SlowBody(), sink), daemon=True)
    worker.start()
    deadline = time.time() + 0.8
    while time.time() < deadline and sink.buf.getvalue() != b"data: hi\n\n":
        time.sleep(0.02)
    assert sink.buf.getvalue() == b"data: hi\n\n", "first picture packet must arrive before the stream ends"
    assert stream_reaches_client(proxy), "a small picture must reach the browser before 16KB has piled up"
    print("ok")


class _FakeSock:
    def __init__(self, payload: bytes) -> None:
        self.payload = payload

    def recv(self, _size: int) -> bytes:
        data = self.payload
        self.payload = b""
        return data


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
        self.wfile.write(b": " + (b" " * 2048) + b"\n\ndata: {\"view\":\"middle\"}\n\n")
        self.wfile.flush()
        time.sleep(2)


def stream_reaches_client(proxy) -> bool:
    agent = ThreadingHTTPServer(("127.0.0.1", 0), _Agent)
    port = agent.server_address[1]
    threading.Thread(target=agent.serve_forever, daemon=True).start()
    status = ROOT / "data" / "camera-live-status.json"
    status.parent.mkdir(exist_ok=True)
    previous = status.read_text(encoding="utf-8") if status.is_file() else None
    status.write_text(json.dumps({"port": port, "state": "live"}), encoding="utf-8")
    httpd = ThreadingHTTPServer(("127.0.0.1", 0), proxy.PanelHttpsProxyHandler)
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    try:
        client = socket.create_connection(("127.0.0.1", httpd.server_address[1]), timeout=3)
        client.sendall(b"GET /api/camera_live.php?action=frames HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
        client.settimeout(1.0)
        buf = b""
        deadline = time.time() + 1.0
        while time.time() < deadline and b"middle" not in buf:
            try:
                part = client.recv(4096)
            except socket.timeout:
                break
            if not part:
                break
            buf += part
        client.close()
    finally:
        httpd.shutdown()
        agent.shutdown()
        if previous is None:
            status.unlink(missing_ok=True)
        else:
            status.write_text(previous, encoding="utf-8")
    if b"Transfer-Encoding" in buf:
        return False
    return b"middle" in buf and b"text/event-stream" in buf


if __name__ == "__main__":
    main()
