#!/usr/bin/env python3
"""Subscribe to UniFi Protect Integration API events (smart detections).

Official path: wss://{host}/proxy/protect/integration/v1/subscribe/events
with X-API-KEY from data/unifi-config.json. Writes detection stamps for
Home automations — no private Protect API.
"""

from __future__ import annotations

import base64
import hashlib
import json
import os
import socket
import ssl
import struct
import threading
import time
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
CONFIG_PATH = ROOT / "data" / "unifi-config.json"
OUT_PATH = ROOT / "data" / "unifi-protect-events.json"
PID_PATH = ROOT / "data" / "unifi-protect-events.pid"
LOG_PATH = ROOT / "data" / "unifi-protect-events.log"

WS_PATH = "/proxy/protect/integration/v1/subscribe/events"
DETECT_TYPES = {
    "person": "person",
    "vehicle": "vehicle",
    "animal": "animal",
    "package": "package",
    "face": "person",
    "pet": "animal",
    "car": "vehicle",
}
LINE_TYPES = {"smartDetectLine", "smartDetectLoiterZone"}
ZONE_TYPES = {"smartDetectZone", "smartAudioDetect"}
MOTION_TYPES = {"motion"}
ACTIVE_HOLD_S = 90.0
CONFIG_RELOAD_S = 15.0
RECONNECT_S = 3.0
MAX_RECONNECT_S = 60.0


def log(msg: str) -> None:
    line = f"{time.strftime('%Y-%m-%d %H:%M:%S')} {msg}"
    try:
        LOG_PATH.parent.mkdir(parents=True, exist_ok=True)
        with LOG_PATH.open("a", encoding="utf-8") as fh:
            fh.write(line + "\n")
    except OSError:
        pass
    print(line, flush=True)


def load_config() -> dict[str, Any]:
    try:
        raw = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return raw if isinstance(raw, dict) else {}


def empty_cam() -> dict[str, Any]:
    return {
        "motion": False,
        "motion_at": 0,
        "person": False,
        "vehicle": False,
        "animal": False,
        "package": False,
        "line_crossing": False,
        "detect_person_at": 0,
        "detect_vehicle_at": 0,
        "detect_animal_at": 0,
        "detect_package_at": 0,
        "detect_line_crossing_at": 0,
    }


class State:
    def __init__(self) -> None:
        self.cameras: dict[str, dict[str, Any]] = {}
        self.subscriber_ok = False
        self.last_error = ""
        self.dirty = True

    def cam(self, native_id: str) -> dict[str, Any]:
        row = self.cameras.get(native_id)
        if row is None:
            row = empty_cam()
            self.cameras[native_id] = row
        return row

    def expire_active(self, now: float) -> None:
        cutoff = now - ACTIVE_HOLD_S
        for row in self.cameras.values():
            changed = False
            for key in ("person", "vehicle", "animal", "package", "line_crossing", "motion"):
                if not row.get(key):
                    continue
                stamp_key = "motion_at" if key == "motion" else f"detect_{key}_at"
                stamp = int(row.get(stamp_key) or 0)
                if stamp > 0 and stamp < cutoff:
                    row[key] = False
                    changed = True
            if changed:
                self.dirty = True

    def write(self, force: bool = False) -> None:
        if not self.dirty and not force:
            return
        payload = {
            "updated_at": int(time.time()),
            "subscriber_ok": self.subscriber_ok,
            "last_error": self.last_error,
            "cameras": self.cameras,
        }
        OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
        tmp = OUT_PATH.with_suffix(".json.tmp")
        tmp.write_text(json.dumps(payload, separators=(",", ":")), encoding="utf-8")
        os.replace(tmp, OUT_PATH)
        self.dirty = False


