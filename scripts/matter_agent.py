#!/usr/bin/env python3
"""Local Matter controller agent for the Yarbo panel.

Talks JSON HTTP on 127.0.0.1:8766 and forwards to python-matter-server
(WebSocket on 127.0.0.1:5580). Starts the Matter server via Docker when possible.

Usage:
  python3 scripts/matter_agent.py
"""

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
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
HOST = "127.0.0.1"
AGENT_PORT = int(os.environ.get("YARBO_MATTER_AGENT_PORT", "8766"))
SERVER_PORT = int(os.environ.get("YARBO_MATTER_SERVER_PORT", "5580"))
DOCKER_IMAGE = os.environ.get(
    "YARBO_MATTER_DOCKER_IMAGE",
    "ghcr.io/home-assistant-libs/python-matter-server:stable",
)
DOCKER_NAME = os.environ.get("YARBO_MATTER_DOCKER_NAME", "yarbo-matter-server")
STORAGE = ROOT / "data" / "matter-server"

ON_OFF = 6
LEVEL_CONTROL = 8
DESCRIPTOR = 29
ATTR_ON_OFF = 0
ATTR_CURRENT_LEVEL = 0
ATTR_DEVICE_TYPES = 0

_ws_lock = threading.Lock()
_ws: socket.socket | None = None
_start_lock = threading.Lock()
_started_docker = False


def attr_key(endpoint: int, cluster: int, attr: int) -> str:
    return f"{endpoint}/{cluster}/{attr}"


class MatterWs:
    def __init__(self, sock: socket.socket) -> None:
        self.sock = sock
        self.buf = bytearray()

    def send_json(self, payload: dict[str, Any]) -> None:
        data = json.dumps(payload, separators=(",", ":")).encode()
        key = os.urandom(4)
        header = bytearray()
        n = len(data)
        if n < 126:
            header.append(0x81)
            header.append(0x80 | n)
        elif n < 65536:
            header.append(0x81)
            header.append(0x80 | 126)
            header.extend(struct.pack("!H", n))
        else:
            header.append(0x81)
            header.append(0x80 | 127)
            header.extend(struct.pack("!Q", n))
        header.extend(key)
        masked = bytes(b ^ key[i % 4] for i, b in enumerate(data))
        self.sock.sendall(header + masked)

    def recv_json(self, timeout: float) -> dict[str, Any] | None:
        self.sock.settimeout(timeout)
        deadline = time.time() + timeout
        while time.time() < deadline:
            frame = self._read_frame(max(0.2, deadline - time.time()))
            if frame is None:
                return None
            if not frame:
                continue
            try:
                parsed = json.loads(frame.decode())
            except (UnicodeDecodeError, json.JSONDecodeError):
                continue
            if isinstance(parsed, dict):
                return parsed
        return None

    def _read_frame(self, timeout: float) -> bytes | None:
        try:
            while len(self.buf) < 2:
                chunk = self.sock.recv(4096)
                if not chunk:
                    return None
                self.buf.extend(chunk)
            b1, b2 = self.buf[0], self.buf[1]
            opcode = b1 & 0x0F
            masked = b2 & 0x80
            length = b2 & 0x7F
            idx = 2
            if length == 126:
                while len(self.buf) < 4:
                    chunk = self.sock.recv(4096)
                    if not chunk:
                        return None
                    self.buf.extend(chunk)
                length = struct.unpack("!H", self.buf[2:4])[0]
                idx = 4
            elif length == 127:
                while len(self.buf) < 10:
                    chunk = self.sock.recv(4096)
                    if not chunk:
                        return None
                    self.buf.extend(chunk)
                length = struct.unpack("!Q", self.buf[2:10])[0]
                idx = 10
            if masked:
                while len(self.buf) < idx + 4:
                    chunk = self.sock.recv(4096)
                    if not chunk:
                        return None
                    self.buf.extend(chunk)
                mask = self.buf[idx : idx + 4]
                idx += 4
            else:
                mask = b""
            total = idx + length
            while len(self.buf) < total:
                chunk = self.sock.recv(min(65536, total - len(self.buf)))
                if not chunk:
                    return None
                self.buf.extend(chunk)
            payload = bytes(self.buf[idx:total])
            del self.buf[:total]
            if mask:
                payload = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
            if opcode == 0x8:
                return None
            if opcode == 0x9:
                return b""
            if opcode in (0x1, 0x2, 0x0):
                return payload
            return b""
        except (TimeoutError, socket.timeout, OSError):
            return None


