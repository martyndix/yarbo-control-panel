#!/usr/bin/env python3
"""Yarbo live cameras: join Agora, then arm the robot, then hand H.264 to the browser.

The Agora library is installed by Settings → Panel updates. This file does not
contain it. Token, key, and salt stay in this process and are never written to
the status file or the log.
"""

from __future__ import annotations

import argparse
import base64
import json
import os
import queue
import random
import re
import shutil
import socket
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
import zlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

VIEWS = ("middle", "left", "right", "back")
SERVICE_URL = "https://4zx17x5q7l.execute-api.us-east-1.amazonaws.com/prod"
TOKEN_PATH = "/yarbo/robot-service/robot/commonUser/getAgoraToken"
# The robot's video service is the Yarbo app region, North America (2),
# which is also where the token service lives. 4 would be Europe and the
# subscriber would sit in an empty room. YARBO_AGORA_AREA overrides it.
DEFAULT_AREA_CODE = 2
LEASE_SECONDS = 30 * 60
IDLE_AFTER_VIEWER_S = 20
IDLE_BEFORE_VIEWER_S = 45
HEARTBEAT_S = 10
LINK_CHECK_S = 30


def link_name(raw: dict[str, Any] | None) -> str:
    """Same order as YarboTelemetry::connectionTypeName. Unknown is not 4G."""
    if not isinstance(raw, dict):
        return "Unknown"
    net = raw.get("NetMSG") if isinstance(raw.get("NetMSG"), dict) else {}
    halow = raw.get("halow_status", net.get("halow_status"))
    if isinstance(halow, bool) and halow:
        return "HaLow"
    if isinstance(halow, (int, float)) and int(halow) > 0:
        return "HaLow"
    if isinstance(halow, dict) and halow:
        return "HaLow"
    value = str(raw.get("net_type", net.get("net_type", "")) or "").strip().lower()
    named = {
        "0": "WiFi",
        "wifi": "WiFi",
        "wlan": "WiFi",
        "1": "4G",
        "4g": "4G",
        "lte": "4G",
        "cellular": "4G",
        "2": "HaLow",
        "halow": "HaLow",
        "ha_low": "HaLow",
    }.get(value)
    if named:
        return named
    route = raw.get("route_priority", net.get("route_priority"))
    iface = _best_iface(route)
    return {"hg0": "HaLow", "wlan0": "WiFi", "wwan0": "4G"}.get(iface or "", "Unknown")


def link_is_cellular(raw: dict[str, Any] | None) -> bool:
    return link_name(raw) == "4G"


def _best_iface(route: Any) -> str | None:
    if not isinstance(route, dict):
        return None
    best_iface = None
    best_metric = None
    for iface, metric in route.items():
        try:
            value = float(metric)
        except (TypeError, ValueError):
            continue
        if value < 0:
            continue
        if best_metric is None or value < best_metric:
            best_metric = value
            best_iface = str(iface)
    return best_iface


def channel_name(serial: str, view: str) -> str:
    return f"{serial}_{view}_image"


def app_id_from_token(token: str) -> str:
    """Agora AccessToken2 stores the 32-hex app id inside the compressed body."""
    body = token[3:] if token.startswith("007") else token
    pad = "=" * ((4 - (len(body) % 4)) % 4)
    try:
        blob = base64.b64decode(body + pad)
    except Exception as exc:  # noqa: BLE001
        raise ValueError("Agora token could not be read") from exc
    try:
        blob = zlib.decompress(blob)
    except zlib.error:
        pass
    match = re.search(rb"[0-9a-fA-F]{32}", blob)
    if not match:
        raise ValueError("Agora token did not include an app id")
    return match.group(0).decode("ascii").lower()


def decode_salt(salt: str) -> bytes:
    text = salt.strip()
    try:
        raw = base64.b64decode(text, validate=False)
    except Exception:
        raw = b""
    if len(raw) == 32:
        return raw
    if re.fullmatch(r"[0-9a-fA-F]{64}", text):
        return bytes.fromhex(text)
    if 16 <= len(raw) <= 32:
        return raw
    raise ValueError("Agora salt was not usable")


def find_jwt(value: Any, depth: int = 0) -> str | None:
    if depth > 5:
        return None
    if isinstance(value, str) and value.count(".") == 2 and len(value) > 40:
        return value
    if isinstance(value, dict):
        for key in ("accessToken", "access_token", "token", "jwt", "id_token"):
            found = find_jwt(value.get(key), depth + 1)
            if found:
                return found
        for item in value.values():
            found = find_jwt(item, depth + 1)
            if found:
                return found
        return None
    if isinstance(value, (list, tuple)):
        for item in value:
            found = find_jwt(item, depth + 1)
            if found:
                return found
        return None
    if hasattr(value, "__dict__"):
        return find_jwt(vars(value), depth + 1)
    return None


