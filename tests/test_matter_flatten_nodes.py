#!/usr/bin/env python3
"""Hue Bridge endpoints must still appear when Matter only reports Bridged Node."""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import time
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
    if any(d.get("colorable") or d.get("color_ct") for d in devices):
        raise SystemExit(f"Hue OnOff lights must not show colour {devices[0]}")
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
            "1/97/4": 1,
        },
    }
    mill_heater = {
        "node_id": 25,
        "available": True,
        "attributes": {
            "0/40/1": "Mill",
            "0/40/3": "Mill Wi-Fi Panel Heater Gen4",
            "1/29/0": [{"deviceType": 0x0100, "revision": 1}],
            "1/513/0": 2140,
            "1/513/18": 2100,
            "1/513/28": 0,
            "1/1026/0": 2140,
        },
    }
    boiler_light = {
        "node_id": 31,
        "available": True,
        "attributes": {
            "0/40/3": "Boiler",
            "1/29/0": [{"deviceType": 0x0100, "revision": 1}],
            "1/6/0": False,
            "1/8/0": 254,
            "1/57/5": "Boiler",
        },
    }
    extra = {d["id"]: d for d in agent.flatten_nodes({"nodes": [light_heater, light_vacuum, mill_heater, boiler_light]})}
    if extra.get("23:1", {}).get("kind") != "heater" or extra["23:1"].get("colorable") or extra["23:1"].get("dimmable"):
        raise SystemExit(f"light-typed heater {extra.get('23:1')}")
    if extra.get("24:1", {}).get("kind") != "vacuum" or extra["24:1"].get("colorable"):
        raise SystemExit(f"light-typed vacuum {extra.get('24:1')}")
    if extra.get("25:1", {}).get("kind") != "heater" or extra["25:1"].get("colorable") or extra["25:1"].get("dimmable"):
        raise SystemExit(f"mill panel heater {extra.get('25:1')}")
    if extra.get("31:1", {}).get("kind") != "light" or extra["31:1"].get("name") != "Boiler":
        raise SystemExit(f"Boiler must stay a light {extra.get('31:1')}")
    if extra["31:1"].get("colorable") or extra["31:1"].get("color_ct"):
        raise SystemExit(f"Boiler must not show colour {extra.get('31:1')}")
    if agent.name_looks_heater("Boiler"):
        raise SystemExit("Boiler is a light name, not a heater")
    if extra["25:1"].get("on") is not False:
        raise SystemExit(f"mill SystemMode Off must be off {extra['25:1']}")
    if extra["25:1"].get("local_temperature") != 21.4 or extra["25:1"].get("heating_setpoint") != 21.0:
        raise SystemExit(f"mill heater temps {extra['25:1']}")

    white = {
        "node_id": 40,
        "available": True,
        "attributes": {
            "0/40/3": "Hue white",
            "1/29/0": [{"deviceType": 0x0101, "revision": 1}],
            "1/6/0": False,
            "1/8/0": 80,
            "1/768/0": 0,
            "1/768/16394": 0,
        },
    }
    ambiance = {
        "node_id": 41,
        "available": True,
        "attributes": {
            "0/40/3": "Hue ambiance",
            "1/29/0": [{"deviceType": 0x010C, "revision": 1}],
            "1/6/0": False,
            "1/8/0": 80,
            "1/768/7": 370,
            "1/768/16394": 16,
            "1/768/16395": 153,
            "1/768/16396": 500,
        },
    }
    rgb = {
        "node_id": 42,
        "available": True,
        "attributes": {
            "0/40/3": "Hue color",
            "1/29/0": [{"deviceType": 0x010D, "revision": 1}],
            "1/6/0": True,
            "1/8/0": 80,
            "1/768/16394": 25,
        },
    }
    spec = {d["id"]: d for d in agent.flatten_nodes({"nodes": [white, ambiance, rgb]})}
    if spec.get("40:1", {}).get("colorable") or spec.get("40:1", {}).get("color_ct"):
        raise SystemExit(f"dimmable white must not show colour {spec.get('40:1')}")
    if not spec.get("40:1", {}).get("dimmable"):
        raise SystemExit(f"dimmable white must be dimmable {spec.get('40:1')}")
    if not spec.get("41:1", {}).get("color_ct") or spec.get("41:1", {}).get("colorable"):
        raise SystemExit(f"white ambiance must be CT only {spec.get('41:1')}")
    if not spec.get("42:1", {}).get("colorable"):
        raise SystemExit(f"extended colour must stay colourable {spec.get('42:1')}")

    # IKEA DUBBELKISEL: Matter dimmable LED driver (0x0101). LevelControl may
    # be listed before CurrentLevel has been read — still show a brightness slider.
    dubbelkisel = {
        "node_id": 77,
        "available": True,
        "attributes": {
            "0/40/1": "IKEA of Sweden",
            "0/40/3": "DUBBELKISEL",
            "1/29/0": [{"deviceType": 0x0101, "revision": 1}],
            "1/29/1": [3, 4, 5, 6, 8, 29],
            "1/6/0": False,
        },
    }
    ikea = {d["id"]: d for d in agent.flatten_nodes({"nodes": [dubbelkisel]})}
    row77 = ikea.get("77:1") or {}
    if row77.get("kind") != "light" or not row77.get("dimmable"):
        raise SystemExit(f"DUBBELKISEL must be a dimmable light {row77}")
    if row77.get("colorable") or row77.get("color_ct") or row77.get("color_hs"):
        raise SystemExit(f"DUBBELKISEL must not show colour {row77}")

    mill_split = {
        "node_id": 26,
        "available": True,
        "attributes": {
            "0/40/1": "Mill",
            "0/40/3": "Mill Wi-Fi Panel Heater Gen4",
            "1/29/0": [{"deviceType": 0x0100, "revision": 1}],
            "1/6/0": True,
            "2/29/0": [{"deviceType": 0x0300, "revision": 1}],
            "2/513/0": 2000,
            "2/513/18": 2200,
            "2/513/28": 4,
        },
    }
    split = {d["id"]: d for d in agent.flatten_nodes({"nodes": [mill_split]})}
    if "26:1" in split or "26:2" not in split:
        raise SystemExit(f"mill OnOff sibling must not be a heater row {split}")
    if split["26:2"].get("kind") != "heater" or split["26:2"].get("on") is not True:
        raise SystemExit(f"mill thermostat endpoint {split.get('26:2')}")

    agent._live_devices = [{"id": "25:1", "kind": "heater", "on": False}]
    agent.apply_attribute_event([25, "1/513/0", 2190])
    agent.apply_attribute_event([25, "1/513/18", 2200])
    agent.apply_attribute_event([25, "1/513/28", 4])
    heated = agent.current_live_devices()
    if (
        not heated
        or heated[0].get("local_temperature") != 21.9
        or heated[0].get("heating_setpoint") != 22.0
        or heated[0].get("on") is not True
    ):
        raise SystemExit(f"thermostat events {heated}")

    agent._live_devices = [{"id": "1:2", "on": False}]
    agent.apply_attribute_event([1, "2/6/0", True])
    live = agent.current_live_devices()
    if not live or live[0].get("on") is not True:
        raise SystemExit(f"attribute event did not turn on {live}")

    agent._live_devices = []
    agent.apply_attribute_event([1, "2/6/0", True])
    seeded = agent.current_live_devices()
    if not seeded or seeded[0].get("id") != "1:2" or seeded[0].get("on") is not True:
        raise SystemExit(f"event on empty live list {seeded}")

    agent._live_devices = [{"id": "1:2", "on": True, "_patched_at": __import__("time").time(), "_sticky_keys": ["on"]}]
    agent.apply_attribute_event([1, "2/6/0", False])
    held = agent.current_live_devices()
    if not held or held[0].get("on") is not True:
        raise SystemExit(f"sticky should hold against stale off {held}")

    agent._live_devices = [{"id": "1:2", "on": True, "_patched_at": __import__("time").time() - 10}]
    agent.apply_attribute_event([1, "2/6/0", False])
    yielded = agent.current_live_devices()
    if not yielded or yielded[0].get("on") is not False:
        raise SystemExit(f"live event after sticky {yielded}")

    agent._live_devices = [{"id": "1:2", "on": True, "_patched_at": __import__("time").time()}]
    agent.remember_live_devices([{"id": "1:2", "on": False, "name": "Lamp"}])
    kept = agent.current_live_devices()
    if not kept or kept[0].get("on") is not True:
        raise SystemExit(f"sticky command patch lost {kept}")

    agent._live_devices = [{"id": "1:2", "on": True, "brightness": 20}]
    agent.apply_attribute_event([1, "2/8/0", 127])
    dimmed = agent.current_live_devices()
    if not dimmed or dimmed[0].get("brightness") != 50:
        raise SystemExit(f"CurrentLevel event did not update brightness {dimmed}")

    agent._live_devices = [{"id": "1:2", "on": False, "brightness": 20}]
    agent.apply_attribute_event([1, "2/8/0", 200])
    stayed_off = agent.current_live_devices()
    if not stayed_off or stayed_off[0].get("on") is not False:
        raise SystemExit(f"CurrentLevel must not turn an Off Hue light On {stayed_off}")
    if int(stayed_off[0].get("brightness") or 0) != 79:
        raise SystemExit(f"off light must still take CurrentLevel brightness {stayed_off}")

    agent._live_devices = [{"id": "1:2", "on": True, "brightness": 20}]
    agent.apply_attribute_event({"node_id": 1, "endpoint": 2, "cluster": 8, "attribute": 0, "value": {"value": 254}})
    full = agent.current_live_devices()
    if not full or full[0].get("brightness") != 100:
        raise SystemExit(f"wrapped CurrentLevel event {full}")

    agent._live_devices = [{"id": "1:2", "on": True, "brightness": 20, "_patched_at": __import__("time").time(), "_sticky_keys": ["on"]}]
    agent.apply_attribute_event([1, "2/8/0", 127])
    during_on = agent.current_live_devices()
    if not during_on or during_on[0].get("on") is not True or during_on[0].get("brightness") != 50:
        raise SystemExit(f"sticky On must still take Apple Home brightness {during_on}")

    agent._live_devices = [{"id": "1:2", "on": True, "color_hex": "#000000"}]
    agent.apply_attribute_event([1, "2/768/0", 0])
    agent.apply_attribute_event([1, "2/768/1", 254])
    hs = agent.current_live_devices()
    if not hs or str(hs[0].get("color_hex") or "").lower() != "#ff0000":
        raise SystemExit(f"HS colour event {hs}")

    agent._live_devices = [{"id": "1:2", "on": True, "color_hex": "#000000"}]
    agent.apply_attribute_event([1, "2/768/8", 1])
    agent.apply_attribute_event([1, "2/768/3", 19660])
    agent.apply_attribute_event([1, "2/768/4", 19660])
    xy = agent.current_live_devices()
    hex_s = str((xy[0] if xy else {}).get("color_hex") or "")
    if not hex_s.startswith("#") or hex_s.lower() == "#000000":
        raise SystemExit(f"XY colour event {xy}")

    agent._live_devices = [
        {"id": "1:2", "node_id": 1, "endpoint": 2, "kind": "light", "on": True, "brightness": 10, "color_hex": "#000000"}
    ]
    agent.apply_read_attributes(1, {"2/8/0": 127, "2/768/0": 0, "2/768/1": 254})
    pulled = agent.current_live_devices()
    if not pulled or pulled[0].get("brightness") != 50:
        raise SystemExit(f"read_attribute brightness {pulled}")
    if str(pulled[0].get("color_hex") or "").lower() != "#ff0000":
        raise SystemExit(f"read_attribute colour {pulled}")

    groups = agent.light_poll_groups()
    if groups.get(1) != [2]:
        raise SystemExit(f"light poll groups {groups}")

    calls: list[tuple] = []

    def fake_rpc(command, args=None, timeout=20.0, channel="cmd", listen=True, retries=2):
        calls.append((command, args, channel))
        if command == "read_attribute":
            paths = list((args or {}).get("attribute_path") or [])
            result = {}
            for path in paths:
                if path.endswith("/6/0"):
                    result[path] = True
                elif path.endswith("/8/0"):
                    result[path] = 200
                elif path.endswith("/768/0"):
                    result[path] = 0
                elif path.endswith("/768/1"):
                    result[path] = 254
            return {"ok": True, "result": result}
        if command == "get_nodes":
            return {"ok": True, "result": [hue_bridge(1)]}
        return {"ok": False, "error": "no"}

    agent._live_devices = [
        {"id": "1:2", "node_id": 1, "endpoint": 2, "kind": "light", "on": False, "brightness": 5, "color_hex": "#111111"}
    ]
    agent.matter_rpc = fake_rpc
    agent._poll_color_at = 0.0
    agent._poll_node_backoff.clear()
    agent.poll_light_attributes()
    polled = agent.current_live_devices()
    on_paths = []
    for command, args, channel in calls:
        if command == "read_attribute" and channel == "poll":
            on_paths.extend(list((args or {}).get("attribute_path") or []))
    if not calls or calls[0][0] != "read_attribute" or calls[0][2] != "poll":
        raise SystemExit(f"poll did not read on poll channel {calls}")
    if not any(str(path).endswith("/6/0") for path in on_paths):
        raise SystemExit(f"poll did not read OnOff {on_paths}")
    if not polled or polled[0].get("on") is not True:
        raise SystemExit(f"Apple Home OnOff poll did not turn the tile on {polled}")
    if int(polled[0].get("brightness") or 0) != 79:
        raise SystemExit(f"poll brightness {polled}")
    if str(polled[0].get("color_hex") or "").lower() != "#ff0000":
        raise SystemExit(f"poll colour {polled}")

    calls.clear()

    def fake_rpc_off(command, args=None, timeout=20.0, channel="cmd", listen=True, retries=2):
        calls.append((command, args, channel))
        if command == "read_attribute":
            paths = list((args or {}).get("attribute_path") or [])
            result = {path: False if str(path).endswith("/6/0") else 80 for path in paths if str(path).endswith("/6/0") or str(path).endswith("/8/0")}
            return {"ok": True, "result": result}
        return {"ok": False, "error": "no"}

    agent.matter_rpc = fake_rpc_off
    agent._live_devices = [
        {"id": "1:2", "node_id": 1, "endpoint": 2, "kind": "light", "on": True, "brightness": 50}
    ]
    agent.poll_light_attributes()
    offed = agent.current_live_devices()
    if not offed or offed[0].get("on") is not False:
        raise SystemExit(f"Apple Home Off via OnOff poll {offed}")

    calls.clear()
    empty_nodes = []

    def fake_rpc_empty_live(command, args=None, timeout=20.0, channel="cmd", listen=True, retries=2):
        calls.append((command, args, channel))
        if command == "get_nodes":
            return {"ok": True, "result": [hue_bridge(1)]}
        if command == "read_attribute":
            paths = list((args or {}).get("attribute_path") or [])
            return {"ok": True, "result": {path: True if str(path).endswith("/6/0") else 100 for path in paths}}
        return {"ok": False, "error": "no"}

    agent._live_devices = []
    agent.matter_rpc = fake_rpc_empty_live
    agent.poll_light_attributes()
    if not any(command == "get_nodes" and channel == "poll" for command, _args, channel in calls):
        raise SystemExit(f"empty live list must get_nodes on poll channel {calls}")
    filled = agent.current_live_devices()
    if not filled or filled[0].get("id") != "1:2":
        raise SystemExit(f"empty live poll did not populate {filled}")

    calls.clear()
    agent._command_busy = 1
    agent._live_devices = [
        {"id": "1:2", "node_id": 1, "endpoint": 2, "kind": "light", "on": False}
    ]
    agent.poll_light_attributes()
    if calls:
        raise SystemExit(f"poll must skip while a website command is in flight {calls}")
    agent._command_busy = 0

    calls.clear()
    agent._poll_node_backoff.clear()
    agent._poll_color_at = 0.0
    agent._live_devices = [
        {"id": "1:2", "node_id": 1, "endpoint": 2, "kind": "light", "on": True, "available": True},
        {"id": "1:3", "node_id": 1, "endpoint": 3, "kind": "light", "on": True, "available": False},
    ]

    def fake_timeout(command, args=None, timeout=20.0, channel="cmd", listen=True, retries=2):
        calls.append((command, args, channel))
        return {"ok": False, "error": "Matter server timed out"}

    agent.matter_rpc = fake_timeout
    agent.poll_light_attributes()
    timed_reads = [item for item in calls if item[0] == "read_attribute"]
    if len(timed_reads) != 1:
        raise SystemExit(f"a timed out poll must stop, not fan out {calls}")
    timed_paths = list((timed_reads[0][1] or {}).get("attribute_path") or [])
    if any(str(path).startswith("3/") for path in timed_paths):
        raise SystemExit(f"unavailable bulb was polled {timed_paths}")
    if int((timed_reads[0][1] or {}).get("node_id") or 0) != 1:
        raise SystemExit(f"timeout poll node {timed_reads}")
    calls.clear()
    agent.poll_light_attributes()
    if any(item[0] == "read_attribute" for item in calls):
        raise SystemExit(f"node must cool down after a poll timeout {calls}")

    calls.clear()
    agent._poll_node_backoff.clear()
    agent._poll_color_at = 0.0
    agent._live_devices = [
        {"id": "1:2", "node_id": 1, "endpoint": 2, "kind": "light", "on": True, "available": True},
        {"id": "1:4", "node_id": 1, "endpoint": 4, "kind": "light", "on": True, "available": True},
    ]

    def fake_color_fail(command, args=None, timeout=20.0, channel="cmd", listen=True, retries=2):
        calls.append((command, args, channel, retries))
        paths = [str(path) for path in ((args or {}).get("attribute_path") or [])]
        if any("/768/" in path for path in paths):
            return {"ok": False, "error": "unsupported cluster"}
        result = {}
        for path in paths:
            if path.endswith("/6/0"):
                result[path] = True
            elif path.endswith("/8/0"):
                result[path] = 80
        return {"ok": True, "result": result}

    agent.matter_rpc = fake_color_fail
    agent.poll_light_attributes()
    color_reads = [item for item in calls if item[0] == "read_attribute"]
    if len(color_reads) != 3:
        raise SystemExit(f"colour failure must not read each bulb again {calls}")
    if any(item[3] != 1 for item in calls if item[0] == "read_attribute"):
        raise SystemExit(f"poll reads must be a single attempt {calls}")

    calls.clear()
    agent._poll_node_backoff.clear()
    agent._poll_cursor = 0
    agent._poll_color_at = 10**12
    agent._live_devices = [
        {
            "id": f"9:{endpoint}",
            "node_id": 9,
            "endpoint": endpoint,
            "kind": "light",
            "on": False,
            "available": True,
        }
        for endpoint in range(1, 31)
    ]

    def fake_slice(command, args=None, timeout=20.0, channel="cmd", listen=True, retries=2):
        calls.append((command, args, channel, timeout, retries))
        return {"ok": True, "result": {}}

    agent.matter_rpc = fake_slice
    agent.poll_light_attributes()
    agent.poll_light_attributes()
    slice_reads = [item for item in calls if item[0] == "read_attribute"]
    if len(slice_reads) != 2:
        raise SystemExit(f"a large bridge must be one read per poll {calls}")
    first_paths = list((slice_reads[0][1] or {}).get("attribute_path") or [])
    second_paths = list((slice_reads[1][1] or {}).get("attribute_path") or [])
    if len(first_paths) != 24 or first_paths == second_paths:
        raise SystemExit(f"large poll did not advance {first_paths} {second_paths}")
    if slice_reads[0][3] > 3 or slice_reads[0][4] != 1:
        raise SystemExit(f"large poll read was too heavy {slice_reads[0]}")

    agent._live_devices = [{"id": "1:2", "on": False, "brightness": 10}]
    agent.apply_read_attributes(1, [["2/6/0", True], ["2/8/0", 127]])
    listed = agent.current_live_devices()
    if not listed or listed[0].get("on") is not True or int(listed[0].get("brightness") or 0) != 50:
        raise SystemExit(f"list read_attribute {listed}")

    cmd_calls: list[tuple] = []
    busy_during_send: list[bool] = []

    def fake_cmd_rpc(command, args=None, timeout=20.0, channel="cmd", listen=True, **_kwargs):
        cmd_calls.append((command, channel))
        busy_during_send.append(agent.command_in_flight())
        return {"ok": True, "result": None}

    agent.matter_rpc = fake_cmd_rpc
    agent._live_devices = [{"id": "1:2", "on": False}]
    sent = agent.dispatch({"op": "command", "id": "1:2", "action": "on"})
    if not sent.get("ok") or sent.get("on") is not True:
        raise SystemExit(f"website On {sent}")
    if any(channel == "poll" for _command, channel in cmd_calls):
        raise SystemExit(f"website On used poll channel {cmd_calls}")
    if not any(command == "device_command" and channel == "cmd" for command, channel in cmd_calls):
        raise SystemExit(f"website On did not use cmd channel {cmd_calls}")
    if not any(busy_during_send):
        raise SystemExit("website On must mark command in flight so poll pauses")
    held = agent.current_live_devices()
    if not held or held[0].get("on") is not True:
        raise SystemExit(f"website On sticky {held}")

    leftover_node = {
        "node_id": 1,
        "available": True,
        "is_bridge": True,
        "attributes": {
            "0/40/1": "Signify Netherlands B.V.",
            "0/40/3": "BSB003",
            "2/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "2/6/0": True,
            "2/8/0": 80,
            "2/57/5": "Hall",
            "96/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "96/6/0": True,
            "96/8/0": 10,
            "100/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "100/6/0": True,
        },
    }
    dimmer_node = {
        "node_id": 1,
        "available": True,
        "is_bridge": True,
        "attributes": {
            "0/40/1": "Signify Netherlands B.V.",
            "0/40/3": "Hue Bridge",
            "8/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "8/6/0": True,
            "8/8/0": 80,
            "8/768/3": 200,
            "8/57/5": "Hue dimmer switch 2",
            "9/29/0": [{"deviceType": 0x0104, "revision": 1}],
            "9/6/0": False,
            "9/57/5": "Hue tap dial switch 3",
        },
    }
    dimmer_rows = {d["id"]: d for d in agent.flatten_nodes([dimmer_node])}
    dim2 = dimmer_rows.get("1:8") or {}
    tap3 = dimmer_rows.get("1:9") or {}
    if dim2.get("kind") != "switch" or dim2.get("dimmable") or dim2.get("colorable"):
        raise SystemExit(f"Hue dimmer switch must not be a light {dim2}")
    if tap3.get("kind") != "switch" or tap3.get("dimmable") or tap3.get("colorable"):
        raise SystemExit(f"Hue tap dial must not be a light {tap3}")
    if agent.incomplete_hue_child_endpoints([dimmer_node]):
        raise SystemExit("Hue switches must not be queued for light colour reads")
    on_only_light = {
        "node_id": 1,
        "available": True,
        "is_bridge": True,
        "attributes": {
            "0/40/1": "Signify Netherlands B.V.",
            "0/40/3": "Hue Bridge",
            "12/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "12/6/0": True,
            "12/57/5": "Landing",
        },
    }
    if (1, 12) not in agent.incomplete_hue_child_endpoints([on_only_light]):
        raise SystemExit("On-only Hue lights should still be read for brightness")
    if not agent.name_looks_switch("Hue dimmer switch 2"):
        raise SystemExit("dimmer switch name")
    if agent.name_looks_switch("Hall"):
        raise SystemExit("Hall is a light")

    leftover_ids = {str(row.get("id") or "") for row in agent.flatten_nodes([leftover_node])}
    if "1:2" not in leftover_ids:
        raise SystemExit(f"named Hue light missing {leftover_ids}")
    if "1:96" in leftover_ids or "1:100" in leftover_ids:
        raise SystemExit(f"leftover Hue endpoints flattened {leftover_ids}")
    if not agent.looks_like_leftover_hue_endpoint("BSB003 96", "BSB003", "Hue Bridge", 96):
        raise SystemExit("BSB003 96 should look leftover")
    if agent.looks_like_leftover_hue_endpoint("Hall", "BSB003", "Hue Bridge", 12):
        raise SystemExit("named Hall should not look leftover")

    pending_node = {
        "node_id": 1,
        "available": True,
        "is_bridge": True,
        "attributes": {
            "0/40/1": "Signify Netherlands B.V.",
            "0/40/3": "Hue Bridge",
            "12/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "12/57/5": "Porch",
            "96/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "96/57/5": "BSB003 96",
        },
    }
    pending_ids = {str(row.get("id") or "") for row in agent.flatten_nodes([pending_node])}
    if "1:12" in pending_ids:
        raise SystemExit(f"named Hue Bridged Node without OnOff must not be a commandable light {pending_ids}")
    if "1:96" in pending_ids:
        raise SystemExit(f"leftover Hue without OnOff flattened {pending_ids}")
    pending_eps = agent.incomplete_hue_child_endpoints([pending_node])
    if (1, 12) not in pending_eps:
        raise SystemExit(f"Porch should be queued for OnOff read {pending_eps}")
    if (1, 96) in pending_eps:
        raise SystemExit(f"leftover Hue queued for OnOff read {pending_eps}")

    mill = {
        "node_id": 26,
        "available": True,
        "attributes": {
            "0/40/3": "Mill Wi-Fi Panel Heater Gen4",
            "1/6/0": False,
            "2/513/18": 2100,
        },
    }
    if agent.scan_candidate_node_ids([leftover_node, mill]) != [1]:
        raise SystemExit(f"scan should prefer the Hue Bridge {agent.scan_candidate_node_ids([leftover_node, mill])}")

    scan_calls: list[tuple] = []

    def fake_scan_rpc(command, args=None, timeout=20.0, channel="cmd", listen=True, **_kwargs):
        scan_calls.append((command, args or {}))
        if command == "get_nodes":
            return {"ok": True, "result": [leftover_node]}
        if command == "interview_node":
            return {"ok": True}
        return {"ok": False, "error": "no"}

    agent.SCAN_POST_INTERVIEW_S = 0
    agent.SCAN_RETRY_WAIT_S = 0
    agent.matter_rpc = fake_scan_rpc  # type: ignore[method-assign]
    agent._live_devices = []
    scanned = agent.dispatch({"op": "scan"})
    if not scanned.get("ok"):
        raise SystemExit(f"scan op {scanned}")
    if not any(command == "interview_node" and (args or {}).get("node_id") == 1 for command, args in scan_calls):
        raise SystemExit(f"scan did not interview the Hue Bridge {scan_calls}")
    scanned_ids = {str(row.get("id") or "") for row in scanned.get("devices") or []}
    if "1:2" not in scanned_ids or "1:96" in scanned_ids:
        raise SystemExit(f"scan devices {scanned_ids}")

    get_nodes_n = {"n": 0}
    incomplete = {
        "node_id": 1,
        "available": True,
        "is_bridge": True,
        "attributes": {
            "0/40/1": "Signify Netherlands B.V.",
            "0/40/3": "Hue Bridge",
            "2/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "2/6/0": True,
            "2/57/5": "Hall",
        },
    }
    complete = {
        "node_id": 1,
        "available": True,
        "is_bridge": True,
        "attributes": {
            **incomplete["attributes"],
            "12/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "12/6/0": True,
            "12/57/5": "Porch",
            "13/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "13/6/0": False,
            "13/57/5": "Path",
            "14/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "14/6/0": True,
            "14/57/5": "Gate",
            "15/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "15/6/0": False,
            "15/57/5": "Shed",
        },
    }

    def growing_rpc(command, args=None, timeout=20.0, channel="cmd", listen=True, **_kwargs):
        if command == "get_nodes":
            get_nodes_n["n"] += 1
            node = incomplete if get_nodes_n["n"] < 3 else complete
            return {"ok": True, "result": [node]}
        if command == "interview_node":
            return {"ok": True}
        return {"ok": False, "error": "no"}

    agent.matter_rpc = growing_rpc  # type: ignore[method-assign]
    agent._live_devices = [{"id": "1:2", "name": "Hall", "kind": "light", "on": True}]
    grown = agent.dispatch({"op": "scan"})
    grown_ids = {str(row.get("id") or "") for row in grown.get("devices") or []}
    if "1:2" not in grown_ids:
        raise SystemExit(f"scan merge dropped Hall {grown_ids}")
    missing = {"1:12", "1:13", "1:14", "1:15"} - grown_ids
    if missing:
        raise SystemExit(f"scan retry missed Apple Home Hue lights {missing} in {grown_ids}")

    named_only = {
        "node_id": 1,
        "available": True,
        "is_bridge": True,
        "attributes": {
            "0/40/1": "Signify Netherlands B.V.",
            "0/40/3": "Hue Bridge",
            "12/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "12/57/5": "Landing",
        },
    }
    filled = {
        "node_id": 1,
        "available": True,
        "is_bridge": True,
        "attributes": {
            **named_only["attributes"],
            "12/6/0": True,
            "12/8/0": 180,
            "12/768/3": 200,
            "12/768/4": 180,
        },
    }
    hue_state = {"node": named_only}
    read_calls: list[list[str]] = []

    def fill_rpc(command, args=None, timeout=20.0, channel="cmd", listen=True, **_kwargs):
        if command == "get_nodes":
            return {"ok": True, "result": [hue_state["node"]]}
        if command == "interview_node":
            return {"ok": True}
        if command == "read_attribute":
            paths = list((args or {}).get("attribute_path") or [])
            read_calls.append(paths)
            hue_state["node"] = filled
            return {"ok": True, "result": {"12/6/0": True, "12/8/0": 180}}
        return {"ok": False, "error": "no"}

    agent.matter_rpc = fill_rpc  # type: ignore[method-assign]
    agent._live_devices = []
    filled_scan = agent.dispatch({"op": "scan"})
    filled_by_id = {str(row.get("id") or ""): row for row in filled_scan.get("devices") or []}
    if not read_calls:
        raise SystemExit("scan did not read OnOff for a named Hue child")
    landing = filled_by_id.get("1:12") or {}
    if not landing.get("has_on_off") or not landing.get("on") or not landing.get("dimmable"):
        raise SystemExit(f"Landing should have On/Off and brightness after scan {landing}")
    stub = {"id": "1:12", "name": "Landing", "kind": "light", "on": False, "dimmable": False}
    rich = {"id": "1:12", "name": "Landing", "kind": "light", "on": True, "dimmable": True, "has_on_off": True, "colorable": True}
    kept = agent._prefer_richer_row(rich, stub)
    if not kept.get("dimmable") or not kept.get("colorable"):
        raise SystemExit(f"stub must not overwrite a complete Hue light {kept}")

    composed = {
        "node_id": 1,
        "available": True,
        "is_bridge": True,
        "attributes": {
            "0/40/1": "Signify Netherlands B.V.",
            "0/40/3": "Hue Bridge",
            "11/29/0": [{"deviceType": DEVTYPE_BRIDGED, "revision": 1}],
            "11/29/3": [12],
            "11/57/5": "Landing",
            "11/6/0": True,
            "11/8/0": 40,
            "12/29/0": [{"deviceType": 0x010D, "revision": 1}],
            "12/6/0": True,
            "12/8/0": 180,
            "12/768/3": 200,
            "12/57/5": "LCA005 12",
        },
    }
    composed_rows = {str(d.get("id") or ""): d for d in agent.flatten_nodes([composed])}
    if "1:11" in composed_rows:
        raise SystemExit(f"Bridged Node parent with PartsList must not flatten {composed_rows.keys()}")
    landing_child = composed_rows.get("1:12") or {}
    if landing_child.get("name") != "Landing" or not landing_child.get("has_on_off"):
        raise SystemExit(f"lighting child should inherit Landing {landing_child}")
    if (1, 11) in agent.incomplete_hue_child_endpoints([composed]):
        raise SystemExit("PartsList parent must not be queued for light reads")

    cmd_calls: list[tuple] = []

    def cmd_rpc(command, args=None, timeout=20.0, channel="cmd", listen=True, **_kwargs):
        cmd_calls.append((command, args or {}))
        if command == "device_command":
            ep = (args or {}).get("endpoint_id")
            if ep == 11:
                return {"ok": False, "error": "InteractionModelError: UnsupportedCluster (0xc3)"}
            if ep == 12:
                return {"ok": True}
        return {"ok": False, "error": "no"}

    agent.matter_rpc = cmd_rpc  # type: ignore[method-assign]
    agent.persist_remapped_endpoint = lambda old, new: 1  # type: ignore[method-assign]
    agent._live_devices = [
        {"id": "1:11", "name": "Landing", "node_id": 1, "endpoint": 11, "kind": "light", "has_on_off": True},
        {"id": "1:12", "name": "Landing", "node_id": 1, "endpoint": 12, "kind": "light", "has_on_off": True},
    ]
    remapped = agent.dispatch({"op": "command", "id": "1:11", "action": "on"})
    if not remapped.get("ok"):
        raise SystemExit(f"OnOff 0xc3 should remap to the lighting child {remapped}")
    if remapped.get("id") != "1:12":
        raise SystemExit(f"command id should become the child {remapped}")
    if not any((args or {}).get("endpoint_id") == 12 for command, args in cmd_calls if command == "device_command"):
        raise SystemExit(f"command did not retry the child endpoint {cmd_calls}")

    climate = {
        "node_id": 80,
        "available": True,
        "attributes": {
            "0/40/1": "IKEA of Sweden",
            "0/40/3": "VINDSTYRKA",
            "1/29/0": [{"deviceType": 0x0302, "revision": 1}],
            "1/1026/0": 2140,
            "1/1029/0": 4750,
        },
    }
    contact = {
        "node_id": 81,
        "available": True,
        "attributes": {
            "0/40/1": "IKEA of Sweden",
            "0/40/3": "PARASOLL",
            "1/29/0": [{"deviceType": 0x0015, "revision": 1}],
            "1/69/0": True,
        },
    }
    plug = {
        "node_id": 82,
        "available": True,
        "attributes": {
            "0/40/1": "IKEA of Sweden",
            "0/40/3": "INSPELNING",
            "1/29/0": [{"deviceType": 0x010A, "revision": 1}],
            "1/6/0": True,
            "1/144/8": 12500,
        },
    }
    grillplats = {
        "node_id": 83,
        "available": True,
        "attributes": {
            "0/40/1": "IKEA of Sweden",
            "0/40/3": "GRILLPLATS",
            "1/29/0": [{"deviceType": 0x010A, "revision": 1}],
            "1/6/0": True,
            # Electrical Sensor sibling (Matter Survey endpoint 2)
            "2/29/0": [{"deviceType": 0x0510, "revision": 1}],
            "2/144/4": 230500,  # Voltage mV
            "2/144/5": 54,  # ActiveCurrent mA
            "2/144/8": 12500,  # ActivePower mW
            "2/144/11": 231000,  # RMSVoltage mV (prefer)
            "2/144/12": 55,  # RMSCurrent mA (prefer)
        },
    }
    occupancy = {
        "node_id": 84,
        "available": True,
        "attributes": {
            "0/40/1": "IKEA of Sweden",
            "0/40/3": "VALLHORN",
            "1/29/0": [{"deviceType": 0x0107, "revision": 1}],
            "1/1030/0": 1,
        },
    }
    sensor_rows = {
        d["id"]: d
        for d in agent.flatten_nodes({"nodes": [climate, contact, plug, grillplats, occupancy]})
    }
    clim = sensor_rows.get("80:1") or {}
    if clim.get("kind") != "sensor" or clim.get("temperature") != 21.4 or clim.get("humidity") != 47.5:
        raise SystemExit(f"climate sensor flatten {clim}")
    if "°" not in str(clim.get("status") or "") or "RH" not in str(clim.get("status") or ""):
        raise SystemExit(f"climate status {clim}")
    timmer = {
        "node_id": 90,
        "available": True,
        "attributes": {
            "0/40/1": "IKEA of Sweden",
            "0/40/3": "TIMMERFLOTTE",
            "1/29/0": [{"deviceType": 0x0302, "revision": 1}],
            "1/40/5": "Workshop Temperature",
            "1/1026/0": 2340,
            "2/29/0": [{"deviceType": 0x0307, "revision": 1}],
            "2/40/5": "Workshop Humidity",
            "2/1029/0": 4900,
            "3/29/0": [{"deviceType": 0x0015, "revision": 1}],
            "3/40/5": "Workshop Door",
            "3/69/0": True,
        },
    }
    timmer_rows = {d["id"]: d for d in agent.flatten_nodes({"nodes": [timmer]})}
    workshop = timmer_rows.get("90:1") or {}
    if "90:2" in timmer_rows:
        raise SystemExit(f"humidity endpoint must join the temperature row {timmer_rows.keys()}")
    if workshop.get("name") != "Workshop" or workshop.get("temperature") != 23.4 or workshop.get("humidity") != 49.0:
        raise SystemExit(f"timmerflotte combined row {workshop}")
    if "°" not in str(workshop.get("status") or "") or "RH" not in str(workshop.get("status") or ""):
        raise SystemExit(f"timmerflotte status {workshop}")
    if workshop.get("climate_endpoints") != [1, 2]:
        raise SystemExit(f"timmerflotte endpoints {workshop.get('climate_endpoints')}")
    door = timmer_rows.get("90:3") or {}
    if door.get("kind") != "sensor" or door.get("has_open") is not True or door.get("open") is not False:
        raise SystemExit(f"contact on the same node stays its own row {door}")
    agent.remember_live_devices([dict(workshop), dict(door)])
    agent.apply_attribute_event({"node_id": 90, "attribute_path": "2/1029/0", "value": 5100})
    live_climate = {d["id"]: d for d in agent.current_live_devices()}
    if "90:2" in live_climate:
        raise SystemExit(f"humidity update recreated a second row {live_climate.keys()}")
    updated = live_climate.get("90:1") or {}
    if updated.get("temperature") != 23.4 or updated.get("humidity") != 51.0:
        raise SystemExit(f"humidity update missed the combined row {updated}")
    if "°" not in str(updated.get("status") or "") or "RH" not in str(updated.get("status") or ""):
        raise SystemExit(f"combined status after update {updated}")
    if "90:3" not in live_climate:
        raise SystemExit("combined climate row dropped the door")
    hidden_hum = {
        "node_id": 91,
        "available": True,
        "attributes": {
            "0/40/1": "IKEA of Sweden",
            "0/40/3": "TIMMERFLOTTE",
            "1/29/0": [{"deviceType": 0x0302, "revision": 1}],
            "1/40/5": "Workshop Temperature",
            "1/1026/0": 1110,
            "2/29/0": [{"deviceType": 0x000E, "revision": 1}],
            "2/29/3": [1],
            "2/1029/0": 6400,
        },
    }
    hidden_rows = {d["id"]: d for d in agent.flatten_nodes({"nodes": [hidden_hum]})}
    if "91:2" in hidden_rows:
        raise SystemExit(f"hidden humidity endpoint became a row {hidden_rows.keys()}")
    workshop_only = hidden_rows.get("91:1") or {}
    if workshop_only.get("temperature") != 11.1 or workshop_only.get("humidity") != 64.0:
        raise SystemExit(f"workshop humidity was not copied onto the temperature row {workshop_only}")
    if "RH" not in str(workshop_only.get("status") or ""):
        raise SystemExit(f"workshop status missing humidity {workshop_only}")
    if 2 not in (workshop_only.get("climate_endpoints") or []):
        raise SystemExit(f"workshop climate endpoints {workshop_only.get('climate_endpoints')}")
    agent.remember_live_devices([
        {
            "id": "91:1",
            "node_id": 91,
            "endpoint": 1,
            "kind": "sensor",
            "name": "T/H Sensor",
            "product": "TIMMERFLOTTE temp/hmd sensor",
            "temperature": 11.1,
            "humidity": None,
            "status": "11.1°",
        }
    ])
    targets = agent.climate_poll_targets()
    if not any(node_id == 91 and 2 in endpoints for node_id, endpoints, _device_id in targets):
        raise SystemExit(f"workshop sensor was not queued for a humidity read {targets}")
    original_read = agent._poll_read

    def fake_read(node_id, paths, timeout=None):
        if list(paths) == ["2/1029/0"]:
            return {"ok": True, "result": {"2/1029/0": 6400}}
        return {"ok": False, "error": "unsupported cluster"}

    agent._poll_read = fake_read
    try:
        agent.poll_climate_attributes()
    finally:
        agent._poll_read = original_read
    polled = {d["id"]: d for d in agent.current_live_devices()}
    if "91:2" in polled:
        raise SystemExit(f"humidity read created a second workshop row {polled.keys()}")
    showed = polled.get("91:1") or {}
    if showed.get("temperature") != 11.1 or showed.get("humidity") != 64.0:
        raise SystemExit(f"humidity read missed the workshop row {showed}")
    if "11.1" not in str(showed.get("status") or "") or "RH" not in str(showed.get("status") or ""):
        raise SystemExit(f"workshop badge after humidity read {showed}")
    cont = sensor_rows.get("81:1") or {}
    if cont.get("kind") != "sensor" or cont.get("has_open") is not True or cont.get("open") is not False:
        raise SystemExit(f"contact sensor flatten {cont}")
    if cont.get("on") is not False or "Closed" not in str(cont.get("status") or ""):
        raise SystemExit(f"contact closed status {cont}")
    if agent.contact_state_is_open(True) is not False or agent.contact_state_is_open(False) is not True:
        raise SystemExit("contact StateValue polarity")
    if agent.contact_state_is_open(None) is not None:
        raise SystemExit("missing contact state")
    agent._live_devices = [dict(cont)]
    agent.apply_attribute_event({"node_id": 81, "attribute_path": "1/69/0", "value": False})
    opened = agent.live_row("81:1") or {}
    if opened.get("open") is not True or "Open" not in str(opened.get("status") or ""):
        raise SystemExit(f"contact opened report {opened}")
    agent.apply_attribute_event({"node_id": 81, "attribute_path": "1/69/0", "value": True})
    closed = agent.live_row("81:1") or {}
    if closed.get("open") is not False or "Closed" not in str(closed.get("status") or ""):
        raise SystemExit(f"contact closed report {closed}")
    plug_row = sensor_rows.get("82:1") or {}
    if plug_row.get("kind") != "plug" or plug_row.get("power_w") != 12.5:
        raise SystemExit(f"plug power flatten {plug_row}")
    grill = sensor_rows.get("83:1") or {}
    if grill.get("kind") != "plug" or grill.get("power_w") != 12.5:
        raise SystemExit(f"grillplats power {grill}")
    if grill.get("voltage_v") != 231.0 or grill.get("current_a") != 0.055:
        raise SystemExit(f"grillplats V/A from sibling electrical endpoint {grill}")
    if grill.get("meter_endpoint") != 2:
        raise SystemExit(f"grillplats meter is the sibling endpoint {grill.get('meter_endpoint')}")
    if plug_row.get("meter_endpoint") != 1:
        raise SystemExit(f"inspelning meter is on the plug endpoint {plug_row.get('meter_endpoint')}")
    agent.remember_live_devices([dict(grill)])
    agent.apply_attribute_event({"node_id": 83, "attribute_path": "2/144/8", "value": 840000})
    agent.apply_attribute_event({"node_id": 83, "attribute_path": "2/144/11", "value": 229000})
    agent.apply_attribute_event({"node_id": 83, "attribute_path": "2/144/12", "value": 3660})
    live_plug = agent.live_row("83:1") or {}
    if live_plug.get("power_w") != 840.0 or live_plug.get("voltage_v") != 229.0 or live_plug.get("current_a") != 3.66:
        raise SystemExit(f"sibling meter read must land on the plug {live_plug}")
    agent.remember_live_devices([dict(grill)])
    kept = agent.live_row("83:1") or {}
    if kept.get("power_w") != 840.0 or kept.get("voltage_v") != 229.0:
        raise SystemExit(f"a fresh node list must not freeze the meter {kept}")
    if "83:2" in sensor_rows:
        raise SystemExit(f"electrical sensor endpoint must not be its own Home row {sensor_rows.keys()}")
    occ = sensor_rows.get("84:1") or {}
    if occ.get("kind") != "sensor" or occ.get("has_motion") is not True or occ.get("motion") is not True:
        raise SystemExit(f"occupancy flatten {occ}")
    if occ.get("on") is not True or "Motion" not in str(occ.get("status") or ""):
        raise SystemExit(f"occupancy motion status {occ}")
    if agent.matter_occupancy_occupied(0) is not False or agent.matter_occupancy_occupied(1) is not True:
        raise SystemExit("occupancy bitmap parse")
    if agent.matter_occupancy_occupied({"Occupied": 0}) is not False:
        raise SystemExit("occupancy dict 0")
    if agent.matter_occupancy_occupied({"Occupied": 1}) is not True:
        raise SystemExit("occupancy dict 1")
    stuck = {
        "id": "84:1",
        "node_id": 84,
        "endpoint": 1,
        "kind": "sensor",
        "has_motion": True,
        "motion": True,
        "on": True,
        "motion_at": int(time.time()) - 120,
        "status": "Motion",
    }
    kept = agent.stabilize_motion(stuck, {**stuck, "motion_at": int(time.time())})
    if kept.get("motion_at") != stuck["motion_at"] or kept.get("motion") is not False:
        raise SystemExit(f"stale occupancy snapshot refreshed motion {kept}")
    agent._live_devices = [dict(stuck)]
    agent._occupancy_idle.clear()
    agent.expire_stale_motion()
    expired = agent.live_row("84:1") or {}
    if expired.get("motion") is not False or "Motion" in str(expired.get("status") or ""):
        raise SystemExit(f"occupancy did not expire {expired}")
    agent._reading_poll = True
    agent.apply_attribute_event({"node_id": 84, "attribute_path": "1/1030/0", "value": 1})
    if (agent.live_row("84:1") or {}).get("motion") is not False:
        raise SystemExit("cached occupied read turned motion back on")
    agent._reading_poll = False
    agent.apply_attribute_event({"node_id": 84, "attribute_path": "1/1030/0", "value": 1})
    woke = agent.live_row("84:1") or {}
    if woke.get("motion") is not True or int(woke.get("motion_at") or 0) <= stuck["motion_at"]:
        raise SystemExit(f"fresh occupancy report did not count as motion {woke}")
    agent._reading_poll = False

    calls: list[dict] = []
    cleared: list[str] = []
    orig_dispatch = agent.dispatch
    orig_map = agent.motor_mode_map
    orig_pending = agent.motor_pending_off_map
    orig_clear = agent.clear_motor_pending_off
    try:
        agent.dispatch = lambda body: calls.append(dict(body)) or {"ok": True, "on": body.get("action") == "on"}
        agent.motor_mode_map = lambda: {"83:1": 100}
        agent.motor_pending_off_map = lambda: {"83:1": 100} if not cleared else {}
        agent.clear_motor_pending_off = lambda device_id: cleared.append(str(device_id))
        agent.remember_live_devices([{
            "id": "83:1",
            "kind": "plug",
            "on": True,
            "power_w": 1043.6,
            "voltage_v": 229.0,
            "current_a": 4.56,
            "node_id": 83,
            "endpoint": 1,
            "meter_endpoint": 1,
            "available": True,
        }])
        agent._motor_high_until["83:1"] = time.time() + 30
        agent.apply_attribute_event({"node_id": 83, "attribute_path": "1/6/0", "value": False})
        apple_off = agent.live_row("83:1") or {}
        if apple_off.get("on") is not False:
            raise SystemExit(f"apple home off must stay off {apple_off}")
        if any(str(item.get("action") or "") == "on" for item in calls):
            raise SystemExit(f"apple home off must not be turned back on {calls}")
        if cleared != ["83:1"]:
            raise SystemExit(f"apple home off must clear a queued off {cleared}")
        agent.remember_live_devices([{
            "id": "83:1",
            "kind": "plug",
            "on": True,
            "power_w": 12.5,
            "node_id": 83,
            "endpoint": 1,
        }])
        kept_off = agent.live_row("83:1") or {}
        if kept_off.get("on") is not False:
            raise SystemExit(f"a node list must not show the plug on after apple home off {kept_off}")
    finally:
        agent.dispatch = orig_dispatch
        agent.motor_mode_map = orig_map
        agent.motor_pending_off_map = orig_pending
        agent.clear_motor_pending_off = orig_clear
        agent._motor_high_until.pop("83:1", None)

    print("ok: 70 Hue Bridge lights flatten from Bridged Node + OnOff")
    return 0


if __name__ == "__main__":
    sys.exit(main())
