#!/usr/bin/env python3
"""Remap saved Home device ids from one Matter node id to another."""

from __future__ import annotations

import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "matter_agent.py"


def load():
    spec = importlib.util.spec_from_file_location("matter_agent", SCRIPT)
    if spec is None or spec.loader is None:
        raise SystemExit(f"Could not load {SCRIPT}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def main() -> int:
    agent = load()
    store = {
        "names": {"13:2": "Landing", "13:3": "Hall", "1:1": "Keep"},
        "rooms": {"13:2": "r1"},
        "groups": {"13:2": "g1"},
        "hidden": ["13:3"],
        "device_order": ["13:2", "1:1"],
        "paper": {"tab": ["13:2", "scene:x"]},
        "scenes": [{"id": "s1", "actions": [{"id": "13:2", "on": True}]}],
        "last_devices": [{"id": "13:2", "node_id": 13, "endpoint": 2}],
    }
    changed = agent.remap_home_node_id(store, 13, 1)
    if store["names"].get("1:2") != "Landing" or "13:2" in store["names"]:
        raise SystemExit(f"names {store['names']}")
    if store["names"].get("1:1") != "Keep":
        raise SystemExit("untouched id rewritten")
    if store["rooms"]["1:2"] != "r1" or store["hidden"] != ["1:3"]:
        raise SystemExit("rooms/hidden")
    if store["scenes"][0]["actions"][0]["id"] != "1:2":
        raise SystemExit("scene")
    if store["last_devices"][0] != {"id": "1:2", "node_id": 1, "endpoint": 2}:
        raise SystemExit(f"last {store['last_devices']}")
    if store["paper"]["tab"][1] != "scene:x":
        raise SystemExit("scene paper id rewritten")
    if changed < 6:
        raise SystemExit(f"changed {changed}")

    if not agent.looks_like_matter_id("12:5"):
        raise SystemExit("12:5 should be a Matter id")
    if agent.looks_like_matter_id("21:00"):
        raise SystemExit("21:00 is a clock time, not a Matter id")
    if agent.looks_like_matter_id("unifi:light:x"):
        raise SystemExit("unifi id rewritten")

    autos = {
        "automations": [
            {
                "name": "At 21:00 → Kitchen",
                "trigger": {"type": "time", "at": "21:00"},
                "triggers": [{"type": "time", "at": "21:00"}],
                "actions": [{"kind": "device", "id": "12:3", "command": "on"}],
            }
        ]
    }
    n = agent.remap_automations_node_id(autos, 12, 1)
    if autos["automations"][0]["actions"][0]["id"] != "1:3":
        raise SystemExit(f"auto action {autos}")
    if autos["automations"][0]["trigger"]["at"] != "21:00":
        raise SystemExit("clock time remapped")
    if n < 1:
        raise SystemExit(f"auto changed {n}")

    if agent.replacement_node_id(12, present=[1], available=[1]) != 1:
        raise SystemExit("missing node 12 should map onto the only live node")
    if agent.replacement_node_id(12, present=[12], available=[]) is not None:
        raise SystemExit("present-but-unready node must not remap")
    if agent.replacement_node_id(12, present=[1, 2], available=[1, 2]) is not None:
        raise SystemExit("two live nodes must not remap")

    print("ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