def python_is_310(path: str) -> bool:
    try:
        result = subprocess.run(
            [path, "-c", "import sys; raise SystemExit(0 if sys.version_info >= (3, 10) else 1)"],
            check=False,
            capture_output=True,
            timeout=15,
        )
    except (OSError, subprocess.TimeoutExpired):
        return False
    return result.returncode == 0


def newer_python() -> str | None:
    for name in ("python3.13", "python3.12", "python3.11", "python3.10", "python3"):
        path = shutil.which(name)
        if path and python_is_310(path) and os.path.realpath(path) != os.path.realpath(sys.executable):
            return path
    return None


def agora_ready() -> bool:
    try:
        import agora.rtc.agora_service  # noqa: F401
    except ImportError:
        return False
    return True


def pip_install_agora() -> str:
    cmd = [sys.executable, "-m", "pip", "install", "--disable-pip-version-check", "agora_python_server_sdk"]
    result = subprocess.run(cmd, check=False, capture_output=True, text=True, timeout=300)
    if result.returncode != 0:
        result = subprocess.run(
            cmd + ["--break-system-packages"],
            check=False,
            capture_output=True,
            text=True,
            timeout=300,
        )
    if result.returncode != 0:
        lines = [line.strip() for line in (result.stderr or result.stdout or "").splitlines() if line.strip()]
        detail = lines[-1] if lines else "pip failed"
        if len(detail) > 180:
            detail = detail[:180]
        raise RuntimeError("The video library did not install. " + detail)
    return ""


def ensure_yarbo_sdk(session: LiveSession) -> None:
    try:
        import yarbo_robot_sdk  # noqa: F401
    except ImportError:
        session.set_state("starting", "Installing the Yarbo sign-in library")
        cmd = [sys.executable, "-m", "pip", "install", "--disable-pip-version-check", "yarbo-data-sdk"]
        result = subprocess.run(cmd, check=False, capture_output=True, text=True, timeout=300)
        if result.returncode != 0:
            lines = [line.strip() for line in (result.stderr or result.stdout or "").splitlines() if line.strip()]
            detail = lines[-1] if lines else "pip failed"
            raise RuntimeError("The Yarbo sign-in library did not install. " + detail[:180])
    import yarbo_robot_sdk  # noqa: F401


def ensure_agora(session: LiveSession, root: Path) -> None:
    if sys.version_info < (3, 10):
        other = newer_python()
        if not other:
            raise RuntimeError(
                f"Live view needs Python 3.10 or newer. This panel is on Python {sys.version_info[0]}.{sys.version_info[1]}."
            )
        camera_venv = root / ".venv-camera"
        venv_py = camera_venv / "bin" / "python3"
        if not venv_py.is_file():
            session.set_state("starting", "Preparing Python for the video library")
            made = subprocess.run([other, "-m", "venv", str(camera_venv)], check=False, capture_output=True, text=True)
            if made.returncode != 0 or not venv_py.is_file():
                raise RuntimeError("Could not prepare Python 3.10 or newer for live view")
        os.execv(str(venv_py), [str(venv_py), str(Path(__file__).resolve()), "--root", str(root)])
    if agora_ready():
        return
    session.set_state("starting", "Installing the video library. This can take a minute.")
    pip_install_agora()
    if not agora_ready():
        raise RuntimeError("The video library installed, but this Python cannot load it")


def read_serial(root: Path) -> str:
    text = (root / "config.php").read_text(encoding="utf-8", errors="replace")
    match = re.search(r"""['"]serial['"]\s*=>\s*['"]([^'"]+)['"]""", text)
    serial = match.group(1).strip() if match else ""
    if serial == "" or serial == "YOUR_SERIAL_HERE":
        raise RuntimeError("Set the Yarbo serial under Settings → Yarbo")
    return serial


def read_cloud(root: Path) -> tuple[str, str]:
    path = root / "data" / "cloud-config.json"
    if not path.is_file():
        raise RuntimeError("Save the Yarbo account under Settings → Yarbo")
    data = json.loads(path.read_text(encoding="utf-8"))
    email = str(data.get("email") or "").strip()
    password = str(data.get("password") or "")
    if email == "" or password == "":
        raise RuntimeError("Save the Yarbo account under Settings → Yarbo")
    return email, password


