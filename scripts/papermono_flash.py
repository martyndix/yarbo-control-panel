#!/usr/bin/env python3
"""USB helper for M5Stack PaperMono and Paper Colour: list ports, flash firmware, push Wi-Fi config."""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from pathlib import Path

ROOT = Path(os.environ.get("PAPERMONO_ROOT") or Path(__file__).resolve().parents[1])
KIND_MONO = "papermono"
KIND_COLOR = "papercolor"
FIRMWARE_BINS = {
    KIND_MONO: ROOT / "firmware" / "papermono" / ".pio" / "build" / "papermono" / "firmware.bin",
    KIND_COLOR: ROOT / "firmware" / "papercolor" / ".pio" / "build" / "papercolor" / "firmware.bin",
}
PIO_HINTS = {
    KIND_MONO: "pip3 install platformio && pio run -d firmware/papermono",
    KIND_COLOR: "pip3 install platformio && pio run -e papercolor -d firmware/papercolor",
}


def normalize_kind(kind: str | None) -> str:
    value = (kind or KIND_MONO).strip().lower().replace(" ", "")
    if value in ("papercolor", "papercolour", "color", "colour"):
        return KIND_COLOR
    return KIND_MONO


def firmware_bin(kind: str) -> Path:
    return FIRMWARE_BINS[normalize_kind(kind)]


