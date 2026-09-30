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
    print("ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