def login_jwt(email: str, password: str) -> str:
    from yarbo_robot_sdk import YarboClient

    client = YarboClient()
    try:
        result = client.login(email, password)
        token = find_jwt(result) or find_jwt(client)
        if not token:
            raise RuntimeError("The Yarbo sign-in did not return a session for live view")
        return token
    finally:
        close = getattr(client, "close", None)
        if callable(close):
            try:
                close()
            except Exception:
                pass


def request_token(jwt: str, serial: str, view: str, uid: int) -> dict[str, Any]:
    body = json.dumps(
        {"sn": serial, "channel_name": channel_name(serial, view), "uid": uid}
    ).encode("utf-8")
    req = urllib.request.Request(
        SERVICE_URL + TOKEN_PATH,
        data=body,
        method="POST",
        headers={
            "Authorization": "Bearer " + jwt,
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            payload = json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        raise RuntimeError(f"Live view sign-in was refused ({exc.code})") from exc
    except Exception as exc:  # noqa: BLE001
        raise RuntimeError("Could not reach Yarbo for a camera token") from exc
    if not isinstance(payload, dict) or str(payload.get("code")) != "00000":
        message = ""
        if isinstance(payload, dict):
            message = str(payload.get("message") or payload.get("code") or "")
        raise RuntimeError(message or "Yarbo did not issue a camera token")
    data = payload.get("data") if isinstance(payload.get("data"), dict) else {}
    token = str(data.get("token") or "")
    key = str(data.get("key") or "")
    salt = str(data.get("salt") or "")
    if token == "" or key == "" or salt == "":
        raise RuntimeError("Yarbo sent an incomplete camera token")
    return {"token": token, "key": key, "salt": salt, "app_id": app_id_from_token(token)}


def agent_request(
    payload: dict[str, Any],
    timeout: float = 8.0,
    attempts: int = 8,
) -> dict[str, Any]:
    port = int(os.environ.get("YARBO_MQTT_AGENT_PORT") or 8765)
    message = dict(payload)
    message["id"] = f"{random.randint(0, 0xFFFFFF):06x}"
    last_error: Exception | None = None
    for attempt in range(max(1, attempts)):
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=timeout) as sock:
                sock.settimeout(timeout)
                sock.sendall((json.dumps(message) + "\n").encode("utf-8"))
                buf = b""
                while b"\n" not in buf:
                    chunk = sock.recv(65536)
                    if not chunk:
                        break
                    buf += chunk
            line = buf.split(b"\n", 1)[0]
            decoded = json.loads(line.decode("utf-8") or "{}")
            return decoded if isinstance(decoded, dict) else {}
        except ConnectionRefusedError as exc:
            last_error = exc
            time.sleep(0.4)
    raise RuntimeError(
        "The panel is not connected to the Yarbo yet. Wait until the robot shows online, then press Start live view again."
    ) from last_error


def publish_vision(
    cmd: str,
    payload: dict[str, Any],
    ack: bool = False,
    timeout: float | None = None,
    attempts: int | None = None,
) -> dict[str, Any]:
    body: dict[str, Any] = {
        "op": "publish",
        "cmd": cmd,
        "payload": payload,
        "session": "vision",
    }
    if ack:
        body["ack"] = True
    # Waiting here does not hold the robot session. Giving up after 2s let the
    # cameras go quiet, so Safari had nothing left to draw.
    if timeout is None:
        timeout = 12.0
    if attempts is None:
        attempts = 2 if ack else 1
    result = agent_request(body, timeout=timeout, attempts=attempts)
    if not result.get("ok", False):
        raise RuntimeError(str(result.get("error") or f"{cmd} failed"))
    return result


def cached_raw() -> dict[str, Any] | None:
    try:
        result = agent_request(
            {"op": "telemetry", "timeout": 1.0, "wifi": False, "prefer_cache": True},
            timeout=1.5,
            attempts=1,
        )
    except Exception:
        return None
    raw = result.get("raw")
    return raw if isinstance(raw, dict) else None


class Hub:
    def __init__(self) -> None:
        self.lock = threading.Lock()
        self.subs: list[queue.Queue[dict[str, Any] | None]] = []
        self.ever_subscribed = False
        self.drops = 0
        self.last_key: dict[str, dict[str, Any]] = {}
        self.views: dict[str, dict[str, Any]] = {
            view: {
                "connected": False,
                "robot_seen": False,
                "frames": 0,
                "last_frame_at": 0.0,
                "fps": 0.0,
                "_times": [],
            }
            for view in VIEWS
        }

    def subscribe(self) -> queue.Queue[dict[str, Any] | None]:
        item: queue.Queue[dict[str, Any] | None] = queue.Queue(maxsize=45)
        with self.lock:
            self.subs.append(item)
            self.ever_subscribed = True
            for event in self.last_key.values():
                try:
                    item.put_nowait(event)
                except queue.Full:
                    break
        return item

    def unsubscribe(self, item: queue.Queue[dict[str, Any] | None]) -> None:
        with self.lock:
            self.subs = [sub for sub in self.subs if sub is not item]

    def subscriber_count(self) -> int:
        with self.lock:
            return len(self.subs)

    def saw_viewer(self) -> bool:
        with self.lock:
            return self.ever_subscribed

    def push(self, view: str, key: bool, pts: int, data: bytes, uid: int) -> None:
        now = time.time()
        event = {
            "view": view,
            "k": 1 if key else 0,
            "t": pts,
            "d": base64.b64encode(data).decode("ascii"),
        }
        with self.lock:
            slot = self.views[view]
            slot["frames"] = int(slot["frames"]) + 1
            slot["last_frame_at"] = now
            if uid == 1:
                slot["robot_seen"] = True
            times: list[float] = slot["_times"]
            times.append(now)
            slot["_times"] = [stamp for stamp in times if now - stamp <= 2.0]
            slot["fps"] = round(len(slot["_times"]) / 2.0, 1)
            if key:
                self.last_key[view] = event
            stale: list[queue.Queue[dict[str, Any] | None]] = []
            for sub in self.subs:
                try:
                    sub.put_nowait(event)
                except queue.Full:
                    self.drops += 1
                    try:
                        sub.get_nowait()
                    except queue.Empty:
                        pass
                    try:
                        sub.put_nowait(event)
                    except queue.Full:
                        stale.append(sub)
            if stale:
                self.subs = [sub for sub in self.subs if sub not in stale]

    def mark(self, view: str, **fields: Any) -> None:
        with self.lock:
            self.views[view].update(fields)

    def snapshot(self, state: str, message: str, started_at: float) -> dict[str, Any]:
        now = time.time()
        with self.lock:
            views = {}
            for name, slot in self.views.items():
                last = float(slot["last_frame_at"] or 0)
                views[name] = {
                    "connected": bool(slot["connected"]),
                    "robotSeen": bool(slot["robot_seen"]),
                    "frames": int(slot["frames"]),
                    "fps": slot["fps"],
                    "lastFrameAgeMs": int((now - last) * 1000) if last else None,
                }
            return {
                "ok": True,
                "state": state,
                "message": message,
                "viewers": len(self.subs),
                "hubDrops": self.drops,
                "uptimeS": int(now - started_at) if started_at else 0,
                "views": views,
            }


class LiveSession:
    def __init__(self, root: Path) -> None:
        self.root = root
        self.hub = Hub()
        self.stop = threading.Event()
        self.state = "starting"
        self.message = "Starting live view"
        self.started_at = time.time()
        self.armed = False
        self.port = 0
        self.links: dict[str, Any] = {}
        self.grants: dict[str, dict[str, Any]] = {}
        self.serial = ""
        self.viewer_uid = 0
        self.video_options: Any = None
        self.rejoined = False
        self.rearmed = False
        self.rejoin_at = 0.0
        self.sensors_on = False
        self._status_lock = threading.Lock()

    def write_status(self) -> None:
        snap = self.hub.snapshot(self.state, self.message, self.started_at)
        snap["port"] = self.port
        path = self.root / "data" / "camera-live-status.json"
        tmp = path.with_suffix(".json.tmp")
        tmp.write_text(json.dumps(snap), encoding="utf-8")
        tmp.replace(path)

    def set_state(self, state: str, message: str) -> None:
        with self._status_lock:
            self.state = state
            self.message = message
        self.write_status()
        print(f"[camera] {state}: {message}", file=sys.stderr, flush=True)


SESSION: LiveSession | None = None


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt: str, *args: Any) -> None:
        return

    def do_GET(self) -> None:  # noqa: N802
        session = SESSION
        if session is None:
            self._json(503, {"ok": False, "error": "Live view is not running"})
            return
        path = self.path.split("?", 1)[0]
        if path == "/status":
            self._json(200, session.hub.snapshot(session.state, session.message, session.started_at))
            return
        if path == "/frames":
            self._frames(session)
            return
        if path == "/stop":
            session.stop.set()
            self._json(200, {"ok": True})
            threading.Thread(target=session.shutdown_server, daemon=True).start()
            return
        self._json(404, {"ok": False, "error": "Not found"})

    def do_POST(self) -> None:  # noqa: N802
        self.do_GET()

    def _json(self, status: int, payload: dict[str, Any]) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def _frames(self, session: LiveSession) -> None:
        sub = session.hub.subscribe()
        try:
            self.send_response(200)
            self.send_header("Content-Type", "text/event-stream")
            self.send_header("Cache-Control", "no-cache")
            self.send_header("Connection", "close")
            self.end_headers()
            # Safari will not deliver the first events until about 2KB has arrived.
            self.wfile.write(b": " + (b" " * 2048) + b"\n\n")
            self.wfile.flush()
            while not session.stop.is_set():
                try:
                    item = sub.get(timeout=5)
                except queue.Empty:
                    self.wfile.write(b": ping\n\n")
                    self.wfile.flush()
                    continue
                if item is None:
                    break
                line = "data: " + json.dumps(item, separators=(",", ":")) + "\n\n"
                self.wfile.write(line.encode("utf-8"))
                self.wfile.flush()
        except Exception:
            pass
        finally:
            session.hub.unsubscribe(sub)