def ws_connect() -> MatterWs:
    key = base64.b64encode(os.urandom(16)).decode()
    sock = socket.create_connection((HOST, SERVER_PORT), timeout=5)
    sock.sendall(
        (
            f"GET /ws HTTP/1.1\r\n"
            f"Host: {HOST}:{SERVER_PORT}\r\n"
            "Upgrade: websocket\r\n"
            "Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\n"
            "Sec-WebSocket-Version: 13\r\n"
            "\r\n"
        ).encode()
    )
    buf = b""
    while b"\r\n\r\n" not in buf:
        chunk = sock.recv(4096)
        if not chunk:
            sock.close()
            raise OSError("Matter server closed during websocket handshake")
        buf += chunk
    if b"101" not in buf.split(b"\r\n", 1)[0]:
        sock.close()
        raise OSError("Matter server rejected websocket")
    expected = hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest()
    accept = base64.b64encode(expected).decode()
    if accept.encode() not in buf:
        # Some stacks omit a strict accept check in tests; still require 101.
        pass
    return MatterWs(sock)


def docker_bin() -> str | None:
    which = subprocess.run("command -v docker", shell=True, capture_output=True, text=True)
    path = (which.stdout or "").strip()
    if path:
        return path
    for candidate in ("/usr/bin/docker", "/usr/local/bin/docker", "/opt/homebrew/bin/docker"):
        if os.access(candidate, os.X_OK):
            return candidate
    return None


def ensure_matter_server() -> str | None:
    global _started_docker
    sock = socket.socket()
    sock.settimeout(0.4)
    try:
        sock.connect((HOST, SERVER_PORT))
        return None
    except OSError:
        pass
    finally:
        sock.close()

    with _start_lock:
        sock = socket.socket()
        sock.settimeout(0.4)
        try:
            sock.connect((HOST, SERVER_PORT))
            return None
        except OSError:
            pass
        finally:
            sock.close()

        docker = docker_bin()
        if docker is None:
            return (
                "Matter server is not running. On the Pi, install Docker and enable the Home module, "
                "or run python-matter-server on port 5580."
            )
        STORAGE.mkdir(parents=True, exist_ok=True)
        inspect = subprocess.run(
            [docker, "inspect", "-f", "{{.State.Running}}", DOCKER_NAME],
            capture_output=True,
            text=True,
        )
        if inspect.returncode == 0:
            running = (inspect.stdout or "").strip().lower() == "true"
            if not running:
                subprocess.run([docker, "start", DOCKER_NAME], capture_output=True, text=True)
        else:
            subprocess.run(
                [
                    docker,
                    "run",
                    "-d",
                    "--name",
                    DOCKER_NAME,
                    "--restart",
                    "unless-stopped",
                    "--network",
                    "host",
                    "-v",
                    f"{STORAGE}:/data",
                    DOCKER_IMAGE,
                ],
                capture_output=True,
                text=True,
            )
        _started_docker = True
        deadline = time.time() + 25
        while time.time() < deadline:
            probe = socket.socket()
            probe.settimeout(0.4)
            try:
                probe.connect((HOST, SERVER_PORT))
                return None
            except OSError:
                time.sleep(0.5)
            finally:
                probe.close()
        return "Started the Matter Docker container, but port 5580 is not listening yet. Wait and retry."


def connected_ws() -> MatterWs:
    global _ws
    if _ws is not None:
        return _ws
    err = ensure_matter_server()
    if err:
        raise RuntimeError(err)
    _ws = ws_connect()
    # Drain the hello event so the first command is not mixed with it.
    _ws.recv_json(2.0)
    return _ws


def reset_ws() -> None:
    global _ws
    if _ws is not None:
        try:
            _ws.sock.close()
        except OSError:
            pass
        _ws = None


