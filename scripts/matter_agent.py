#!/usr/bin/env python3
"""Local Matter controller agent for the Yarbo panel.

Talks JSON HTTP on 127.0.0.1:8766 and forwards to python-matter-server
(WebSocket on 127.0.0.1:5580). Starts the Matter server via Docker when possible.

Usage:
  python3 scripts/matter_agent.py
  python3 scripts/matter_agent.py --restore-nodes
"""

from __future__ import annotations

import base64
import hashlib
import json
import os
import re
import shutil
import socket
import struct
import subprocess
import sys
import tempfile
import threading
import time
import uuid
from datetime import datetime, timezone
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
SHARED_MATTER_NAME = os.environ.get("YARBO_SHARED_MATTER_NAME", "matter-server")
STORAGE = ROOT / "data" / "matter-server"
AGENT_VERSION = 21
STICKY_HOLD = 4.0
STICKY_STATE_KEYS = ("on", "brightness", "color_hex", "hue", "saturation", "color_temp")
CMD_CHANNEL = "cmd"
LISTEN_CHANNEL = "listen"
POLL_CHANNEL = "poll"
COLOR_ACTIONS = frozenset({"color", "colour", "set_color", "set_colour"})
COLOR_TEMP_ACTIONS = frozenset({"color_temp", "colour_temp", "kelvin"})

ON_OFF = 6
LEVEL_CONTROL = 8
COLOR_CONTROL = 0x0300
THERMOSTAT = 0x0201
TEMP_MEASUREMENT = 0x0402
RVC_RUN = 0x0054
RVC_CLEAN = 0x0055
RVC_OPERATIONAL = 0x0061
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
ATTR_ENHANCED_CURRENT_HUE = 0x4000
ATTR_ENHANCED_COLOR_MODE = 0x4001
ATTR_COLOR_CAPABILITIES = 0x400A
ATTR_CT_PHYSICAL_MIN = 0x400B
ATTR_CT_PHYSICAL_MAX = 0x400C
ATTR_DEVICE_TYPES = 0
ATTR_SERVER_LIST = 1
ATTR_VENDOR_NAME = 1
ATTR_PRODUCT_NAME = 3
ATTR_NODE_LABEL = 5
ATTR_FEATURE_MAP = 0xFFFC
ATTR_LOCAL_TEMPERATURE = 0
ATTR_ABS_MIN_HEAT_SETPOINT = 3
ATTR_ABS_MAX_HEAT_SETPOINT = 4
ATTR_OCCUPIED_HEATING_SETPOINT = 0x0012
ATTR_MIN_HEAT_SETPOINT = 0x0015
ATTR_MAX_HEAT_SETPOINT = 0x0016
ATTR_SYSTEM_MODE = 0x001C
ATTR_MEASURED_TEMP = 0
SYSTEM_MODE_OFF = 0
SYSTEM_MODE_AUTO = 1
SYSTEM_MODE_HEAT = 4
CHIP_STATUS_SUCCESS = 0
CHIP_STATUS_FAILURE = 1
CHIP_STATUS_CONSTRAINT = 0x87
CHIP_STATUS_UNSUPPORTED_WRITE = 0x88
CHIP_STATUS_UNSUPPORTED_CLUSTER = 0xC3
DEVTYPE_AGGREGATOR = 0x000E
DEVTYPE_BRIDGED_NODE = 0x0013
DEVTYPE_ONOFF_LIGHT = 0x0100
DEVTYPE_DIMMABLE_LIGHT = 0x0101
DEVTYPE_COLOR_LIGHT = 0x0102
DEVTYPE_CT_LIGHT = 0x010C
DEVTYPE_EXTENDED_COLOR_LIGHT = 0x010D
DEVTYPE_THERMOSTAT = 0x0300
DEVTYPE_HEATING_COOLING = 0x0301
DEVTYPE_RVC = 0x0074
COLOR_CAP_HS = 1 << 0
COLOR_CAP_EHUE = 1 << 1
COLOR_CAP_XY = 1 << 3
COLOR_CAP_CT = 1 << 4

_ws_slots: dict[str, MatterWs | None] = {
    CMD_CHANNEL: None,
    LISTEN_CHANNEL: None,
    POLL_CHANNEL: None,
}
_ws_send_locks = {
    CMD_CHANNEL: threading.Lock(),
    LISTEN_CHANNEL: threading.Lock(),
    POLL_CHANNEL: threading.Lock(),
}
_ws_connect_locks = {
    CMD_CHANNEL: threading.Lock(),
    LISTEN_CHANNEL: threading.Lock(),
    POLL_CHANNEL: threading.Lock(),
}
_pending: dict[str, dict[str, Any]] = {}
_pending_lock = threading.Lock()
_recv_started = {CMD_CHANNEL: False, LISTEN_CHANNEL: False, POLL_CHANNEL: False}
_listening = False
_listen_lock = threading.Lock()
_start_lock = threading.Lock()
_started_docker = False
_recovered_storage = False
_recover_thread_started = False
_recover_done = threading.Event()
_recover_lock = threading.Lock()
_recover_last_at = 0.0
_restore_lock = threading.Lock()
_preferred_shared = False
_live_lock = threading.Lock()
_live_devices: list[dict[str, Any]] = []
_state_poll_started = False
_command_busy = 0
_command_busy_lock = threading.Lock()
_listen_starting = False
_listen_start_lock = threading.Lock()


def attr_key(endpoint: int, cluster: int, attr: int) -> str:
    return f"{endpoint}/{cluster}/{attr}"


class MatterWs:
    def __init__(self, sock: socket.socket, leftover: bytes = b"") -> None:
        self.sock = sock
        self.buf = bytearray(leftover)
        self._out = threading.Lock()

    def send_json(self, payload: dict[str, Any]) -> None:
        data = json.dumps(payload, separators=(",", ":")).encode()
        self._send_frame(0x1, data)

    def send_pong(self, payload: bytes = b"") -> None:
        self._send_frame(0xA, payload)

    def _send_frame(self, opcode: int, payload: bytes) -> None:
        key = os.urandom(4)
        header = bytearray()
        n = len(payload)
        if n < 126:
            header.append(0x80 | opcode)
            header.append(0x80 | n)
        elif n < 65536:
            header.append(0x80 | opcode)
            header.append(0x80 | 126)
            header.extend(struct.pack("!H", n))
        else:
            header.append(0x80 | opcode)
            header.append(0x80 | 127)
            header.extend(struct.pack("!Q", n))
        header.extend(key)
        masked = bytes(b ^ key[i % 4] for i, b in enumerate(payload))
        with self._out:
            self.sock.sendall(header + masked)

    def recv_json(self, timeout: float) -> dict[str, Any] | None:
        self.sock.settimeout(timeout)
        deadline = time.time() + timeout
        acc = bytearray()
        while time.time() < deadline:
            frame = self._read_frame(max(0.2, deadline - time.time()))
            if frame is None:
                return None
            fin, opcode, payload = frame
            if opcode == 0x9:
                try:
                    self.send_pong(payload)
                except OSError:
                    return None
                continue
            if opcode not in (0x1, 0x2, 0x0):
                acc.clear()
                continue
            acc.extend(payload)
            if not fin:
                continue
            try:
                parsed = json.loads(bytes(acc).decode())
            except (UnicodeDecodeError, json.JSONDecodeError):
                acc.clear()
                continue
            acc.clear()
            if isinstance(parsed, dict):
                return parsed
        return None

    def _read_frame(self, timeout: float) -> tuple[bool, int, bytes] | None:
        try:
            self.sock.settimeout(max(0.2, timeout))
            while len(self.buf) < 2:
                chunk = self.sock.recv(4096)
                if not chunk:
                    return None
                self.buf.extend(chunk)
            b1, b2 = self.buf[0], self.buf[1]
            fin = bool(b1 & 0x80)
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
            return fin, opcode, payload
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
    leftover = buf.split(b"\r\n\r\n", 1)[1] if b"\r\n\r\n" in buf else b""
    return MatterWs(sock, leftover)


def docker_bin() -> str | None:
    which = subprocess.run("command -v docker", shell=True, capture_output=True, text=True)
    path = (which.stdout or "").strip()
    if path:
        return path
    for candidate in ("/usr/bin/docker", "/usr/local/bin/docker", "/opt/homebrew/bin/docker"):
        if os.access(candidate, os.X_OK):
            return candidate
    return None


def _docker_once(bin_path: str, args: list[str], timeout: float) -> subprocess.CompletedProcess:
    try:
        return subprocess.run([bin_path, *args], capture_output=True, text=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        return subprocess.CompletedProcess(
            args=[bin_path, *args],
            returncode=124,
            stdout="",
            stderr="docker command timed out",
        )


def run_docker(args: list[str], timeout: float = 12.0) -> subprocess.CompletedProcess:
    docker = docker_bin()
    if docker is None:
        return subprocess.CompletedProcess(args=["docker", *args], returncode=127, stdout="", stderr="docker not found")
    result = _docker_once(docker, args, timeout)
    if result.returncode == 0:
        return result
    sudo = _docker_once("sudo", ["-n", docker, *args], timeout)
    if sudo.returncode == 0:
        return sudo
    if docker != "/usr/bin/docker" and os.access("/usr/bin/docker", os.X_OK):
        sudo_bin = _docker_once("sudo", ["-n", "/usr/bin/docker", *args], timeout)
        if sudo_bin.returncode == 0:
            return sudo_bin
    return result


def storage_json_files() -> list[Path]:
    STORAGE.mkdir(parents=True, exist_ok=True)
    files: list[Path] = []
    try:
        listing = list(STORAGE.iterdir())
    except OSError:
        return files
    for path in listing:
        if not path.is_file():
            continue
        name = path.name.lower()
        if name.endswith(".json") or name.endswith(".json.backup"):
            files.append(path)
    return sorted(files)


def write_storage_file(path: Path, text: str) -> bool:
    """Write Matter storage even when Docker left the file root-owned."""
    try:
        path.write_text(text, encoding="utf-8")
        return True
    except OSError:
        pass
    fd, tmp_name = tempfile.mkstemp(prefix="yarbo-matter-", suffix=".json")
    os.close(fd)
    tmp = Path(tmp_name)
    try:
        tmp.write_text(text, encoding="utf-8")
        copied = run_docker(["cp", str(tmp), f"{DOCKER_NAME}:/data/{path.name}"], timeout=15.0)
        if copied.returncode == 0:
            return True
        sudo_cp = subprocess.run(
            ["sudo", "-n", "cp", str(tmp), str(path)],
            capture_output=True,
            text=True,
            timeout=10,
        )
        return sudo_cp.returncode == 0
    except (OSError, subprocess.TimeoutExpired):
        return False
    finally:
        try:
            tmp.unlink()
        except OSError:
            pass


def read_storage_file(path: Path) -> str | None:
    """Read Matter storage even when Docker left the file root:600."""
    try:
        return path.read_text(encoding="utf-8")
    except OSError:
        pass
    fd, tmp_name = tempfile.mkstemp(prefix="yarbo-matter-read-", suffix=".json")
    os.close(fd)
    tmp = Path(tmp_name)
    try:
        copied = run_docker(["cp", f"{DOCKER_NAME}:/data/{path.name}", str(tmp)], timeout=15.0)
        if copied.returncode == 0:
            return tmp.read_text(encoding="utf-8")
        sudo_cat = subprocess.run(
            ["sudo", "-n", "cat", str(path)],
            capture_output=True,
            text=True,
            timeout=10,
        )
        if sudo_cat.returncode == 0:
            return sudo_cat.stdout
        return None
    except (OSError, subprocess.TimeoutExpired, UnicodeDecodeError):
        return None
    finally:
        try:
            tmp.unlink()
        except OSError:
            pass


def ensure_storage_readable() -> None:
    """Docker bind-mounts are often root:600; the panel user cannot flatten those files."""
    try:
        STORAGE.mkdir(parents=True, exist_ok=True)
        try:
            os.chmod(STORAGE, 0o755)
        except OSError:
            pass
        for path in storage_json_files():
            try:
                os.chmod(path, 0o644)
            except OSError:
                pass
        for path in STORAGE.glob("*.ini"):
            try:
                os.chmod(path, 0o644)
            except OSError:
                pass
    except OSError:
        pass
    run_docker(
        [
            "exec",
            DOCKER_NAME,
            "sh",
            "-c",
            "chmod a+r /data/*.json /data/*.json.backup /data/*.ini 2>/dev/null; chmod a+X /data",
        ],
        timeout=8.0,
    )


def restore_chip_backups() -> bool:
    restored = False
    chip = STORAGE / "chip.json"
    backup = STORAGE / "chip.json.backup"
    if backup.is_file() and (not chip.is_file() or chip.stat().st_size < 32):
        shutil.copy2(backup, chip)
        restored = True
    for path in list(STORAGE.glob("*.json.backup")):
        primary = Path(str(path)[: -len(".backup")])
        if path.name == "chip.json.backup":
            continue
        if path.is_file() and (not primary.is_file() or primary.stat().st_size < 32):
            shutil.copy2(path, primary)
            restored = True
    return restored


def nodes_from_disk() -> list[dict[str, Any]]:
    found: dict[int, dict[str, Any]] = {}
    for path in storage_json_files():
        if path.name in ("chip.json", "chip.json.backup"):
            continue
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, UnicodeDecodeError, json.JSONDecodeError):
            continue
        for row in nodes_from_result(data):
            if not isinstance(row, dict):
                continue
            try:
                node_id = int(row.get("node_id") or row.get("nodeId") or 0)
            except (TypeError, ValueError):
                node_id = 0
            if node_id <= 0:
                continue
            prev = found.get(node_id)
            prev_attrs = prev.get("attributes") if isinstance(prev, dict) else None
            new_attrs = row.get("attributes") if isinstance(row.get("attributes"), dict) else {}
            if prev is None or (isinstance(new_attrs, dict) and len(new_attrs) > len(prev_attrs or {})):
                found[node_id] = row
    return [found[key] for key in sorted(found)]