def annexb_has_idr(data: bytes) -> bool:
    """True when the access unit contains an H.264 IDR NAL (type 5).

    The Agora frame_type field is often 0 on this path. Waiting for
    frame_type == 3 leaves the browser black forever.
    """
    size = len(data)
    index = 0
    while index + 4 < size:
        if data[index] == 0 and data[index + 1] == 0 and data[index + 2] == 1:
            nal = data[index + 3]
            index += 3
        elif (
            data[index] == 0
            and data[index + 1] == 0
            and data[index + 2] == 0
            and data[index + 3] == 1
            and index + 4 < size
        ):
            nal = data[index + 4]
            index += 4
        else:
            index += 1
            continue
        if (nal & 0x1F) == 5:
            return True
    return False


def copy_frame(image_buffer: Any, length: int) -> bytes:
    try:
        size = int(length or 0)
    except (TypeError, ValueError):
        size = 0
    if size <= 0:
        return b""
    try:
        return bytes(image_buffer[:size])
    except Exception:
        pass
    if isinstance(image_buffer, (bytes, bytearray)):
        return bytes(image_buffer[:size])
    return b""


def join_views(session: LiveSession, serial: str, uid: int, grants: dict[str, dict[str, Any]]) -> Any:
    from agora.rtc.agora_service import AgoraService
    from agora.rtc.agora_base import (
        AgoraServiceConfig,
        ChannelProfileType,
        ClientRoleType,
        RTCConnConfig,
        VideoStreamType,
        VideoSubscriptionOptions,
    )
    from agora.rtc.video_encoded_frame_observer import IVideoEncodedFrameObserver

    area = int(os.environ.get("YARBO_AGORA_AREA") or DEFAULT_AREA_CODE)
    app_id = next(iter(grants.values()))["app_id"]
    config = AgoraServiceConfig()
    if hasattr(config, "appid"):
        config.appid = app_id
    else:
        config.app_id = app_id
    if hasattr(config, "enable_video"):
        config.enable_video = 1
    if hasattr(config, "enable_audio_processor"):
        config.enable_audio_processor = 0
    if hasattr(config, "enable_audio_device"):
        config.enable_audio_device = 0
    if hasattr(config, "area_code"):
        config.area_code = area
    log_path = str(session.root / "data" / "agora.log")
    if hasattr(config, "log_path"):
        config.log_path = log_path

    service = AgoraService()
    service.initialize(config)
    session.grants = grants
    session.serial = serial
    session.viewer_uid = uid
    held: list[Any] = []

    class Encoded(IVideoEncodedFrameObserver):
        def __init__(self, view: str) -> None:
            super().__init__()
            self.view = view

        def on_encoded_video_frame(self, frame_uid, image_buffer, length, info):  # type: ignore[no-untyped-def]
            # Every encoded frame has to be forwarded. Dropping some of them
            # removes the keyframe the browser needs, and the tiles stay black.
            data = copy_frame(image_buffer, length)
            if not data:
                return 1
            frame_type = getattr(info, "frame_type", None)
            key = annexb_has_idr(data) or frame_type == 3
            pts = int(getattr(info, "capture_time_ms", 0) or 0)
            try:
                uid_num = int(frame_uid)
            except (TypeError, ValueError):
                uid_num = 0
            session.hub.push(self.view, key, pts, data, uid_num)
            return 1

    options = VideoSubscriptionOptions(
        type=VideoStreamType.VIDEO_STREAM_HIGH,
        encodedFrameOnly=True,
    )
    session.video_options = options
    publish_cls = None
    try:
        from agora.rtc.agora_base import RtcConnectionPublishConfig

        publish_cls = RtcConnectionPublishConfig
    except Exception:
        publish_cls = None

    base = None
    for name in (
        "agora.rtc.rtc_connection_observer",
        "agora.rtc.agora_base",
        "agora.rtc.rtc_connection",
    ):
        try:
            import importlib

            mod = importlib.import_module(name)
            base = getattr(mod, "IRTCConnectionObserver", None)
            if base:
                break
        except Exception:
            continue

    for view, grant in grants.items():
        con_config = RTCConnConfig(
            client_role_type=ClientRoleType.CLIENT_ROLE_BROADCASTER,
            channel_profile=ChannelProfileType.CHANNEL_PROFILE_LIVE_BROADCASTING,
            auto_subscribe_audio=0,
            auto_subscribe_video=0,
        )
        if publish_cls is not None:
            try:
                conn = service.create_rtc_connection(
                    con_config,
                    publish_cls(is_publish_audio=False, is_publish_video=False),
                )
            except TypeError:
                conn = service.create_rtc_connection(con_config)
        else:
            conn = service.create_rtc_connection(con_config)
        if not conn:
            session.hub.mark(view, connected=False)
            continue
        salt = decode_salt(grant["salt"])
        enc_cls = None
        try:
            from agora.rtc.agora_base import EncryptionConfig

            enc_cls = EncryptionConfig
        except Exception:
            enc_cls = None
        if enc_cls is not None and hasattr(conn, "enable_encryption"):
            try:
                conn.enable_encryption(
                    1,
                    enc_cls(encryption_mode=7, encryption_key=grant["key"], encryption_kdf_salt=bytearray(salt)),
                )
            except Exception:
                print(f"[camera] {view} encryption was rejected", file=sys.stderr, flush=True)
                session.hub.mark(view, connected=False)
                continue
        session.links[view] = conn
        local_user = conn.get_local_user()
        observer = Encoded(view)
        # SDK 2.4 exposes this on the connection. The local-user name is
        # private, so looking it up there registers nothing and every tile
        # stays on "waiting" after the channel has joined.
        listened = False
        for target in (conn, local_user):
            register = getattr(target, "register_video_encoded_frame_observer", None) if target is not None else None
            if not callable(register):
                continue
            try:
                ret = register(observer)
            except Exception as exc:  # noqa: BLE001
                print(f"[camera] {view} picture listener was rejected: {exc}", file=sys.stderr, flush=True)
                continue
            if not isinstance(ret, int) or ret >= 0:
                listened = True
                break
        if not listened:
            print(f"[camera] {view} has no picture listener", file=sys.stderr, flush=True)
            session.hub.mark(view, connected=False)
            continue
        saw_callback = False
        if base is not None and hasattr(conn, "register_observer"):
            class _Conn(base):  # type: ignore[misc, valid-type]
                def __init__(self, name: str) -> None:
                    super().__init__()
                    self.name = name

                def on_connected(self, agora_rtc_conn, conn_info, reason):  # type: ignore[no-untyped-def]
                    session.hub.mark(self.name, connected=True)

                def on_user_joined(self, agora_rtc_conn, user_id):  # type: ignore[no-untyped-def]
                    if str(user_id) == "1":
                        session.hub.mark(self.name, robot_seen=True)

            try:
                conn.register_observer(_Conn(view))
                saw_callback = True
            except Exception:
                saw_callback = False
        # Subscribe before connect. Doing it afterwards misses the robot's first pictures.
        if local_user is not None:
            local_user.subscribe_all_video(options)
        ret = conn.connect(grant["token"], channel_name(serial, view), str(uid))
        if isinstance(ret, int) and ret < 0:
            session.hub.mark(view, connected=False)
        elif not saw_callback:
            session.hub.mark(view, connected=True)
        held.append((conn, observer, local_user))

    return service, held