def emit(payload: dict) -> None:
    json.dump(payload, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")


def _is_host_uart(info) -> bool:
    """Skip onboard / Bluetooth UARTs that are not a USB PaperMono."""
    device = (getattr(info, "device", None) or "").lower()
    desc = (getattr(info, "description", None) or "").lower()
    name = device.rsplit("/", 1)[-1]
    if name.startswith("ttyama") or name.startswith("ttys"):
        return True
    skip = ("bluetooth", "debug-console", "wlan-debug", "incoming-port")
    return any(token in device or token in desc for token in skip)


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
        if _is_host_uart(info):
            continue
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


def wait_for_serial_port(preferred: str, timeout_s: float = 25.0) -> str:
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        listed = list_ports()
        devices = [row["device"] for row in listed.get("ports") or []]
        if preferred in devices:
            return preferred
        if len(devices) == 1:
            return devices[0]
        time.sleep(0.4)
    return preferred


def open_app_serial(port: str):
    import serial

    ser = serial.Serial()
    ser.port = port
    ser.baudrate = 115200
    ser.timeout = 2
    ser.write_timeout = 2
    ser.dsrdtr = False
    ser.rtscts = False
    try:
        ser.dtr = False
        ser.rts = False
    except Exception:
        pass
    ser.open()
    return ser


def send_config(
    port: str,
    ssid: str,
    password: str,
    panel_url: str,
    token: str,
    name: str,
    extras: dict | None = None,
) -> dict:
    try:
        import serial
    except ImportError:
        return {
            "ok": False,
            "needs_usb_tools": True,
            "error": "pyserial is not installed on this host.",
        }

    body = {
        "ssid": ssid,
        "password": password,
        "panel_url": panel_url.rstrip("/"),
        "token": token,
        "name": name,
    }
    if extras:
        body.update({k: v for k, v in extras.items() if v is not None and v != ""})
    payload = ("CFG:" + json.dumps(body, ensure_ascii=False) + "\n").encode("utf-8")

    last_ack = ""
    last_error = ""
    current = port
    for attempt in range(1, 9):
        current = wait_for_serial_port(current)
        ser = None
        try:
            ser = open_app_serial(current)
            time.sleep(0.6)
            try:
                ser.reset_input_buffer()
            except Exception:
                pass
            ser.write(payload)
            ser.flush()
            time.sleep(1.2)
            last_ack = ser.read(1024).decode("utf-8", errors="replace")
        except Exception as exc:
            last_error = str(exc)
            last_ack = ""
        finally:
            if ser is not None:
                try:
                    ser.close()
                except Exception:
                    pass
        if "CFG_OK" in last_ack:
            return {
                "ok": True,
                "error": None,
                "ack": last_ack.strip()[:400],
                "port": current,
            }
        time.sleep(1.5)

    detail = last_ack.strip()[:200] if last_ack.strip() else (last_error or "empty reply")
    return {
        "ok": False,
        "error": (
            "Tablet did not acknowledge Wi-Fi config (CFG_OK). "
            "Leave it on the setup screen, USB plugged in, then try Send Wi-Fi only. "
            "Last reply: " + detail
        ),
        "ack": last_ack.strip()[:400],
    }


def extras_from_args(args) -> dict:
    extras = {}
    if getattr(args, "brightness", None) is not None:
        extras["brightness"] = args.brightness
    if getattr(args, "lock_screen", None):
        extras["lock_screen"] = args.lock_screen
    if getattr(args, "unlock_page", None):
        extras["unlock_page"] = args.unlock_page
    if getattr(args, "clock_offset", None) is not None:
        extras["clock_offset"] = args.clock_offset
    if getattr(args, "timezone", None):
        extras["clock_tz"] = args.timezone
    if getattr(args, "vestaboard", None) is not None:
        extras["vestaboard_enabled"] = bool(args.vestaboard)
    return extras


def factory_bin(kind: str) -> Path:
    return firmware_bin(kind).parent / "firmware-factory.bin"


def flash_firmware(port: str, kind: str = KIND_MONO) -> dict:
    kind = normalize_kind(kind)
    app = firmware_bin(kind)
    path = factory_bin(kind)
    label = "Paper Colour" if kind == KIND_COLOR else "PaperMono"
    if not path.is_file() or path.stat().st_size < 1024:
        return {
            "ok": False,
            "error": (
                f"{label} USB factory image is not built yet. In Settings → E-paper companions click Build firmware, "
                f"or from the project root run: {PIO_HINTS[kind]}"
            ),
            "firmware_path": str(app),
            "kind": kind,
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
        "--flash_mode",
        "dio",
        "--flash_freq",
        "80m",
        "--flash_size",
        "16MB",
        "0x0",
        str(path),
    ]
    try:
        esptool.main(argv)
    except SystemExit as exc:
        code = exc.code if isinstance(exc.code, int) else 1
        if code not in (0, None):
            return {"ok": False, "error": f"esptool exited with status {code}"}
    except Exception as exc:
        return {"ok": False, "error": f"esptool failed: {exc}"}

    return {"ok": True, "firmware_path": str(path), "kind": kind}


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
        p.add_argument("--kind", default=KIND_MONO)
        p.add_argument("--brightness", type=int, default=None)
        p.add_argument("--lock-screen", default=None)
        p.add_argument("--unlock-page", default=None)
        p.add_argument("--clock-offset", type=int, default=None)
        p.add_argument("--timezone", default=None)
        p.add_argument("--vestaboard", type=int, default=None)
    args = parser.parse_args()

    if args.cmd == "ports":
        emit(list_ports())
        return 0

    if args.cmd == "install_tools":
        result = install_tools()
        emit(result)
        return 0 if result.get("ok") else 1

    if args.cmd == "flash":
        flashed = flash_firmware(args.port, args.kind)
        if not flashed.get("ok"):
            emit(flashed)
            return 1
        time.sleep(6.0)
        configured = send_config(
            args.port,
            args.ssid,
            args.password,
            args.panel_url,
            args.token,
            args.name,
            extras_from_args(args),
        )
        emit(
            {
                "ok": bool(configured.get("ok")),
                "flashed": True,
                "configured": bool(configured.get("ok")),
                "kind": normalize_kind(args.kind),
                "error": configured.get("error"),
                "ack": configured.get("ack"),
            }
        )
        return 0 if configured.get("ok") else 1

    result = send_config(
        args.port,
        args.ssid,
        args.password,
        args.panel_url,
        args.token,
        args.name,
        extras_from_args(args),
    )
    emit(result)
    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