def rewrite_device_id(device_id: str, old_node: int, new_node: int) -> str:
    if not isinstance(device_id, str) or ":" not in device_id:
        return device_id
    node_s, rest = device_id.split(":", 1)
    try:
        if int(node_s) == old_node:
            return f"{new_node}:{rest}"
    except ValueError:
        pass
    return device_id


def remap_home_node_id(store: dict[str, Any], old_node: int, new_node: int) -> int:
    """Rewrite home.json-style ids from old_node:x to new_node:x. Returns how many strings changed."""
    if old_node == new_node or old_node <= 0 or new_node <= 0:
        return 0
    changed = 0

    def one(value: Any) -> Any:
        nonlocal changed
        if isinstance(value, str) and ":" in value:
            rewritten = rewrite_device_id(value, old_node, new_node)
            if rewritten != value:
                changed += 1
            return rewritten
        return value

    for key in ("names", "rooms", "groups"):
        mapping = store.get(key)
        if not isinstance(mapping, dict):
            continue
        store[key] = {one(k): v for k, v in mapping.items()}
    hidden = store.get("hidden")
    if isinstance(hidden, list):
        store["hidden"] = [one(item) for item in hidden]
    order = store.get("device_order")
    if isinstance(order, list):
        store["device_order"] = [one(item) for item in order]
    paper = store.get("paper")
    if isinstance(paper, dict):
        store["paper"] = {
            pk: [one(item) for item in lst] if isinstance(lst, list) else lst
            for pk, lst in paper.items()
        }
    for scene in store.get("scenes") or []:
        if not isinstance(scene, dict):
            continue
        actions = scene.get("actions")
        if not isinstance(actions, list):
            continue
        for action in actions:
            if isinstance(action, dict) and "id" in action:
                action["id"] = one(action.get("id"))
    devices = store.get("last_devices")
    if isinstance(devices, list):
        for row in devices:
            if not isinstance(row, dict):
                continue
            if "id" in row:
                row["id"] = one(row.get("id"))
            try:
                if int(row.get("node_id") or 0) == old_node:
                    row["node_id"] = new_node
                    changed += 1
            except (TypeError, ValueError):
                pass
    return changed


def persist_remapped_home(old_node: int, new_node: int) -> int:
    path = ROOT / "data" / "home.json"
    try:
        store = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        return 0
    if not isinstance(store, dict):
        return 0
    changed = remap_home_node_id(store, old_node, new_node)
    if changed:
        backup = path.with_suffix(".json.pre-remap")
        if not backup.exists():
            try:
                shutil.copy2(path, backup)
            except OSError:
                pass
        path.write_text(json.dumps(store, indent=2) + "\n", encoding="utf-8")
    return changed


def looks_like_matter_id(value: str) -> bool:
    if not isinstance(value, str) or ":" not in value:
        return False
    if value.startswith("unifi:") or value.startswith("scene:"):
        return False
    node_s, rest = value.split(":", 1)
    if not node_s.isdigit() or not rest.isdigit():
        return False
    if len(node_s) == 2 and len(rest) == 2:
        hour = int(node_s)
        minute = int(rest)
        if 0 <= hour <= 23 and 0 <= minute <= 59:
            return False
    return True


def remap_automations_node_id(store: dict[str, Any], old_node: int, new_node: int) -> int:
    """Rewrite Matter device ids in home-automations.json without touching clock times."""
    if old_node == new_node or old_node <= 0 or new_node <= 0:
        return 0
    changed = 0

    def walk(value: Any) -> Any:
        nonlocal changed
        if isinstance(value, dict):
            items = list(value.items())
            value.clear()
            for key, item in items:
                new_key = key
                if looks_like_matter_id(str(key)):
                    rewritten = rewrite_device_id(str(key), old_node, new_node)
                    if rewritten != key:
                        changed += 1
                        new_key = rewritten
                value[new_key] = walk(item)
            return value
        if isinstance(value, list):
            for index, item in enumerate(value):
                value[index] = walk(item)
            return value
        if isinstance(value, str) and looks_like_matter_id(value):
            rewritten = rewrite_device_id(value, old_node, new_node)
            if rewritten != value:
                changed += 1
            return rewritten
        return value

    walk(store)
    return changed


def persist_remapped_automations(old_node: int, new_node: int) -> int:
    path = ROOT / "data" / "home-automations.json"
    try:
        store = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        return 0
    if not isinstance(store, dict):
        return 0
    changed = remap_automations_node_id(store, old_node, new_node)
    if changed:
        path.write_text(json.dumps(store, indent=2) + "\n", encoding="utf-8")
    return changed


