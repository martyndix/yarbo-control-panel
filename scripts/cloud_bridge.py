#!/usr/bin/env python3
"""Optional Yarbo cloud bridge for map/plan reads via yarbo-data-sdk."""

from __future__ import annotations

import argparse
import asyncio
import json
import sys
import time
from pathlib import Path
from typing import Any


def emit(payload: dict[str, Any]) -> None:
    sys.stdout.write(json.dumps(payload))
    sys.stdout.flush()


def load_config(path: Path) -> dict[str, Any]:
    if not path.is_file():
        raise FileNotFoundError(f"Cloud config not found: {path}")
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError("Cloud config must be a JSON object")
    return data


def sdk_installed() -> bool:
    try:
        import yarbo_robot_sdk  # noqa: F401
        return True
    except ImportError:
        try:
            import yarbo_data_sdk  # noqa: F401
            return True
        except ImportError:
            return False


def _import_client():
    try:
        from yarbo_robot_sdk import YarboClient
    except ImportError:
        from yarbo_data_sdk import YarboClient  # type: ignore
    return YarboClient


def _device_name(device: Any) -> str:
    for attr in ("name", "nickname", "alias", "device_name", "display_name"):
        value = getattr(device, attr, None)
        if value:
            return str(value).strip()
    if isinstance(device, dict):
        for key in ("name", "nickname", "alias", "device_name", "display_name"):
            if device.get(key):
                return str(device[key]).strip()
    return ""


def _devices_public(devices: Any) -> list[dict[str, str]]:
    out: list[dict[str, str]] = []
    if not devices:
        return out
    for candidate in devices:
        sn = _device_serial(candidate)
        name = _device_name(candidate)
        if sn or name:
            out.append({"sn": sn, "name": name})
    return out


def _device_serial(device: Any) -> str:
    for attr in ("sn", "serial", "serial_number"):
        value = getattr(device, attr, None)
        if value:
            return str(value)
    if isinstance(device, dict):
        for key in ("sn", "serial", "serial_number"):
            if device.get(key):
                return str(device[key])
    return ""


def run_unpublished_command(client: Any, device: Any, serial: str, cmd: str, payload: dict[str, Any], timeout: float) -> Any:
    """Publish an unpublished app topic on cloud MQTT and wait for data_feedback.

    Backup/restore commands are not in the official SDK registry, so we publish
    the raw snowbot/{sn}/app/{cmd} topic the phone app uses.
    """
    import threading

    try:
        from yarbo_robot_sdk.codec import encode_mqtt_payload
    except ImportError:
        from yarbo_data_sdk.codec import encode_mqtt_payload  # type: ignore

    if cmd == "":
        raise ValueError("--cmd is required")

    type_id = getattr(device, "type_id", None) or "yarbo_Y"
    mqtt_connect = getattr(client, "mqtt_connect", None)
    if callable(mqtt_connect):
        mqtt_connect()

    done = threading.Event()
    box: dict[str, Any] = {}

    def on_feedback(_topic: str, data: Any) -> None:
        if not isinstance(data, dict):
            return
        if _feedback_matches_command(cmd, data):
            box["data"] = data
            done.set()
            return
        if _looks_like_app_map(data) or _looks_like_app_map(data.get("data")):
            box["maybe"] = data

    subscribe_feedback = getattr(client, "subscribe_data_feedback", None)
    if not callable(subscribe_feedback):
        raise ValueError("SDK subscribe_data_feedback is not available")
    subscribe_feedback(serial, type_id, on_feedback)
    time.sleep(0.45)

    mqtt = client._ensure_mqtt_for(serial)
    topic = f"snowbot/{serial}/app/{cmd}"
    mqtt.publish(topic, encode_mqtt_payload(payload if payload else {}))
    if not done.wait(timeout):
        maybe = box.get("maybe")
        if isinstance(maybe, dict):
            return maybe
        raise TimeoutError(
            f"No cloud reply to {cmd} within {timeout:.0f}s. "
            "The Core must be online on the Yarbo account used in Settings."
        )
    return box.get("data")


