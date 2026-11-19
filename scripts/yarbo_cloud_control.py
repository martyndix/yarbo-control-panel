#!/usr/bin/env python3
"""Async Yarbo control client over official cloud MQTT (app account).

Mirrors the subset of ``yarbo.local.YarboLocalClient`` that ``mqtt_agent.py``
uses, but talks to Yarbo's cloud brokers (legacy / new EMQX) after login —
local LAN :1883 is not used.
"""

from __future__ import annotations

import asyncio
import json
import logging
import threading
import time
from pathlib import Path
from types import SimpleNamespace
from typing import Any

_LOGGER = logging.getLogger("yarbo.cloud_control")

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_TYPE_ID = "yarbo_Y"


class YarboCloudControlClient:
    """Persistent cloud MQTT session for one robot serial."""

    def __init__(
        self,
        email: str,
        password: str,
        sn: str,
        type_id: str = DEFAULT_TYPE_ID,
    ) -> None:
        self.email = email.strip()
        self.password = password
        self.sn = sn.strip()
        self.type_id = (type_id or DEFAULT_TYPE_ID).strip() or DEFAULT_TYPE_ID
        self._sdk: Any = None
        self._connected = False
        self._controller_acquired = False
        self._last_raw: dict[str, Any] | None = None
        self._last_raw_at = 0.0
        self._last_heartbeat: dict[str, Any] | None = None
        self._last_heartbeat_at = 0.0
        self._last_plan_feedback: dict[str, Any] | None = None
        self._last_plan_feedback_at = 0.0
        self._plan_feedback_event = threading.Event()
        self._lock = asyncio.Lock()
        self._msg_lock = asyncio.Lock()

    @property
    def is_connected(self) -> bool:
        return self._connected

    @property
    def controller_acquired(self) -> bool:
        return self._controller_acquired

    async def connect(self) -> None:
        async with self._lock:
            await asyncio.to_thread(self._connect_sync)

    def _connect_sync(self) -> None:
        from yarbo_robot_sdk import YarboClient

        if not self.email or not self.password:
            raise RuntimeError("Cloud email and password are required in Settings → Yarbo")
        if not self.sn:
            raise RuntimeError("Serial number is required in Settings → Yarbo")

        client = YarboClient()
        client.login(self.email, self.password)
        devices = client.get_devices() or []
        matched = None
        for device in devices:
            sn = _device_serial(device)
            if sn and sn.lower() == self.sn.lower():
                matched = device
                break
        if matched is None:
            raise RuntimeError(
                f"Robot serial {self.sn} not found on this Yarbo account. "
                "Check Settings → Yarbo email and serial."
            )
        type_id = str(getattr(matched, "type_id", None) or self.type_id or DEFAULT_TYPE_ID)
        self.type_id = type_id
        self.sn = _device_serial(matched) or self.sn

        # mqtt_connect() is a no-op on current yarbo-data-sdk: the broker
        # session starts on the first subscribe. data_feedback must be
        # registered before get_device_msg, or the snapshot request times out
        # and the status card stays empty.
        client.mqtt_connect()
        try:
            client.subscribe_data_feedback(self.sn, self.type_id, self._on_data_feedback)
        except Exception as exc:  # noqa: BLE001
            _LOGGER.warning("subscribe_data_feedback failed: %s", exc)
        client.subscribe_device_message(self.sn, self.type_id, self._on_device_message)
        try:
            client.subscribe_heart_beat(self.sn, self.type_id, self._on_heart_beat)
        except Exception as exc:  # noqa: BLE001
            _LOGGER.warning("subscribe_heart_beat failed: %s", exc)
        try:
            self._subscribe_plan_feedback_topic(client)
        except Exception as exc:  # noqa: BLE001
            _LOGGER.warning("subscribe plan_feedback topic failed: %s", exc)

        mqtt = client._ensure_mqtt_for(self.sn)
        deadline = time.time() + 8.0
        while not mqtt.is_connected and time.time() < deadline:
            time.sleep(0.1)
        if not mqtt.is_connected:
            raise RuntimeError(
                "Cloud MQTT did not finish connecting. Check internet on the panel host."
            )

        self._sdk = client
        self._connected = True
        _LOGGER.info("Cloud MQTT ready for SN=%s type_id=%s", self.sn, self.type_id)

    def latest_device_msg(self) -> dict[str, Any] | None:
        """Last DeviceMSG of any age, or None when it has no robot fields."""
        raw = self._last_raw
        if not isinstance(raw, dict) or not raw:
            return None
        if not any(raw.get(key) is not None for key in ("StateMSG", "BatteryMSG", "CombinedOdom", "RTKMSG")):
            return None
        return raw

    def heartbeat_age(self) -> float | None:
        if self._last_heartbeat_at <= 0:
            return None
        return max(0.0, time.time() - self._last_heartbeat_at)

    def _remember_device_msg(self, data: Any) -> None:
        if not isinstance(data, dict) or not data:
            return
        unwrapped = _unwrap_device_msg(data)
        if not isinstance(unwrapped, dict):
            return
        if not any(unwrapped.get(key) is not None for key in _DEVICE_MSG_KEYS):
            return
        self._last_raw = unwrapped
        self._last_raw_at = time.time()

    def _on_device_message(self, _topic: str, data: Any) -> None:
        self._remember_device_msg(data)

    def _on_heart_beat(self, _topic: str, data: Any) -> None:
        if isinstance(data, dict) and data:
            self._last_heartbeat = data
            self._last_heartbeat_at = time.time()

    def _on_data_feedback(self, _topic: str, data: Any) -> None:
        if not isinstance(data, dict) or not data:
            return
        self._remember_device_msg(data)
        response_topic = str(data.get("topic") or "")
        payload = data.get("data") if isinstance(data.get("data"), (dict, str)) else data
        if response_topic in ("get_plan_feedback", "plan_feedback") or _looks_like_plan_feedback(payload):
            fb = _unwrap_plan_feedback(payload if isinstance(payload, dict) else data)
            if fb:
                self._store_plan_feedback(fb)

    def _subscribe_plan_feedback_topic(self, client: Any) -> None:
        """Legacy device push topic (still published by some firmware over cloud)."""
        from yarbo_robot_sdk.codec import decode_mqtt_payload

        mqtt = client._ensure_mqtt_for(self.sn)
        topic = f"snowbot/{self.sn}/device/plan_feedback"

        def _wrapper(_topic_str: str, payload: bytes) -> None:
            try:
                data = decode_mqtt_payload(payload)
            except Exception:  # noqa: BLE001
                try:
                    data = json.loads(payload.decode(errors="replace"))
                except Exception:  # noqa: BLE001
                    return
            fb = _unwrap_plan_feedback(data)
            if fb:
                self._store_plan_feedback(fb)

        mqtt.subscribe(topic, _wrapper)

    def _store_plan_feedback(self, feedback: dict[str, Any]) -> None:
        self._last_plan_feedback = feedback
        self._last_plan_feedback_at = time.time()
        self._plan_feedback_event.set()

    def take_cached_plan_feedback(self) -> dict[str, Any] | None:
        """Return last plan_feedback without clearing (agent attaches a copy)."""
        fb = self._last_plan_feedback
        return dict(fb) if isinstance(fb, dict) and fb else None

    async def fetch_plan_feedback(self, timeout: float = 4.0) -> dict[str, Any] | None:
        """Ask for plan progress and wait for data_feedback / plan_feedback."""
        try:
            return await asyncio.to_thread(self._fetch_plan_feedback_sync, timeout)
        except Exception as exc:  # noqa: BLE001
            _LOGGER.debug("fetch_plan_feedback failed: %s", exc)
            return self.take_cached_plan_feedback()

    def _fetch_plan_feedback_sync(self, timeout: float) -> dict[str, Any] | None:
        self._plan_feedback_event.clear()
        before = self._last_plan_feedback_at
        # get_plan_feedback is not in the SDK control_topics registry — raw publish.
        self._publish_sync("get_plan_feedback", {})
        deadline = time.time() + max(0.5, timeout)
        while time.time() < deadline:
            if self._last_plan_feedback_at > before and isinstance(self._last_plan_feedback, dict):
                return dict(self._last_plan_feedback)
            remaining = deadline - time.time()
            if remaining <= 0:
                break
            self._plan_feedback_event.wait(timeout=min(0.5, remaining))
            self._plan_feedback_event.clear()
        return self.take_cached_plan_feedback()

    async def disconnect(self) -> None:
        async with self._lock:
            await asyncio.to_thread(self._disconnect_sync)

    def _disconnect_sync(self) -> None:
        self._connected = False
        self._controller_acquired = False
        sdk = self._sdk
        self._sdk = None
        if sdk is None:
            return
        close = getattr(sdk, "close", None)
        if callable(close):
            try:
                close()
            except Exception:  # noqa: BLE001
                pass

    async def start_polling(
        self,
        interval_seconds: float = 15.0,
        acquire_controller: bool = False,
    ) -> None:
        # DeviceMSG is already subscribed; optional snapshot warm-up.
        _ = interval_seconds
        _ = acquire_controller
        try:
            await self.get_status(timeout=8.0, acquire_controller=False)
        except Exception as exc:  # noqa: BLE001
            _LOGGER.warning("Initial cloud telemetry snapshot failed: %s", exc)

    async def publish_command(self, cmd: str, payload: dict[str, Any] | None = None) -> None:
        body = payload if isinstance(payload, dict) else {}
        await asyncio.to_thread(self._publish_sync, str(cmd), body)

    def _publish_sync(self, cmd: str, payload: dict[str, Any]) -> None:
        sdk = self._require_sdk()
        # Prefer the SDK registry when the topic is known (handles compression).
        try:
            sdk.mqtt_publish_command(self.sn, self.type_id, cmd, payload)
            if cmd == "get_controller":
                self._controller_acquired = True
            return
        except Exception as reg_err:  # noqa: BLE001
            # Drive / get_controller / community light payloads are not always registered.
            _LOGGER.debug("registry publish %s failed (%s); raw topic", cmd, reg_err)

        from yarbo_robot_sdk.codec import encode_mqtt_payload

        mqtt = sdk._ensure_mqtt_for(self.sn)
        topic = f"snowbot/{self.sn}/app/{cmd}"
        mqtt.publish(topic, encode_mqtt_payload(payload if payload else {}))
        if cmd == "get_controller":
            self._controller_acquired = True

    async def get_controller(self, timeout: float | None = None) -> Any:
        _ = timeout
        await self.publish_command("get_controller", {})
        self._controller_acquired = True
        return SimpleNamespace(ok=True)

    async def get_status(
        self,
        timeout: float = 4.0,
        acquire_controller: bool = False,
    ) -> Any:
        if acquire_controller and not self._controller_acquired:
            await self.get_controller()

        # Prefer a fresh push; otherwise request a full snapshot.
        age = time.time() - self._last_raw_at
        if self.latest_device_msg() is not None and age <= max(1.0, timeout):
            return SimpleNamespace(raw=self._last_raw)

        async with self._msg_lock:
            age = time.time() - self._last_raw_at
            if self.latest_device_msg() is not None and age <= max(1.0, timeout):
                return SimpleNamespace(raw=self._last_raw)
            try:
                raw = await asyncio.to_thread(self._get_device_msg_sync, timeout)
            except TimeoutError as exc:
                # SDK raises on missed data_feedback; keep last DeviceMSG push if any.
                _LOGGER.debug("get_device_msg timed out: %s", exc)
                latest = self.latest_device_msg()
                if latest is not None:
                    return SimpleNamespace(raw=latest, stale=True)
                raise
            if isinstance(raw, dict) and raw:
                # request_with_feedback wraps the snapshot under data / DeviceMSG
                self._remember_device_msg(raw)
                latest = self.latest_device_msg()
                if latest is not None:
                    return SimpleNamespace(raw=latest)
            latest = self.latest_device_msg()
            if latest is not None:
                return SimpleNamespace(raw=latest, stale=True)
            return SimpleNamespace(raw=None)

    def _get_device_msg_sync(self, timeout: float) -> Any:
        sdk = self._require_sdk()
        return sdk.get_device_msg(self.sn, self.type_id, timeout=timeout)

    async def get_connected_wifi(self, timeout: float = 1.5) -> Any:
        try:
            data = await asyncio.to_thread(self._wifi_sync, timeout)
            return data
        except Exception:  # noqa: BLE001
            return None

    def _wifi_sync(self, timeout: float) -> Any:
        sdk = self._require_sdk()
        return sdk.get_connect_wifi_name(self.sn, self.type_id, timeout=timeout)

    async def set_sound_param(self, volume: int, switch: int) -> None:
        await self.publish_command(
            "set_sound_param",
            {"volume": int(volume), "switch": int(switch)},
        )

    async def set_sound(self, volume: int, switch: int) -> None:
        await self.set_sound_param(volume, switch)

    async def buzzer(self, state: int = 1) -> None:
        await self.publish_command("buzzer", {"state": int(state)})

    async def play_song(self, song_id: Any) -> None:
        await self.publish_command("song_cmd", {"song_name": str(song_id)})

    def _require_sdk(self) -> Any:
        if self._sdk is None or not self._connected:
            raise RuntimeError("Cloud MQTT is not connected")
        return self._sdk