class ProtectWs:
    def __init__(self, sock: ssl.SSLSocket | socket.socket, leftover: bytes = b"") -> None:
        self.sock = sock
        self.buf = bytearray(leftover)
        self._out = threading.Lock()

    def close(self) -> None:
        try:
            self.sock.close()
        except OSError:
            pass

    def send_pong(self, payload: bytes = b"") -> None:
        self._send_frame(0xA, payload)

    def send_ping(self) -> None:
        self._send_frame(0x9, b"")

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

    def recv_messages(self, timeout: float) -> list[Any]:
        self.sock.settimeout(timeout)
        deadline = time.time() + timeout
        out: list[Any] = []
        acc = bytearray()
        while time.time() < deadline:
            frame = self._read_frame(max(0.2, deadline - time.time()))
            if frame is None:
                break
            fin, opcode, payload = frame
            if opcode == 0x9:
                try:
                    self.send_pong(payload)
                except OSError:
                    return out
                continue
            if opcode == 0xA:
                continue
            if opcode == 0x8:
                return out
            if opcode not in (0x1, 0x2, 0x0):
                acc.clear()
                continue
            acc.extend(payload)
            if not fin:
                continue
            try:
                parsed = json.loads(bytes(acc).decode("utf-8"))
            except (UnicodeDecodeError, json.JSONDecodeError):
                acc.clear()
                continue
            acc.clear()
            out.append(parsed)
        return out

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
            return fin, opcode, payload
        except (TimeoutError, socket.timeout, OSError):
            return None


def connect(host: str, api_key: str, verify_tls: bool) -> ProtectWs:
    host = host.strip().rstrip("/")
    if host.startswith("https://"):
        host = host[8:]
    elif host.startswith("http://"):
        host = host[7:]
    host = host.split("/")[0]
    if ":" in host and not host.startswith("["):
        hostname, port_s = host.rsplit(":", 1)
        try:
            port = int(port_s)
        except ValueError:
            hostname, port = host, 443
    else:
        hostname, port = host, 443

    raw = socket.create_connection((hostname, port), timeout=15)
    ctx = ssl.create_default_context()
    if not verify_tls:
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    sock = ctx.wrap_socket(raw, server_hostname=hostname)

    key = base64.b64encode(os.urandom(16)).decode()
    req = (
        f"GET {WS_PATH} HTTP/1.1\r\n"
        f"Host: {hostname}\r\n"
        "Upgrade: websocket\r\n"
        "Connection: Upgrade\r\n"
        f"Sec-WebSocket-Key: {key}\r\n"
        "Sec-WebSocket-Version: 13\r\n"
        f"X-API-KEY: {api_key}\r\n"
        "\r\n"
    )
    sock.sendall(req.encode())
    buf = b""
    while b"\r\n\r\n" not in buf:
        chunk = sock.recv(4096)
        if not chunk:
            sock.close()
            raise OSError("Protect closed during websocket handshake")
        buf += chunk
    head, _, rest = buf.partition(b"\r\n\r\n")
    status = head.split(b"\r\n", 1)[0]
    if b"101" not in status:
        sock.close()
        raise OSError(f"Protect rejected websocket: {status.decode(errors='replace')[:120]}")
    return ProtectWs(sock, rest)


def to_epoch_s(value: Any) -> int:
    if value is None or value is False:
        return 0
    try:
        n = float(value)
    except (TypeError, ValueError):
        return 0
    if n <= 0:
        return 0
    if n > 1e12:
        n = n / 1000.0
    return int(n)


def event_ended(event: dict[str, Any]) -> bool:
    end = event.get("end")
    if end in (None, 0, "", False):
        return False
    return to_epoch_s(end) > 0


def extract_events(message: Any) -> list[dict[str, Any]]:
    events: list[dict[str, Any]] = []
    known = ZONE_TYPES | LINE_TYPES | MOTION_TYPES

    def consider(obj: Any) -> None:
        if not isinstance(obj, dict):
            return
        etype = obj.get("type")
        if obj.get("modelKey") == "event" or (
            isinstance(etype, str)
            and etype in known
            and (obj.get("device") or obj.get("camera"))
        ):
            events.append(obj)
            return
        for key in ("item", "event", "data", "payload", "body"):
            if key in obj:
                consider(obj[key])
        items = obj.get("items")
        if isinstance(items, list):
            for it in items:
                consider(it)

    if isinstance(message, list):
        for item in message:
            consider(item)
    else:
        consider(message)
    return events


