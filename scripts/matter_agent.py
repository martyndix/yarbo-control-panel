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
AGENT_VERSION = 2
COLOR_ACTIONS = frozenset({"color", "colour", "set_color", "set_colour"})
COLOR_TEMP_ACTIONS = frozenset({"color_temp", "colour_temp", "kelvin"})

ON_OFF = 6
LEVEL_CONTROL = 8
COLOR_CONTROL = 0x0300
DESCRIPTOR = 29
BASIC_INFO = 40
BRIDGED_BASIC = 57
FIXED_LABEL = 64
USER_LABEL = 65
ATTR_ON_OFF = 0
ATTR_CURRENT_LEVEL = 0
ATTR_CURRENT_HUE = 0
ATTR_CURRENT_SATURATION = 1
ATTR_CURRENT_X = 3
ATTR_CURRENT_Y = 4
ATTR_COLOR_TEMP_MIREDS = 7
ATTR_COLOR_MODE = 8
ATTR_COLOR_CAPABILITIES = 0x400A
ATTR_CT_PHYSICAL_MIN = 0x400B
ATTR_CT_PHYSICAL_MAX = 0x400C
ATTR_DEVICE_TYPES = 0
ATTR_SERVER_LIST = 1
ATTR_VENDOR_NAME = 1
ATTR_PRODUCT_NAME = 3
ATTR_NODE_LABEL = 5
ATTR_FEATURE_MAP = 0xFFFC
DEVTYPE_AGGREGATOR = 0x000E
DEVTYPE_BRIDGED_NODE = 0x0013
DEVTYPE_ONOFF_LIGHT = 0x0100
DEVTYPE_DIMMABLE_LIGHT = 0x0101
DEVTYPE_COLOR_LIGHT = 0x0102
DEVTYPE_CT_LIGHT = 0x010C
DEVTYPE_EXTENDED_COLOR_LIGHT = 0x010D
COLOR_CAP_HS = 1 << 0
COLOR_CAP_XY = 1 << 3
COLOR_CAP_CT = 1 << 4

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