def prepare_sensors(session: LiveSession) -> None:
    """Keep the cameras awake. The manual check must go out before the toggle.

    overtime is 30 seconds, so this pair is repeated about every 10 seconds
    while live view is armed. One shot lets the robot stop publishing.
    """
    publish_vision("set_manul_camera_check", {"state": 1, "overtime": 30})
    publish_vision("camera_toggle", {"enabled": True})
    session.sensors_on = True


def arm(session: LiveSession) -> None:
    """Ask the robot to publish. Retry once if it is asleep or refuses.

    A missing acknowledgement still counts as sent. The SDK has no
    registered topic for this command, and blocking on that used to
    stop live view before any picture could start.
    """
    last_error = "The robot did not confirm live view"
    for attempt in range(2):
        try:
            result = publish_vision("smart_vision_control", {"state": 1}, ack=True)
        except RuntimeError as exc:
            last_error = str(exc)
            if attempt == 0:
                _wake_once()
            continue
        session.armed = True
        if result.get("acked") or attempt == 1:
            return
        _wake_once()
    raise RuntimeError(last_error)


def _wake_once() -> None:
    try:
        agent_request(
            {"op": "telemetry", "timeout": 8, "wifi": False, "prefer_cache": False},
            timeout=12,
        )
    except Exception:
        pass


