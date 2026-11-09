#!/usr/bin/env python3
"""Regression tests for PaperMono USB flash helper argument and CFG payload shaping."""

from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

import papermono_flash as flash  # noqa: E402


class PaperMonoFlashTests(unittest.TestCase):
    def test_esptool_v5_uses_hyphenated_write_flash(self) -> None:
        image = Path("/tmp/firmware-factory.bin")
        hyphen, underscore = flash.esptool_write_flash_argvs("/dev/ttyACM0", image)
        self.assertIn("write-flash", hyphen)
        self.assertIn("--flash-mode", hyphen)
        self.assertIn("--flash-freq", hyphen)
        self.assertIn("--flash-size", hyphen)
        self.assertNotIn("write_flash", hyphen)
        self.assertNotIn("--flash_mode", hyphen)
        self.assertEqual(hyphen[hyphen.index("0x0") + 1], str(image))
        self.assertIn("write_flash", underscore)
        self.assertIn("--flash_mode", underscore)

    def test_usage_error_detects_invalid_choice(self) -> None:
        self.assertTrue(flash._esptool_usage_error("error: argument command: invalid choice: 'write-flash'"))
        self.assertFalse(flash._esptool_usage_error("A fatal error occurred: Failed to connect to ESP32-S3"))

    def test_cfg_payload_is_one_line_json(self) -> None:
        raw = flash.config_payload_bytes(
            "HomeWiFi",
            "secret",
            "http://192.168.1.50:8080/",
            "tok",
            "Kitchen tablet",
            {"brightness": 80, "unlock_page": "house"},
        )
        self.assertTrue(raw.endswith(b"\n"))
        self.assertTrue(raw.startswith(b"CFG:"))
        body = json.loads(raw.decode("utf-8")[4:].strip())
        self.assertEqual(body["ssid"], "HomeWiFi")
        self.assertEqual(body["panel_url"], "http://192.168.1.50:8080")
        self.assertEqual(body["unlock_page"], "house")
        self.assertEqual(body["brightness"], 80)

    def test_cfg_payload_includes_remote_url(self) -> None:
        raw = flash.config_payload_bytes(
            "HomeWiFi",
            "secret",
            "http://192.168.1.50:8080",
            "tok",
            "Kitchen tablet",
            {"remote_url": "https://pi.tailxxxxx.ts.net"},
        )
        body = json.loads(raw.decode("utf-8")[4:].strip())
        self.assertEqual(body["remote_url"], "https://pi.tailxxxxx.ts.net")

    def test_send_config_succeeds_on_cfg_ok(self) -> None:
        class FakeSer:
            is_open = True

            def write(self, data):
                return len(data)

            def flush(self):
                return None

            def reset_input_buffer(self):
                return None

        fake_serial = type(sys)("serial")
        with patch.dict(sys.modules, {"serial": fake_serial}), patch.object(
            flash, "wait_for_serial_port", return_value="/dev/ttyACM0"
        ), patch.object(flash, "listed_devices", return_value=["/dev/ttyACM0"]), patch.object(
            flash, "port_identity", return_value=(1, 2, 3)
        ), patch.object(flash, "open_app_serial", return_value=FakeSer()), patch.object(
            flash, "wait_for_app_ready", return_value="PAPER_READY\nPAPER_READY\n"
        ), patch.object(flash, "read_serial_text", return_value="CFG_OK\n"), patch.object(flash, "close_serial"):
            result = flash.send_config(
                "/dev/ttyACM0",
                "HomeWiFi",
                "secret",
                "http://192.168.1.50:8080",
                "tok",
                "Kitchen",
            )
        self.assertTrue(result["ok"])
        self.assertIn("CFG_OK", result["ack"])

    def test_read_serial_text_keeps_waiting_after_boot_noise(self) -> None:
        class Scripted:
            def __init__(self) -> None:
                self.chunks = [b"PAPER_READY\n", b"", b"CFG_OK\n"]
                self.in_waiting = 0

            def read(self, _n):
                if self.chunks:
                    return self.chunks.pop(0)
                return b""

        text = flash.read_serial_text(Scripted(), 1.0)
        self.assertIn("CFG_OK", text)

    def test_send_config_reopens_until_paper_ready(self) -> None:
        class FakeSer:
            def __init__(self) -> None:
                self.is_open = True
                self.writes: list[bytes] = []

            def write(self, data):
                self.writes.append(data)
                return len(data)

            def flush(self):
                return None

            def reset_input_buffer(self):
                return None

        ready = {"n": 0}

        def fake_ready(_ser, timeout_s=40.0):
            ready["n"] += 1
            if ready["n"] == 1:
                return ""
            return "PAPER_READY\nPAPER_READY\n"

        fake_serial = type(sys)("serial")
        with patch.dict(sys.modules, {"serial": fake_serial}), patch.object(
            flash, "wait_for_serial_port", return_value="/dev/ttyACM0"
        ), patch.object(flash, "listed_devices", return_value=["/dev/ttyACM0"]), patch.object(
            flash, "port_identity", return_value=(1, 2, 3)
        ), patch.object(flash, "open_app_serial", return_value=FakeSer()), patch.object(
            flash, "wait_for_app_ready", side_effect=fake_ready
        ), patch.object(flash, "read_serial_text", return_value="CFG_OK\n"), patch.object(flash, "close_serial"):
            result = flash.send_config(
                "/dev/ttyACM0",
                "HomeWiFi",
                "secret",
                "http://192.168.1.50:8080",
                "tok",
                "Kitchen",
            )
        self.assertTrue(result["ok"])
        self.assertGreaterEqual(ready["n"], 2)

    def test_firmware_keeps_listening_after_display_init(self) -> None:
        mono = (ROOT / "firmware" / "papermono" / "src" / "main.cpp").read_text(encoding="utf-8")
        color = (ROOT / "firmware" / "papercolor" / "src" / "main.cpp").read_text(encoding="utf-8")
        for src in (mono, color):
            self.assertIn("Serial.setTxTimeoutMs(0)", src)
            self.assertGreaterEqual(src.count("announceUsbReady();"), 3)
        self.assertIn("while (M5.Display.displayBusy() && (millis() - t0) < 25000)", color)
        self.assertIn("pollSerialConfig();", color)
        self.assertIn("M5.Display.startWrite();", color)
        self.assertIn("void finishEpdFrame()", color)

    def test_panel_copy_mentions_wifi_only(self) -> None:
        html = (ROOT / "public" / "index.php").read_text(encoding="utf-8")
        php = (ROOT / "src" / "YarboPaperDevice.php").read_text(encoding="utf-8")
        kit = (ROOT / "scripts" / "paper_setup_kit.py").read_text(encoding="utf-8")
        self.assertIn("flash.py --wifi-only", html)
        self.assertIn("PAPER_READY", html)
        self.assertIn("flash.py --wifi-only", php)
        self.assertIn("--wifi-only", kit)
        self.assertIn("wait_for_app_ready", kit)
        self.assertIn("cdc_host_present", kit)

    def test_setup_kit_prefers_usbmodem_over_bluetooth(self) -> None:
        kit = (ROOT / "scripts" / "paper_setup_kit.py").read_text(encoding="utf-8")
        flash = (ROOT / "scripts" / "papermono_flash.py").read_text(encoding="utf-8")
        js = (ROOT / "public" / "assets" / "app.js").read_text(encoding="utf-8")
        php = (ROOT / "src" / "YarboPaperDevice.php").read_text(encoding="utf-8")
        self.assertIn("is_tablet_usb_port", kit)
        self.assertIn("normalize_panel_url", kit)
        self.assertIn("cu.JBL", kit)
        self.assertIn("_is_tablet_usb_port", flash)
        self.assertIn("paperCompanionPanelUrlDefault", js)
        self.assertIn("http://' . $httpsMatch[1] . ':8080'", php)

        import importlib.util

        spec = importlib.util.spec_from_file_location("paper_setup_kit", ROOT / "scripts" / "paper_setup_kit.py")
        assert spec and spec.loader
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        self.assertTrue(mod.is_tablet_usb_port("/dev/cu.usbmodem1101"))
        self.assertFalse(mod.is_tablet_usb_port("/dev/cu.JBLFlip5"))
        self.assertEqual(
            mod.normalize_panel_url("https://192.168.9.253:8443"),
            "http://192.168.9.253:8080",
        )


if __name__ == "__main__":
    unittest.main()