def _feedback_matches_command(cmd: str, data: dict[str, Any]) -> bool:
    topic = data.get("topic")
    aliases = {
        cmd,
        cmd.replace("buckup", "backup"),
        cmd.replace("backup", "buckup"),
        "get_map_backup_from_id",
        "get_map_buckup_from_id",
        "upload_cloud_map_backup",
        "upload_cloud_map_buckup",
        "save_clean_area",
        "map_recovery",
    }
    return isinstance(topic, str) and topic in aliases


def _looks_like_app_map(data: Any) -> bool:
    if not isinstance(data, dict):
        return False
    for key in (
        "areas",
        "area",
        "pathways",
        "pathway",
        "nogozones",
        "nogozone",
        "novisionzones",
        "novisionzone",
        "elec_fence",
        "sidewalks",
        "sidewalk",
        "deadends",
        "deadend",
    ):
        zones = data.get(key)
        if isinstance(zones, dict) and isinstance(zones.get("range"), list) and zones["range"]:
            return True
        if not isinstance(zones, list) or not zones:
            continue
        first = zones[0]
        if isinstance(first, dict) and isinstance(first.get("range"), list) and first["range"]:
            return True
    charging = data.get("chargingData")
    if isinstance(charging, dict) and (
        isinstance(charging.get("chargingPoint"), dict) or isinstance(charging.get("charging_point"), dict)
    ):
        return True
    return False


def run_login_test(config: dict[str, Any]) -> dict[str, Any]:
    email = str(config.get("email", "")).strip()
    password = str(config.get("password", "")).strip()
    if not email or not password:
        raise ValueError("Cloud email and password are required in cloud-config.json")

    YarboClient = _import_client()
    client = YarboClient()

    try:
        login = getattr(client, "login", None)
        if login is None:
            raise ValueError("YarboClient.login is not available")
        login_result = login(email, password)
        if asyncio.iscoroutine(login_result):
            raise ValueError("Unexpected async YarboClient.login — update cloud_bridge.py")

        devices = client.get_devices()
        device_count = len(devices) if devices is not None else 0
        return {
            "email": email,
            "device_count": device_count,
            "devices": _devices_public(devices),
        }
    finally:
        close = getattr(client, "close", None)
        if callable(close):
            close()


def run_device_name(config: dict[str, Any], serial: str) -> dict[str, str]:
    data = run_login_test(config)
    devices = data.get("devices") or []
    serial_l = serial.lower()
    for device in devices:
        if str(device.get("sn") or "").lower() == serial_l:
            return {"sn": serial, "name": str(device.get("name") or "")}
    if len(devices) == 1:
        only = devices[0]
        return {"sn": str(only.get("sn") or serial), "name": str(only.get("name") or "")}
    raise ValueError(f"Robot serial {serial} not found in Yarbo account")


def run_action_sync(
    action: str,
    serial: str,
    timeout: float,
    config: dict[str, Any],
    cmd: str = "",
    payload: dict[str, Any] | None = None,
) -> Any:
    email = str(config.get("email", "")).strip()
    password = str(config.get("password", "")).strip()
    if not email or not password:
        raise ValueError("Cloud email and password are required in cloud-config.json")

    YarboClient = _import_client()
    client = YarboClient()

    try:
        login = getattr(client, "login", None)
        if login is None:
            raise ValueError("YarboClient.login is not available")
        login_result = login(email, password)
        if asyncio.iscoroutine(login_result):
            raise ValueError("Unexpected async YarboClient.login — update cloud_bridge.py")

        devices = client.get_devices()
        device = None
        for candidate in devices:
            if _device_serial(candidate) == serial:
                device = candidate
                break
        if device is None:
            raise ValueError(f"Robot serial {serial} not found in Yarbo account")

        mqtt_connect = getattr(client, "mqtt_connect", None)
        if callable(mqtt_connect):
            mqtt_connect()

        bound = client.device(device)
        core = bound.core if hasattr(bound, "core") else bound

        subscribe_feedback = getattr(core, "subscribe_data_feedback", None)
        if callable(subscribe_feedback):
            subscribe_feedback(lambda _topic, _data: None)
            time.sleep(0.35)

        if action == "read_all_plan":
            return core.read_all_plan(timeout=timeout)
        if action == "get_map":
            return core.get_map(timeout=timeout)
        if action == "read_gps_ref":
            return core.read_gps_ref(timeout=timeout)
        if action == "get_device_msg":
            return core.get_device_msg(timeout=timeout)
        if action == "command":
            return run_unpublished_command(client, device, serial, cmd, payload or {}, timeout)

        raise ValueError(f"Unsupported action: {action}")
    finally:
        close = getattr(client, "close", None)
        if callable(close):
            close()