def heartbeat(session: LiveSession) -> None:
    prepare_sensors(session)


def disarm(session: LiveSession) -> None:
    if not session.armed:
        return
    try:
        publish_vision("smart_vision_control", {"state": 0})
    except Exception as exc:  # noqa: BLE001
        print(f"[camera] stop vision failed: {exc}", file=sys.stderr, flush=True)
    session.armed = False


def finish_session(session: LiveSession) -> None:
    session.stop.set()
    shutdown = getattr(session, "shutdown_server", None)
    if callable(shutdown):
        shutdown()


def subscribe_again(session: LiveSession) -> None:
    """Ask for pictures again without leaving the channel.

    Disconnecting unregisters the picture listener, so a rejoin leaves
    the tiles on "waiting" for good.
    """
    options = session.video_options
    if options is None:
        return
    for view, conn in session.links.items():
        try:
            user = conn.get_local_user()
            if user is not None:
                user.subscribe_all_video(options)
        except Exception:
            print(f"[camera] {view} subscribe again failed", file=sys.stderr, flush=True)


def watch_lease(session: LiveSession, armed_at: float) -> None:
    last_beat = time.time()
    last_link = 0.0
    quiet_since: float | None = None
    resubscribed = False
    while not session.stop.is_set():
        now = time.time()
        snap = session.hub.snapshot(session.state, session.message, session.started_at)
        frames = sum(int(slot.get("frames") or 0) for slot in snap["views"].values())
        robots = sum(1 for slot in snap["views"].values() if slot.get("robotSeen"))
        if frames == 0 and session.state == "live":
            if not resubscribed and now - armed_at >= 12:
                resubscribed = True
                subscribe_again(session)
            waiting = (
                "The robot joined the cameras. Waiting for the first picture."
                if robots
                else "Cameras joined. Waiting for the robot to send pictures."
            )
            if session.message != waiting:
                session.set_state("live", waiting)
        elif frames > 0 and session.message:
            session.set_state("live", "")
        if now - armed_at >= LEASE_SECONDS:
            session.set_state("stopped", "Live view stopped after 30 minutes. Press Start to watch again.")
            finish_session(session)
            return
        if now - last_link >= LINK_CHECK_S:
            last_link = now
            if link_is_cellular(cached_raw()):
                session.set_state("stopped", "Live view stopped because the robot is on 4G.")
                finish_session(session)
                return
        viewers = session.hub.subscriber_count()
        if viewers == 0:
            if quiet_since is None:
                quiet_since = now
            limit = IDLE_AFTER_VIEWER_S if session.hub.saw_viewer() else IDLE_BEFORE_VIEWER_S
            if now - quiet_since >= limit:
                session.set_state("stopped", "Live view stopped because the page closed.")
                finish_session(session)
                return
        else:
            quiet_since = None
        if now - last_beat >= HEARTBEAT_S:
            try:
                heartbeat(session)
            except Exception as exc:  # noqa: BLE001
                print(f"[camera] heartbeat failed: {exc}", file=sys.stderr, flush=True)
            last_beat = now
        session.write_status()
        session.stop.wait(1.0)


