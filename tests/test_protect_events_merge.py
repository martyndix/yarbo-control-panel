#!/usr/bin/env python3
"""Unit tests for Protect event merge + person stamp path."""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PY = ROOT / "scripts" / "unifi_protect_events.py"


def load_mod():
    spec = importlib.util.spec_from_file_location("unifi_protect_events", PY)
    assert spec and spec.loader
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def assert_true(ok: bool, msg: str) -> None:
    if not ok:
        print(f"FAIL: {msg}", file=sys.stderr)
        raise SystemExit(1)
    print(f"ok: {msg}")


def main() -> int:
    m = load_mod()
    state = m.State()

    # Official envelope: first add often has empty smartDetectTypes.
    m.ingest_message(
        state,
        {
            "type": "add",
            "item": {
                "id": "evt-1",
                "modelKey": "event",
                "type": "smartDetectZone",
                "start": 1_900_000_000_000,
                "end": None,
                "device": "cam-yarbo",
                "smartDetectTypes": [],
            },
        },
    )
    cam = state.cameras["cam-yarbo"]
    assert_true(cam["person"] is False, "empty types: person still false")
    assert_true(int(cam["detect_person_at"]) == 0, "empty types: no person stamp yet")
    assert_true(cam["motion"] is True, "empty types: motion marked")

    # Partial update fills person without repeating device/type — previously dropped.
    m.ingest_message(
        state,
        {
            "type": "update",
            "item": {
                "id": "evt-1",
                "smartDetectTypes": ["Person"],
            },
        },
    )
    cam = state.cameras["cam-yarbo"]
    assert_true(cam["person"] is True, "merged Person casing → person true")
    assert_true(int(cam["detect_person_at"]) == 1_900_000_000, "person stamp from start ms")

    # End-only update clears active flag but keeps stamp for Automations edge.
    stamp = int(cam["detect_person_at"])
    m.ingest_message(
        state,
        {
            "type": "update",
            "item": {
                "id": "evt-1",
                "end": 1_900_000_030_000,
            },
        },
    )
    cam = state.cameras["cam-yarbo"]
    assert_true(cam["person"] is False, "ended: person cleared")
    assert_true(int(cam["detect_person_at"]) == stamp, "ended: stamp kept")

    # Direct (non-envelope) event still works.
    m.ingest_message(
        state,
        {
            "modelKey": "event",
            "id": "evt-2",
            "type": "smartDetectZone",
            "start": 1_900_000_100,
            "device": "cam-2",
            "smartDetectTypes": ["vehicle"],
        },
    )
    assert_true(state.cameras["cam-2"]["vehicle"] is True, "direct vehicle event")

    # Face aliases to person.
    m.ingest_message(
        state,
        {
            "type": "add",
            "item": {
                "id": "evt-3",
                "modelKey": "event",
                "type": "smartDetectZone",
                "start": 1_900_000_200_000,
                "device": "cam-3",
                "smartDetectTypes": ["face"],
            },
        },
    )
    assert_true(state.cameras["cam-3"]["person"] is True, "face → person")

    print("ALL PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