def apply_event(state: State, event: dict[str, Any]) -> None:
    native = str(event.get("device") or event.get("camera") or "").strip()
    if native == "":
        return
    etype = str(event.get("type") or "").strip()
    start = to_epoch_s(event.get("start") or event.get("timestamp") or time.time())
    ended = event_ended(event)
    row = state.cam(native)
    changed = False

    if etype in MOTION_TYPES:
        if start > int(row.get("motion_at") or 0):
            row["motion_at"] = start
            changed = True
        want = not ended
        if bool(row.get("motion")) != want:
            row["motion"] = want
            changed = True

    if etype in LINE_TYPES:
        if start > int(row.get("detect_line_crossing_at") or 0):
            row["detect_line_crossing_at"] = start
            changed = True
        want = not ended
        if bool(row.get("line_crossing")) != want:
            row["line_crossing"] = want
            changed = True
        if start > int(row.get("motion_at") or 0):
            row["motion_at"] = start
            changed = True
        if want:
            row["motion"] = True
            changed = True

    if etype in ZONE_TYPES or etype in LINE_TYPES:
        raw_types = event.get("smartDetectTypes") or event.get("smart_detect_types") or []
        if not isinstance(raw_types, list):
            raw_types = [raw_types] if raw_types else []
        mapped: set[str] = set()
        for item in raw_types:
            key = DETECT_TYPES.get(str(item).strip())
            if key:
                mapped.add(key)
        if etype in LINE_TYPES and not mapped:
            mapped.add("line_crossing")
        for key in mapped:
            stamp_key = f"detect_{key}_at"
            if start > int(row.get(stamp_key) or 0):
                row[stamp_key] = start
                changed = True
            want = not ended
            if bool(row.get(key)) != want:
                row[key] = want
                changed = True
            if start > int(row.get("motion_at") or 0):
                row["motion_at"] = start
                changed = True
            if want:
                row["motion"] = True
                changed = True

    if changed:
        state.dirty = True


def write_pid() -> None:
    PID_PATH.parent.mkdir(parents=True, exist_ok=True)
    PID_PATH.write_text(str(os.getpid()), encoding="utf-8")


def clear_pid() -> None:
    try:
        if PID_PATH.is_file() and PID_PATH.read_text(encoding="utf-8").strip() == str(os.getpid()):
            PID_PATH.unlink(missing_ok=True)
    except OSError:
        pass


def main() -> int:
    write_pid()
    state = State()
    state.write(force=True)
    backoff = RECONNECT_S
    last_cfg_check = 0.0
    host = ""
    api_key = ""
    verify_tls = False

    try:
        while True:
            now = time.time()
            if now - last_cfg_check >= CONFIG_RELOAD_S or not api_key:
                cfg = load_config()
                host = str(cfg.get("host") or "").strip()
                api_key = str(cfg.get("protect_api_key") or "").strip()
                verify_tls = bool(cfg.get("verify_tls"))
                last_cfg_check = now

            if not host or not api_key:
                state.subscriber_ok = False
                state.last_error = "missing host or protect_api_key"
                state.dirty = True
                state.write()
                time.sleep(CONFIG_RELOAD_S)
                continue

            ws: ProtectWs | None = None
            try:
                log(f"connecting to Protect events on {host}")
                ws = connect(host, api_key, verify_tls)
                state.subscriber_ok = True
                state.last_error = ""
                state.dirty = True
                state.write()
                backoff = RECONNECT_S
                log("Protect events subscribed")
                last_ping = time.time()
                while True:
                    msgs = ws.recv_messages(5.0)
                    for msg in msgs:
                        for event in extract_events(msg):
                            apply_event(state, event)
                    state.expire_active(time.time())
                    state.write()
                    if time.time() - last_ping > 25:
                        try:
                            ws.send_ping()
                        except OSError as exc:
                            raise OSError(f"ping failed: {exc}") from exc
                        last_ping = time.time()
                    if time.time() - last_cfg_check >= CONFIG_RELOAD_S:
                        cfg = load_config()
                        new_host = str(cfg.get("host") or "").strip()
                        new_key = str(cfg.get("protect_api_key") or "").strip()
                        new_tls = bool(cfg.get("verify_tls"))
                        last_cfg_check = time.time()
                        if new_host != host or new_key != api_key or new_tls != verify_tls:
                            host, api_key, verify_tls = new_host, new_key, new_tls
                            raise OSError("config changed; reconnecting")
                        if not host or not api_key:
                            raise OSError("Protect API key cleared")
            except Exception as exc:  # noqa: BLE001 — keep loop alive
                state.subscriber_ok = False
                state.last_error = str(exc)[:240]
                state.dirty = True
                state.write()
                log(f"Protect events error: {exc}")
                time.sleep(backoff)
                backoff = min(MAX_RECONNECT_S, backoff * 1.5)
            finally:
                if ws is not None:
                    ws.close()
    finally:
        clear_pid()
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        clear_pid()
        raise SystemExit(0)