def run(root: Path) -> int:
    global SESSION
    session = LiveSession(root)
    SESSION = session
    (root / "data").mkdir(parents=True, exist_ok=True)
    httpd: ThreadingHTTPServer | None = None
    service = None
    held: list[Any] = []

    def shutdown() -> None:
        if httpd is not None:
            threading.Thread(target=httpd.shutdown, daemon=True).start()

    session.shutdown_server = shutdown  # type: ignore[attr-defined]

    try:
        ensure_agora(session, root)
        ensure_yarbo_sdk(session)
        serial = read_serial(root)
        email, password = read_cloud(root)
        session.set_state("starting", "Signing in to Yarbo")
        jwt = login_jwt(email, password)
        uid = random.randint(1024, 999999)
        grants: dict[str, dict[str, Any]] = {}
        for view in VIEWS:
            grants[view] = request_token(jwt, serial, view, uid)
        if link_is_cellular(cached_raw()):
            raise RuntimeError("Live view stays off while the robot is on 4G. It uses home Wi-Fi or HaLow.")
        session.set_state("starting", "Joining the cameras")
        service, held = join_views(session, serial, uid, grants)
        deadline = time.time() + 12.0
        missing = list(VIEWS)
        while time.time() < deadline and missing:
            snap = session.hub.snapshot(session.state, session.message, session.started_at)
            missing = [
                name
                for name, slot in snap["views"].items()
                if not slot.get("connected")
            ]
            if missing:
                time.sleep(0.2)
        if missing:
            raise RuntimeError(
                "These cameras did not join: " + ", ".join(missing) + ". Press Start live view again."
            )
        if link_is_cellular(cached_raw()):
            raise RuntimeError("Live view stays off while the robot is on 4G. It uses home Wi-Fi or HaLow.")
        session.set_state("starting", "Turning the cameras on")
        prepare_sensors(session)
        session.set_state("starting", "Asking the robot to publish")
        arm(session)
        # 8766 is the Matter agent. Binding there raises Errno 98 and live view
        # never starts, even though the robot itself is online.
        port = int(os.environ.get("YARBO_CAMERA_PORT") or 8767)
        if port == 8766:
            port = 8767
        httpd = None
        bind_error: Exception | None = None
        for _ in range(10):
            try:
                httpd = ThreadingHTTPServer(("127.0.0.1", port), Handler)
                break
            except OSError as exc:
                bind_error = exc
                time.sleep(0.2)
        if httpd is None:
            raise bind_error or OSError("Live view could not open its picture port")
        session.port = port
        session.set_state("live", "Live view is on")
        threading.Thread(target=watch_lease, args=(session, time.time()), daemon=True).start()
        httpd.serve_forever(poll_interval=0.5)
    except ImportError as exc:
        missing = str(exc)
        if "yarbo" in missing:
            text = "The Yarbo sign-in library is missing for live view. Use Settings → Panel updates → Update to latest, then try again."
        else:
            text = "The live camera library is not installed yet. Use Settings → Panel updates → Update to latest, then try again."
        session.set_state("error", text)
        return 1
    except Exception as exc:  # noqa: BLE001
        text = str(exc) or "Live view failed"
        if "Errno 111" in text or "Connection refused" in text:
            text = "Live view could not reach Yarbo. Wait until the robot shows online, then press Start live view again."
        if "Errno 98" in text or "Address already in use" in text:
            text = "Live view could not open its picture port. Press Stop, then Start live view again."
        session.set_state("error", text)
        return 1
    finally:
        session.stop.set()
        try:
            disarm(session)
        except Exception:
            pass
        for conn, _observer, _user in held:
            try:
                conn.disconnect()
            except Exception:
                pass
            try:
                conn.release()
            except Exception:
                pass
        if service is not None:
            try:
                service.release()
            except Exception:
                pass
        if httpd is not None:
            try:
                httpd.server_close()
            except Exception:
                pass
        session.write_status()
    return 0


