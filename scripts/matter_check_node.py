#!/usr/bin/env python3
"""Dump Matter/Hue discovery and interview node 13. Does not pair or rename."""

from __future__ import annotations

import json
import os
import re
import socket
import struct
import subprocess
import time
import uuid
from pathlib import Path

HOST, PORT, NODE = "127.0.0.1", 5580, 13
FABRIC = 1415963636765591517
MDNS_NAME = f"{FABRIC:016X}-000000000000000D._matter._tcp.local."


def run(cmd: list[str], timeout: float = 12.0) -> str:
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    except (FileNotFoundError, subprocess.TimeoutExpired) as e:
        return f"{type(e).__name__}: {e}"
    out = ((r.stdout or "") + (r.stderr or "")).strip()
    return out[-4000:] if out else f"rc={r.returncode} (empty)"


def docker(*args: str, timeout: float = 20.0) -> str:
    last = "docker failed"
    for prefix in ([], ["sudo", "-n"]):
        try:
            r = subprocess.run(prefix + ["docker", *args], capture_output=True, text=True, timeout=timeout)
        except (FileNotFoundError, subprocess.TimeoutExpired) as e:
            last = f"{type(e).__name__}: {e}"
            continue
        text = ((r.stdout or "") + (r.stderr or "")).strip()
        if r.returncode == 0:
            return text
        last = text or f"rc={r.returncode}"
    return last


def send(sock: socket.socket, payload: dict) -> None:
    data = json.dumps(payload, separators=(",", ":")).encode()
    key = os.urandom(4)
    n = len(data)
    header = bytearray([0x81])
    if n < 126:
        header.append(0x80 | n)
    elif n < 65536:
        header.extend([0x80 | 126])
        header.extend(struct.pack("!H", n))
    else:
        header.extend([0x80 | 127])
        header.extend(struct.pack("!Q", n))
    header.extend(key)
    sock.sendall(header + bytes(b ^ key[i % 4] for i, b in enumerate(data)))


def recv_json(sock: socket.socket, timeout: float) -> dict | None:
    sock.settimeout(timeout)
    buf = bytearray()
    deadline = time.time() + timeout
    while time.time() < deadline:
        chunk = sock.recv(4096)
        if not chunk:
            return None
        buf.extend(chunk)
        while True:
            if len(buf) < 2:
                break
            ln = buf[1] & 0x7F
            idx = 2
            if ln == 126:
                if len(buf) < 4:
                    break
                ln = struct.unpack("!H", buf[2:4])[0]
                idx = 4
            elif ln == 127:
                if len(buf) < 10:
                    break
                ln = struct.unpack("!Q", buf[2:10])[0]
                idx = 10
            if buf[1] & 0x80:
                idx += 4
            total = idx + ln
            if len(buf) < total:
                break
            payload = bytes(buf[idx:total])
            if buf[1] & 0x80:
                mask = buf[idx - 4 : idx]
                payload = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
            del buf[:total]
            if payload:
                return json.loads(payload.decode())
    return None


def dump_chip() -> None:
    docker("cp", "yarbo-matter-server:/data/chip.json", "/tmp/yarbo-chip.json")
    path = Path("/tmp/yarbo-chip.json")
    if not path.is_file():
        print("chip missing")
        return
    data = json.loads(path.read_text())
    sdk = data.get("sdk-config") if isinstance(data, dict) else None
    keys = list(sdk) if isinstance(sdk, dict) else list(data)[:40]
    print("chip size", path.stat().st_size, "keys", len(keys))
    print("chip key names", keys)
    interesting = [k for k in keys if re.search(r"13|000000000000000[Dd]|/s/|node", str(k), re.I)]
    print("chip node-ish", interesting)


def interview() -> None:
    sock = socket.create_connection((HOST, PORT), timeout=5)
    sock.sendall(
        f"GET /ws HTTP/1.1\r\nHost: {HOST}:{PORT}\r\nUpgrade: websocket\r\n"
        f"Connection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
        f"Sec-WebSocket-Version: 13\r\n\r\n".encode()
    )
    buf = b""
    while b"\r\n\r\n" not in buf:
        buf += sock.recv(4096)
    hello = recv_json(sock, 8)
    print("hello", {k: hello.get(k) for k in ("fabric_id", "compressed_fabric_id", "sdk_version")} if isinstance(hello, dict) else hello)
    mid = uuid.uuid4().hex[:12]
    send(sock, {"message_id": mid, "command": "interview_node", "args": {"node_id": NODE}})
    print("interviewing node", NODE)
    while True:
        msg = recv_json(sock, 90)
        if msg is None:
            print("interview timed out")
            return
        print("event", msg.get("event") or msg.get("message_id") or list(msg)[:6])
        if msg.get("message_id") == mid:
            if msg.get("error_code") or msg.get("error"):
                print("interview failed", msg.get("details") or msg.get("error") or msg.get("error_code"))
            else:
                print("interview ok")
            break
    mid2 = uuid.uuid4().hex[:12]
    send(sock, {"message_id": mid2, "command": "get_nodes"})
    while True:
        msg = recv_json(sock, 20)
        if msg is None:
            print("get_nodes timed out")
            return
        if msg.get("message_id") == mid2:
            nodes = msg.get("result") or []
            if isinstance(nodes, dict):
                nodes = list(nodes.values())
            print("nodes", len(nodes) if hasattr(nodes, "__len__") else nodes)
            if isinstance(nodes, list) and nodes and isinstance(nodes[0], dict):
                attrs = nodes[0].get("attributes") or {}
                print("available", nodes[0].get("available"), "attrs", len(attrs))
            break


def main() -> None:
    print("=== docker logs ===")
    print(docker("logs", "--tail", "80", "yarbo-matter-server", timeout=15.0))
    print("=== chip ===")
    dump_chip()
    print("=== mdns name ===")
    print(MDNS_NAME)
    print("=== avahi matter ===")
    print(run(["timeout", "8", "avahi-browse", "-tprt", "_matter._tcp"], timeout=10.0))
    print("=== avahi hue ===")
    print(run(["timeout", "8", "avahi-browse", "-tprt", "_hue._tcp"], timeout=10.0))
    print("=== avahi resolve ===")
    print(run(["timeout", "6", "avahi-resolve", "-n", MDNS_NAME], timeout=8.0))
    print("=== interview ===")
    interview()
    print("done. Names stay in data/home.json. Do not pair.")


if __name__ == "__main__":
    main()
