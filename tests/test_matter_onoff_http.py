#!/usr/bin/env python3
"""On/Off must not wait behind a slow get_nodes poll on the Matter websocket."""

from __future__ import annotations

import base64
import hashlib
import json
import os
import socket
import struct
import subprocess
import sys
import threading
import time
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AGENT_PORT = 18768
SERVER_PORT = 15581
WS_GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"


class FakeMatterServer:
    def __init__(self) -> None:
        self.commands: list[dict] = []
        self._sock = socket.socket()
        self._sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self._sock.bind(("127.0.0.1", SERVER_PORT))
        self._sock.listen(4)
        self._sock.settimeout(0.5)
        self._stop = threading.Event()
        self._thread = threading.Thread(target=self._run, daemon=True)

    def start(self) -> None:
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()
        try:
            self._sock.close()
        except OSError:
            pass

    def _run(self) -> None:
        while not self._stop.is_set():
            try:
                conn, _ = self._sock.accept()
            except (TimeoutError, socket.timeout, OSError):
                continue
            threading.Thread(target=self._handle, args=(conn,), daemon=True).start()

    def _handle(self, conn: socket.socket) -> None:
        conn.settimeout(12)
        try:
            buf = b""
            while b"\r\n\r\n" not in buf:
                chunk = conn.recv(4096)
                if not chunk:
                    return
                buf += chunk
            key = ""
            for line in buf.decode(errors="ignore").split("\r\n"):
                if line.lower().startswith("sec-websocket-key:"):
                    key = line.split(":", 1)[1].strip()
            accept = base64.b64encode(hashlib.sha1((key + WS_GUID).encode()).digest()).decode()
            conn.sendall(
                (
                    "HTTP/1.1 101 Switching Protocols\r\n"
                    "Upgrade: websocket\r\n"
                    "Connection: Upgrade\r\n"
                    f"Sec-WebSocket-Accept: {accept}\r\n"
                    "\r\n"
                ).encode()
            )
            self._send(conn, {"event": "server_info", "data": {}})
            leftover = buf.split(b"\r\n\r\n", 1)[1]
            data = bytearray(leftover)
            while not self._stop.is_set():
                msg = self._read_json(conn, data)
                if msg is None:
                    return
                if not isinstance(msg, dict):
                    continue
                self.commands.append(msg)
                if msg.get("command") == "get_nodes":
                    time.sleep(6)
                    self._send(conn, {"message_id": msg.get("message_id"), "result": []})
                    continue
                self._send(conn, {"message_id": msg.get("message_id"), "result": None})
        except (TimeoutError, socket.timeout, OSError, struct.error):
            return
        finally:
            try:
                conn.close()
            except OSError:
                pass

    def _send(self, conn: socket.socket, payload: dict) -> None:
        raw = json.dumps(payload).encode()
        header = bytearray([0x81])
        n = len(raw)
        if n < 126:
            header.append(n)
        elif n < 65536:
            header.append(126)
            header.extend(struct.pack("!H", n))
        else:
            header.append(127)
            header.extend(struct.pack("!Q", n))
        conn.sendall(header + raw)

    def _read_json(self, conn: socket.socket, buf: bytearray) -> dict | None:
        while True:
            while len(buf) < 2:
                chunk = conn.recv(4096)
                if not chunk:
                    return None
                buf.extend(chunk)
            b1, b2 = buf[0], buf[1]
            masked = bool(b2 & 0x80)
            length = b2 & 0x7F
            idx = 2
            if length == 126:
                while len(buf) < 4:
                    chunk = conn.recv(4096)
                    if not chunk:
                        return None
                    buf.extend(chunk)
                length = struct.unpack("!H", buf[2:4])[0]
                idx = 4
            elif length == 127:
                while len(buf) < 10:
                    chunk = conn.recv(4096)
                    if not chunk:
                        return None
                    buf.extend(chunk)
                length = struct.unpack("!Q", buf[2:10])[0]
                idx = 10
            need = idx + (4 if masked else 0) + length
            while len(buf) < need:
                chunk = conn.recv(4096)
                if not chunk:
                    return None
                buf.extend(chunk)
            mask = bytes(buf[idx : idx + 4]) if masked else b""
            start = idx + (4 if masked else 0)
            payload = bytes(buf[start:need])
            del buf[:need]
            if mask:
                payload = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
            opcode = b1 & 0x0F
            if opcode in (0x1, 0x2, 0x0) and payload:
                try:
                    parsed = json.loads(payload.decode())
                except (UnicodeDecodeError, json.JSONDecodeError):
                    continue
                if isinstance(parsed, dict):
                    return parsed
            if opcode == 0x8:
                return None


def post(body: dict, timeout: float = 8.0) -> dict:
    req = urllib.request.Request(
        f"http://127.0.0.1:{AGENT_PORT}/",
        data=json.dumps(body).encode(),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode())


def wait_ping(timeout: float = 5.0) -> dict:
    deadline = time.time() + timeout
    last = {}
    while time.time() < deadline:
        try:
            last = post({"op": "ping"})
            if last.get("ok"):
                return last
        except OSError:
            time.sleep(0.1)
    raise AssertionError(f"agent did not start: {last}")


def main() -> int:
    fake = FakeMatterServer()
    fake.start()
    env = os.environ.copy()
    env["YARBO_MATTER_AGENT_PORT"] = str(AGENT_PORT)
    env["YARBO_MATTER_SERVER_PORT"] = str(SERVER_PORT)
    proc = subprocess.Popen(
        [sys.executable, str(ROOT / "scripts" / "matter_agent.py")],
        cwd=str(ROOT),
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
    )
    try:
        wait_ping()
        time.sleep(0.4)
        started = time.time()
        result = post({"op": "command", "id": "1:2", "action": "on"}, timeout=3.0)
        elapsed = time.time() - started
        assert result.get("ok") is True, result
        assert result.get("on") is True, result
        assert elapsed < 2.5, f"On/Off waited on get_nodes ({elapsed:.2f}s)"
        names = [c.get("args", {}).get("command_name") for c in fake.commands if c.get("command") == "device_command"]
        assert "On" in names, fake.commands
        print("ok: On/Off did not wait for get_nodes")
        return 0
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=2)
        except subprocess.TimeoutExpired:
            proc.kill()
        fake.stop()


if __name__ == "__main__":
    raise SystemExit(main())