def persist_remapped_node(old_node: int, new_node: int) -> int:
    changed = persist_remapped_home(old_node, new_node)
    changed += persist_remapped_automations(old_node, new_node)
    cache = ROOT / "data" / "home-nodes-cache.json"
    try:
        blob = json.loads(cache.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        blob = None
    if isinstance(blob, dict):
        devices = blob.get("devices")
        n = 0
        if isinstance(devices, list):
            for row in devices:
                if not isinstance(row, dict):
                    continue
                if "id" in row and looks_like_matter_id(str(row.get("id") or "")):
                    new_id = rewrite_device_id(str(row.get("id")), old_node, new_node)
                    if new_id != row.get("id"):
                        row["id"] = new_id
                        n += 1
                try:
                    if int(row.get("node_id") or 0) == old_node:
                        row["node_id"] = new_node
                        n += 1
                except (TypeError, ValueError):
                    pass
        if n:
            cache.write_text(json.dumps(blob) + "\n", encoding="utf-8")
            changed += n
    return changed


def names_for_node(node_id: int) -> list[str]:
    prefix = f"{node_id}:"
    found: list[str] = []
    seen: set[str] = set()
    path = ROOT / "data" / "home.json"
    try:
        store = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        return []
    if not isinstance(store, dict):
        return []
    mapping = store.get("names")
    if isinstance(mapping, dict):
        for key, label in mapping.items():
            if str(key).startswith(prefix):
                name = str(label or "").strip()
                if name and name not in seen:
                    seen.add(name)
                    found.append(name)
    for row in store.get("last_devices") or []:
        if not isinstance(row, dict):
            continue
        try:
            if int(row.get("node_id") or 0) != node_id:
                continue
        except (TypeError, ValueError):
            continue
        name = str(row.get("name") or "").strip()
        if name and name not in seen:
            seen.add(name)
            found.append(name)
    return found


def friendly_unavailable_error(node_id: int, raw: Any = "") -> str:
    names = names_for_node(node_id)
    if len(names) == 1:
        who = names[0]
    elif names:
        who = ", ".join(names[:3])
        if len(names) > 3:
            who += " and others"
    else:
        who = f"Matter node {node_id}"
    detail = (
        f"{who} is not available yet (Matter node {node_id}). "
        "That number is the Hue Bridge or other Matter device the light sits on, not a room name."
    )
    extra = str(raw or "").strip()
    if extra and extra.lower() not in detail.lower():
        return detail
    return detail


def live_present_and_available() -> tuple[list[int], list[int]]:
    rpc = matter_rpc("get_nodes", timeout=8.0, channel=CMD_CHANNEL)
    if not rpc.get("ok"):
        return [], []
    present: list[int] = []
    available: list[int] = []
    for node in nodes_from_result(rpc.get("result")):
        if not isinstance(node, dict):
            continue
        try:
            node_id = int(node.get("node_id") or node.get("nodeId") or 0)
        except (TypeError, ValueError):
            node_id = 0
        if node_id <= 0:
            continue
        present.append(node_id)
        if node.get("available"):
            available.append(node_id)
    return present, available


def replacement_node_id(
    wanted: int,
    present: list[int] | None = None,
    available: list[int] | None = None,
) -> int | None:
    """If the saved node is gone and exactly one other Matter node is live, use that."""
    if wanted <= 0:
        return None
    if present is None or available is None:
        present, available = live_present_and_available()
    if wanted in available:
        return None
    if wanted in present:
        return None
    others = [node_id for node_id in available if node_id != wanted]
    if len(others) == 1:
        return others[0]
    return None


def recover_unavailable_node(node_id: int) -> int:
    """Interview the saved node, or remap Home + automations onto the live Hue node."""
    replacement = replacement_node_id(node_id)
    if replacement:
        persist_remapped_node(node_id, replacement)
        print(f"matter: remapped node {node_id} -> {replacement} (live fabric)", flush=True)
        return replacement
    interview_node_ids([node_id])
    if wait_node_available(node_id, 15.0):
        return node_id
    replacement = replacement_node_id(node_id)
    if replacement:
        persist_remapped_node(node_id, replacement)
        print(f"matter: remapped node {node_id} -> {replacement} after interview", flush=True)
        return replacement
    return node_id


def node_ids_from_home_store() -> list[int]:
    ids: set[int] = set()
    home = ROOT / "data" / "home.json"
    cache = ROOT / "data" / "home-nodes-cache.json"
    blobs: list[Any] = []
    for path in (home, cache):
        try:
            blobs.append(json.loads(path.read_text(encoding="utf-8")))
        except (OSError, UnicodeDecodeError, json.JSONDecodeError):
            continue
    for data in blobs:
        if not isinstance(data, dict):
            continue
        for key in ("names", "rooms", "groups"):
            mapping = data.get(key)
            if isinstance(mapping, dict):
                for device_id in mapping:
                    if isinstance(device_id, str) and ":" in device_id:
                        try:
                            ids.add(int(device_id.split(":", 1)[0]))
                        except ValueError:
                            pass
        for row in data.get("last_devices") or data.get("devices") or []:
            if isinstance(row, dict):
                try:
                    node_id = int(row.get("node_id") or 0)
                except (TypeError, ValueError):
                    node_id = 0
                if node_id > 0:
                    ids.add(node_id)
        for scene in data.get("scenes") or []:
            if not isinstance(scene, dict):
                continue
            for action in scene.get("actions") or []:
                if not isinstance(action, dict):
                    continue
                device_id = str(action.get("id") or "")
                if ":" in device_id:
                    try:
                        ids.add(int(device_id.split(":", 1)[0]))
                    except ValueError:
                        pass
        for device_id in data.get("device_order") or []:
            if isinstance(device_id, str) and ":" in device_id:
                try:
                    ids.add(int(device_id.split(":", 1)[0]))
                except ValueError:
                    pass
        paper = data.get("paper")
        if isinstance(paper, dict):
            for lst in paper.values():
                if not isinstance(lst, list):
                    continue
                for device_id in lst:
                    if isinstance(device_id, str) and ":" in device_id:
                        try:
                            ids.add(int(device_id.split(":", 1)[0]))
                        except ValueError:
                            pass
    return sorted(i for i in ids if i > 0)


def fabric_json_path() -> Path | None:
    best: Path | None = None
    best_size = -1
    for path in storage_json_files():
        name = path.name.lower()
        if name.startswith("chip.json") or name.endswith(".backup"):
            continue
        try:
            size = path.stat().st_size
        except OSError:
            continue
        if size > best_size:
            best = path
            best_size = size
    return best


def load_fabric_json() -> tuple[Path | None, dict[str, Any]]:
    path = fabric_json_path()
    if path is None:
        return None, {}
    raw = read_storage_file(path)
    if not raw:
        return path, {}
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        return path, {}
    return path, data if isinstance(data, dict) else {}


def fabric_node_map(data: dict[str, Any]) -> dict[str, Any]:
    existing = data.get("nodes")
    return dict(existing) if isinstance(existing, dict) else {}


def fabric_needs_node_restore(node_ids: list[int] | None = None) -> bool:
    wanted = node_ids if node_ids is not None else node_ids_from_home_store()
    if not wanted:
        return False
    _path, data = load_fabric_json()
    nodes = fabric_node_map(data)
    for node_id in wanted:
        row = nodes.get(str(node_id))
        if not isinstance(row, dict):
            return True
    return False


def restore_nodes_into_fabric() -> list[int]:
    """Re-insert Matter node stubs so python-matter-server can interview the Hue fabric."""
    node_ids = node_ids_from_home_store()
    path, data = load_fabric_json()
    if not node_ids or path is None or not data:
        return []
    nodes = fabric_node_map(data)
    changed = False
    now = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
    written: list[int] = []
    for node_id in node_ids:
        key = str(node_id)
        row = nodes.get(key)
        attrs = row.get("attributes") if isinstance(row, dict) else None
        if isinstance(attrs, dict) and attrs:
            continue
        nodes[key] = {
            "node_id": node_id,
            "date_commissioned": now,
            "last_interview": now,
            "interview_version": 0,
            "available": False,
            "is_bridge": False,
            "attributes": {},
            "attribute_subscriptions": [],
        }
        written.append(node_id)
        changed = True
    if not changed:
        return []
    data["nodes"] = nodes
    try:
        current_last = int(data.get("last_node_id") or 0)
    except (TypeError, ValueError):
        current_last = 0
    data["last_node_id"] = max(current_last, max(node_ids))
    backup = Path(str(path) + ".nodes-restore")
    payload = json.dumps(data, separators=(",", ":"))
    if not backup.exists():
        try:
            shutil.copy2(path, backup)
        except OSError:
            raw = read_storage_file(path)
            if raw:
                write_storage_file(backup, raw)
    if not write_storage_file(path, payload):
        return []
    ensure_storage_readable()
    return written


def stop_matter_server() -> None:
    reset_ws()
    run_docker(["stop", DOCKER_NAME], timeout=25.0)


def start_matter_container() -> bool:
    inspect = run_docker(["inspect", "-f", "{{.Id}}", DOCKER_NAME], timeout=8.0)
    if inspect.returncode != 0:
        return False
    run_docker(["start", DOCKER_NAME], timeout=25.0)
    return wait_matter_port(30.0)


def reload_matter_server() -> bool:
    stop_matter_server()
    return start_matter_container()


def interview_node_ids(node_ids: list[int]) -> None:
    for node_id in node_ids:
        matter_rpc("interview_node", {"node_id": node_id}, timeout=45.0, channel=CMD_CHANNEL)


def live_node_ids() -> list[int]:
    rpc = matter_rpc("get_nodes", timeout=8.0, channel=CMD_CHANNEL)
    if not rpc.get("ok"):
        return []
    found: list[int] = []
    for node in nodes_from_result(rpc.get("result")):
        if not isinstance(node, dict):
            continue
        try:
            node_id = int(node.get("node_id") or node.get("nodeId") or 0)
        except (TypeError, ValueError):
            node_id = 0
        if node_id > 0:
            found.append(node_id)
    return found


def wait_node_available(node_id: int, timeout: float) -> bool:
    deadline = time.time() + timeout
    while time.time() < deadline:
        rpc = matter_rpc("get_node", {"node_id": node_id}, timeout=4.0, channel=CMD_CHANNEL)
        node = rpc.get("result") if rpc.get("ok") else None
        if isinstance(node, dict) and node.get("available"):
            return True
        time.sleep(1.0)
    return False


def wait_any_node_available(node_ids: list[int], timeout: float) -> bool:
    if not node_ids:
        return False
    deadline = time.time() + timeout
    while time.time() < deadline:
        rpc = matter_rpc("get_nodes", timeout=8.0, channel=CMD_CHANNEL)
        if rpc.get("ok"):
            wanted = set(node_ids)
            for node in nodes_from_result(rpc.get("result")):
                if not isinstance(node, dict) or not node.get("available"):
                    continue
                try:
                    node_id = int(node.get("node_id") or node.get("nodeId") or 0)
                except (TypeError, ValueError):
                    node_id = 0
                if node_id in wanted:
                    return True
        time.sleep(1.5)
    return False


def persist_node_stubs_and_reload() -> list[int]:
    """Stop the Matter container, write missing node stubs, then start it again."""
    if shared_matter_container():
        prefer_shared_matter_server()
        return []
    wanted = node_ids_from_home_store()
    if not wanted:
        return []
    ensure_matter_server()
    with _restore_lock:
        live = live_node_ids()
        need_write = fabric_needs_node_restore(wanted)
        if not need_write and live:
            return wanted
        stop_matter_server()
        restore_nodes_into_fabric()
        start_matter_container()
    return wanted


def node_unavailable(result: dict[str, Any]) -> bool:
    err = str(result.get("error") or "").lower()
    return "not (yet) available" in err or "is not available" in err


def begin_command() -> None:
    global _command_busy
    with _command_busy_lock:
        _command_busy += 1


def end_command() -> None:
    global _command_busy
    with _command_busy_lock:
        _command_busy = max(0, _command_busy - 1)


def command_in_flight() -> bool:
    with _command_busy_lock:
        return _command_busy > 0


def command_with_reconnect(node_id: int, send) -> dict[str, Any]:
    begin_command()
    try:
        result = _invoke_send(send, node_id)
        if result.get("ok") or not node_unavailable(result):
            return result
        start_background_recover()
        use_id = recover_unavailable_node(node_id)
        _recover_done.wait(timeout=20.0)
        wait_node_available(use_id, 8.0)
        result = _invoke_send(send, use_id)
        if result.get("ok") or not node_unavailable(result):
            return result
        return {
            "ok": False,
            "error": friendly_unavailable_error(use_id, result.get("error")),
        }
    finally:
        end_command()


def _invoke_send(send, node_id: int) -> dict[str, Any]:
    try:
        return send(node_id)
    except TypeError:
        return send()


def docker_data_mount() -> str:
    inspect = run_docker(
        [
            "inspect",
            "-f",
            '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}',
            DOCKER_NAME,
        ]
    )
    if inspect.returncode != 0:
        return ""
    return (inspect.stdout or "").strip()


def copy_container_storage() -> None:
    STORAGE.mkdir(parents=True, exist_ok=True)
    run_docker(["cp", f"{DOCKER_NAME}:/data/.", str(STORAGE)])
    ensure_storage_readable()


def wait_matter_port(seconds: float = 25.0) -> bool:
    deadline = time.time() + seconds
    while time.time() < deadline:
        if matter_port_up():
            return True
        time.sleep(0.4)
    return False


def matter_port_up() -> bool:
    probe = socket.socket()
    probe.settimeout(0.2)
    try:
        probe.connect((HOST, SERVER_PORT))
        return True
    except OSError:
        return False
    finally:
        probe.close()


def primary_interface() -> str:
    env = (os.environ.get("YARBO_MATTER_PRIMARY_INTERFACE") or "").strip()
    if env:
        return env
    try:
        out = subprocess.run(
            ["ip", "-4", "route", "show", "default"],
            capture_output=True,
            text=True,
            timeout=3,
        )
        parts = (out.stdout or "").split()
        if "dev" in parts:
            return parts[parts.index("dev") + 1]
    except (OSError, subprocess.TimeoutExpired, ValueError, IndexError):
        pass
    return "eth0"


def ensure_ipv6_default_route() -> None:
    """CHIP mDNS needs an IPv6 route even on IPv4-only ISPs (link-local on LAN)."""
    iface = primary_interface()
    if not iface:
        return
    try:
        shown = subprocess.run(
            ["ip", "-6", "route", "show", "default"],
            capture_output=True,
            text=True,
            timeout=3,
        )
        if (shown.stdout or "").strip():
            return
    except (OSError, subprocess.TimeoutExpired):
        return
    candidates = [
        ["ip", "-6", "route", "add", "default", "dev", iface],
        ["sudo", "-n", "ip", "-6", "route", "add", "default", "dev", iface],
        ["sudo", "-n", "/usr/sbin/ip", "-6", "route", "add", "default", "dev", iface],
        ["sudo", "-n", "/usr/local/sbin/yarbo-matter-setup", "ipv6-route"],
    ]
    for cmd in candidates:
        try:
            r = subprocess.run(cmd, capture_output=True, text=True, timeout=5)
        except (OSError, subprocess.TimeoutExpired):
            continue
        if r.returncode == 0:
            return


def matter_container_args() -> list[str]:
    return [
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
        "--storage-path",
        "/data",
        "--paa-root-cert-dir",
        "/data/credentials",
        "--primary-interface",
        primary_interface(),
    ]


def recreate_matter_container() -> None:
    if shared_matter_container():
        prefer_shared_matter_server()
        return
    reset_ws()
    ensure_ipv6_default_route()
    run_docker(["stop", DOCKER_NAME])
    run_docker(["rm", DOCKER_NAME])
    run_docker(matter_container_args())
    wait_matter_port(30.0)


def recover_docker_storage() -> str:
    global _recovered_storage
    if _recovered_storage:
        return ""
    _recovered_storage = True
    inspect = run_docker(["inspect", "-f", "{{.Id}}", DOCKER_NAME])
    note = ""
    restored = restore_chip_backups()
    ensure_storage_readable()
    if inspect.returncode != 0:
        return "chip.json restored from backup" if restored else ""
    host_files = [p for p in storage_json_files() if p.stat().st_size > 32]
    if not host_files:
        copy_container_storage()
        restore_chip_backups()
        host_files = [p for p in storage_json_files() if p.stat().st_size > 32]
        if host_files:
            note = "Copied Matter storage out of Docker"
    mount = docker_data_mount()
    mount_ok = False
    if mount:
        try:
            mount_ok = Path(mount).resolve() == STORAGE.resolve()
        except OSError:
            mount_ok = False
    if restored or not mount_ok:
        recreate_matter_container()
        if not note:
            note = "Remounted Matter storage so the Hue fabric loads"
    return note


def start_background_recover() -> None:
    global _recover_thread_started, _recover_last_at
    now = time.time()
    with _recover_lock:
        if _recover_thread_started and not _recover_done.is_set():
            return
        if _recover_thread_started and (now - _recover_last_at) < 60.0:
            return
        _recover_thread_started = True
        _recover_last_at = now
        _recover_done.clear()
    threading.Thread(target=_background_recover, daemon=True).start()


def _background_recover() -> None:
    try:
        note = recover_docker_storage()
        if note:
            print(f"matter recover: {note}", flush=True)
        restored = persist_node_stubs_and_reload()
        if restored:
            print(f"matter recover: node stubs {restored}", flush=True)
            if not wait_any_node_available(restored, 35.0):
                interview_node_ids(restored)
                wait_any_node_available(restored, 20.0)
        rpc = matter_rpc("get_nodes", timeout=20.0, channel=CMD_CHANNEL)
        nodes = nodes_from_result(rpc.get("result")) if rpc.get("ok") else []
        if nodes and not flatten_nodes(nodes):
            ids: list[int] = []
            for node in nodes:
                try:
                    node_id = int(node.get("node_id") or node.get("nodeId") or 0)
                except (TypeError, ValueError):
                    node_id = 0
                if node_id > 0:
                    ids.append(node_id)
            interview_node_ids(ids)
    except Exception as exc:  # noqa: BLE001
        print(f"matter recover failed: {exc}", flush=True)
    finally:
        _recover_done.set()


def looks_like_uninterviewed_stub(devices: list[dict[str, Any]]) -> bool:
    if not devices:
        return False
    for row in devices:
        if not isinstance(row, dict):
            return False
        name = str(row.get("name") or "")
        vendor = str(row.get("vendor") or "").strip()
        product = str(row.get("product") or "").strip()
        if not name.startswith("Matter node ") or vendor or product:
            return False
    return True


def remember_live_devices(devices: list[dict[str, Any]]) -> None:
    global _live_devices
    if not devices or looks_like_uninterviewed_stub(devices):
        return
    now = time.time()
    with _live_lock:
        previous = {
            str(row.get("id") or ""): row
            for row in _live_devices
            if isinstance(row, dict)
        }
        merged: list[dict[str, Any]] = []
        for row in devices:
            if not isinstance(row, dict):
                continue
            item = dict(row)
            device_id = str(item.get("id") or "")
            prev = previous.get(device_id)
            patched_at = float((prev or {}).get("_patched_at") or 0)
            if prev is not None and patched_at > 0 and (now - patched_at) < STICKY_HOLD:
                keys = prev.get("_sticky_keys") or STICKY_STATE_KEYS
                for key in keys:
                    name = str(key)
                    if name.startswith("_"):
                        continue
                    if name in prev:
                        item[name] = prev[name]
                item["_patched_at"] = patched_at
                item["_sticky_keys"] = [str(key) for key in keys if not str(key).startswith("_")]
            merged.append(item)
        if merged:
            _live_devices = merged


def current_live_devices() -> list[dict[str, Any]]:
    with _live_lock:
        return [dict(row) for row in _live_devices]


def patch_live_device(device_id: str, fields: dict[str, Any], *, sticky: bool = False) -> dict[str, Any] | None:
    if device_id == "" or not fields:
        return None
    now = time.time()
    with _live_lock:
        payload = {key: value for key, value in fields.items() if key not in ("_patched_at", "_sticky_keys")}
        if not payload:
            return None
        for row in _live_devices:
            if str(row.get("id") or "") != device_id:
                continue
            if sticky:
                sticky_keys = list(payload.keys())
                payload["_patched_at"] = now
                payload["_sticky_keys"] = sticky_keys
            else:
                patched_at = float(row.get("_patched_at") or 0)
                sticky_keys = {str(key) for key in (row.get("_sticky_keys") or [])}
                if patched_at > 0 and (now - patched_at) < STICKY_HOLD:
                    for key in list(payload.keys()):
                        if key in sticky_keys and key in row and row[key] != payload[key]:
                            payload.pop(key, None)
                    if not payload:
                        return dict(row)
                    row.update(payload)
                    return dict(row)
                payload["_patched_at"] = 0
                payload["_sticky_keys"] = []
            row.update(payload)
            return dict(row)
        seeded = {"id": device_id, **payload}
        if sticky:
            seeded["_patched_at"] = now
            seeded["_sticky_keys"] = [key for key in payload.keys()]
        _live_devices.append(seeded)
        return dict(seeded)


def refresh_live_devices(timeout: float = 5.0) -> list[dict[str, Any]]:
    ensure_listening()
    return current_live_devices()


COLOR_POLL_ATTRS = (
    ATTR_CURRENT_HUE,
    ATTR_CURRENT_SATURATION,
    ATTR_CURRENT_X,
    ATTR_CURRENT_Y,
    ATTR_COLOR_TEMP_MIREDS,
    ATTR_COLOR_MODE,
    ATTR_ENHANCED_CURRENT_HUE,
    ATTR_ENHANCED_COLOR_MODE,
)


def apply_read_attributes(node_id: int, values: Any) -> None:
    if isinstance(values, dict) and "result" in values and not any(
        "/" in str(key) for key in values if key != "result"
    ):
        values = values.get("result")
    if isinstance(values, list):
        for item in values:
            if isinstance(item, dict):
                event = dict(item)
                event.setdefault("node_id", node_id)
                apply_attribute_event(event)
            elif isinstance(item, (list, tuple)) and len(item) >= 2:
                if len(item) >= 3:
                    apply_attribute_event(item)
                else:
                    apply_attribute_event([node_id, item[0], item[1]])
        return
    if not isinstance(values, dict):
        return
    for path, value in values.items():
        apply_attribute_event([node_id, str(path), value])


def light_poll_groups() -> dict[int, list[int]]:
    groups: dict[int, list[int]] = {}
    for row in current_live_devices():
        if not isinstance(row, dict):
            continue
        kind = str(row.get("kind") or "")
        if kind in ("switch", "vacuum", "plug", "heater", "sensor", "camera", "door", "hub"):
            continue
        try:
            node_id = int(row.get("node_id") or 0)
            endpoint = int(row.get("endpoint") or 0)
        except (TypeError, ValueError):
            node_id = 0
            endpoint = 0
        if node_id <= 0 or endpoint <= 0:
            try:
                node_s, ep_s = str(row.get("id") or "").split(":", 1)
                node_id, endpoint = int(node_s), int(ep_s)
            except (TypeError, ValueError):
                continue
        if node_id <= 0 or endpoint <= 0:
            continue
        bucket = groups.setdefault(node_id, [])
        if endpoint not in bucket:
            bucket.append(endpoint)
    return groups


def refresh_live_from_nodes() -> bool:
    rpc = matter_rpc("get_nodes", timeout=15.0, channel=POLL_CHANNEL, listen=False)
    if not rpc.get("ok"):
        return False
    devices = flatten_nodes(nodes_from_result(rpc.get("result")))
    if not devices:
        return False
    remember_live_devices(devices)
    return True


def poll_light_attributes() -> None:
    if command_in_flight():
        return
    groups = light_poll_groups()
    if not groups:
        if not refresh_live_from_nodes():
            return
        groups = light_poll_groups()
        if not groups:
            return
    any_ok = False
    for node_id, endpoints in groups.items():
        if command_in_flight():
            return
        on_paths = [attr_key(endpoint, ON_OFF, ATTR_ON_OFF) for endpoint in endpoints]
        level_paths = [attr_key(endpoint, LEVEL_CONTROL, ATTR_CURRENT_LEVEL) for endpoint in endpoints]
        color_paths: list[str] = []
        for endpoint in endpoints:
            color_paths.extend(attr_key(endpoint, COLOR_CONTROL, attr) for attr in COLOR_POLL_ATTRS)
        for paths in (on_paths, level_paths, color_paths):
            if not paths:
                continue
            rpc = matter_rpc(
                "read_attribute",
                {"node_id": node_id, "attribute_path": paths},
                timeout=12.0,
                channel=POLL_CHANNEL,
                listen=False,
            )
            if not rpc.get("ok"):
                err = str(rpc.get("error") or "").lower()
                if "timed" in err or "timeout" in err:
                    continue
                if paths is color_paths:
                    for endpoint in endpoints:
                        one = [attr_key(endpoint, COLOR_CONTROL, attr) for attr in COLOR_POLL_ATTRS]
                        one_rpc = matter_rpc(
                            "read_attribute",
                            {"node_id": node_id, "attribute_path": one},
                            timeout=6.0,
                            channel=POLL_CHANNEL,
                            listen=False,
                        )
                        if one_rpc.get("ok"):
                            apply_read_attributes(node_id, one_rpc.get("result"))
                            any_ok = True
                continue
            apply_read_attributes(node_id, rpc.get("result"))
            any_ok = True
    if any_ok:
        return
    refresh_live_from_nodes()


def start_state_poll() -> None:
    global _state_poll_started
    if _state_poll_started:
        return
    _state_poll_started = True

    def loop() -> None:
        while True:
            try:
                # Listen dump can take tens of seconds on a Hue Bridge. Do not
                # wait for it here or Apple Home On/Off never gets polled.
                kick_listening()
                poll_light_attributes()
            except Exception as exc:  # noqa: BLE001
                print(f"matter state poll failed: {exc}", flush=True)
                reset_ws(POLL_CHANNEL)
            time.sleep(3.0)

    threading.Thread(target=loop, daemon=True).start()


def collect_nodes(quick: bool = True) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    start_background_recover()
    info: dict[str, Any] = {
        "source": "",
        "storage_files": [p.name for p in storage_json_files()],
        "storage_nodes": 0,
        "hint": "",
        "recover": "",
    }
    disk = nodes_from_disk()
    info["storage_nodes"] = len(disk)
    if quick and disk:
        info["source"] = "disk"
        return disk, info
    rpc = matter_rpc("get_nodes", timeout=15.0 if quick else 45.0, channel=CMD_CHANNEL)
    nodes = nodes_from_result(rpc.get("result")) if rpc.get("ok") else []
    if nodes:
        info["source"] = "live"
    if not nodes and disk:
        nodes = disk
        info["source"] = "disk"
    if not quick and not nodes:
        try:
            ensure_listening()
        except Exception as exc:  # noqa: BLE001
            print(f"matter listen during collect failed: {exc}", flush=True)
        if not nodes and disk:
            nodes = disk
            info["source"] = "disk"
        if nodes and not flatten_nodes(nodes):
            for node in nodes:
                try:
                    node_id = int(node.get("node_id") or node.get("nodeId") or 0)
                except (TypeError, ValueError):
                    node_id = 0
                if node_id > 0:
                    matter_rpc("interview_node", {"node_id": node_id}, timeout=40.0, channel=CMD_CHANNEL)
            rpc = matter_rpc("get_nodes", timeout=45.0, channel=CMD_CHANNEL)
            if rpc.get("ok"):
                interviewed = nodes_from_result(rpc.get("result"))
                if interviewed:
                    nodes = interviewed
                    info["source"] = "interview"
    if not nodes:
        info["hint"] = (
            "The Matter fabric on this Pi has no saved nodes. "
            "Add the Hue Bridge pairing code once (Hue app → Settings → Smart Home → Matter). "
            "Room names and scenes are still saved."
        )
    return nodes, info


def shared_matter_container() -> str | None:
    inspect = run_docker(["inspect", "-f", "{{.Id}}", SHARED_MATTER_NAME], timeout=8.0)
    if inspect.returncode == 0 and (inspect.stdout or "").strip():
        return SHARED_MATTER_NAME
    return None


def prefer_shared_matter_server() -> None:
    """Home Assistant's matter-server already owns the working Hue fabric. Do not steal 5580."""
    global _preferred_shared
    if _preferred_shared:
        return
    shared = shared_matter_container()
    if not shared:
        return
    _preferred_shared = True
    yarbo = run_docker(["inspect", "-f", "{{.State.Running}}", DOCKER_NAME], timeout=8.0)
    if yarbo.returncode == 0 and (yarbo.stdout or "").strip().lower() == "true":
        print(
            "matter: stopping yarbo-matter-server so Home Assistant can keep port 5580",
            flush=True,
        )
        run_docker(["stop", DOCKER_NAME], timeout=25.0)
        reset_ws()
    run_docker(["start", shared], timeout=25.0)
    wait_matter_port(35.0)


def ensure_matter_server() -> str | None:
    global _started_docker
    if matter_port_up():
        return None
    prefer_shared_matter_server()
    if matter_port_up():
        return None

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
        ensure_ipv6_default_route()
        inspect = run_docker(["inspect", "-f", "{{.State.Running}}", DOCKER_NAME])
        if inspect.returncode == 0:
            running = (inspect.stdout or "").strip().lower() == "true"
            if not running:
                run_docker(["start", DOCKER_NAME])
        else:
            run_docker(matter_container_args())
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


def _channel_name(channel: str | None) -> str:
    if channel in (LISTEN_CHANNEL, POLL_CHANNEL, CMD_CHANNEL):
        return str(channel)
    return CMD_CHANNEL


def connected_ws(channel: str = CMD_CHANNEL) -> MatterWs:
    channel = _channel_name(channel)
    existing = _ws_slots.get(channel)
    if existing is not None:
        return existing
    with _ws_connect_locks[channel]:
        existing = _ws_slots.get(channel)
        if existing is not None:
            return existing
        err = ensure_matter_server()
        if err:
            raise RuntimeError(err)
        client = ws_connect()
        _ws_slots[channel] = client
        start_recv_loop(channel)
        return client


def fail_pending(error: str, channel: str | None = None) -> None:
    with _pending_lock:
        if channel is None:
            waiters = list(_pending.values())
            _pending.clear()
        else:
            waiters = []
            for mid, waiter in list(_pending.items()):
                if waiter.get("channel") == channel:
                    waiters.append(waiter)
                    _pending.pop(mid, None)
    for waiter in waiters:
        waiter["error"] = error
        waiter["event"].set()


def reset_ws(channel: str | None = None) -> None:
    global _listening
    names = [_channel_name(channel)] if channel else [CMD_CHANNEL, LISTEN_CHANNEL, POLL_CHANNEL]
    if LISTEN_CHANNEL in names:
        _listening = False
    for name in names:
        client = _ws_slots.get(name)
        _ws_slots[name] = None
        if client is not None:
            try:
                client.sock.close()
            except OSError:
                pass
        fail_pending("Matter server unavailable", name)


def start_recv_loop(channel: str) -> None:
    channel = _channel_name(channel)
    if _recv_started.get(channel):
        return
    _recv_started[channel] = True

    def loop() -> None:
        while True:
            client = _ws_slots.get(channel)
            if client is None:
                time.sleep(0.15)
                continue
            try:
                if _ws_slots.get(channel) is not client:
                    time.sleep(0.05)
                    continue
                msg = client.recv_json(1.0)
            except Exception:  # noqa: BLE001
                if _ws_slots.get(channel) is client:
                    reset_ws(channel)
                time.sleep(0.4)
                continue
            if _ws_slots.get(channel) is not client:
                continue
            if msg is None:
                if _ws_slots.get(channel) is client:
                    try:
                        client.sock.getpeername()
                    except OSError:
                        reset_ws(channel)
                continue
            ingest_ws_message(msg)
            mid = str(msg.get("message_id") or "")
            if mid == "":
                continue
            with _pending_lock:
                waiter = _pending.get(mid)
            if waiter is not None:
                waiter["msg"] = msg
                waiter["event"].set()

    threading.Thread(target=loop, daemon=True, name=f"matter-recv-{channel}").start()


def kick_listening() -> None:
    global _listen_starting
    if _listening and _ws_slots.get(LISTEN_CHANNEL) is not None:
        return
    with _listen_start_lock:
        if _listening and _ws_slots.get(LISTEN_CHANNEL) is not None:
            return
        if _listen_starting:
            return
        _listen_starting = True
    threading.Thread(target=_listen_worker, daemon=True).start()


def _listen_worker() -> None:
    global _listen_starting
    try:
        ensure_listening()
    except Exception as exc:  # noqa: BLE001
        print(f"matter listen failed: {exc}", flush=True)
        reset_ws(LISTEN_CHANNEL)
    finally:
        with _listen_start_lock:
            _listen_starting = False


def ensure_listening() -> None:
    global _listening
    with _listen_lock:
        if _listening and _ws_slots.get(LISTEN_CHANNEL) is not None:
            return
        connected_ws(LISTEN_CHANNEL)
        rpc = matter_rpc("start_listening", timeout=45.0, channel=LISTEN_CHANNEL, listen=False)
        if not rpc.get("ok"):
            reset_ws(LISTEN_CHANNEL)
            raise RuntimeError(str(rpc.get("error") or "Matter listen failed"))
        devices = flatten_nodes(nodes_from_result(rpc.get("result")))
        remember_live_devices(devices)
        _listening = True


def matter_rpc(
    command: str,
    args: dict[str, Any] | None = None,
    timeout: float = 20.0,
    channel: str = CMD_CHANNEL,
    listen: bool = True,
    retries: int = 2,
) -> dict[str, Any]:
    if command == "start_listening":
        channel = LISTEN_CHANNEL
        listen = False
    else:
        channel = _channel_name(channel)
        if channel == LISTEN_CHANNEL and command != "start_listening":
            channel = CMD_CHANNEL
    payload: dict[str, Any] = {"command": command}
    if args:
        payload["args"] = args
    last_err: Exception | str | None = None
    attempt_count = max(1, int(retries))
    for attempt in range(attempt_count):
        message_id = uuid.uuid4().hex[:12]
        payload["message_id"] = message_id
        waiter: dict[str, Any] = {
            "event": threading.Event(),
            "msg": None,
            "error": None,
            "channel": channel,
        }
        try:
            if listen:
                kick_listening()
            client = connected_ws(channel)
            with _pending_lock:
                _pending[message_id] = waiter
            with _ws_send_locks[channel]:
                client.send_json(payload)
            if not waiter["event"].wait(timeout):
                last_err = "Matter server timed out"
                with _pending_lock:
                    _pending.pop(message_id, None)
                reset_ws(channel)
                continue
            with _pending_lock:
                _pending.pop(message_id, None)
            if waiter.get("error"):
                last_err = str(waiter["error"])
                if attempt == 0:
                    reset_ws(channel)
                continue
            msg = waiter.get("msg")
            if not isinstance(msg, dict):
                last_err = "Matter server unavailable"
                reset_ws(channel)
                continue
            if "error_code" in msg or "error" in msg:
                err = msg.get("details") or msg.get("error") or msg.get("error_code")
                return {"ok": False, "error": str(err)}
            return {"ok": True, "result": msg.get("result")}
        except Exception as exc:  # noqa: BLE001
            last_err = exc
            with _pending_lock:
                _pending.pop(message_id, None)
            reset_ws(channel)
    return {"ok": False, "error": str(last_err) if last_err else "Matter server unavailable"}


def parse_attribute_event(data: Any) -> tuple[int, str, Any] | None:
    node_id = 0
    path = ""
    value: Any = None
    if isinstance(data, dict):
        try:
            node_id = int(data.get("node_id") or data.get("nodeId") or 0)
        except (TypeError, ValueError):
            node_id = 0
        path = str(data.get("attribute_path") or data.get("path") or data.get("attributePath") or "")
        value = data.get("value")
        if "new_value" in data:
            value = data.get("new_value")
    elif isinstance(data, (list, tuple)) and len(data) >= 3:
        try:
            node_id = int(data[0] or 0)
        except (TypeError, ValueError):
            node_id = 0
        path = str(data[1] or "")
        value = data[2]
    else:
        return None
    if isinstance(data, dict) and path == "":
        try:
            endpoint = int(data.get("endpoint") or data.get("endpoint_id") or 0)
            cluster = int(data.get("cluster") or data.get("cluster_id") or 0)
            attr = int(data.get("attribute") or data.get("attribute_id") or 0)
        except (TypeError, ValueError):
            endpoint = cluster = attr = 0
        if endpoint > 0:
            path = f"{endpoint}/{cluster}/{attr}"
    if node_id <= 0 or path == "":
        return None
    return node_id, path, value


def attr_path_parts(path: str) -> tuple[int, int, int] | None:
    nums: list[int] = []
    for part in str(path).replace(".", "/").split("/"):
        part = part.strip()
        if part == "":
            continue
        try:
            nums.append(int(part))
        except ValueError:
            return None
    if len(nums) >= 4:
        return nums[-3], nums[-2], nums[-1]
    if len(nums) >= 3:
        return nums[0], nums[1], nums[2]
    return None


def event_number(value: Any) -> float | None:
    if value is None or isinstance(value, bool):
        return None
    if isinstance(value, (int, float)):
        return float(value)
    if isinstance(value, str):
        try:
            return float(value.strip())
        except ValueError:
            return None
    if isinstance(value, dict):
        for key in ("value", "Value", "current_level", "CurrentLevel", 0, "0"):
            if key in value:
                found = event_number(value[key])
                if found is not None:
                    return found
        return None
    if isinstance(value, (list, tuple)) and value:
        return event_number(value[0])
    return None


def live_device_snapshot(device_id: str) -> dict[str, Any]:
    with _live_lock:
        for row in _live_devices:
            if str(row.get("id") or "") == device_id:
                return dict(row)
    return {"id": device_id}


def color_hex_from_row(row: dict[str, Any]) -> str | None:
    mode_raw = row.get("_color_mode")
    try:
        mode = int(mode_raw) if mode_raw is not None else None
    except (TypeError, ValueError):
        mode = None
    hue = row.get("_hue_254")
    sat = row.get("_sat_254")
    if hue is None and row.get("hue") is not None:
        try:
            hue = float(row["hue"]) * 254 / 360
        except (TypeError, ValueError):
            hue = None
    if sat is None and row.get("saturation") is not None:
        try:
            sat = float(row["saturation"]) * 254 / 100
        except (TypeError, ValueError):
            sat = None
    x = row.get("_color_x")
    y = row.get("_color_y")
    mireds = row.get("_mireds")
    kelvin: int | None = None
    if mireds is not None:
        kelvin = mireds_to_kelvin(mireds)
    elif row.get("color_temp") is not None:
        try:
            kelvin = int(row["color_temp"])
        except (TypeError, ValueError):
            kelvin = None
    if mode in (0, 3) and hue is not None and sat is not None:
        return hs_to_hex(hue, sat)
    if mode == 1 and x is not None and y is not None:
        return xy_to_hex(x, y)
    if mode == 2 and kelvin is not None:
        return kelvin_to_hex(kelvin)
    if hue is not None and sat is not None:
        return hs_to_hex(hue, sat)
    if x is not None and y is not None:
        return xy_to_hex(x, y)
    if kelvin is not None:
        return kelvin_to_hex(kelvin)
    return None


def apply_color_attribute(device_id: str, attr: int, value: Any) -> dict[str, Any]:
    fields: dict[str, Any] = {}
    number = event_number(value)
    if attr == ATTR_CURRENT_HUE and number is not None:
        fields["_hue_254"] = number
        fields["hue"] = clamp_int(number * 360 / 254, 0, 360)
    elif attr == ATTR_ENHANCED_CURRENT_HUE and number is not None:
        hue_254 = number / 256.0
        fields["_hue_254"] = hue_254
        fields["hue"] = clamp_int(hue_254 * 360 / 254, 0, 360)
    elif attr == ATTR_CURRENT_SATURATION and number is not None:
        fields["_sat_254"] = number
        fields["saturation"] = clamp_int(number * 100 / 254, 0, 100)
    elif attr == ATTR_CURRENT_X and number is not None:
        fields["_color_x"] = number
    elif attr == ATTR_CURRENT_Y and number is not None:
        fields["_color_y"] = number
    elif attr == ATTR_COLOR_TEMP_MIREDS and number is not None:
        fields["_mireds"] = number
        fields["color_temp"] = mireds_to_kelvin(number)
    elif attr in (ATTR_COLOR_MODE, ATTR_ENHANCED_COLOR_MODE) and number is not None:
        fields["_color_mode"] = int(number)
    if not fields:
        return fields
    merged = live_device_snapshot(device_id)
    merged.update(fields)
    hex_s = color_hex_from_row(merged)
    if hex_s:
        fields["color_hex"] = hex_s
    return fields


def apply_attribute_event(data: Any) -> None:
    parsed = parse_attribute_event(data)
    if parsed is None:
        return
    node_id, path, value = parsed
    parts = attr_path_parts(path)
    if parts is None:
        return
    endpoint, cluster, attr = parts
    device_id = f"{node_id}:{endpoint}"
    fields: dict[str, Any] = {}
    if cluster == ON_OFF and attr == ATTR_ON_OFF:
        fields["on"] = attr_bool(value)
    elif cluster == THERMOSTAT and attr == ATTR_LOCAL_TEMPERATURE:
        fields["local_temperature"] = matter_celsius(value)
    elif cluster == THERMOSTAT and attr == ATTR_OCCUPIED_HEATING_SETPOINT:
        fields["heating_setpoint"] = matter_celsius(value)
    elif cluster == THERMOSTAT and attr == ATTR_SYSTEM_MODE:
        on_mode = system_mode_is_on(value)
        if on_mode is not None:
            fields["on"] = on_mode
            fields["system_mode"] = int(event_number(value) or 0)
    elif cluster == TEMP_MEASUREMENT and attr == ATTR_MEASURED_TEMP:
        fields["local_temperature"] = matter_celsius(value)
    elif cluster == LEVEL_CONTROL and attr == ATTR_CURRENT_LEVEL:
        level = event_number(value)
        if level is None:
            return
        # Hue keeps CurrentLevel at the last brightness while Off. Never infer On from it.
        fields["brightness"] = max(0, min(100, int(round(level * 100 / 254)))) if level else 0
    elif cluster == COLOR_CONTROL:
        fields = apply_color_attribute(device_id, attr, value)
    if fields:
        patch_live_device(device_id, fields)


def merge_live_nodes(nodes: list[dict[str, Any]]) -> None:
    devices = flatten_nodes(nodes)
    if not devices:
        return
    with _live_lock:
        by_id = {str(row.get("id") or ""): dict(row) for row in _live_devices if isinstance(row, dict)}
        for row in devices:
            if not isinstance(row, dict):
                continue
            device_id = str(row.get("id") or "")
            if device_id == "":
                continue
            prev = by_id.get(device_id, {})
            merged = dict(prev)
            merged.update(row)
            by_id[device_id] = merged
        _live_devices.clear()
        _live_devices.extend(by_id.values())


def ingest_ws_message(msg: dict[str, Any]) -> None:
    event = str(msg.get("event") or "")
    if event == "attribute_updated":
        apply_attribute_event(msg.get("data"))
        return
    if event in ("node_updated", "node_added"):
        data = msg.get("data")
        if isinstance(data, dict):
            merge_live_nodes([data])
        elif isinstance(data, list):
            merge_live_nodes([row for row in data if isinstance(row, dict)])


def looks_like_node(row: dict[str, Any]) -> bool:
    return "node_id" in row or "nodeId" in row or isinstance(row.get("attributes"), dict)


def collect_node_dicts(raw: Any, depth: int = 0) -> list[dict[str, Any]]:
    if depth > 6:
        return []
    if isinstance(raw, list):
        out: list[dict[str, Any]] = []
        for item in raw:
            out.extend(collect_node_dicts(item, depth + 1))
        return out
    if not isinstance(raw, dict):
        return []
    if looks_like_node(raw):
        return [raw]
    for key in ("nodes", "result", "data"):
        if key in raw:
            found = collect_node_dicts(raw[key], depth + 1)
            if found:
                return found
    nodeish = [v for v in raw.values() if isinstance(v, dict) and looks_like_node(v)]
    if nodeish:
        return nodeish
    out: list[dict[str, Any]] = []
    for value in raw.values():
        if isinstance(value, (dict, list)):
            out.extend(collect_node_dicts(value, depth + 1))
    return out


def nodes_from_result(raw: Any) -> list[dict[str, Any]]:
    return collect_node_dicts(raw)


def endpoint_ids(attributes: dict[str, Any]) -> set[int]:
    out: set[int] = set()
    for key in attributes:
        parsed = parse_attr_path(key)
        if parsed is not None:
            out.add(parsed[0])
            continue
        try:
            out.add(int(str(key).split("/", 1)[0], 0))
        except ValueError:
            continue
    return out


def device_type_id(item: Any) -> int | None:
    raw_id: Any = None
    if isinstance(item, (int, float)):
        raw_id = item
    elif isinstance(item, dict):
        for key in ("0", 0, "deviceType", "device_type", "DeviceType", "type"):
            if key in item:
                raw_id = item.get(key)
                break
    try:
        return int(raw_id) if raw_id is not None else None
    except (TypeError, ValueError):
        return None


def device_type_ids(types: Any) -> list[int]:
    ids: list[int] = []
    if isinstance(types, dict):
        types = types.get("value", types.get("0", [types]))
    if isinstance(types, (int, float)):
        types = [types]
    if not isinstance(types, list):
        return ids
    for item in types:
        parsed = device_type_id(item)
        if parsed is not None:
            ids.append(parsed)
    return ids


def name_looks_heater(text: str) -> bool:
    t = (text or "").lower()
    if re.search(r"\b(heater|radiator|thermostat|towel\s*rail)\b", t):
        return True
    return bool(re.search(r"\bmill\b", t) and re.search(r"\b(panel|wifi|wi-?fi|gen\s*\d)\b", t))


def name_looks_vacuum(text: str) -> bool:
    t = (text or "").lower()
    return bool(re.search(r"\b(vacuum|robot\s*vac|roborock|roomba)\b", t))


def classify_by_name(kind: str, *labels: str) -> str:
    text = " ".join(labels)
    if name_looks_vacuum(text):
        return "vacuum"
    if name_looks_heater(text):
        return "heater"
    return kind or "light"


def device_kind(types: Any) -> str:
    ids = device_type_ids(types)
    if any(i == DEVTYPE_RVC for i in ids):
        return "vacuum"
    if any(i in (DEVTYPE_THERMOSTAT, DEVTYPE_HEATING_COOLING) for i in ids):
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


def attr_bool(val: Any) -> bool:
    if val is None or val is False:
        return False
    if val is True:
        return True
    if isinstance(val, (int, float)) and not isinstance(val, bool):
        return val != 0
    if isinstance(val, str):
        s = val.strip().lower()
        if s in ("", "0", "false", "off", "no", "null", "none"):
            return False
        return s in ("1", "true", "on", "yes")
    if isinstance(val, dict):
        for key in ("value", "Value", "OnOff", "on", 0, "0"):
            if key in val:
                return attr_bool(val[key])
        return False
    if isinstance(val, (list, tuple)) and val:
        return attr_bool(val[0])
    return False


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
    color_hs = bool(cap_bits & (COLOR_CAP_HS | COLOR_CAP_EHUE))
    color_xy = bool(cap_bits & COLOR_CAP_XY)
    color_ct = bool(cap_bits & COLOR_CAP_CT)
    if not (color_hs or color_xy or color_ct):
        if extended:
            color_hs = True
            color_xy = True
            color_ct = True
        elif DEVTYPE_COLOR_LIGHT in type_ids:
            color_hs = True
            color_xy = True
        elif ct_type:
            color_ct = True
        elif has_cc and (ct_min is not None or ct_max is not None) and hue is None and x is None:
            # White ambiance: Color Control is present for CT only.
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
    def send(nid: int | None = None) -> dict[str, Any]:
        use = node_id if nid is None else nid
        rpc = matter_rpc(
            "device_command",
            {
                "node_id": use,
                "endpoint_id": endpoint,
                "cluster_id": cluster,
                "command_name": name,
                "payload": payload,
            },
            timeout=12.0,
        )
        return rpc if not rpc.get("ok") else {"ok": True}

    return command_with_reconnect(node_id, send)


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
            "not ready",
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
        if is_transport_error(last) or is_unsupported_cluster(last):
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
    on_result = device_command(node_id, endpoint, ON_OFF, "On", {})
    if is_transport_error(on_result):
        return on_result
    attempts: list[tuple[str, dict[str, Any]]] = []
    # Hue often applies XY more reliably than HS, and some stacks reject optionsMask.
    if xy is not None:
        attempts.append(("MoveToColor", {"colorX": xy[0], "colorY": xy[1], "transitionTime": 0}))
    attempts.append(
        ("MoveToHueAndSaturation", {"hue": hs[0], "saturation": hs[1], "transitionTime": 0})
    )
    attempts.append(
        (
            "MoveToHueAndSaturation",
            {"hue": hs[0], "saturation": hs[1], **color_transition(True)},
        )
    )
    attempts.append(
        (
            "EnhancedMoveToHueAndSaturation",
            {
                "enhancedHue": min(65535, hs[0] * 256),
                "saturation": hs[1],
                "transitionTime": 0,
            },
        )
    )
    if xy is not None:
        attempts.append(("MoveToColor", {"colorX": xy[0], "colorY": xy[1], **color_transition(True)}))
    result = try_color_commands(node_id, endpoint, attempts)
    if result.get("ok"):
        return {**result, "on": True, "color_hex": hex_s or None}
    if is_transport_error(result) or is_unsupported_cluster(result):
        return result
    fallback: list[tuple[str, dict[str, Any]]] = [
        (
            "MoveToHueAndSaturation",
            {"hue": hs[0], "saturation": hs[1], **color_transition(False)},
        ),
    ]
    if xy is not None:
        fallback.append(("MoveToColor", {"colorX": xy[0], "colorY": xy[1], **color_transition(False)}))
    last = try_color_commands(node_id, endpoint, fallback)
    if last.get("ok"):
        return {**last, "on": True, "color_hex": hex_s or None}
    return last


def set_color_temp(node_id: int, endpoint: int, kelvin: int) -> dict[str, Any]:
    on_result = device_command(node_id, endpoint, ON_OFF, "On", {})
    if is_transport_error(on_result):
        return on_result
    payload_on = {
        "colorTemperatureMireds": kelvin_to_mireds(kelvin),
        **color_transition(True),
    }
    result = device_command(node_id, endpoint, COLOR_CONTROL, "MoveToColorTemperature", payload_on)
    if result.get("ok") or is_transport_error(result) or is_unsupported_cluster(result):
        return result
    payload_off = {
        "colorTemperatureMireds": kelvin_to_mireds(kelvin),
        **color_transition(False),
    }
    return device_command(node_id, endpoint, COLOR_CONTROL, "MoveToColorTemperature", payload_off)


def endpoint_looks_heater(attributes: dict[str, Any], endpoint: int) -> bool:
    return endpoint_has_cluster(attributes, endpoint, THERMOSTAT)


def matter_celsius(raw: Any) -> float | None:
    number = event_number(raw) if not isinstance(raw, (int, float)) else float(raw)
    if number is None:
        try:
            number = float(raw)
        except (TypeError, ValueError):
            return None
    n = int(round(number))
    if n in (0x8000, -32768):
        return None
    if n < -27315 or n > 32767:
        return None
    return round(n / 100.0, 1)


def system_mode_is_on(raw: Any) -> bool | None:
    number = event_number(raw)
    if number is None:
        try:
            number = int(raw)
        except (TypeError, ValueError):
            return None
    return int(number) not in (SYSTEM_MODE_OFF, 7)


def thermostat_payload(attributes: dict[str, Any], endpoint: int) -> dict[str, Any]:
    has_thermo = endpoint_has_cluster(attributes, endpoint, THERMOSTAT)
    local = matter_celsius(attr_raw(attributes, endpoint, THERMOSTAT, ATTR_LOCAL_TEMPERATURE))
    if local is None:
        local = matter_celsius(attr_raw(attributes, endpoint, TEMP_MEASUREMENT, ATTR_MEASURED_TEMP))
    setpoint = matter_celsius(attr_raw(attributes, endpoint, THERMOSTAT, ATTR_OCCUPIED_HEATING_SETPOINT))
    heat_min = matter_celsius(attr_raw(attributes, endpoint, THERMOSTAT, ATTR_MIN_HEAT_SETPOINT))
    if heat_min is None:
        heat_min = matter_celsius(attr_raw(attributes, endpoint, THERMOSTAT, ATTR_ABS_MIN_HEAT_SETPOINT))
    heat_max = matter_celsius(attr_raw(attributes, endpoint, THERMOSTAT, ATTR_MAX_HEAT_SETPOINT))
    if heat_max is None:
        heat_max = matter_celsius(attr_raw(attributes, endpoint, THERMOSTAT, ATTR_ABS_MAX_HEAT_SETPOINT))
    mode = attr_raw(attributes, endpoint, THERMOSTAT, ATTR_SYSTEM_MODE)
    on_from_mode = system_mode_is_on(mode) if mode is not None else None
    return {
        "has_thermostat": has_thermo or local is not None or setpoint is not None,
        "local_temperature": local,
        "heating_setpoint": setpoint,
        "heating_min": heat_min if heat_min is not None else (5.0 if has_thermo or setpoint is not None else None),
        "heating_max": heat_max if heat_max is not None else (35.0 if has_thermo or setpoint is not None else None),
        "system_mode": int(mode) if isinstance(mode, (int, float)) else None,
        "thermostat_on": on_from_mode,
    }


def write_status_code(result: Any) -> int:
    """CHIP Interaction Model status from a write_attribute result (0 = success)."""
    if result is None or result is True:
        return CHIP_STATUS_SUCCESS
    if isinstance(result, dict):
        for key in ("status", "Status", "statusCode", "StatusCode", "IMStatus"):
            if key not in result:
                continue
            val = result[key]
            if isinstance(val, dict):
                for nested in ("value", "status", "Status", "0"):
                    if nested in val and isinstance(val[nested], (int, float)):
                        return int(val[nested])
                name = str(val.get("name") or val.get("Name") or "").lower()
                if name == "success":
                    return CHIP_STATUS_SUCCESS
                if "constraint" in name:
                    return CHIP_STATUS_CONSTRAINT
                if "unsupportedcluster" in name.replace(" ", ""):
                    return CHIP_STATUS_UNSUPPORTED_CLUSTER
            if isinstance(val, (int, float)):
                return int(val)
            if isinstance(val, str):
                text = val.strip().lower()
                if text in ("0", "success", "ok"):
                    return CHIP_STATUS_SUCCESS
                if "constraint" in text or "0x87" in text:
                    return CHIP_STATUS_CONSTRAINT
                if "unsupportedcluster" in text.replace(" ", "") or "0xc3" in text:
                    return CHIP_STATUS_UNSUPPORTED_CLUSTER
                try:
                    return int(text, 0)
                except ValueError:
                    return CHIP_STATUS_FAILURE
        return CHIP_STATUS_SUCCESS
    if isinstance(result, (list, tuple)):
        if not result:
            return CHIP_STATUS_SUCCESS
        codes: list[int] = []
        for item in result:
            if isinstance(item, dict):
                codes.append(write_status_code(item))
            elif isinstance(item, (list, tuple)) and item:
                codes.append(write_status_code(item[-1]))
            else:
                codes.append(write_status_code(item))
        return next((code for code in codes if code), CHIP_STATUS_SUCCESS)
    return CHIP_STATUS_SUCCESS


def write_status_error(status: int) -> str:
    names = {
        CHIP_STATUS_FAILURE: "Failure",
        CHIP_STATUS_CONSTRAINT: "ConstraintError",
        CHIP_STATUS_UNSUPPORTED_WRITE: "UnsupportedWrite",
        CHIP_STATUS_UNSUPPORTED_CLUSTER: "UnsupportedCluster",
        0x85: "InvalidValue",
        0x86: "UnsupportedAttribute",
    }
    label = names.get(int(status), "Error")
    return f"{label} (0x{int(status):02x})"


def write_attribute(
    node_id: int,
    endpoint: int,
    cluster: int,
    attr: int,
    value: Any,
    *,
    reconnect: bool = True,
) -> dict[str, Any]:
    def send(nid: int | None = None) -> dict[str, Any]:
        use = node_id if nid is None else nid
        rpc = matter_rpc(
            "write_attribute",
            {
                "node_id": use,
                "attribute_path": f"{endpoint}/{cluster}/{attr}",
                "value": value,
            },
            timeout=18.0,
            retries=1,
        )
        if not rpc.get("ok"):
            return rpc
        status = write_status_code(rpc.get("result"))
        if status:
            return {"ok": False, "error": write_status_error(status), "status": status}
        return {"ok": True}

    if not reconnect:
        return send()
    return command_with_reconnect(node_id, send)


def is_unsupported_cluster(result: dict[str, Any]) -> bool:
    if int(result.get("status") or 0) == CHIP_STATUS_UNSUPPORTED_CLUSTER:
        return True
    err = str(result.get("error") or "").lower().replace(" ", "")
    return "unsupportedcluster" in err or "0xc3" in err


def is_mode_rejected(result: dict[str, Any]) -> bool:
    status = int(result.get("status") or 0)
    if status in (
        CHIP_STATUS_CONSTRAINT,
        CHIP_STATUS_UNSUPPORTED_WRITE,
        CHIP_STATUS_FAILURE,
        0x85,
        0x86,
    ):
        return True
    err = str(result.get("error") or "").lower().replace(" ", "")
    return any(
        token in err
        for token in (
            "constraint",
            "0x87",
            "unsupportedwrite",
            "0x88",
            "invalid",
            "unsupportedattribute",
            "0x86",
        )
    )


def live_row(device_id: str) -> dict[str, Any] | None:
    row = next((item for item in current_live_devices() if str(item.get("id") or "") == device_id), None)
    return row if isinstance(row, dict) else None


def live_kind(device_id: str, hinted: str = "") -> str:
    hint = str(hinted or "").strip().lower()
    row = live_row(device_id)
    kind = str((row or {}).get("kind") or "").strip().lower()
    if hint == "heater" or kind == "heater":
        return "heater"
    if row is not None:
        if (
            row.get("has_thermostat")
            or row.get("heating_setpoint") is not None
            or row.get("system_mode") is not None
        ):
            return "heater"
        labels = " ".join(str(row.get(key) or "") for key in ("name", "product", "vendor", "source"))
        if name_looks_heater(labels):
            return "heater"
    return kind or hint


def heater_setpoint_celsius(device_id: str, hinted: Any = None) -> float:
    for raw in (hinted, (live_row(device_id) or {}).get("heating_setpoint")):
        if raw is None or raw == "":
            continue
        try:
            value = float(raw)
        except (TypeError, ValueError):
            continue
        if value > 0:
            return max(5.0, min(35.0, value))
    return 21.0


def set_heater_power(
    node_id: int,
    endpoint: int,
    on: bool,
    celsius: Any = None,
    device_id: str = "",
) -> dict[str, Any]:
    def send(nid: int | None = None) -> dict[str, Any]:
        use = node_id if nid is None else nid
        if not on:
            off = write_attribute(
                use, endpoint, THERMOSTAT, ATTR_SYSTEM_MODE, SYSTEM_MODE_OFF, reconnect=False
            )
            if off.get("ok"):
                return {"ok": True, "on": False, "system_mode": SYSTEM_MODE_OFF}
            if is_unsupported_cluster(off):
                onoff = device_command(use, endpoint, ON_OFF, "Off", {})
                if onoff.get("ok"):
                    return {"ok": True, "on": False}
            return off
        target = heater_setpoint_celsius(device_id, celsius)
        hundredths = int(round(target * 100))
        setpoint = write_attribute(
            use,
            endpoint,
            THERMOSTAT,
            ATTR_OCCUPIED_HEATING_SETPOINT,
            hundredths,
            reconnect=False,
        )
        if not setpoint.get("ok") and is_transport_error(setpoint):
            return setpoint
        heat = write_attribute(
            use, endpoint, THERMOSTAT, ATTR_SYSTEM_MODE, SYSTEM_MODE_HEAT, reconnect=False
        )
        if heat.get("ok"):
            out: dict[str, Any] = {
                "ok": True,
                "on": True,
                "system_mode": SYSTEM_MODE_HEAT,
                "heating_setpoint": round(target, 1),
            }
            return out
        if is_transport_error(heat) or not is_mode_rejected(heat):
            if is_unsupported_cluster(heat):
                onoff = device_command(use, endpoint, ON_OFF, "On", {})
                if onoff.get("ok"):
                    return {"ok": True, "on": True, "heating_setpoint": round(target, 1)}
            return heat
        auto = write_attribute(
            use, endpoint, THERMOSTAT, ATTR_SYSTEM_MODE, SYSTEM_MODE_AUTO, reconnect=False
        )
        if auto.get("ok"):
            return {
                "ok": True,
                "on": True,
                "system_mode": SYSTEM_MODE_AUTO,
                "heating_setpoint": round(target, 1),
            }
        return auto if auto.get("error") else heat

    return command_with_reconnect(node_id, send)


def set_heater_setpoint(
    node_id: int,
    endpoint: int,
    celsius: float,
    device_id: str = "",
) -> dict[str, Any]:
    return set_heater_power(node_id, endpoint, True, celsius=celsius, device_id=device_id)


def endpoint_looks_vacuum(attributes: dict[str, Any], endpoint: int) -> bool:
    return (
        endpoint_has_cluster(attributes, endpoint, RVC_RUN)
        or endpoint_has_cluster(attributes, endpoint, RVC_CLEAN)
        or endpoint_has_cluster(attributes, endpoint, RVC_OPERATIONAL)
    )


def fallback_kind(attributes: dict[str, Any], endpoint: int) -> str:
    if endpoint_looks_heater(attributes, endpoint):
        return "heater"
    if endpoint_looks_vacuum(attributes, endpoint):
        return "vacuum"
    if endpoint_has_cluster(attributes, endpoint, COLOR_CONTROL) or endpoint_has_cluster(
        attributes, endpoint, LEVEL_CONTROL
    ):
        return "light"
    return "switch"


def flatten_nodes(raw: Any) -> list[dict[str, Any]]:
    nodes = nodes_from_result(raw)
    devices: list[dict[str, Any]] = []
    for node in nodes:
        node_id = int(node.get("node_id") or node.get("nodeId") or 0)
        available = bool(node.get("available", True))
        is_bridge = bool(node.get("is_bridge") or node.get("isBridge") or False)
        attributes = node.get("attributes") if isinstance(node.get("attributes"), dict) else {}
        vendor = attr_str(attributes, 0, BASIC_INFO, ATTR_VENDOR_NAME)
        product = attr_str(attributes, 0, BASIC_INFO, ATTR_PRODUCT_NAME)
        node_name = attr_str(attributes, 0, BASIC_INFO, ATTR_NODE_LABEL)
        source = node_name or product or vendor or f"Matter node {node_id}"
        ep_ids = endpoint_ids(attributes)
        node_has_thermo = any(ep != 0 and endpoint_looks_heater(attributes, ep) for ep in ep_ids)
        before = len(devices)
        for endpoint in sorted(ep_ids):
            if endpoint == 0:
                continue
            on_val = attr_raw(attributes, endpoint, ON_OFF, ATTR_ON_OFF)
            types = attr_raw(attributes, endpoint, DESCRIPTOR, ATTR_DEVICE_TYPES)
            type_ids = device_type_ids(types)
            kind = device_kind(types)
            label = endpoint_name(attributes, endpoint, vendor, product)
            kind = classify_by_name(kind, label, vendor, product, source)
            if endpoint_looks_heater(attributes, endpoint):
                kind = "heater"
            elif endpoint_looks_vacuum(attributes, endpoint):
                kind = "vacuum"
            elif kind == "other":
                if DEVTYPE_AGGREGATOR in type_ids and not endpoint_has_cluster(
                    attributes, endpoint, ON_OFF
                ) and not endpoint_looks_heater(attributes, endpoint) and not endpoint_looks_vacuum(
                    attributes, endpoint
                ):
                    continue
                kind = fallback_kind(attributes, endpoint)
            if kind == "heater" and node_has_thermo and not endpoint_looks_heater(attributes, endpoint):
                continue
            has_on_off = on_val is not None or endpoint_has_cluster(attributes, endpoint, ON_OFF)
            if not has_on_off and kind not in ("heater", "vacuum"):
                continue
            is_light = kind == "light"
            thermo = thermostat_payload(attributes, endpoint) if kind == "heater" else {
                "has_thermostat": False,
                "local_temperature": None,
                "heating_setpoint": None,
                "heating_min": None,
                "heating_max": None,
                "system_mode": None,
                "thermostat_on": None,
            }
            on = attr_bool(on_val)
            if thermo.get("thermostat_on") is not None:
                on = bool(thermo["thermostat_on"])
            level = attr_num(attributes, endpoint, LEVEL_CONTROL, ATTR_CURRENT_LEVEL) if is_light else None
            brightness = None
            if level is not None and level >= 0:
                brightness = int(round(float(level) * 100 / 254))
            color = color_payload(attributes, endpoint, type_ids) if is_light else {
                "colorable": False,
                "color_hs": False,
                "color_xy": False,
                "color_ct": False,
                "color_hex": "",
            }
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
                    "on": on,
                    "brightness": brightness,
                    "dimmable": is_light
                    and attr_raw(attributes, endpoint, LEVEL_CONTROL, ATTR_CURRENT_LEVEL) is not None,
                    "available": available,
                    **color,
                    **({k: v for k, v in thermo.items() if k != "thermostat_on"} if kind == "heater" else {}),
                }
            )
        if len(devices) == before and node_id > 0 and attributes:
            stub_kind = classify_by_name("light", source, vendor, product)
            stub_light = stub_kind == "light"
            devices.append(
                {
                    "id": f"{node_id}:1",
                    "node_id": node_id,
                    "endpoint": 1,
                    "name": source,
                    "kind": stub_kind,
                    "vendor": vendor,
                    "product": product,
                    "source": source,
                    "bridge": True,
                    "on": False,
                    "brightness": None,
                    "dimmable": False,
                    "available": available,
                    "colorable": False,
                    "color_hs": False,
                    "color_xy": False,
                    "color_ct": False,
                    "color_hex": "",
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
            "features": ["color", "color_temp", "thermostat"],
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
        if not listening:
            threading.Thread(target=ensure_matter_server, daemon=True).start()
        return {
            "ok": listening,
            "server": listening,
            "docker": _started_docker,
            "error": None if listening else "Matter server is not listening on port 5580",
        }
    if op == "nodes":
        quick = body.get("quick", True) is not False
        nodes, fabric = collect_nodes(quick=quick)
        devices = flatten_nodes(nodes)
        if fabric.get("source") == "live":
            remember_live_devices(devices)
        live = current_live_devices()
        if live:
            by_id = {str(row.get("id") or ""): row for row in live}
            for row in devices:
                device_id = str(row.get("id") or "")
                if device_id in by_id and "on" in by_id[device_id]:
                    row["on"] = attr_bool(by_id[device_id].get("on"))
                    live_row = by_id[device_id]
                    if "brightness" in live_row:
                        row["brightness"] = live_row.get("brightness")
                    for key in (
                        "local_temperature",
                        "heating_setpoint",
                        "heating_min",
                        "heating_max",
                        "has_thermostat",
                        "system_mode",
                    ):
                        if key in live_row:
                            row[key] = live_row.get(key)
        return {
            "ok": True,
            "devices": devices,
            "node_count": len(nodes),
            "fabric": fabric,
        }
    if op == "states":
        start_state_poll()
        return {
            "ok": True,
            "devices": current_live_devices(),
        }
    if op == "restore_nodes":
        restored = persist_node_stubs_and_reload()
        if restored:
            threading.Thread(
                target=lambda: (
                    wait_any_node_available(restored, 35.0)
                    or interview_node_ids(restored)
                ),
                daemon=True,
            ).start()
        return {
            "ok": True,
            "restored": restored,
            "reloaded": bool(restored),
        }
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
            heater = live_kind(device_id, str(body.get("kind") or body.get("device_kind") or "")) == "heater"
            on: bool | None
            if action == "on":
                on = True
            elif action == "off":
                on = False
            else:
                current = next(
                    (row for row in current_live_devices() if str(row.get("id") or "") == device_id),
                    None,
                )
                on = (not attr_bool(current.get("on"))) if current is not None else True
            if heater:
                hinted = body.get("celsius")
                if hinted is None:
                    hinted = body.get("setpoint")
                rpc = set_heater_power(
                    node_id, endpoint, bool(on), celsius=hinted, device_id=device_id
                )
            else:

                def send_onoff(nid: int | None = None) -> dict[str, Any]:
                    use = node_id if nid is None else nid
                    return matter_rpc(
                        "device_command",
                        {
                            "node_id": use,
                            "endpoint_id": endpoint,
                            "cluster_id": ON_OFF,
                            "command_name": name if action != "toggle" else ("On" if on else "Off"),
                            "payload": {},
                        },
                        timeout=12.0,
                    )

                rpc = command_with_reconnect(node_id, send_onoff)
                if not rpc.get("ok") and is_unsupported_cluster(rpc):
                    rpc = set_heater_power(node_id, endpoint, bool(on))
            if not rpc.get("ok"):
                return rpc
            if on is not None:
                patch = {"on": bool(rpc.get("on", on))}
                if rpc.get("system_mode") is not None:
                    patch["system_mode"] = rpc["system_mode"]
                if rpc.get("heating_setpoint") is not None:
                    patch["heating_setpoint"] = rpc["heating_setpoint"]
                patch_live_device(device_id, patch, sticky=True)
            out: dict[str, Any] = {"ok": True, "id": device_id}
            if on is not None:
                out["on"] = bool(rpc.get("on", on))
            if rpc.get("heating_setpoint") is not None:
                out["heating_setpoint"] = rpc["heating_setpoint"]
            return out
        if action in ("setpoint", "temperature", "heating_setpoint"):
            try:
                celsius = float(body.get("celsius") or body.get("setpoint") or body.get("temperature") or 0)
            except (TypeError, ValueError):
                celsius = 0.0
            if celsius <= 0:
                return {"ok": False, "error": "Set a heating temperature"}
            rpc = set_heater_setpoint(node_id, endpoint, celsius, device_id=device_id)
            if rpc.get("ok"):
                patch = {"on": True, "heating_setpoint": rpc.get("heating_setpoint")}
                if rpc.get("system_mode") is not None:
                    patch["system_mode"] = rpc["system_mode"]
                patch_live_device(device_id, patch, sticky=True)
                rpc = {**rpc, "id": device_id}
            return rpc
        if action == "brightness":
            pct = max(0, min(100, int(body.get("brightness") or 0)))
            on = pct > 0
            if live_kind(device_id, str(body.get("kind") or body.get("device_kind") or "")) == "heater":
                rpc = set_heater_power(node_id, endpoint, on, device_id=device_id)
                if rpc.get("ok"):
                    patch = {"on": bool(rpc.get("on", on))}
                    if rpc.get("system_mode") is not None:
                        patch["system_mode"] = rpc["system_mode"]
                    patch_live_device(device_id, patch, sticky=True)
                    rpc = {**rpc, "id": device_id, "on": bool(rpc.get("on", on))}
                return rpc
            level = int(round(pct * 254 / 100))
            rpc = device_command(
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
            if rpc.get("ok"):
                patch_live_device(device_id, {"on": on, "brightness": pct}, sticky=True)
                rpc = {**rpc, "id": device_id, "on": on, "brightness": pct}
                return rpc
            if is_unsupported_cluster(rpc):
                name = "On" if on else "Off"
                onoff = device_command(node_id, endpoint, ON_OFF, name, {})
                if onoff.get("ok"):
                    patch_live_device(device_id, {"on": on}, sticky=True)
                    return {"ok": True, "id": device_id, "on": on}
                if is_unsupported_cluster(onoff):
                    heater = set_heater_power(node_id, endpoint, on)
                    if heater.get("ok"):
                        patch = {"on": bool(heater.get("on", on))}
                        if heater.get("system_mode") is not None:
                            patch["system_mode"] = heater["system_mode"]
                        patch_live_device(device_id, patch, sticky=True)
                        return {**heater, "id": device_id, "on": bool(heater.get("on", on))}
                    return heater
                return onoff
            return rpc
        if action in COLOR_ACTIONS:
            rpc = set_color(node_id, endpoint, body)
            if rpc.get("ok"):
                fields: dict[str, Any] = {"on": True}
                hex_s = rpc.get("color_hex") or body.get("hex") or body.get("color_hex")
                if hex_s:
                    fields["color_hex"] = hex_s
                patch_live_device(device_id, fields, sticky=True)
                rpc = {**rpc, "id": device_id, **fields}
            return rpc
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
    ensure_storage_readable()
    threading.Thread(target=ensure_matter_server, daemon=True).start()
    start_background_recover()
    start_state_poll()
    httpd = ThreadingHTTPServer((HOST, AGENT_PORT), Handler)
    print(f"matter_agent listening on {HOST}:{AGENT_PORT}", flush=True)
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass


def restore_nodes_cli() -> int:
    STORAGE.mkdir(parents=True, exist_ok=True)
    ensure_storage_readable()
    restored = persist_node_stubs_and_reload()
    print(f"restored={restored}", flush=True)
    if restored:
        wait_any_node_available(restored, 40.0)
    return 0


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] in ("--restore-nodes", "restore_nodes"):
        raise SystemExit(restore_nodes_cli())
    main()
