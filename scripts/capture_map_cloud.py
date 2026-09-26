#!/usr/bin/env python3
"""Listen on Yarbo cloud MQTT for map-save command names. Does not publish.

Usage (from the repo root, with cloud credentials in data/cloud-config.json):

  .venv/bin/python scripts/capture_map_cloud.py 180

While this runs, save a map in the official Yarbo app or Yardstick.
The script only logs command names, payload size, and JSON keys — not coordinates.
"""

from __future__ import annotations

import json
import re
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
DUMP_DIR = ROOT / "debug" / "map-dumps"


def serial_from_php_config(path: Path) -> str:
    if not path.is_file():
        return ""
    match = re.search(r"'serial'\s*=>\s*'([^']+)'", path.read_text(encoding="utf-8"))
    return match.group(1).strip() if match else ""


def load_cloud_config(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError("cloud-config.json must be an object")
    return data


def summarize_payload(data: Any) -> dict[str, Any]:
    if not isinstance(data, dict):
        return {"decoded_type": type(data).__name__}
    nested = data.get("data")
    return {
        "decoded_keys": sorted(str(k) for k in data.keys()),
        "feedback_topic": data.get("topic") if isinstance(data.get("topic"), str) else None,
        "state": data.get("state"),
        "data_keys": sorted(str(k) for k in nested.keys()) if isinstance(nested, dict) else None,
    }


def write_commands_file(path: Path | None, commands: dict[str, int]) -> None:
    if path is None:
        return
    path.write_text(json.dumps(commands), encoding="utf-8")


def main() -> int:
    import argparse

    parser = argparse.ArgumentParser(description="Listen for Yarbo map-save MQTT commands")
    parser.add_argument("seconds", nargs="?", type=int, default=180)
    parser.add_argument("--commands-file", default="")
    parser.add_argument("--stop-file", default="")
    args = parser.parse_args()
    seconds = max(10, int(args.seconds))
    commands_file = Path(args.commands_file) if args.commands_file else None
    stop_file = Path(args.stop_file) if args.stop_file else None

    try:
        from yarbo_robot_sdk import YarboClient
        from yarbo_robot_sdk.codec import decode_mqtt_payload
    except ImportError:
        print("yarbo_robot_sdk is not installed. Use the project venv.", file=sys.stderr)
        return 1

    cloud_path = ROOT / "data" / "cloud-config.json"
    if not cloud_path.is_file():
        print(f"Missing {cloud_path}", file=sys.stderr)
        return 1

    config = load_cloud_config(cloud_path)
    email = str(config.get("email") or "").strip()
    password = str(config.get("password") or "").strip()
    serial = serial_from_php_config(ROOT / "config.php")
    if not email or not password:
        print("cloud-config.json needs email and password", file=sys.stderr)
        return 1
    if not serial or serial.startswith("YOUR_"):
        print("config.php must set serial", file=sys.stderr)
        return 1

    DUMP_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    out_path = DUMP_DIR / f"mqtt_cloud_capture_{stamp}.jsonl"

    client = YarboClient()
    commands: dict[str, int] = {}
    count = 0
    out_path.write_text("", encoding="utf-8")

    def log(entry: dict[str, Any]) -> None:
        nonlocal count
        count += 1
        with out_path.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(entry, separators=(",", ":")) + "\n")
        label = entry.get("command") or entry.get("feedback_topic") or entry.get("topic")
        print(f"[{entry['captured_at']}] {label} ({entry.get('payload_bytes', 0)} bytes)")
        cmd = entry.get("command")
        if isinstance(cmd, str) and cmd:
            commands[cmd] = commands.get(cmd, 0) + 1
            write_commands_file(commands_file, commands)
        feedback = entry.get("feedback_topic")
        if isinstance(feedback, str) and feedback:
            commands[feedback] = commands.get(feedback, 0) + 1
            write_commands_file(commands_file, commands)

    def on_bytes(topic: str, payload: bytes) -> None:
        parts = topic.split("/")
        command = parts[-1] if parts else topic
        entry: dict[str, Any] = {
            "captured_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "topic_tail": command,
            "payload_bytes": len(payload),
        }
        if "/app/" in topic:
            entry["direction"] = "app_publish"
            entry["command"] = command
        else:
            entry["direction"] = "device_feedback"
        try:
            decoded = decode_mqtt_payload(payload)
            entry.update(summarize_payload(decoded))
        except Exception:
            entry["decode_error"] = True
        log(entry)

    try:
        client.login(email, password)
        devices = client.get_devices()
        device = next((d for d in devices if getattr(d, "sn", "") == serial), None)
        if device is None and len(devices or []) == 1:
            device = devices[0]
            serial = device.sn
        if device is None:
            print("Robot serial not found on this Yarbo account", file=sys.stderr)
            return 1
        type_id = getattr(device, "type_id", None) or "yarbo_Y"

        mqtt = client._ensure_mqtt_for(serial)
        client.subscribe_data_feedback(serial, type_id, lambda topic, data: log({
            "captured_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "direction": "device_feedback",
            "topic": "data_feedback",
            "payload_bytes": 0,
            **summarize_payload(data),
        }))
        try:
            mqtt.subscribe(f"snowbot/{serial}/app/#", on_bytes)
            app_sub = True
        except Exception as exc:  # noqa: BLE001
            app_sub = False
            print(f"Could not subscribe to app/# ({exc}). Listening on data_feedback only.")

        print(f"Cloud capture {seconds}s → {out_path}")
        print("Save a map in the Yarbo app or Yardstick now. Ctrl+C to stop.\n")
        deadline = time.time() + seconds
        while time.time() < deadline:
            if stop_file is not None and stop_file.is_file():
                break
            time.sleep(0.25)
    except KeyboardInterrupt:
        print("\nStopped.")
    finally:
        close = getattr(client, "close", None)
        if callable(close):
            close()

    print(f"\nCapture complete. {count} message(s) written to:\n{out_path}")
    if commands:
        print("App commands:")
        for name, times in sorted(commands.items()):
            print(f" - {name} x{times}")
    else:
        print("No app publishes seen. Cloud ACL may hide app/#; check data_feedback topic names instead.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