def _looks_like_plan_feedback(payload: Any) -> bool:
    if not isinstance(payload, dict) or not payload:
        return False
    keys = {
        "actualCleanArea",
        "actual_clean_area",
        "finish_clean_area",
        "total_clean_area",
        "finishCleanArea",
        "totalCleanArea",
        "areaCovered",
        "area_covered",
        "area_ids",
        "areaIds",
        "finish_ids",
        "finishIds",
        "left_time",
        "leftTime",
        "total_time",
        "totalTime",
        "plan_id",
        "planId",
        "duration",
    }
    return any(key in payload for key in keys)


def _unwrap_plan_feedback(payload: Any) -> dict[str, Any] | None:
    if isinstance(payload, str):
        text = payload.strip()
        if not text:
            return None
        try:
            payload = json.loads(text)
        except json.JSONDecodeError:
            return None
    if not isinstance(payload, dict) or not payload:
        return None
    data = payload.get("data")
    if isinstance(data, str):
        try:
            data = json.loads(data)
        except json.JSONDecodeError:
            data = None
    if isinstance(data, dict) and _looks_like_plan_feedback(data):
        return data
    if _looks_like_plan_feedback(payload):
        return payload
    if isinstance(data, dict) and data:
        return data
    return payload if _looks_like_plan_feedback(payload) else None


