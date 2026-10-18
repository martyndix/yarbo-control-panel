#!/usr/bin/env python3
"""Lymow live error is robotState (f1), not a leftover errorCodes list."""

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


def test_decode_live_error_and_clear_codes() -> None:
    packed = _field_bytes(3, _encode_varint(77))
    decoded = decode_pboutput(packed)
    if decoded.get("errorCode") != 77 or decoded.get("errorCodes") != [77]:
        fail(f"packed E77 decode: {decoded}")

    live = decode_pboutput(_field_bytes(5, _field_i32(1, 7) + _field_i32(6, 2) + _field_i32(2, 92)))
    if live.get("robotState") != 7:
        fail(f"robotState f1 missing: {live}")
    if live.get("workStatus") != 2:
        fail(f"work status missing: {live}")
    if live.get("errorCode") != 0 or live.get("errorCodes") != []:
        fail(f"status without field 3 must clear leftover codes: {live}")

    mowing = decode_pboutput(_field_bytes(5, _field_i32(1, 2) + _field_i32(6, 2) + _field_i32(2, 90)))
    if mowing.get("robotState") != 2 or mowing.get("workStatus") != 2:
        fail(f"mowing robotState: {mowing}")

    empty = decode_pboutput(_field_bytes(3, b""))
    if empty.get("errorCode") != 0 or empty.get("errorCodes") != []:
        fail(f"empty field 3 should clear: {empty}")


def test_merge_does_not_keep_e77_as_live() -> None:
    bridge = load_bridge()
    previous = {
        "battery": 92,
        "work_status": 2,
        "robot_state": 7,
        "error_code": 77,
        "error_codes": [77],
    }
    decoded_mowing = {
        "battery": 90,
        "workStatus": 2,
        "robotState": 2,
        "errorCode": 0,
        "errorCodes": [],
    }
    merged = bridge.merge_state(
        {"info": {"deviceState": "online"}, "device": {}, "thing": "t"},
        decoded_mowing,
        previous,
    )
    if merged.get("robot_state") != 2:
        fail(f"merge kept error robot_state: {merged}")
    if merged.get("error_code") not in (0, None) or merged.get("error_codes") not in ([], None):
        fail(f"merge kept leftover E77: {merged}")
    if merged.get("work_status") != 2:
        fail(f"merge lost mowing: {merged}")

    live = bridge.merge_state(
        {"info": {}, "device": {}, "thing": "t"},
        {"errorCode": 77, "errorCodes": [77], "workStatus": 2, "robotState": 7},
        previous,
    )
    if live.get("robot_state") != 7 or live.get("error_code") != 77:
        fail(f"live E77 must keep robot_state 7: {live}")


if __name__ == "__main__":
    test_decode_live_error_and_clear_codes()
    test_merge_does_not_keep_e77_as_live()
    print("test_lymow_error.py ok")
