#!/usr/bin/env python3
"""On/Off must not wait behind a slow start_listening dump; Apple Home events must apply."""

from __future__ import annotations

import base64
import copy
import hashlib
import json
import os
import socket
import struct
import subprocess
import sys
import threading
import time
import tempfile
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AGENT_PORT = 18768
SERVER_PORT = 15581
WS_GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
DUMP_DELAY = 6.0
SAMPLE_NODES = [
    {
        "node_id": 1,
        "available": True,
        "is_bridge": True,
        "attributes": {
            "0/40/1": "Signify Netherlands B.V.",
            "0/40/3": "Hue Bridge",
            "0/40/5": "Hue Bridge",
            "1/29/0": [{"0": 0x000E, "1": 1}],
            "2/29/0": [{"deviceType": 0x0013, "revision": 1}],
            "2/6/0": False,
            "2/8/0": 80,
            "2/57/5": "Lamp",
        },
    }
]


class FakeMatterServer:
    def __init__(self) -> None:
        self.commands: list[dict] = []
        self.conn: socket.socket | None = None
        self.listen_conn: socket.socket | None = None
        self.cmd_conn: socket.socket | None = None
        self.poll_conn: socket.socket | None = None
        self.light_on = False
        self._conns: list[socket.socket] = []
        self._sock = socket.socket()
        self._sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self._sock.bind(("127.0.0.1", SERVER_PORT))
        self._sock.listen(8)
        self._sock.settimeout(0.5)
        self._stop = threading.Event()
        self._send_lock = threading.Lock()
        self._thread = threading.Thread(target=self._run, daemon=True)

    def current_nodes(self) -> list[dict]:
        nodes = copy.deepcopy(SAMPLE_NODES)
        nodes[0]["attributes"]["2/6/0"] = self.light_on
        return nodes

    def start(self) -> None:
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()
        try:
            self._sock.close()
        except OSError:
            pass
        for conn in list(self._conns):
            try:
                conn.close()
            except OSError:
                pass

    def _remember(self, conn: socket.socket) -> None:
        if conn not in self._conns:
            self._conns.append(conn)
        self.conn = conn

    def _forget(self, conn: socket.socket) -> None:
        if conn in self._conns:
            self._conns.remove(conn)
        if self.conn is conn:
            self.conn = self._conns[-1] if self._conns else None

    def emit_updated(self, node_id: int, path: str, value: object) -> None:
        deadline = time.time() + 3.0
        last_err: Exception | None = None
        while time.time() < deadline:
            conn = self.listen_conn or self.conn
            if conn is not None:
                try:
                    self._send(conn, {"event": "attribute_updated", "data": [node_id, path, value]})
                    return
                except OSError as exc:
                    last_err = exc
            time.sleep(0.05)
        raise AssertionError(f"websocket not connected ({last_err})")

    def _run(self) -> None:
        while not self._stop.is_set():
            try:
                conn, _ = self._sock.accept()
            except (TimeoutError, socket.timeout, OSError):
                continue
            threading.Thread(target=self._handle, args=(conn,), daemon=True).start()

    def _handle(self, conn: socket.socket) -> None:
        conn.settimeout(30)
        self.conn = conn
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
            self._remember(conn)
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
                command = str(msg.get("command") or "")
                if command == "start_listening":
                    self.listen_conn = conn
                    threading.Thread(
                        target=self._reply_dump,
                        args=(conn, msg),
                        daemon=True,
                    ).start()
                    continue
                if command == "device_command":
                    self.cmd_conn = conn
                    name = str((msg.get("args") or {}).get("command_name") or "")
                    if name == "On":
                        self.light_on = True
                    elif name == "Off":
                        self.light_on = False
                if command == "read_attribute":
                    self.poll_conn = conn
                    paths = list((msg.get("args") or {}).get("attribute_path") or [])
                    result = {}
                    for path in paths:
                        path_s = str(path)
                        if path_s.endswith("/6/0"):
                            result[path_s] = self.light_on
                        elif path_s.endswith("/8/0"):
                            result[path_s] = 80
                    self._send(conn, {"message_id": msg.get("message_id"), "result": result})
                    continue
                if command == "get_nodes":
                    self.poll_conn = self.poll_conn or conn
                    self._send(conn, {"message_id": msg.get("message_id"), "result": self.current_nodes()})
                    continue
                self._send(conn, {"message_id": msg.get("message_id"), "result": None})
        except (TimeoutError, socket.timeout, OSError, struct.error):
            return
        finally:
            self._forget(conn)
            try:
                conn.close()
            except OSError:
                pass

    def _reply_dump(self, conn: socket.socket, msg: dict) -> None:
        time.sleep(DUMP_DELAY)
        if self._stop.is_set():
            return
        try:
            self._send(conn, {"message_id": msg.get("message_id"), "result": self.current_nodes()})
        except OSError:
            return

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
        with self._send_lock:
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


