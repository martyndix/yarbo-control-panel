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


def esptool_write_flash_argvs(port: str) -> list[list[str]]:
    head = ["--chip", "esp32s3", "--port", port, "--baud", "460800"]
    hyphen = head + [
        "write-flash",
        "-z",
        "--flash-mode",
        "dio",
        "--flash-freq",
        "80m",
        "--flash-size",
        "16MB",
        "0x0",
        str(FIRMWARE),
    ]
    underscore = head + [
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
    return [hyphen, underscore]


def _esptool_usage_error(log: str) -> bool:
    low = log.lower()
    return any(
        token in low
        for token in ("unrecognized arguments", "invalid choice", "unknown command", "ambiguous option")
    )


def flash_firmware(port: str) -> None:
    if not FIRMWARE.is_file() or FIRMWARE.stat().st_size < 1024:
        die("firmware.bin is missing or too small. Unzip the whole kit and download a new one if needed.")

    print("Flashing factory image at 0x0 (bootloader + partitions + app) …")
    for argv in esptool_write_flash_argvs(port):
        proc = subprocess.run(
            [sys.executable, "-m", "esptool", *argv],
            check=False,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
        )
        log = proc.stdout or ""
        if log:
            print(log, end="" if log.endswith("\n") else "\n")
        if proc.returncode == 0:
            return
        if not _esptool_usage_error(log):
            die(f"esptool exited with status {proc.returncode}")
    die("esptool did not accept write-flash arguments (tried hyphen and underscore forms).")


def listed_devices() -> list[str]:
    return [row["device"] for row in list_ports()]


def wait_for_serial_port(preferred: str, timeout_s: float = 40.0) -> str:
    last = preferred
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        found = listed_devices()
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


def wait_for_usb_reboot(preferred: str) -> str:
    deadline = time.time() + 8.0
    while time.time() < deadline:
        if preferred not in listed_devices():
            print("USB serial dropped after reset; waiting for it to come back …")
            break
        time.sleep(0.25)
    port = wait_for_serial_port(preferred, timeout_s=40.0)
    time.sleep(2.5)
    return port


def port_identity(path: str):
    try:
        st = os.stat(path)
    except OSError:
        return None
    return (st.st_dev, st.st_ino, getattr(st, "st_rdev", 0))


def serial_snippet(text: str, limit: int = 160) -> str:
    compact = " ".join((text or "").split())
    if not compact:
        return "(empty)"
    if len(compact) > limit:
        return compact[: limit - 3] + "..."
    return compact


def idle_modem_lines(ser) -> None:
    for _ in range(2):
        try:
            ser.dtr = False
            ser.rts = False
        except Exception:
            pass
    try:
        import termios

        fd = ser.fileno()
        attrs = termios.tcgetattr(fd)
        attrs[2] &= ~termios.HUPCL
        termios.tcsetattr(fd, termios.TCSANOW, attrs)
    except Exception:
        pass


def cdc_host_present(ser) -> None:
    try:
        ser.rts = False
        ser.dtr = True
    except Exception:
        pass


def open_app_serial(port: str):
    import serial

    ser = serial.Serial()
    ser.port = port
    ser.baudrate = 115200
    ser.timeout = 0.4
    ser.write_timeout = 3
    ser.dsrdtr = False
    ser.rtscts = False
    try:
        ser.exclusive = True
    except Exception:
        pass
    try:
        ser.dtr = False
        ser.rts = False
    except Exception:
        pass
    ser.open()
    idle_modem_lines(ser)
    cdc_host_present(ser)
    return ser


def close_serial(ser) -> None:
    if ser is None:
        return
    idle_modem_lines(ser)
    try:
        ser.close()
    except Exception:
        pass


def read_serial_text(ser, timeout_s: float, stop: tuple[str, ...] = ("CFG_OK", "CFG_ERR")) -> str:
    buf = ""
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        try:
            waiting = getattr(ser, "in_waiting", 0) or 0
            chunk = ser.read(waiting or 256)
        except Exception:
            break
        if chunk:
            buf += chunk.decode("utf-8", errors="replace")
            if any(token in buf for token in stop):
                break
        else:
            time.sleep(0.05)
    return buf


def wait_for_app_ready(ser, timeout_s: float = 40.0) -> str:
    buf = ""
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        remain = max(0.05, deadline - time.time())
        buf += read_serial_text(ser, min(0.8, remain), ("CFG_OK", "CFG_ERR", "PAPER_READY"))
        if "CFG_OK" in buf or "CFG_ERR" in buf:
            return buf
        if buf.count("PAPER_READY") >= 2:
            return buf
    return buf


def send_config(port: str, cfg: dict) -> None:
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
    if "remote_url" in cfg:
        body["remote_url"] = str(cfg.get("remote_url") or "").rstrip("/")
    payload = ("CFG:" + json.dumps(body, ensure_ascii=False) + "\n").encode("utf-8")
    print("Sending Wi-Fi and panel URL over USB …")
    last_ack = ""
    last_error = ""
    current = wait_for_serial_port(port, timeout_s=40.0)
    ser = None
    opened_port = ""
    opened_id = None
    deadline = time.time() + 120.0
    attempt = 0
    empty_sends = 0
    try:
        while time.time() < deadline:
            attempt += 1
            current = wait_for_serial_port(current, timeout_s=12.0)
            identity = port_identity(current)
            try:
                devices = listed_devices()
                missing = bool(devices) and current not in devices
            except Exception:
                missing = False
            stale = (
                ser is None
                or not getattr(ser, "is_open", False)
                or opened_port != current
                or (opened_id is not None and identity is not None and identity != opened_id)
                or missing
            )
            if stale:
                close_serial(ser)
                ser = None
                try:
                    ser = open_app_serial(current)
                    opened_port = current
                    opened_id = port_identity(current)
                    print("Waiting for PAPER_READY (e-paper init, USB CDC) …")
                    boot = wait_for_app_ready(ser, 40.0)
                    if boot:
                        last_ack = boot
                    if "CFG_OK" in boot:
                        return
                    if boot.count("PAPER_READY") < 2 and "CFG_ERR" not in boot and attempt < 3:
                        last_error = "no PAPER_READY yet"
                        print(f"USB not listening yet ({serial_snippet(boot)}). Reopening …")
                        close_serial(ser)
                        ser = None
                        time.sleep(1.5)
                        continue
                except Exception as exc:
                    last_error = str(exc)
                    print(f"USB dropped ({exc}). Waiting for the tablet to reappear …")
                    close_serial(ser)
                    ser = None
                    time.sleep(1.5)
                    continue
            try:
                ser.reset_input_buffer()
            except Exception:
                pass
            try:
                ser.write(b"\n")
                ser.flush()
                time.sleep(0.2)
                ser.write(payload)
                ser.flush()
                last_ack = read_serial_text(ser, 8.0)
            except Exception as exc:
                last_error = str(exc)
                last_ack = ""
                print(f"USB dropped ({exc}). Waiting for the tablet to reappear …")
                close_serial(ser)
                ser = None
                time.sleep(1.5)
                continue
            if "CFG_OK" in last_ack:
                return
            print(f"No CFG_OK yet (try {attempt}): {serial_snippet(last_ack)}")
            if not last_ack.strip():
                empty_sends += 1
            else:
                empty_sends = 0
            if empty_sends >= 2 or "CFG_ERR" in last_ack:
                close_serial(ser)
                ser = None
            time.sleep(1.2)
    finally:
        close_serial(ser)
    detail = serial_snippet(last_ack) if last_ack.strip() else (last_error or "(empty)")
    die(
        "Tablet did not acknowledge config. Leave it on the PaperMono setup screen "
        "(not the factory demo, not a blinking red LED), USB plugged in, do not hold power, "
        "then: python3 flash.py --wifi-only. Last reply: " + detail
    )


def main() -> int:
    parser = argparse.ArgumentParser(description="Flash this USB setup kit onto a PaperMono or Paper Colour.")
    parser.add_argument("--port", default="", help="Serial port (optional if only one USB device is present)")
    parser.add_argument("--list-ports", action="store_true", help="List USB serial ports and exit")
    parser.add_argument(
        "--wifi-only",
        action="store_true",
        help="Skip flashing. Push Wi-Fi/config over USB to firmware that is already on the tablet.",
    )
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
    port = pick_port(args.port.strip() or None)
    if args.wifi_only:
        print("Skipping flash. USB in, tablet on the setup screen (do not hold power).")
        send_config(port, cfg)
        print("Done. Keep USB in until the setup screen clears, then ship the tablet to the site Wi-Fi.")
        return 0
    hold = "~3 s" if str(cfg.get("kind")) == "papercolor" else "~2 s"
    print(f"Put the tablet in download mode (hold power {hold}), USB-C plugged in.")
    flash_firmware(port)
    print("Waiting for the tablet to reboot on USB (e-paper is slow) …")
    port = wait_for_usb_reboot(port)
    send_config(port, cfg)
    print("Done. Keep USB in until the setup screen clears, then ship the tablet to the site Wi-Fi.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