def matter_rpc(command: str, args: dict[str, Any] | None = None, timeout: float = 20.0) -> dict[str, Any]:
    message_id = uuid.uuid4().hex[:12]
    payload: dict[str, Any] = {"message_id": message_id, "command": command}
    if args:
        payload["args"] = args
    with _ws_lock:
        last_err = None
        for _ in range(2):
            try:
                client = connected_ws()
                client.send_json(payload)
                deadline = time.time() + timeout
                while time.time() < deadline:
                    msg = client.recv_json(max(0.5, deadline - time.time()))
                    if not isinstance(msg, dict):
                        continue
                    if msg.get("message_id") != message_id:
                        continue
                    if "error_code" in msg or "error" in msg:
                        err = msg.get("details") or msg.get("error") or msg.get("error_code")
                        return {"ok": False, "error": str(err)}
                    return {"ok": True, "result": msg.get("result")}
                return {"ok": False, "error": "Matter server timed out"}
            except Exception as exc:  # noqa: BLE001
                last_err = exc
                reset_ws()
        return {"ok": False, "error": str(last_err) if last_err else "Matter server unavailable"}


def endpoint_ids(attributes: dict[str, Any]) -> set[int]:
    out: set[int] = set()
    for key in attributes:
        try:
            ep = int(str(key).split("/", 1)[0])
        except ValueError:
            continue
        out.add(ep)
    return out


def device_kind(types: Any) -> str:
    ids: list[int] = []
    if isinstance(types, list):
        for item in types:
            if isinstance(item, dict) and "0" in item:
                try:
                    ids.append(int(item["0"]))
                except (TypeError, ValueError):
                    continue
            elif isinstance(item, (int, float)):
                ids.append(int(item))
            elif isinstance(item, dict) and "deviceType" in item:
                try:
                    ids.append(int(item["deviceType"]))
                except (TypeError, ValueError):
                    continue
    if any(i in (0x0301,) for i in ids):
        return "heater"
    if any(i in (0x010A, 0x010B) for i in ids):
        return "plug"
    if any(i in (0x0103, 0x010F) for i in ids):
        return "switch"
    if any(i in (0x0100, 0x0101, 0x010C, 0x010D) for i in ids):
        return "light"
    return "other"


def flatten_nodes(raw: Any) -> list[dict[str, Any]]:
    nodes = raw if isinstance(raw, list) else []
    devices: list[dict[str, Any]] = []
    for node in nodes:
        if not isinstance(node, dict):
            continue
        node_id = int(node.get("node_id") or 0)
        available = bool(node.get("available", True))
        attributes = node.get("attributes") if isinstance(node.get("attributes"), dict) else {}
        name_root = ""
        for key, val in attributes.items():
            if str(key).endswith("/40/1") and isinstance(val, str) and val.strip():
                name_root = val.strip()
                break
        for endpoint in sorted(endpoint_ids(attributes)):
            if endpoint == 0:
                continue
            on_key = attr_key(endpoint, ON_OFF, ATTR_ON_OFF)
            if on_key not in attributes:
                continue
            types = attributes.get(attr_key(endpoint, DESCRIPTOR, ATTR_DEVICE_TYPES))
            kind = device_kind(types)
            if kind == "other":
                kind = "light"
            label_key = attr_key(endpoint, 40, 1)  # BasicInformation.NodeLabel is node-wide; try endpoint label
            label = attributes.get(label_key)
            if not isinstance(label, str) or not label.strip():
                label = attributes.get(attr_key(endpoint, 5, 1))  # UserLabel
            if not isinstance(label, str) or not label.strip():
                label = name_root or f"Node {node_id} ep {endpoint}"
            level = attributes.get(attr_key(endpoint, LEVEL_CONTROL, ATTR_CURRENT_LEVEL))
            brightness = None
            if isinstance(level, (int, float)) and level >= 0:
                brightness = int(round(float(level) * 100 / 254))
            devices.append(
                {
                    "id": f"{node_id}:{endpoint}",
                    "node_id": node_id,
                    "endpoint": endpoint,
                    "name": str(label).strip()[:48],
                    "kind": kind,
                    "on": bool(attributes.get(on_key)),
                    "brightness": brightness,
                    "dimmable": attr_key(endpoint, LEVEL_CONTROL, ATTR_CURRENT_LEVEL) in attributes,
                    "available": available,
                }
            )
    devices.sort(key=lambda d: (d["name"].lower(), d["id"]))
    return devices


