#!/usr/bin/env python3
"""Offline Lymow Start/Pause/Resume/Dock encoding (ha-lymow userCtrl wire format)."""

from __future__ import annotations

import base64
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts" / "lib"))

from lymow_protocol import (  # noqa: E402
    USER_CTRL_ACTIONS,
    USER_CTRL_CLEAN,
    USER_CTRL_PAUSE,
    USER_CTRL_QUERY_WIFI_4G,
    USER_CTRL_RECHARGE_DOCK,
    USER_CTRL_RESUME,
    encode_action,
    encode_status_query,
    encode_userctrl,
    wrap_envelope,
)


def fail(message: str) -> None:
    raise SystemExit(message)


def test_action_codes() -> None:
    expect = {
        "start": USER_CTRL_CLEAN,
        "pause": USER_CTRL_PAUSE,
        "resume": USER_CTRL_RESUME,
        "dock": USER_CTRL_RECHARGE_DOCK,
    }
    if USER_CTRL_ACTIONS != expect:
        fail(f"USER_CTRL_ACTIONS mismatch: {USER_CTRL_ACTIONS}")
    if USER_CTRL_CLEAN != 1 or USER_CTRL_PAUSE != 3 or USER_CTRL_RESUME != 4 or USER_CTRL_RECHARGE_DOCK != 33:
        fail("ha-lymow userCtrl constants drifted")
    if USER_CTRL_QUERY_WIFI_4G != 52:
        fail("status query code drifted")


def test_encode_hex() -> None:
    # Field 2 = version 49, field 5 = userCtrl — same shape as encode_status_query.
    expect_hex = {
        "start": "10312801",
        "pause": "10312803",
        "resume": "10312804",
        "dock": "10312821",
    }
    for action, hex_value in expect_hex.items():
        pb = encode_action(action)
        if pb.hex() != hex_value:
            fail(f"{action}: got {pb.hex()}, want {hex_value}")
        if pb != encode_userctrl(USER_CTRL_ACTIONS[action]):
            fail(f"{action}: encode_action != encode_userctrl")
        env = json.loads(wrap_envelope(pb))
        raw = base64.b64decode(env["message"])
        if raw != pb:
            fail(f"{action}: envelope round-trip failed")
    if encode_status_query().hex() != "10312834":
        fail(f"status query hex drifted: {encode_status_query().hex()}")


def test_unknown_action() -> None:
    try:
        encode_action("cancel")
    except ValueError:
        return
    fail("encode_action should reject unknown actions")


def test_bridge_wires_command() -> None:
    bridge = (ROOT / "scripts" / "lymow_bridge.py").read_text(encoding="utf-8")
    if '"command"' not in bridge and "'command'" not in bridge:
        fail("bridge argparse missing command")
    if "mqtt_publish_command" not in bridge:
        fail("bridge missing mqtt_publish_command")
    if "cmd_command" not in bridge:
        fail("bridge missing cmd_command")
    if "encode_action" not in bridge:
        fail("bridge does not import encode_action")


def main() -> None:
    test_action_codes()
    test_encode_hex()
    test_unknown_action()
    test_bridge_wires_command()
    print("test_lymow_controls.py ok")


if __name__ == "__main__":
    main()
