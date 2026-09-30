#!/usr/bin/env python3
"""Dump Matter/Hue discovery, fix the IPv6 default route, interview node 13.

Does not pair or rename. Fabric names stay in data/home.json.
"""

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
HUE_HINT = re.compile(r"hue|ipbridge|signify|philips", re.I)


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


def primary_interface() -> str:
    text = run(["ip", "-4", "route", "show", "default"], timeout=3.0)
    parts = text.split()
    if "dev" in parts:
        return parts[parts.index("dev") + 1]
    return "eth0"


def encode_dns_name(name: str) -> bytes:
    out = b""
    for label in name.rstrip(".").split("."):
        lab = label.encode("ascii")
        out += bytes([len(lab)]) + lab
    return out + b"\x00"


def mdns_query_packet(qname: str, qtype: int = 12) -> bytes:
    header = struct.pack("!HHHHHH", 0x1200, 0, 1, 0, 0, 0)
    return header + encode_dns_name(qname) + struct.pack("!HH", qtype, 1)


def decode_dns_labels(payload: bytes, offset: int = 12) -> list[str]:
    labels: list[str] = []
    seen = 0
    while offset < len(payload) and seen < 24:
        length = payload[offset]
        if length == 0:
            break
        if length & 0xC0 == 0xC0:
            break
        offset += 1
        if offset + length > len(payload):
            break
        labels.append(payload[offset : offset + length].decode("ascii", "replace"))
        offset += length
        seen += 1
    return labels


def mdns_query(qname: str, ipv6: bool = False, seconds: float = 2.5) -> list[str]:
    packet = mdns_query_packet(qname)
    hits: list[str] = []
    if ipv6:
        sock = socket.socket(socket.AF_INET6, socket.SOCK_DGRAM)
        sock.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_MULTICAST_HOPS, 1)
        dest: tuple = ("ff02::fb", 5353, 0, 0)
    else:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_TTL, 1)
        dest = ("224.0.0.251", 5353)
    sock.settimeout(0.4)
    sock.bind(("", 0))
    try:
        sock.sendto(packet, dest)
    except OSError as e:
        sock.close()
        return [f"send failed: {e}"]
    deadline = time.time() + seconds
    while time.time() < deadline:
        try:
            data, addr = sock.recvfrom(2048)
        except TimeoutError:
            continue
        except OSError:
            break
        names = decode_dns_labels(data)
        hits.append(f"{addr[0]} {' '.join(names)[:120]}")
    sock.close()
    return hits or ["no replies"]


def ssdp_search() -> list[str]:
    msg = (
        "M-SEARCH * HTTP/1.1\r\n"
        "HOST: 239.255.255.250:1900\r\n"
        'MAN: "ssdp:discover"\r\n'
        "MX: 2\r\n"
        "ST: upnp:rootdevice\r\n"
        "\r\n"
    ).encode()
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
    sock.settimeout(0.5)
    sock.bind(("", 0))
    try:
        sock.sendto(msg, ("239.255.255.250", 1900))
    except OSError as e:
        sock.close()
        return [f"send failed: {e}"]
    found: list[str] = []
    deadline = time.time() + 3.0
    while time.time() < deadline:
        try:
            data, addr = sock.recvfrom(4096)
        except TimeoutError:
            continue
        except OSError:
            break
        text = data.decode("utf-8", "replace")
        if HUE_HINT.search(text) or HUE_HINT.search(addr[0]):
            loc = ""
            for line in text.split("\n"):
                if line.lower().startswith("location:"):
                    loc = line.split(":", 1)[1].strip()
                    break
            found.append(f"{addr[0]} {loc[:160]}")
    sock.close()
    return found or ["no Hue SSDP replies"]


