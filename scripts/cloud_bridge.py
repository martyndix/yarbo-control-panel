#!/usr/bin/env python3
"""Optional Yarbo cloud bridge for map/plan reads via yarbo-data-sdk."""

from __future__ import annotations

import argparse
import asyncio
import json
import sys
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


async def login_and_run(action: str, serial: str, timeout: float, config: dict[str, Any]) -> Any:
    email = str(config.get("email", "")).strip()
    password = str(config.get("password", "")).strip()
    if not email or not password:
        raise ValueError("Cloud email and password are required in cloud-config.json")

    try:
        from yarbo_robot_sdk import YarboClient
    except ImportError:
        from yarbo_data_sdk import YarboClient  # type: ignore

    client = YarboClient()
    await client.login(email, password)

    if action == "read_all_plan":
        return await client.read_all_plan(serial, timeout=timeout)
    if action == "get_map":
        return await client.get_map(serial, timeout=timeout)
    if action == "read_gps_ref":
        return await client.read_gps_ref(serial, timeout=timeout)
    if action == "get_device_msg":
        return await client.get_device_msg(serial, timeout=timeout)

    raise ValueError(f"Unsupported action: {action}")


def main() -> int:
    parser = argparse.ArgumentParser(description="Yarbo cloud bridge")
    parser.add_argument(
        "action",
        choices=["status", "read_all_plan", "get_map", "read_gps_ref", "get_device_msg"],
    )
    parser.add_argument("--serial", default="")
    parser.add_argument("--timeout", type=float, default=30.0)
    parser.add_argument("--config", default="")
    args = parser.parse_args()

    if args.action == "status":
        emit(
            {
                "ok": True,
                "sdk_installed": sdk_installed(),
                "python_version": sys.version.split()[0],
            }
        )
        return 0

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
        data = asyncio.run(login_and_run(args.action, args.serial, args.timeout, config))
        emit({"ok": True, "data": data, "cloud": True})
        return 0
    except Exception as exc:  # noqa: BLE001 - bridge returns JSON errors to PHP
        emit({"ok": False, "error": str(exc), "cloud": True})
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
