#!/usr/bin/env python3
"""Flash a PaperMono or Paper Colour from a USB setup kit (firmware.bin + config.json)."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
FIRMWARE = HERE / "firmware.bin"
CONFIG = HERE / "config.json"
VENV = HERE / ".venv"


def die(message: str, code: int = 1) -> None:
    print(message, file=sys.stderr)
    raise SystemExit(code)


def venv_python() -> Path:
    if os.name == "nt":
        return VENV / "Scripts" / "python.exe"
    return VENV / "bin" / "python"


def in_kit_venv() -> bool:
    try:
        return Path(sys.prefix).resolve() == VENV.resolve()
    except OSError:
        return False


def deps_ok() -> bool:
    try:
        import esptool  # noqa: F401
        import serial  # noqa: F401
    except ImportError:
        return False
    return True


def pip_install(python: str) -> None:
    cmd = [python, "-m", "pip", "install", "--disable-pip-version-check", "esptool", "pyserial"]
    completed = subprocess.run(cmd, check=False)
    if completed.returncode != 0:
        die("Could not install esptool and pyserial into the kit virtualenv.")


def ensure_deps() -> None:
    if deps_ok():
        return
    py = venv_python()
    if not py.is_file():
        print("Creating a local Python environment in .venv …")
        print("(Homebrew Python blocks system-wide pip, so the kit uses its own folder.)")
        created = subprocess.run([sys.executable, "-m", "venv", str(VENV)], check=False)
        if created.returncode != 0 or not py.is_file():
            die(
                "Could not create .venv. From this unzipped folder run:\n"
                "  python3 -m venv .venv\n"
                "  .venv/bin/pip install esptool pyserial\n"
                "  .venv/bin/python flash.py\n"
                "Windows: py -m venv .venv then .venv\\Scripts\\python.exe -m pip install esptool pyserial"
            )
    print("Installing esptool and pyserial into .venv …")
    pip_install(str(py) if py.is_file() else sys.executable)
    if in_kit_venv():
        if not deps_ok():
            die("esptool/pyserial still missing after install.")
        return
    if not py.is_file():
        die("Kit virtualenv Python is missing after install.")
    os.execv(str(py), [str(py), str(Path(__file__).resolve()), *sys.argv[1:]])


def load_config() -> dict:
    if not CONFIG.is_file():
        die("config.json is missing. Unzip the whole kit into one folder, then run flash.py from that folder.")
    try:
        data = json.loads(CONFIG.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        die(f"config.json is not valid JSON: {exc}")
    if not isinstance(data, dict):
        die("config.json is not an object.")
    for key in ("ssid", "panel_url", "token", "name", "kind"):
        if not str(data.get(key) or "").strip():
            die(f"config.json is missing {key}. Download a new kit from the panel.")
    return data


def skip_port(info) -> bool:
    device = (getattr(info, "device", None) or "").lower()
    desc = (getattr(info, "description", None) or "").lower()
    name = device.rsplit("/", 1)[-1]
    if name.startswith("ttyama") or name.startswith("ttys"):
        return True
    skip = ("bluetooth", "debug-console", "wlan-debug", "incoming-port")
    return any(token in device or token in desc for token in skip)


def list_ports() -> list[dict]:
    from serial.tools import list_ports

    ports = []
    for info in list_ports.comports():
        if skip_port(info):
            continue
        ports.append(
            {
                "device": info.device,
                "label": f"{info.device} — {info.description or 'serial'}",
            }
        )
    return ports


def pick_port(requested: str | None) -> str:
    if requested:
        return requested
    ports = list_ports()
    if not ports:
        die(
            "No USB serial port found. Plug the tablet in, put it in download mode, then try again.\n"
            "Windows: if no COM port appears, install the Espressif USB JTAG/serial driver for ESP32-S3."
        )
    if len(ports) == 1:
        print(f"Using {ports[0]['label']}")
        return str(ports[0]["device"])
    print("More than one serial port. Pass --port with one of:")
    for row in ports:
        print(f"  {row['device']}")
    die("Example: python3 flash.py --port " + str(ports[0]["device"]))


def flash_firmware(port: str) -> None:
    if not FIRMWARE.is_file() or FIRMWARE.stat().st_size < 1024:
        die("firmware.bin is missing or too small. Unzip the whole kit and download a new one if needed.")
    import esptool

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
        str(FIRMWARE),
    ]
    print("Flashing factory image at 0x0 (bootloader + partitions + app) …")
    try:
        esptool.main(argv)
    except SystemExit as exc:
        code = exc.code if isinstance(exc.code, int) else 1
        if code not in (0, None):
            die(f"esptool exited with status {code}")
    except Exception as exc:
        die(f"esptool failed: {exc}")


def wait_for_serial_port(preferred: str) -> str:
    last = preferred
    for _ in range(16):
        found = [row["device"] for row in list_ports()]
        if preferred in found:
            return preferred
        if len(found) == 1:
            if found[0] != last:
                print(f"USB port is now {found[0]}")
            return found[0]
        time.sleep(0.4)
    die(
        "USB serial port disappeared. Unplug, wait 3 seconds, plug back in, "
        "then: python3 flash.py --list-ports"
    )


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


def send_config(port: str, cfg: dict) -> None:
    import serial

    body = {
                "ssid": cfg["ssid"],
                "password": cfg.get("password") or "",
                "panel_url": str(cfg["panel_url"]).rstrip("/"),
                "token": cfg["token"],
                "name": cfg["name"],
            }
    if cfg.get("brightness") is not None:
        body["brightness"] = int(cfg["brightness"])
    if cfg.get("lock_screen"):
        body["lock_screen"] = cfg["lock_screen"]
    if cfg.get("unlock_page"):
        body["unlock_page"] = cfg["unlock_page"]
    if cfg.get("clock_offset") is not None:
        body["clock_offset"] = int(cfg["clock_offset"])
    if cfg.get("clock_tz"):
        body["clock_tz"] = cfg["clock_tz"]
    if "vestaboard_enabled" in cfg:
        body["vestaboard_enabled"] = bool(cfg["vestaboard_enabled"])
    payload = ("CFG:" + json.dumps(body, ensure_ascii=False) + "\n").encode("utf-8")
    print("Sending Wi-Fi and panel URL over USB …")
    last_ack = ""
    last_error = ""
    current = port
    for attempt in range(1, 8):
        current = wait_for_serial_port(current)
        ser = None
        try:
            ser = open_app_serial(current)
            time.sleep(0.4)
            try:
                ser.reset_input_buffer()
            except Exception:
                pass
            ser.write(payload)
            ser.flush()
            time.sleep(0.8)
            last_ack = ser.read(512).decode("utf-8", errors="replace")
        except Exception as exc:
            last_error = str(exc)
            last_ack = ""
            print(f"USB dropped ({exc}). Waiting for the tablet to reappear …")
        finally:
            if ser is not None:
                try:
                    ser.close()
                except Exception:
                    pass
        if "CFG_OK" in last_ack:
            return
        print(f"No CFG_OK yet (try {attempt}/7).")
        time.sleep(1.5)
    detail = last_ack.strip()[:200] if last_ack.strip() else (last_error or "(empty)")
    die(
        "Tablet did not acknowledge config. Unplug USB, short-press power so it shows "
        "PaperMono setup (not the factory demo, not a blinking red LED), plug in again "
        "without holding power, wait 5 seconds, then re-run flash.py. Last reply: "
        + detail
    )


def main() -> int:
    parser = argparse.ArgumentParser(description="Flash this USB setup kit onto a PaperMono or Paper Colour.")
    parser.add_argument("--port", default="", help="Serial port (optional if only one USB device is present)")
    parser.add_argument("--list-ports", action="store_true", help="List USB serial ports and exit")
    args = parser.parse_args()
    ensure_deps()

    if args.list_ports:
        ports = list_ports()
        if not ports:
            print("No USB serial ports found.")
            return 1
        for row in ports:
            print(row["label"])
        return 0

    cfg = load_config()
    label = "Paper Colour" if str(cfg.get("kind")) == "papercolor" else "PaperMono"
    print(f"{label} kit for {cfg['name']}")
    print(f"Panel URL: {cfg['panel_url']}")
    hold = "~3 s" if str(cfg.get("kind")) == "papercolor" else "~2 s"
    print(f"Put the tablet in download mode (hold power {hold}), USB-C plugged in.")
    port = pick_port(args.port.strip() or None)
    flash_firmware(port)
    print("Waiting for the tablet to boot our firmware (e-paper is slow) …")
    time.sleep(6.0)
    send_config(port, cfg)
    print("Done. Keep USB in until the setup screen clears, then ship the tablet to the site Wi-Fi.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