def homebridge_hue_hosts() -> list[str]:
    paths = [
        Path("/var/lib/homebridge/config.json"),
        Path.home() / ".homebridge/config.json",
        Path("/homebridge/config.json"),
        Path("/opt/homebridge/config.json"),
    ]
    hits: list[str] = []
    for path in paths:
        if not path.is_file():
            continue
        try:
            data = json.loads(path.read_text())
        except (OSError, json.JSONDecodeError):
            hits.append(f"{path} unreadable")
            continue
        platforms = data.get("platforms") if isinstance(data, dict) else None
        if not isinstance(platforms, list):
            continue
        for plat in platforms:
            if not isinstance(plat, dict):
                continue
            name = str(plat.get("platform") or plat.get("name") or "")
            if not HUE_HINT.search(name) and not HUE_HINT.search(json.dumps(plat)[:500]):
                continue
            for key in ("host", "hosts", "ip", "ipaddress", "bridge", "address"):
                if key in plat:
                    hits.append(f"{path} {name} {key}={plat[key]}")
        if not hits:
            hits.append(f"{path} no Hue platform keys")
    return hits or ["no homebridge config"]


def add_ipv6_default_route() -> str:
    iface = primary_interface()
    existing = run(["ip", "-6", "route", "show", "default"], timeout=3.0)
    if existing and "default" in existing:
        return f"already have: {existing.splitlines()[0][:200]}"
    cmds = [
        ["sudo", "-n", "ip", "-6", "route", "add", "default", "dev", iface],
        ["sudo", "-n", "/usr/sbin/ip", "-6", "route", "add", "default", "dev", iface],
        ["sudo", "ip", "-6", "route", "add", "default", "dev", iface],
        ["sudo", "-n", "/usr/local/sbin/yarbo-matter-setup", "ipv6-route"],
    ]
    notes: list[str] = [f"iface={iface}"]
    for cmd in cmds:
        text = run(cmd, timeout=20.0)
        notes.append(" ".join(cmd) + " -> " + text.replace("\n", " ")[:180])
        shown = run(["ip", "-6", "route", "show", "default"], timeout=3.0)
        if shown and "default" in shown:
            return " ; ".join(notes[-2:] + [shown.splitlines()[0][:200]])
    return " ; ".join(notes)


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
    print(
        "hello",
        {k: hello.get(k) for k in ("fabric_id", "compressed_fabric_id", "sdk_version")}
        if isinstance(hello, dict)
        else hello,
    )
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


def wait_matter(seconds: float = 40.0) -> bool:
    deadline = time.time() + seconds
    while time.time() < deadline:
        probe = socket.socket()
        probe.settimeout(0.4)
        try:
            probe.connect((HOST, PORT))
            probe.close()
            time.sleep(2)
            return True
        except OSError:
            time.sleep(0.5)
        finally:
            probe.close()
    return False


def main() -> None:
    print("=== ipv4/ipv6 routes ===")
    print("primary", primary_interface())
    print(run(["ip", "-4", "route", "show"]))
    print(run(["ip", "-6", "route", "show"]))
    print("=== ssdp hue ===")
    for line in ssdp_search():
        print(line)
    print("=== homebridge hue ===")
    for line in homebridge_hue_hosts():
        print(line)
    print("=== mdns before ===")
    print("v4 _matter", mdns_query("_matter._tcp.local"))
    print("v4 _hue", mdns_query("_hue._tcp.local"))
    print("v6 _matter", mdns_query("_matter._tcp.local", ipv6=True))
    print("=== add ipv6 default (sudo password ok) ===")
    print(add_ipv6_default_route())
    print(run(["ip", "-6", "route", "show", "default"]))
    print("=== restart matter ===")
    print(docker("restart", "yarbo-matter-server", timeout=40.0))
    print("port", "up" if wait_matter() else "not up")
    print("=== logs after restart ===")
    logs = docker("logs", "--tail", "40", "yarbo-matter-server", timeout=15.0)
    for line in logs.splitlines():
        if re.search(r"primary|unreachable|Timeout|Interview|initialized|Loaded \d+ nodes", line, re.I):
            print(line)
    print("=== mdns after ===")
    print("v6 _matter", mdns_query("_matter._tcp.local", ipv6=True))
    print("=== interview ===")
    interview()
    print("done. Names stay in data/home.json. Do not pair.")


if __name__ == "__main__":
    main()
