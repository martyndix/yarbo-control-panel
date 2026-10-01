#!/usr/bin/env python3
"""Hue Bridge endpoints must still appear when Matter only reports Bridged Node."""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "matter_agent.py"
ON_OFF = 6
LEVEL_CONTROL = 8
DESCRIPTOR = 29
BRIDGED_BASIC = 57
BASIC_INFO = 40
DEVTYPE_BRIDGED = 0x0013
DEVTYPE_AGGREGATOR = 0x000E


def load_agent():
    spec = importlib.util.spec_from_file_location("matter_agent", SCRIPT)
    if spec is None or spec.loader is None:
        raise SystemExit(f"Could not load {SCRIPT}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def hue_bridge(count: int) -> dict:
    attributes: dict[str, object] = {
        "0/40/1": "Signify Netherlands B.V.",
        "0/40/3": "Hue Bridge",
        "0/40/5": "Hue Bridge",
        "1/29/0": [{"0": DEVTYPE_AGGREGATOR, "1": 1}],
    }
    for endpoint in range(2, count + 2):
        attributes[f"{endpoint}/29/0"] = [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}]
        attributes[f"{endpoint}/6/0"] = endpoint % 2 == 0
        attributes[f"{endpoint}/8/0"] = 80
        attributes[f"{endpoint}/57/5"] = f"Light {endpoint}"
    return {
        "node_id": 1,
        "available": True,
        "is_bridge": True,
        "attributes": attributes,
    }


def main() -> int:
    agent = load_agent()
    wrapped = {"nodes": [hue_bridge(70)]}
    devices = agent.flatten_nodes(wrapped)
    if len(devices) != 70:
        raise SystemExit(f"expected 70 Hue lights, got {len(devices)}")
    kinds = {d["kind"] for d in devices}
    if "other" in kinds:
        raise SystemExit(f"unexpected other kind: {kinds}")
    ids = {d["id"] for d in devices}
    if "1:2" not in ids or "1:71" not in ids:
        raise SystemExit(f"missing endpoints {sorted(ids)[:8]}")
    named = [d for d in devices if d["name"].startswith("Light ")]
    if len(named) != 70:
        raise SystemExit("Hue names missing")

    empty = agent.flatten_nodes({"nodes": []})
    if empty != []:
        raise SystemExit("empty nodes should flatten to []")

    stub = agent.flatten_nodes({"nodes": [{"node_id": 13, "available": False, "attributes": {}}]})
    if stub != []:
        raise SystemExit(f"uninterviewed stub should not flatten to a fake light {stub}")

    wrapped_result = agent.nodes_from_result({"result": [hue_bridge(1)]})
    if not wrapped_result or wrapped_result[0]["node_id"] != 1:
        raise SystemExit("wrapped result list missing")

    keyed = agent.flatten_nodes({"nodes": {"1": hue_bridge(70)}})
    if len(keyed) != 70:
        raise SystemExit(f"dict-keyed nodes flattened to {len(keyed)}")

    tmp = Path(tempfile.mkdtemp())
    agent.STORAGE = tmp
    (tmp / "aabbcc.json").write_text(json.dumps({"nodes": {"1": hue_bridge(12)}}))
    disk = agent.nodes_from_disk()
    if len(disk) != 1:
        raise SystemExit(f"disk nodes {len(disk)}")
    lights = agent.flatten_nodes(disk)
    if len(lights) != 12:
        raise SystemExit(f"disk flatten {len(lights)}")

    tmp2 = Path(tempfile.mkdtemp())
    agent.STORAGE = tmp2
    (tmp2 / "1415.json").write_text(json.dumps({"1": hue_bridge(9), "last_node_id": 1}))
    bare = agent.flatten_nodes(agent.nodes_from_disk())
    if len(bare) != 9:
        raise SystemExit(f"bare fabric map flattened to {len(bare)}")

    wrapped_off = hue_bridge(2)
    wrapped_off["attributes"]["2/6/0"] = {"value": False}
    wrapped_off["attributes"]["3/6/0"] = {"0": 0}
    wrapped_devices = agent.flatten_nodes({"nodes": [wrapped_off]})
    by_id = {d["id"]: d for d in wrapped_devices}
    if by_id["1:2"]["on"] or by_id["1:3"]["on"]:
        raise SystemExit(f"wrapped off counted as on: {by_id}")
    wrapped_on = hue_bridge(1)
    wrapped_on["attributes"]["2/6/0"] = {"value": True}
    if not agent.flatten_nodes({"nodes": [wrapped_on]})[0]["on"]:
        raise SystemExit("wrapped true should be on")
    if agent.attr_bool({"value": False}) or not agent.attr_bool({"value": True}):
        raise SystemExit("attr_bool wrappers failed")
    if agent.attr_bool("false") or agent.attr_bool("off") or not agent.attr_bool(1):
        raise SystemExit("attr_bool scalars failed")

    light_heater = {
        "node_id": 23,
        "available": True,
        "attributes": {
            "0/40/3": "Towel rail",
            "1/29/0": [{"deviceType": 0x0101, "revision": 1}],
            "1/6/0": True,
            "1/8/0": 200,
            "1/768/3": 200,
            "1/513/0": 2100,
        },
    }
    light_vacuum = {
        "node_id": 24,
        "available": True,
        "attributes": {
            "0/40/3": "Robot vac",
            "1/29/0": [{"deviceType": 0x0100, "revision": 1}],
            "1/6/0": False,
            "1/8/0": 40,
            "1/84/0": 1,
            "1/97/0": 1,
        },
    }
    extra = {d["id"]: d for d in agent.flatten_nodes({"nodes": [light_heater, light_vacuum]})}
    if extra.get("23:1", {}).get("kind") != "heater" or extra["23:1"].get("colorable") or extra["23:1"].get("dimmable"):
        raise SystemExit(f"light-typed heater {extra.get('23:1')}")
    if extra.get("24:1", {}).get("kind") != "vacuum" or extra["24:1"].get("colorable"):
        raise SystemExit(f"light-typed vacuum {extra.get('24:1')}")

    agent._live_devices = [{"id": "1:2", "on": False}]
    agent.apply_attribute_event([1, "2/6/0", True])
    live = agent.current_live_devices()
    if not live or live[0].get("on") is not True:
        raise SystemExit(f"attribute event did not turn on {live}")

    agent._live_devices = [{"id": "1:2", "on": True, "_patched_at": __import__("time").time()}]
    agent.remember_live_devices([{"id": "1:2", "on": False, "name": "Lamp"}])
    kept = agent.current_live_devices()
    if not kept or kept[0].get("on") is not True:
        raise SystemExit(f"sticky command patch lost {kept}")

    print("ok: 70 Hue Bridge lights flatten from Bridged Node + OnOff")
    return 0


if __name__ == "__main__":
    sys.exit(main())
