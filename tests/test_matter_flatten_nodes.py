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
    mill_heater = {
        "node_id": 25,
        "available": True,
        "attributes": {
            "0/40/1": "Mill",
            "0/40/3": "Mill Wi-Fi Panel Heater Gen4",
            "1/29/0": [{"deviceType": 0x0100, "revision": 1}],
            "1/6/0": True,
        },
    }
    extra = {d["id"]: d for d in agent.flatten_nodes({"nodes": [light_heater, light_vacuum, mill_heater]})}
    if extra.get("23:1", {}).get("kind") != "heater" or extra["23:1"].get("colorable") or extra["23:1"].get("dimmable"):
        raise SystemExit(f"light-typed heater {extra.get('23:1')}")
    if extra.get("24:1", {}).get("kind") != "vacuum" or extra["24:1"].get("colorable"):
        raise SystemExit(f"light-typed vacuum {extra.get('24:1')}")
    if extra.get("25:1", {}).get("kind") != "heater" or extra["25:1"].get("colorable") or extra["25:1"].get("dimmable"):
        raise SystemExit(f"mill panel heater {extra.get('25:1')}")

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

    def fake_rpc(command, args=None, timeout=20.0, channel="cmd", listen=True):
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

    def fake_rpc_off(command, args=None, timeout=20.0, channel="cmd", listen=True):
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

    def fake_rpc_empty_live(command, args=None, timeout=20.0, channel="cmd", listen=True):
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

    agent._live_devices = [{"id": "1:2", "on": False, "brightness": 10}]
    agent.apply_read_attributes(1, [["2/6/0", True], ["2/8/0", 127]])
    listed = agent.current_live_devices()
    if not listed or listed[0].get("on") is not True or int(listed[0].get("brightness") or 0) != 50:
        raise SystemExit(f"list read_attribute {listed}")

    cmd_calls: list[tuple] = []
    busy_during_send: list[bool] = []

    def fake_cmd_rpc(command, args=None, timeout=20.0, channel="cmd", listen=True):
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

    print("ok: 70 Hue Bridge lights flatten from Bridged Node + OnOff")
    return 0


if __name__ == "__main__":
    sys.exit(main())