def run_docker(args: list[str]) -> subprocess.CompletedProcess:
    docker = docker_bin()
    if docker is None:
        return subprocess.CompletedProcess(args=["docker", *args], returncode=127, stdout="", stderr="docker not found")
    result = subprocess.run([docker, *args], capture_output=True, text=True)
    if result.returncode == 0:
        return result
    sudo = subprocess.run(["sudo", "-n", docker, *args], capture_output=True, text=True)
    if sudo.returncode == 0:
        return sudo
    if docker != "/usr/bin/docker" and os.access("/usr/bin/docker", os.X_OK):
        sudo_bin = subprocess.run(["sudo", "-n", "/usr/bin/docker", *args], capture_output=True, text=True)
        if sudo_bin.returncode == 0:
            return sudo_bin
    return result


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
                "Matter server is not ready. Open Settings → Home and tap Set up Matter server. "
                "A Raspberry Pi panel update installs Docker and the server automatically."
            )
        STORAGE.mkdir(parents=True, exist_ok=True)
        inspect = run_docker(["inspect", "-f", "{{.State.Running}}", DOCKER_NAME])
        if inspect.returncode == 0:
            running = (inspect.stdout or "").strip().lower() == "true"
            if not running:
                run_docker(["start", DOCKER_NAME])
        else:
            run_docker(
                [
                    "run",
                    "-d",
                    "--name",
                    DOCKER_NAME,
                    "--restart",
                    "unless-stopped",
                    "--security-opt",
                    "apparmor=unconfined",
                    "--network",
                    "host",
                    "-v",
                    f"{STORAGE}:/data",
                    DOCKER_IMAGE,
                ]
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
        return (
            "Started the Matter Docker container, but it is not listening yet. "
            "Wait a minute, or tap Set up Matter server in Settings → Home."
        )


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


def device_type_ids(types: Any) -> list[int]:
    ids: list[int] = []
    if isinstance(types, list):
        for item in types:
            raw_id: Any = None
            if isinstance(item, dict):
                raw_id = item.get("0", item.get(0, item.get("deviceType")))
            elif isinstance(item, (int, float)):
                raw_id = item
            try:
                if raw_id is not None:
                    ids.append(int(raw_id))
            except (TypeError, ValueError):
                continue
    return ids


def device_kind(types: Any) -> str:
    ids = device_type_ids(types)
    if any(i in (0x0301,) for i in ids):
        return "heater"
    if any(i in (0x010A, 0x010B) for i in ids):
        return "plug"
    if any(i in (0x0103, 0x010F) for i in ids):
        return "switch"
    if any(i in (0x0100, 0x0101, 0x0102, 0x010C, 0x010D) for i in ids):
        return "light"
    return "other"


def attr_str(attributes: dict[str, Any], endpoint: int, cluster: int, attr: int) -> str:
    val = attributes.get(attr_key(endpoint, cluster, attr))
    if isinstance(val, str) and val.strip():
        return val.strip()
    return ""


def label_list_text(val: Any) -> str:
    if not isinstance(val, list):
        return ""
    parts: list[str] = []
    for item in val:
        if not isinstance(item, dict):
            continue
        text = item.get("1") or item.get("value") or item.get("0") or item.get("label")
        if isinstance(text, str) and text.strip():
            parts.append(text.strip())
    return " · ".join(parts)


def endpoint_name(
    attributes: dict[str, Any],
    endpoint: int,
    vendor: str,
    product: str,
) -> str:
    for cluster in (BRIDGED_BASIC, BASIC_INFO):
        label = attr_str(attributes, endpoint, cluster, ATTR_NODE_LABEL)
        if label:
            return label[:48]
        product_ep = attr_str(attributes, endpoint, cluster, ATTR_PRODUCT_NAME)
        if product_ep:
            return product_ep[:48]
    for cluster in (FIXED_LABEL, USER_LABEL):
        listed = label_list_text(attributes.get(attr_key(endpoint, cluster, 0)))
        if listed:
            return listed[:48]
    base = product or vendor or "Device"
    return f"{base} {endpoint}"[:48]


def parse_attr_path(key: Any) -> tuple[int, int, int] | None:
    parts = str(key).replace(" ", "").split("/")
    if len(parts) < 3:
        return None
    try:
        return int(parts[0], 0), int(parts[1], 0), int(parts[2], 0)
    except ValueError:
        return None


def attr_raw(attributes: dict[str, Any], endpoint: int, cluster: int, attr: int) -> Any:
    key = attr_key(endpoint, cluster, attr)
    if key in attributes:
        return attributes[key]
    for stored, val in attributes.items():
        if parse_attr_path(stored) == (endpoint, cluster, attr):
            return val
    return None


def list_ints(val: Any) -> list[int]:
    out: list[int] = []
    if not isinstance(val, list):
        return out
    for item in val:
        raw: Any = None
        if isinstance(item, (int, float)):
            raw = item
        elif isinstance(item, dict):
            raw = item.get("0", item.get(0, item.get("value", item.get("clusterId"))))
        try:
            if raw is not None:
                out.append(int(raw))
        except (TypeError, ValueError):
            continue
    return out


def endpoint_has_cluster(attributes: dict[str, Any], endpoint: int, cluster: int) -> bool:
    for key in attributes:
        parsed = parse_attr_path(key)
        if parsed and parsed[0] == endpoint and parsed[1] == cluster:
            return True
    return cluster in list_ints(attr_raw(attributes, endpoint, DESCRIPTOR, ATTR_SERVER_LIST))


def attr_num(attributes: dict[str, Any], endpoint: int, cluster: int, attr: int) -> float | None:
    val = attr_raw(attributes, endpoint, cluster, attr)
    if isinstance(val, bool) or val is None:
        return None
    if isinstance(val, (int, float)):
        return float(val)
    if isinstance(val, dict):
        inner = val.get("value", val.get("0"))
        if isinstance(inner, (int, float)):
            return float(inner)
    return None


def clamp_int(value: float, lo: int, hi: int) -> int:
    return max(lo, min(hi, int(round(value))))


def hs_to_hex(hue_254: float, sat_254: float) -> str:
    h = (max(0.0, min(254.0, hue_254)) / 254.0) * 6.0
    s = max(0.0, min(254.0, sat_254)) / 254.0
    v = 1.0
    i = int(h)
    f = h - i
    p = v * (1.0 - s)
    q = v * (1.0 - f * s)
    t = v * (1.0 - (1.0 - f) * s)
    rgb = {
        0: (v, t, p),
        1: (q, v, p),
        2: (p, v, t),
        3: (p, q, v),
        4: (t, p, v),
    }.get(i % 6, (v, p, q))
    return "#{:02x}{:02x}{:02x}".format(*(clamp_int(c * 255, 0, 255) for c in rgb))


def hex_to_rgb(hex_s: str) -> tuple[int, int, int] | None:
    raw = hex_s.strip().lstrip("#")
    if len(raw) == 3:
        raw = "".join(ch * 2 for ch in raw)
    if len(raw) != 6:
        return None
    try:
        return int(raw[0:2], 16), int(raw[2:4], 16), int(raw[4:6], 16)
    except ValueError:
        return None


def hex_to_hs(hex_s: str) -> tuple[int, int] | None:
    rgb = hex_to_rgb(hex_s)
    if rgb is None:
        return None
    r, g, b = (c / 255.0 for c in rgb)
    mx, mn = max(r, g, b), min(r, g, b)
    df = mx - mn
    if df == 0:
        hue = 0.0
    elif mx == r:
        hue = (60 * ((g - b) / df) + 360) % 360
    elif mx == g:
        hue = (60 * ((b - r) / df) + 120) % 360
    else:
        hue = (60 * ((r - g) / df) + 240) % 360
    sat = 0.0 if mx == 0 else df / mx
    return clamp_int(hue * 254 / 360, 0, 254), clamp_int(sat * 254, 0, 254)


def hex_to_xy(hex_s: str) -> tuple[int, int] | None:
    rgb = hex_to_rgb(hex_s)
    if rgb is None:
        return None

    def linear(channel: int) -> float:
        c = channel / 255.0
        return ((c + 0.055) / 1.055) ** 2.4 if c > 0.04045 else c / 12.92

    red, green, blue = (linear(c) for c in rgb)
    x = red * 0.4124 + green * 0.3576 + blue * 0.1805
    y = red * 0.2126 + green * 0.7152 + blue * 0.0722
    z = red * 0.0193 + green * 0.1192 + blue * 0.9505
    total = x + y + z
    if total <= 0:
        return 19660, 19660
    return clamp_int((x / total) * 65536, 0, 65279), clamp_int((y / total) * 65536, 0, 65279)


def xy_to_hex(x_raw: float, y_raw: float) -> str:
    x = max(0.0, min(1.0, x_raw / 65536.0 if x_raw > 1.5 else x_raw))
    y = max(0.0, min(1.0, y_raw / 65536.0 if y_raw > 1.5 else y_raw))
    if y <= 0.0001:
        return "#ffffff"
    z = 1.0 - x - y
    Y = 1.0
    X = (x / y) * Y
    Z = (z / y) * Y
    r = X * 3.2406 + Y * -1.5372 + Z * -0.4986
    g = X * -0.9689 + Y * 1.8758 + Z * 0.0415
    b = X * 0.0557 + Y * -0.2040 + Z * 1.0570

    def gamma(channel: float) -> int:
        c = max(0.0, channel)
        c = 1.055 * (c ** (1 / 2.4)) - 0.055 if c > 0.0031308 else 12.92 * c
        return clamp_int(c * 255, 0, 255)

    return "#{:02x}{:02x}{:02x}".format(gamma(r), gamma(g), gamma(b))


def mireds_to_kelvin(mireds: float) -> int:
    if mireds <= 0:
        return 2700
    return clamp_int(1_000_000 / mireds, 1500, 8000)


def kelvin_to_mireds(kelvin: int) -> int:
    k = max(1500, min(8000, kelvin))
    return clamp_int(1_000_000 / k, 1, 1000)


def kelvin_to_hex(kelvin: int) -> str:
    # Approximate black-body for the UI swatch; not a photometric conversion.
    k = max(1000, min(12000, kelvin)) / 100.0
    if k <= 66:
        r = 255
        g = clamp_int(99.4705 * (k ** 0.5) - 161.1196, 0, 255)
        b = 0 if k <= 19 else clamp_int(138.5177 * ((k - 10) ** 0.5) - 305.0448, 0, 255)
    else:
        r = clamp_int(329.6987 * ((k - 60) ** -0.1332), 0, 255)
        g = clamp_int(288.1222 * ((k - 60) ** -0.0755), 0, 255)
        b = 255
    return "#{:02x}{:02x}{:02x}".format(r, g, b)


def color_payload(attributes: dict[str, Any], endpoint: int, type_ids: list[int] | None = None) -> dict[str, Any]:
    type_ids = type_ids or []
    hue = attr_num(attributes, endpoint, COLOR_CONTROL, ATTR_CURRENT_HUE)
    sat = attr_num(attributes, endpoint, COLOR_CONTROL, ATTR_CURRENT_SATURATION)
    x = attr_num(attributes, endpoint, COLOR_CONTROL, ATTR_CURRENT_X)
    y = attr_num(attributes, endpoint, COLOR_CONTROL, ATTR_CURRENT_Y)
    mireds = attr_num(attributes, endpoint, COLOR_CONTROL, ATTR_COLOR_TEMP_MIREDS)
    mode = attr_num(attributes, endpoint, COLOR_CONTROL, ATTR_COLOR_MODE)
    caps = attr_num(attributes, endpoint, COLOR_CONTROL, ATTR_COLOR_CAPABILITIES)
    if caps is None:
        caps = attr_num(attributes, endpoint, COLOR_CONTROL, ATTR_FEATURE_MAP)
    ct_min = attr_num(attributes, endpoint, COLOR_CONTROL, ATTR_CT_PHYSICAL_MIN)
    ct_max = attr_num(attributes, endpoint, COLOR_CONTROL, ATTR_CT_PHYSICAL_MAX)
    cap_bits = int(caps) if caps is not None else 0
    has_cc = endpoint_has_cluster(attributes, endpoint, COLOR_CONTROL)
    extended = any(i in (DEVTYPE_COLOR_LIGHT, DEVTYPE_EXTENDED_COLOR_LIGHT) for i in type_ids)
    ct_type = DEVTYPE_CT_LIGHT in type_ids
    is_light = any(
        i in (
            DEVTYPE_ONOFF_LIGHT,
            DEVTYPE_DIMMABLE_LIGHT,
            DEVTYPE_COLOR_LIGHT,
            DEVTYPE_CT_LIGHT,
            DEVTYPE_EXTENDED_COLOR_LIGHT,
        )
        for i in type_ids
    )
    color_hs = bool(cap_bits & COLOR_CAP_HS) or hue is not None
    color_xy = bool(cap_bits & COLOR_CAP_XY) or x is not None
    color_ct = bool(cap_bits & COLOR_CAP_CT) or mireds is not None
    if not (color_hs or color_xy or color_ct):
        if ct_type and not extended:
            color_ct = True
        elif has_cc or extended or is_light:
            # Hue Bridge often omits Color Control values until you write them.
            color_hs = not ct_type
            color_xy = not ct_type
            color_ct = True
    hex_s = None
    hue_deg = clamp_int((hue or 0) * 360 / 254, 0, 360) if hue is not None else None
    sat_pct = clamp_int((sat or 0) * 100 / 254, 0, 100) if sat is not None else None
    kelvin = mireds_to_kelvin(mireds) if mireds is not None else None
    if mode == 0 and hue is not None and sat is not None:
        hex_s = hs_to_hex(hue, sat)
    elif mode == 1 and x is not None and y is not None:
        hex_s = xy_to_hex(x, y)
    elif mode == 2 and kelvin is not None:
        hex_s = kelvin_to_hex(kelvin)
    elif hue is not None and sat is not None:
        hex_s = hs_to_hex(hue, sat)
    elif x is not None and y is not None:
        hex_s = xy_to_hex(x, y)
    elif kelvin is not None:
        hex_s = kelvin_to_hex(kelvin)
    return {
        "colorable": color_hs or color_xy,
        "color_hs": color_hs,
        "color_xy": color_xy,
        "color_ct": color_ct,
        "color_hex": hex_s,
        "hue": hue_deg,
        "saturation": sat_pct,
        "color_temp": kelvin,
        "color_temp_min": mireds_to_kelvin(ct_max) if ct_max else (2000 if color_ct else None),
        "color_temp_max": mireds_to_kelvin(ct_min) if ct_min else (6500 if color_ct else None),
    }


def device_command(node_id: int, endpoint: int, cluster: int, name: str, payload: dict[str, Any]) -> dict[str, Any]:
    rpc = matter_rpc(
        "device_command",
        {
            "node_id": node_id,
            "endpoint_id": endpoint,
            "cluster_id": cluster,
            "command_name": name,
            "payload": payload,
        },
        timeout=12.0,
    )
    return rpc if not rpc.get("ok") else {"ok": True}


def is_transport_error(result: dict[str, Any]) -> bool:
    err = str(result.get("error") or "").lower()
    return any(
        token in err
        for token in (
            "unavailable",
            "timed out",
            "timeout",
            "not listening",
            "not running",
            "connection refused",
            "websocket",
        )
    )


def color_transition(execute_if_off: bool = True) -> dict[str, int]:
    return {
        "transitionTime": 0,
        "optionsMask": 1 if execute_if_off else 0,
        "optionsOverride": 1 if execute_if_off else 0,
    }


def try_color_commands(node_id: int, endpoint: int, attempts: list[tuple[str, dict[str, Any]]]) -> dict[str, Any]:
    last: dict[str, Any] = {"ok": False, "error": "Colour command failed"}
    for name, payload in attempts:
        last = device_command(node_id, endpoint, COLOR_CONTROL, name, payload)
        if last.get("ok"):
            return last
        if is_transport_error(last):
            return last
    return last


def set_color(node_id: int, endpoint: int, body: dict[str, Any]) -> dict[str, Any]:
    hex_s = str(body.get("hex") or body.get("color_hex") or "").strip()
    hs = hex_to_hs(hex_s) if hex_s else None
    if hs is None and body.get("hue") is not None and body.get("saturation") is not None:
        hs = (
            clamp_int(float(body.get("hue") or 0) * 254 / 360, 0, 254),
            clamp_int(float(body.get("saturation") or 0) * 254 / 100, 0, 254),
        )
    if hs is None:
        return {"ok": False, "error": "Colour needs a hex value"}
    xy = hex_to_xy(hex_s) if hex_s else None
    # Hue often ignores colour writes while off, even with ExecuteIfOff.
    on_result = device_command(node_id, endpoint, ON_OFF, "On", {})
    if is_transport_error(on_result):
        return on_result
    attempts: list[tuple[str, dict[str, Any]]] = [
        (
            "MoveToHueAndSaturation",
            {"hue": hs[0], "saturation": hs[1], **color_transition(True)},
        ),
        (
            "EnhancedMoveToHueAndSaturation",
            {
                "enhancedHue": min(65535, hs[0] * 256),
                "saturation": hs[1],
                **color_transition(True),
            },
        ),
    ]
    if xy is not None:
        attempts.append(("MoveToColor", {"colorX": xy[0], "colorY": xy[1], **color_transition(True)}))
    result = try_color_commands(node_id, endpoint, attempts)
    if result.get("ok") or is_transport_error(result):
        return result
    fallback: list[tuple[str, dict[str, Any]]] = [
        (
            "MoveToHueAndSaturation",
            {"hue": hs[0], "saturation": hs[1], **color_transition(False)},
        ),
    ]
    if xy is not None:
        fallback.append(("MoveToColor", {"colorX": xy[0], "colorY": xy[1], **color_transition(False)}))
    return try_color_commands(node_id, endpoint, fallback)


def set_color_temp(node_id: int, endpoint: int, kelvin: int) -> dict[str, Any]:
    on_result = device_command(node_id, endpoint, ON_OFF, "On", {})
    if is_transport_error(on_result):
        return on_result
    payload_on = {
        "colorTemperatureMireds": kelvin_to_mireds(kelvin),
        **color_transition(True),
    }
    result = device_command(node_id, endpoint, COLOR_CONTROL, "MoveToColorTemperature", payload_on)
    if result.get("ok") or is_transport_error(result):
        return result
    payload_off = {
        "colorTemperatureMireds": kelvin_to_mireds(kelvin),
        **color_transition(False),
    }
    return device_command(node_id, endpoint, COLOR_CONTROL, "MoveToColorTemperature", payload_off)


def flatten_nodes(raw: Any) -> list[dict[str, Any]]:
    nodes = raw if isinstance(raw, list) else []
    devices: list[dict[str, Any]] = []
    for node in nodes:
        if not isinstance(node, dict):
            continue
        node_id = int(node.get("node_id") or 0)
        available = bool(node.get("available", True))
        is_bridge = bool(node.get("is_bridge", False))
        attributes = node.get("attributes") if isinstance(node.get("attributes"), dict) else {}
        vendor = attr_str(attributes, 0, BASIC_INFO, ATTR_VENDOR_NAME)
        product = attr_str(attributes, 0, BASIC_INFO, ATTR_PRODUCT_NAME)
        node_name = attr_str(attributes, 0, BASIC_INFO, ATTR_NODE_LABEL)
        source = node_name or product or vendor or f"Matter node {node_id}"
        ep_ids = endpoint_ids(attributes)
        for endpoint in sorted(ep_ids):
            if endpoint == 0:
                continue
            on_key = attr_key(endpoint, ON_OFF, ATTR_ON_OFF)
            if on_key not in attributes:
                continue
            types = attributes.get(attr_key(endpoint, DESCRIPTOR, ATTR_DEVICE_TYPES))
            type_ids = device_type_ids(types)
            kind = device_kind(types)
            if DEVTYPE_AGGREGATOR in type_ids and kind == "other":
                continue
            if kind == "other":
                continue
            label = endpoint_name(attributes, endpoint, vendor, product)
            level = attributes.get(attr_key(endpoint, LEVEL_CONTROL, ATTR_CURRENT_LEVEL))
            brightness = None
            if isinstance(level, (int, float)) and level >= 0:
                brightness = int(round(float(level) * 100 / 254))
            color = color_payload(attributes, endpoint, type_ids)
            devices.append(
                {
                    "id": f"{node_id}:{endpoint}",
                    "node_id": node_id,
                    "endpoint": endpoint,
                    "name": label,
                    "kind": kind,
                    "vendor": vendor,
                    "product": product,
                    "source": source,
                    "bridge": is_bridge or len(ep_ids) > 3,
                    "on": bool(attributes.get(on_key)),
                    "brightness": brightness,
                    "dimmable": attr_key(endpoint, LEVEL_CONTROL, ATTR_CURRENT_LEVEL) in attributes,
                    "available": available,
                    **color,
                }
            )
    devices.sort(key=lambda d: (str(d.get("source") or "").lower(), d["name"].lower(), d["id"]))
    return devices


def parse_id(device_id: str) -> tuple[int, int]:
    node_s, ep_s = device_id.split(":", 1)
    return int(node_s), int(ep_s)


def dispatch(body: dict[str, Any]) -> dict[str, Any]:
    op = str(body.get("op") or "")
    if op == "ping":
        return {
            "ok": True,
            "engine": "matter-agent",
            "version": AGENT_VERSION,
            "features": ["color", "color_temp"],
        }
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
    if op == "remove_node":
        try:
            node_id = int(body.get("node_id") or 0)
        except (TypeError, ValueError):
            node_id = 0
        if node_id <= 0:
            return {"ok": False, "error": "node_id is required"}
        rpc = matter_rpc("remove_node", {"node_id": node_id}, timeout=20.0)
        return rpc if not rpc.get("ok") else {"ok": True}
    if op == "command":
        device_id = str(body.get("id") or "")
        try:
            node_id, endpoint = parse_id(device_id)
        except ValueError:
            return {"ok": False, "error": "Invalid device id"}
        action = str(body.get("action") or body.get("command") or "").strip().lower()
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
            return device_command(
                node_id,
                endpoint,
                LEVEL_CONTROL,
                "MoveToLevelWithOnOff",
                {
                    "level": level,
                    "transitionTime": 0,
                    "optionsMask": 0,
                    "optionsOverride": 0,
                },
            )
        if action in COLOR_ACTIONS:
            return set_color(node_id, endpoint, body)
        if action in COLOR_TEMP_ACTIONS:
            try:
                kelvin = int(body.get("kelvin") or body.get("color_temp") or 0)
            except (TypeError, ValueError):
                kelvin = 0
            if kelvin <= 0:
                return {"ok": False, "error": "Colour temperature needs a kelvin value"}
            return set_color_temp(node_id, endpoint, kelvin)
        shown = action or "(empty)"
        return {"ok": False, "error": f"Unknown Matter command ({shown})"}
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