def wait_device(device_id: str, timeout: float = 2.0) -> dict:
    deadline = time.time() + timeout
    last: list = []
    while time.time() < deadline:
        last = post({"op": "states"}).get("devices") or []
        row = next((d for d in last if str(d.get("id") or "") == device_id), None)
        if row is not None:
            return row
        time.sleep(0.05)
    raise AssertionError(f"device {device_id} missing from states: {last}")


def main() -> int:
    fake = FakeMatterServer()
    fake.start()
    env = os.environ.copy()
    env["YARBO_MATTER_AGENT_PORT"] = str(AGENT_PORT)
    env["YARBO_MATTER_SERVER_PORT"] = str(SERVER_PORT)
    log_file = tempfile.NamedTemporaryFile("w+", delete=False)
    proc = subprocess.Popen(
        [sys.executable, str(ROOT / "scripts" / "matter_agent.py")],
        cwd=str(ROOT),
        env=env,
        stdout=log_file,
        stderr=subprocess.STDOUT,
    )
    try:
        ping = wait_ping()
        assert ping.get("version") == 16, ping
        time.sleep(0.4)
        started = time.time()
        result = post({"op": "command", "id": "1:2", "action": "on"}, timeout=3.0)
        elapsed = time.time() - started
        assert result.get("ok") is True, result
        assert result.get("on") is True, result
        assert elapsed < 2.5, f"On/Off waited on start_listening dump ({elapsed:.2f}s)"
        names = [c.get("args", {}).get("command_name") for c in fake.commands if c.get("command") == "device_command"]
        assert "On" in names, fake.commands
        listen_n = len([c for c in fake.commands if c.get("command") == "start_listening"])
        assert listen_n >= 1, fake.commands
        assert fake.cmd_conn is not None and fake.listen_conn is not None, "need cmd and listen sockets"
        assert fake.cmd_conn is not fake.listen_conn, "On/Off must not share the listen websocket"

        row = wait_device("1:2")
        assert row.get("on") is True, row
        # Sticky hold is 4s and the listen dump is 6s. After both, poll must
        # still show On (website → light), then Apple Home Off via OnOff poll.
        time.sleep(7.0)
        row = wait_device("1:2")
        assert row.get("on") is True, f"poll/dump snapped the tile off after website On {row}"
        fake.light_on = False
        deadline = time.time() + 10.0
        saw_off = False
        while time.time() < deadline:
            row = wait_device("1:2", timeout=0.4)
            if row.get("on") is False:
                saw_off = True
                break
            time.sleep(0.2)
        assert saw_off, f"Apple Home Off via OnOff poll did not apply: {post({'op': 'states'})}"
        onoff_paths = []
        for cmd in fake.commands:
            if cmd.get("command") != "read_attribute":
                continue
            onoff_paths.extend(str(p) for p in (cmd.get("args") or {}).get("attribute_path") or [])
        assert any(path.endswith("/6/0") for path in onoff_paths), fake.commands
        print("ok: website On used cmd socket; Apple Home Off reached the tile via poll")
        return 0
    except Exception:
        try:
            log_file.flush()
            sys.stderr.write(Path(log_file.name).read_text()[-4000:])
        except OSError:
            pass
        raise
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=2)
        except subprocess.TimeoutExpired:
            proc.kill()
        fake.stop()
        log_file.close()
        try:
            os.unlink(log_file.name)
        except OSError:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