async def login_and_run(
    action: str,
    serial: str,
    timeout: float,
    config: dict[str, Any],
    cmd: str = "",
    payload: dict[str, Any] | None = None,
) -> Any:
    return await asyncio.to_thread(
        run_action_sync, action, serial, timeout, config, cmd, payload
    )


def main() -> int:
    parser = argparse.ArgumentParser(description="Yarbo cloud bridge")
    parser.add_argument(
        "action",
        choices=[
            "status",
            "test-login",
            "device-name",
            "read_all_plan",
            "get_map",
            "read_gps_ref",
            "get_device_msg",
            "command",
        ],
    )
    parser.add_argument("--serial", default="")
    parser.add_argument("--timeout", type=float, default=30.0)
    parser.add_argument("--config", default="")
    parser.add_argument("--cmd", default="")
    parser.add_argument("--payload-file", default="")
    args = parser.parse_args()

    if args.action == "status":
        emit(
            {
                "ok": True,
                "sdk_installed": sdk_installed(),
                "python_version": sys.version.split()[0],
                "python_executable": sys.executable,
            }
        )
        return 0

    if args.action == "test-login":
        if not args.config:
            emit({"ok": False, "error": "--config is required for test-login"})
            return 1
        if not sdk_installed():
            emit(
                {
                    "ok": False,
                    "error": "yarbo-data-sdk is not installed. Run: ./scripts/install.sh",
                }
            )
            return 1
        try:
            config = load_config(Path(args.config))
            data = asyncio.run(asyncio.to_thread(run_login_test, config))
            emit({"ok": True, "login": data, "cloud": True})
            return 0
        except Exception as exc:  # noqa: BLE001
            emit({"ok": False, "error": str(exc), "cloud": True})
            return 1

    if args.action == "device-name":
        if not args.config:
            emit({"ok": False, "error": "--config is required for device-name"})
            return 1
        if not args.serial:
            emit({"ok": False, "error": "--serial is required"})
            return 1
        if not sdk_installed():
            emit(
                {
                    "ok": False,
                    "error": "yarbo-data-sdk is not installed. Run: ./scripts/install.sh",
                }
            )
            return 1
        try:
            config = load_config(Path(args.config))
            data = asyncio.run(asyncio.to_thread(run_device_name, config, args.serial))
            emit({"ok": True, "data": data, "cloud": True})
            return 0
        except Exception as exc:  # noqa: BLE001
            emit({"ok": False, "error": str(exc), "cloud": True})
            return 1

    if not args.config:
        emit({"ok": False, "error": "--config is required for data actions"})
        return 1
    if not args.serial:
        emit({"ok": False, "error": "--serial is required"})
        return 1
    if not sdk_installed():
        emit(
            {
                "ok": False,
                "error": "yarbo-data-sdk is not installed. Run: pip install yarbo-data-sdk",
            }
        )
        return 1

    try:
        config = load_config(Path(args.config))
        payload: dict[str, Any] = {}
        if args.payload_file:
            raw = Path(args.payload_file).read_text(encoding="utf-8")
            decoded = json.loads(raw) if raw.strip() else {}
            if isinstance(decoded, dict):
                payload = decoded
            else:
                emit({"ok": False, "error": "payload file must be a JSON object", "cloud": True})
                return 1
        data = asyncio.run(
            login_and_run(
                args.action,
                args.serial,
                args.timeout,
                config,
                args.cmd,
                payload,
            )
        )
        emit({"ok": True, "data": data, "cloud": True})
        return 0
    except Exception as exc:  # noqa: BLE001 - bridge returns JSON errors to PHP
        emit({"ok": False, "error": str(exc), "cloud": True})
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