def _device_serial(device: Any) -> str:
    for attr in ("sn", "serial", "serial_number"):
        value = getattr(device, attr, None)
        if value:
            return str(value).strip()
    if isinstance(device, dict):
        for key in ("sn", "serial", "serial_number"):
            if device.get(key):
                return str(device[key]).strip()
    return ""


_DEVICE_MSG_KEYS = ("StateMSG", "BatteryMSG", "CombinedOdom", "RTKMSG")


def _unwrap_device_msg(raw: dict[str, Any]) -> dict[str, Any]:
    found = _find_device_msg(raw)
    return found if isinstance(found, dict) else raw


def _find_device_msg(raw: Any, depth: int = 0) -> dict[str, Any] | None:
    if isinstance(raw, str):
        text = raw.strip()
        if not text.startswith("{") and not text.startswith("["):
            return None
        try:
            raw = json.loads(text)
        except json.JSONDecodeError:
            return None
    if not isinstance(raw, dict) or depth > 4:
        return None
    if any(raw.get(key) is not None for key in _DEVICE_MSG_KEYS):
        return raw
    for value in raw.values():
        found = _find_device_msg(value, depth + 1)
        if found is not None:
            return found
    return None


def load_cloud_config(root: Path | None = None) -> dict[str, Any]:
    base = root or ROOT
    path = base / "data" / "cloud-config.json"
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return data if isinstance(data, dict) else {}


def load_panel_config(root: Path | None = None) -> dict[str, Any]:
    """Read serial (and optional leftover broker fields) from config.php via a tiny PHP eval."""
    base = root or ROOT
    config_php = base / "config.php"
    if not config_php.is_file():
        return {}
    # Prefer a sibling helper the agent already uses when available.
    try:
        import re

        text = config_php.read_text(encoding="utf-8", errors="replace")
        out: dict[str, Any] = {}
        for key in ("broker_host", "broker_port", "serial"):
            m = re.search(rf"['\"]{key}['\"]\s*=>\s*'([^']*)'", text)
            if not m:
                m = re.search(rf'["\']{key}["\']\s*=>\s*"([^"]*)"', text)
            if m:
                out[key] = m.group(1)
            else:
                m = re.search(rf"['\"]{key}['\"]\s*=>\s*(\d+)", text)
                if m:
                    out[key] = int(m.group(1))
        return out
    except OSError:
        return {}
