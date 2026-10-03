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

        fake_serial = type(sys)("serial")
        with patch.dict(sys.modules, {"serial": fake_serial}), patch.object(
            flash, "wait_for_serial_port", return_value="/dev/ttyACM0"
        ), patch.object(flash, "open_app_serial", return_value=FakeSer()), patch.object(
            flash, "read_serial_text", return_value="PAPER_READY\nCFG_OK\n"
        ), patch.object(flash, "close_serial"):
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


if __name__ == "__main__":
    unittest.main()
