#!/usr/bin/env python3
"""Offline Lymow controls + zone Start encoding (ha-lymow wire format)."""

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
    USER_CTRL_DOCK,
    USER_CTRL_PAUSE,
    USER_CTRL_QUERY_MAP,
    USER_CTRL_QUERY_WIFI_4G,
    USER_CTRL_RECHARGE_DOCK,
    USER_CTRL_RESUME,
    _field_bytes,
    _field_i32,
    _field_str,
    decode_map_zones,
    encode_action,
    encode_query_map,
    encode_start_zones,
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
        "cancel": USER_CTRL_DOCK,
    }
    if USER_CTRL_ACTIONS != expect:
        fail(f"USER_CTRL_ACTIONS mismatch: {USER_CTRL_ACTIONS}")
    if USER_CTRL_DOCK != 2:
        fail("cancel USER_CTRL_DOCK must be 2")
    if USER_CTRL_RECHARGE_DOCK != 33:
        fail("dock keep-progress must stay 33")
    if USER_CTRL_QUERY_MAP != 19:
        fail("QUERY_MAP code drifted")
    if USER_CTRL_QUERY_WIFI_4G != 52:
        fail("status query code drifted")


def test_pause_resume_dock_hex() -> None:
    expect_hex = {
        "pause": "10312803",
        "resume": "10312804",
        "dock": "10312821",
        "cancel": "10312802",
    }
    for action, hex_value in expect_hex.items():
        pb = encode_action(action)
        if pb.hex() != hex_value:
            fail(f"{action}: got {pb.hex()}, want {hex_value}")
        if pb != encode_userctrl(USER_CTRL_ACTIONS[action]):
            fail(f"{action}: encode_action != encode_userctrl")


def test_start_requires_zones() -> None:
    try:
        encode_action("start")
    except ValueError:
        pass
    else:
        fail("bare start must require zones")
    try:
        encode_start_zones([])
    except ValueError:
        pass
    else:
        fail("encode_start_zones([]) must raise")


def test_start_zones_wire_snapshot() -> None:
    # ha-lymow tests/test_wire_snapshots.py
    expected = "1031280162100a0e0a0c1a0841424344303030314001"
    got = encode_start_zones(["ABCD0001"]).hex()
    if got != expected:
        fail(f"start zones hex: {got}, want {expected}")
    via_action = encode_action("start", ["ABCD0001"]).hex()
    if via_action != expected:
        fail(f"encode_action start zones: {via_action}")
    env = json.loads(wrap_envelope(encode_start_zones(["ABCD0001"])))
    if base64.b64decode(env["message"]).hex() != expected:
        fail("envelope round-trip failed")


def test_query_map_and_decode_zones() -> None:
    q = encode_query_map()
    if not q.hex().startswith("10312813"):  # version 49 + userCtrl 19
        fail(f"query map hex drifted: {q.hex()}")
    bi = _field_str(3, "wsmjco1T") + _field_str(2, "Front Lawn") + _field_i32(4, 1)
    zone = _field_bytes(1, bi)
    content = _field_bytes(1, zone)
    wrapper = _field_bytes(3, content)
    outer = _field_bytes(2, wrapper)
    pb = _field_bytes(23, outer)
    zones = decode_map_zones(pb)
    if len(zones) != 1 or zones[0].get("hash_id") != "wsmjco1T" or zones[0].get("name") != "Front Lawn":
        fail(f"decode_map_zones: {zones}")
    if encode_status_query().hex() != "10312834":
        fail(f"status query hex drifted: {encode_status_query().hex()}")


def test_bridge_wires_maps() -> None:
    bridge = (ROOT / "scripts" / "lymow_bridge.py").read_text(encoding="utf-8")
    for needle in (
        "mqtt_query_map",
        "cmd_maps",
        "encode_query_map",
        "decode_map_zones",
        "--zones",
        "resolve_task_zones",
        "task_zones",
    ):
        if needle not in bridge:
            fail(f"bridge missing {needle}")


def test_resolve_task_zones() -> None:
    sys.path.insert(0, str(ROOT / "scripts"))
    from lymow_bridge import resolve_task_zones, task_zones_active  # noqa: WPS433

    rows = resolve_task_zones(
        ["a1", "b2"],
        [{"hash_id": "a1", "name": "South Lawn"}, {"hash_id": "b2", "name": "Orchard"}],
    )
    if rows != [
        {"hash_id": "a1", "name": "South Lawn"},
        {"hash_id": "b2", "name": "Orchard"},
    ]:
        fail(f"resolve_task_zones: {rows}")
    if not task_zones_active(2) or task_zones_active(0):
        fail("task_zones_active drift")


def main() -> None:
    test_action_codes()
    test_pause_resume_dock_hex()
    test_start_requires_zones()
    test_start_zones_wire_snapshot()
    test_query_map_and_decode_zones()
    test_bridge_wires_maps()
    test_resolve_task_zones()
    print("test_lymow_controls.py ok")


if __name__ == "__main__":
    main()