def parse_id(device_id: str) -> tuple[int, int]:
    node_s, ep_s = device_id.split(":", 1)
    return int(node_s), int(ep_s)


def dispatch(body: dict[str, Any]) -> dict[str, Any]:
    op = str(body.get("op") or "")
    if op == "ping":
        return {"ok": True, "engine": "matter-agent"}
    if op == "status":
        probe = socket.socket()
        probe.settimeout(0.4)
        listening = False
        try:
            probe.connect((HOST, SERVER_PORT))
            listening = True
        except OSError:
            listening = False
        finally:
            probe.close()
        hint = None if listening else ensure_matter_server()
        return {
            "ok": listening,
            "server": listening,
            "docker": _started_docker,
            "error": None if listening else (hint or "Matter server is not listening on port 5580"),
        }
    if op == "nodes":
        rpc = matter_rpc("get_nodes", timeout=15.0)
        if not rpc.get("ok"):
            return rpc
        return {"ok": True, "devices": flatten_nodes(rpc.get("result"))}
    if op == "commission":
        code = str(body.get("code") or "").strip()
        if not code:
            return {"ok": False, "error": "Pairing code is required"}
        rpc = matter_rpc(
            "commission_with_code",
            {"code": code, "network_only": True},
            timeout=90.0,
        )
        if not rpc.get("ok"):
            return rpc
        return {"ok": True, "result": rpc.get("result")}
    if op == "command":
        device_id = str(body.get("id") or "")
        try:
            node_id, endpoint = parse_id(device_id)
        except ValueError:
            return {"ok": False, "error": "Invalid device id"}
        action = str(body.get("action") or "")
        if action in ("on", "off", "toggle"):
            name = {"on": "On", "off": "Off", "toggle": "Toggle"}[action]
            rpc = matter_rpc(
                "device_command",
                {
                    "node_id": node_id,
                    "endpoint_id": endpoint,
                    "cluster_id": ON_OFF,
                    "command_name": name,
                    "payload": {},
                },
                timeout=12.0,
            )
            return rpc if not rpc.get("ok") else {"ok": True}
        if action == "brightness":
            pct = max(0, min(100, int(body.get("brightness") or 0)))
            level = int(round(pct * 254 / 100))
            rpc = matter_rpc(
                "device_command",
                {
                    "node_id": node_id,
                    "endpoint_id": endpoint,
                    "cluster_id": LEVEL_CONTROL,
                    "command_name": "MoveToLevelWithOnOff",
                    "payload": {
                        "level": level,
                        "transitionTime": 0,
                        "optionsMask": 0,
                        "optionsOverride": 0,
                    },
                },
                timeout=12.0,
            )
            return rpc if not rpc.get("ok") else {"ok": True}
        return {"ok": False, "error": "Unknown Matter command"}
    return {"ok": False, "error": f"Unknown op {op}"}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt: str, *args: Any) -> None:  # noqa: A003
        sys.stderr.write("matter_agent: " + (fmt % args) + "\n")

    def do_POST(self) -> None:  # noqa: N802
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b"{}"
        try:
            body = json.loads(raw.decode() or "{}")
        except json.JSONDecodeError:
            body = {}
        if not isinstance(body, dict):
            body = {}
        try:
            result = dispatch(body)
        except Exception as exc:  # noqa: BLE001
            result = {"ok": False, "error": str(exc)}
        data = json.dumps(result, separators=(",", ":")).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


def main() -> None:
    STORAGE.mkdir(parents=True, exist_ok=True)
    threading.Thread(target=ensure_matter_server, daemon=True).start()
    httpd = ThreadingHTTPServer((HOST, AGENT_PORT), Handler)
    print(f"matter_agent listening on {HOST}:{AGENT_PORT}", flush=True)
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