def self_test() -> int:
    assert link_name({"net_type": "1"}) == "4G"
    assert link_name({"net_type": "wifi"}) == "WiFi"
    assert link_name({"halow_status": 1, "net_type": "1"}) == "HaLow"
    assert link_name({"route_priority": {"wwan0": 10, "wlan0": 100}}) == "4G"
    assert link_name({}) == "Unknown"
    assert link_is_cellular({"net_type": "4g"}) is True
    assert link_is_cellular({"net_type": "wifi"}) is False
    assert channel_name("YB1", "middle") == "YB1_middle_image"
    app_id = "ab" * 16
    token = "007" + base64.b64encode(zlib.compress(b"xx" + app_id.encode() + b"yy")).decode()
    assert app_id_from_token(token) == app_id
    salt = base64.b64encode(b"s" * 32).decode()
    assert decode_salt(salt) == b"s" * 32
    assert find_jwt({"nested": {"accessToken": "aaa." + ("b" * 20) + "." + ("c" * 20)}}) == "aaa." + ("b" * 20) + "." + ("c" * 20)
    assert find_jwt({"password": "secret"}) is None
    idr = b"\x00\x00\x00\x01\x67\x42" + b"\x00\x00\x00\x01\x65\x88"
    assert annexb_has_idr(idr) is True
    assert annexb_has_idr(b"\x00\x00\x00\x01\x41\x9a") is False
    print("ok")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Yarbo live camera listener")
    parser.add_argument("--root", default="")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        return self_test()
    root = Path(args.root or Path(__file__).resolve().parents[1])
    return run(root)


if __name__ == "__main__":
    raise SystemExit(main())
