#!/usr/bin/env python3
"""USB helper for M5Stack PaperMono (beta): list ports, flash firmware, push Wi-Fi config."""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from pathlib import Path

ROOT = Path(os.environ.get("PAPERMONO_ROOT") or Path(__file__).resolve().parents[1])
FIRMWARE_BIN = ROOT / "firmware" / "papermono" / ".pio" / "build" / "papermono" / "firmware.bin"


def emit(payload: dict) -> None:
    json.dump(payload, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")


def list_ports() -> dict:
    try:
        from serial.tools import list_ports
    except ImportError:
        return {
            "ok": False,
            "needs_usb_tools": True,
            "error": "pyserial is not installed on this host.",
            "ports": [],
        }

    ports = []
    for info in list_ports.comports():
        desc = f"{info.device} — {info.description or 'serial'}"
        ports.append(
            {
                "device": info.device,
                "description": info.description or "",
                "hwid": info.hwid or "",
                "label": desc,
            }
        )
    return {"ok": True, "ports": ports}


def send_config(port: str, ssid: str, password: str, panel_url: str, token: str, name: str) -> dict:
    try:
        import serial
    except ImportError:
        return {
            "ok": False,
            "needs_usb_tools": True,
            "error": "pyserial is not installed on this host.",
        }

    line = json.dumps(
        {
            "ssid": ssid,
            "password": password,
            "panel_url": panel_url.rstrip("/"),
            "token": token,
            "name": name,
        },
        ensure_ascii=False,
    )
    payload = ("CFG:" + line + "\n").encode("utf-8")

    try:
        with serial.Serial(port, 115200, timeout=2) as ser:
            time.sleep(1.6)
            ser.reset_input_buffer()
            ser.write(payload)
            ser.flush()
            time.sleep(0.4)
            ack = ser.read(512).decode("utf-8", errors="replace")
    except Exception as exc:
        return {"ok": False, "error": f"USB serial failed: {exc}"}

    ok = "CFG_OK" in ack or ack.strip() == ""
    return {
        "ok": True if ok else False,
        "error": None if ok else f"Device did not acknowledge config ({ack.strip()[:200]})",
        "ack": ack.strip()[:400],
    }


def flash_firmware(port: str) -> dict:
    if not FIRMWARE_BIN.is_file() or FIRMWARE_BIN.stat().st_size < 1024:
        return {
            "ok": False,
            "error": (
                "Firmware binary is not built yet. On the panel host, from the project root run: "
                "pip3 install platformio && pio run -d firmware/papermono"
            ),
            "firmware_path": str(FIRMWARE_BIN),
        }

    try:
        import esptool
    except ImportError:
        return {
            "ok": False,
            "needs_usb_tools": True,
            "error": "esptool is not installed on this host.",
        }

    argv = [
        "--chip",
        "esp32s3",
        "--port",
        port,
        "--baud",
        "460800",
        "write_flash",
        "-z",
        "0x0",
        str(FIRMWARE_BIN),
    ]
    try:
        esptool.main(argv)
    except SystemExit as exc:
        code = exc.code if isinstance(exc.code, int) else 1
        if code not in (0, None):
            return {"ok": False, "error": f"esptool exited with status {code}"}
    except Exception as exc:
        return {"ok": False, "error": f"esptool failed: {exc}"}

    return {"ok": True, "firmware_path": str(FIRMWARE_BIN)}


def install_tools() -> dict:
    import subprocess

    cmd = [sys.executable, "-m", "pip", "install", "--disable-pip-version-check", "pyserial", "esptool"]
    try:
        completed = subprocess.run(
            cmd,
            check=False,
            capture_output=True,
            text=True,
            timeout=120,
        )
    except Exception as exc:
        return {"ok": False, "error": f"Could not install USB tools: {exc}"}

    log = ((completed.stdout or "") + "\n" + (completed.stderr or "")).strip()
    if completed.returncode != 0:
        return {
            "ok": False,
            "error": "pip could not install pyserial and esptool.",
            "log": log[-1200:],
        }

    try:
        import esptool  # noqa: F401
        from serial.tools import list_ports  # noqa: F401
    except ImportError:
        return {
            "ok": False,
            "error": "pip finished but Python still cannot import pyserial/esptool.",
            "log": log[-1200:],
        }

    return {"ok": True, "log": log[-800:]}


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="cmd", required=True)
    sub.add_parser("ports")
    sub.add_parser("install_tools")
    flash = sub.add_parser("flash")
    cfg = sub.add_parser("config")
    for p in (flash, cfg):
        p.add_argument("--port", required=True)
        p.add_argument("--ssid", required=True)
        p.add_argument("--password", default="")
        p.add_argument("--panel-url", required=True)
        p.add_argument("--token", required=True)
        p.add_argument("--name", default="PaperMono")
    args = parser.parse_args()

    if args.cmd == "ports":
        emit(list_ports())
        return 0

    if args.cmd == "install_tools":
        result = install_tools()
        emit(result)
        return 0 if result.get("ok") else 1

    if args.cmd == "flash":
        flashed = flash_firmware(args.port)
        if not flashed.get("ok"):
            emit(flashed)
            return 1
        time.sleep(2.5)
        configured = send_config(
            args.port, args.ssid, args.password, args.panel_url, args.token, args.name
        )
        emit(
            {
                "ok": bool(configured.get("ok")),
                "flashed": True,
                "configured": bool(configured.get("ok")),
                "error": configured.get("error"),
                "ack": configured.get("ack"),
            }
        )
        return 0 if configured.get("ok") else 1

    result = send_config(args.port, args.ssid, args.password, args.panel_url, args.token, args.name)
    emit(result)
    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
