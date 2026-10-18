#!/usr/bin/env python3
"""Lymow MQTT must keep E77 even when the next status omits the error field."""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts" / "lib"))

from lymow_protocol import (  # noqa: E402
    _encode_varint,
    _field_bytes,
    _field_i32,
    decode_pboutput,
)


def load_bridge():
    spec = importlib.util.spec_from_file_location("lymow_bridge", ROOT / "scripts" / "lymow_bridge.py")
    if spec is None or spec.loader is None:
        raise SystemExit(f"Could not load {ROOT / 'scripts' / 'lymow_bridge.py'}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def fail(message: str) -> None:
    raise SystemExit(message)


def test_decode_keeps_missing_error_field() -> None:
    packed = _field_bytes(3, _encode_varint(77))
    decoded = decode_pboutput(packed)
    if decoded.get("errorCode") != 77 or decoded.get("errorCodes") != [77]:
        fail(f"packed E77 decode: {decoded}")

    robot = _field_bytes(5, _field_i32(6, 2) + _field_i32(2, 92))
    later = decode_pboutput(robot)
    if later.get("workStatus") != 2:
        fail(f"work status missing: {later}")
    if "errorCode" in later or "errorCodes" in later:
        fail(f"status without field 3 must not zero errors: {later}")

    empty = decode_pboutput(_field_bytes(3, b""))
    if empty.get("errorCode") != 0 or empty.get("errorCodes") != []:
        fail(f"empty field 3 should clear: {empty}")


def test_merge_keeps_previous_e77() -> None:
    bridge = load_bridge()
    previous = {
        "battery": 92,
        "work_status": 2,
        "error_code": 77,
        "error_codes": [77],
    }
    merged = bridge.merge_state(
        {"info": {"deviceState": "online"}, "device": {}, "thing": "t"},
        {"battery": 90, "workStatus": 2},
        previous,
    )
    if merged.get("error_code") != 77 or merged.get("error_codes") != [77]:
        fail(f"merge dropped E77: {merged}")
    if merged.get("work_status") != 2:
        fail(f"merge lost mowing: {merged}")

    cleared = bridge.merge_state(
        {"info": {}, "device": {}, "thing": "t"},
        {"errorCode": 0, "errorCodes": [], "workStatus": 2},
        previous,
    )
    if cleared.get("error_code") not in (0, None) or cleared.get("error_codes") not in ([], None):
        fail(f"explicit zero must clear: {cleared}")


if __name__ == "__main__":
    test_decode_keeps_missing_error_field()
    test_merge_keeps_previous_e77()
    print("test_lymow_error.py ok")
