#!/usr/bin/env python3
"""Robot vacuums use RVC Run/Clean/Service Area, not OnOff."""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "matter_agent.py"


def load_agent():
    spec = importlib.util.spec_from_file_location("matter_agent", SCRIPT)
    if spec is None or spec.loader is None:
        raise SystemExit(f"Could not load {SCRIPT}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def vacuum_node() -> dict:
    return {
        "node_id": 40,
        "available": True,
        "attributes": {
            "0/40/3": "K11+",
            "1/29/0": [{"deviceType": 0x0074, "revision": 1}],
            "1/84/0": [
                {"label": "Idle", "mode": 0, "modeTags": [{"value": 0x4000}]},
                {"label": "Cleaning", "mode": 1, "modeTags": [{"value": 0x4001}]},
                {"label": "Mapping", "mode": 2, "modeTags": [{"value": 0x4002}]},
            ],
            "1/84/1": 0,
            "1/85/0": [
                {"label": "Vacuum", "mode": 0, "modeTags": [{"value": 0x4000}]},
                {"label": "Mop", "mode": 1, "modeTags": [{"value": 0x4001}]},
            ],
            "1/85/1": 0,
            "1/97/0": ["Cleaning"],
            "1/97/4": 0,
            "1/336/0": [
                {
                    "areaID": 1,
                    "mapID": 9,
                    "areaInfo": {"locationInfo": {"locationName": "Kitchen"}},
                },
                {"0": 2, "1": 9, "2": {"0": {"0": "Living room"}}},
                {
                    "areaID": 3,
                    "mapID": 9,
                    "areaInfo": {"locationInfo": {"locationName": "Unnamed", "areaType": 0x002E}},
                },
            ],
            "1/336/1": [{"mapID": 9, "name": "Upstairs"}],
            "1/336/2": [1],
        },
    }


def s10_node() -> dict:
    return {
        "node_id": 50,
        "available": True,
        "attributes": {
            "0/40/1": "SwitchBot",
            "0/40/3": "S10",
            "1/29/0": [{"deviceType": 0x0074, "revision": 1}],
            "1/84/0": [
                {"label": "Idle", "mode": 0, "modeTags": [{"value": 0x4000}]},
                {"label": "Mapping", "mode": 1, "modeTags": [{"value": 0x4002}]},
                {"label": "Cleaning", "mode": 2, "modeTags": [{"value": 0x4001}]},
            ],
            "1/84/1": 2,
            "1/85/0": [
                {"label": "Vacuum", "mode": 0, "modeTags": [{"value": 0x4000}]},
                {"label": "Mop", "mode": 1, "modeTags": [{"value": 0x4001}]},
            ],
            "1/85/1": 0,
            "1/97/0": ["Cleaning"],
            "1/97/4": 0x42,
            "1/336/0": [
                {"areaID": 1, "areaInfo": {"locationInfo": {"locationName": "Kitchen"}}},
            ],
            "2/29/0": [{"deviceType": 0x0015, "revision": 1}],
            "3/29/0": [{"deviceType": 0x0015, "revision": 1}],
        },
    }


def apply_change_to_mode(agent, attrs: dict, args: dict, *, leave_docked: bool = False) -> None:
    if (args or {}).get("command_name") != "ChangeToMode":
        return
    payload = (args or {}).get("payload") or {}
    mode = payload.get("newMode")
    if mode is None:
        mode = payload.get("NewMode")
    if mode is None:
        return
    cluster = (args or {}).get("cluster_id")
    try:
        ep = int((args or {}).get("endpoint_id") or 1)
        mode_n = int(mode)
    except (TypeError, ValueError):
        return
    if cluster == agent.RVC_RUN:
        attrs[f"{ep}/84/1"] = mode_n
        if leave_docked:
            attrs[f"{ep}/97/4"] = 0x42
        else:
            attrs[f"{ep}/97/4"] = 0 if mode_n == 0 else 1
    elif cluster == agent.RVC_CLEAN:
        attrs[f"{ep}/85/1"] = mode_n


def main() -> int:
    agent = load_agent()
    agent.VACUUM_MODE_SETTLE_S = 0
    agent.VACUUM_START_SETTLE_S = 0
    assert agent.ATTR_OPERATIONAL_STATE == 4, agent.ATTR_OPERATIONAL_STATE
    assert agent.AGENT_VERSION == 33, agent.AGENT_VERSION
    rows = {d["id"]: d for d in agent.flatten_nodes({"nodes": [vacuum_node()]})}
    vac = rows.get("40:1") or {}
    if vac.get("kind") != "vacuum":
        raise SystemExit(f"kind {vac}")
    if vac.get("can_mop") is not True:
        raise SystemExit(f"can_mop {vac}")
    names = [a["name"] for a in vac.get("areas") or []]
    if names != ["Kitchen", "Living room", "Living room"]:
        raise SystemExit(f"areas {vac.get('areas')}")
    if vac.get("selected_areas") != [1]:
        raise SystemExit(f"selected {vac.get('selected_areas')}")
    if vac.get("vacuum_status") != "Idle":
        raise SystemExit(f"status {vac.get('vacuum_status')}")
    docked_node = vacuum_node()
    docked_node["attributes"]["1/97/4"] = 0x42
    docked_node["attributes"]["1/84/1"] = 1
    docked = {d["id"]: d for d in agent.flatten_nodes({"nodes": [docked_node]})}.get("40:1") or {}
    if docked.get("vacuum_status") != "Docked" or docked.get("on") is True:
        raise SystemExit(f"docked {docked}")
    s10 = {d["id"]: d for d in agent.flatten_nodes({"nodes": [s10_node()]})}.get("50:1") or {}
    if s10.get("kind") != "vacuum":
        raise SystemExit(f"s10 kind {s10}")
    if s10.get("vacuum_status") != "Docked" or s10.get("on") is True:
        raise SystemExit(f"s10 should be Docked while Cleaning+docked, got {s10.get('vacuum_status')}")
    if int((s10.get("run_modes") or [{}])[-1].get("mode") or 0) != 2:
        raise SystemExit(f"s10 cleaning mode {s10.get('run_modes')}")
    mapping_node = s10_node()
    mapping_node["attributes"]["1/84/1"] = 1
    mapping_node["attributes"]["1/97/4"] = 1
    mapped = {d["id"]: d for d in agent.flatten_nodes({"nodes": [mapping_node]})}.get("50:1") or {}
    if mapped.get("vacuum_status") != "Mapping" or mapped.get("on") is True:
        raise SystemExit(f"mapping {mapped}")
    picked = agent.pick_cleaning_run_mode(s10.get("run_modes") or [])
    if not picked or int(picked["mode"]) != 2:
        raise SystemExit(f"pick cleaning {picked}")
    untagged = [
        {"mode": 0, "label": "Idle", "tags": []},
        {"mode": 1, "label": "Mapping", "tags": []},
        {"mode": 2, "label": "Cleaning", "tags": []},
    ]
    if int((agent.pick_cleaning_run_mode(untagged) or {}).get("mode") or -1) != 2:
        raise SystemExit("untagged mapping must not be picked as Cleaning")

    calls: list[tuple] = []
    fake_attrs = dict(vacuum_node()["attributes"])

    def fake_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": fake_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": fake_attrs}
        if command == "device_command":
            apply_change_to_mode(agent, fake_attrs, args or {})
            return {"ok": True}
        return {"ok": True, "result": None}

    agent.matter_rpc = fake_rpc  # type: ignore[method-assign]
    agent._live_devices = [vac]

    ping = agent.dispatch({"op": "ping"})
    assert "vacuum" in (ping.get("features") or []), ping
    assert ping.get("version") == agent.AGENT_VERSION, ping

    calls.clear()
    start = agent.dispatch({
        "op": "command",
        "id": "40:1",
        "action": "start",
        "kind": "vacuum",
        "clean_mode": "mop",
        "areas": [2],
    })
    assert start.get("ok") is True, start
    cmds = [args for command, args in calls if command == "device_command"]
    clusters = [c.get("cluster_id") for c in cmds]
    assert agent.SERVICE_AREA in clusters, cmds
    assert agent.RVC_CLEAN in clusters, cmds
    assert agent.RVC_RUN in clusters, cmds
    assert agent.ON_OFF not in clusters, cmds
    area_cmd = next(c for c in cmds if c.get("cluster_id") == agent.SERVICE_AREA)
    payload = area_cmd.get("payload") or {}
    assert payload.get("newAreas") == [2] or payload.get("new_areas") == [2], area_cmd
    mop_cmd = next(c for c in cmds if c.get("cluster_id") == agent.RVC_CLEAN)
    assert (mop_cmd.get("payload") or {}).get("newMode") == 1, mop_cmd
    run_cmd = next(c for c in cmds if c.get("cluster_id") == agent.RVC_RUN)
    assert (run_cmd.get("payload") or {}).get("newMode") == 1, run_cmd
    assert not any(
        ((c.get("payload") or {}).get("newMode", (c.get("payload") or {}).get("NewMode")) == 2)
        for c in cmds
        if c.get("cluster_id") == agent.RVC_RUN
    ), cmds

    calls.clear()
    on = agent.dispatch({"op": "command", "id": "40:1", "action": "on", "kind": "vacuum"})
    assert on.get("ok") is True, on
    assert not any(
        args.get("cluster_id") == agent.ON_OFF
        for command, args in calls
        if command == "device_command"
    ), calls

    calls.clear()
    dock = agent.dispatch({"op": "command", "id": "40:1", "action": "dock", "kind": "vacuum"})
    assert dock.get("ok") is True, dock
    go = [args for command, args in calls if command == "device_command"]
    assert go and go[0].get("command_name") == "GoHome", go

    wrapped = {
        "node_id": 41,
        "available": True,
        "attributes": {
            "0/40/3": "Martynas",
            "1/29/0": [{"deviceType": 0x0074, "revision": 1}],
            "1/84/0": {"value": [
                {"label": "Idle", "mode": 0, "modeTags": [{"value": 0x4000}]},
                {"label": "Cleaning", "mode": 1, "modeTags": [{"value": 0x4001}]},
            ]},
            "1/85/0": {"value": [
                {"label": "Vacuum", "mode": 0, "modeTags": [{"value": 0x4000}]},
                {"label": "Mop", "mode": 1, "modeTags": [{"value": 0x4001}]},
            ]},
            "1/97/4": 0,
            "2/336/0": {"value": [
                {"AreaID": 1, "LocationInfo": {"LocationName": "Kitchen"}},
                {"area_id": 2, "location_info": {"location_name": "Hall"}},
            ]},
        },
    }
    wrapped_rows = {d["id"]: d for d in agent.flatten_nodes({"nodes": [wrapped]})}
    wrapped_vac = wrapped_rows.get("41:1") or {}
    if [a["name"] for a in wrapped_vac.get("areas") or []] != ["Kitchen", "Hall"]:
        raise SystemExit(f"wrapped areas {wrapped_vac.get('areas')}")
    if wrapped_vac.get("can_mop") is not True:
        raise SystemExit(f"wrapped mop {wrapped_vac}")

    calls.clear()
    slim = {k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}
    agent._live_devices = [slim]
    node_attrs = vacuum_node()["attributes"]

    def live_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": node_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": node_attrs}
        if command == "device_command":
            apply_change_to_mode(agent, node_attrs, args or {})
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = live_rpc  # type: ignore[method-assign]
    start_all = agent.dispatch({"op": "command", "id": "40:1", "action": "start", "kind": "vacuum"})
    assert start_all.get("ok") is True, start_all
    live_cmds = [args for command, args in calls if command == "device_command"]
    assert not any(c.get("command_name") == "Resume" for c in live_cmds), live_cmds
    assert not any(c.get("cluster_id") == agent.SERVICE_AREA for c in live_cmds), live_cmds
    assert not any(c.get("command_name") == "Start" for c in live_cmds), live_cmds
    assert any(
        c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
        for c in live_cmds
    ), live_cmds
    run_payload = next(c.get("payload") or {} for c in live_cmds if c.get("cluster_id") == agent.RVC_RUN)
    assert run_payload.get("newMode") == 1 or run_payload.get("NewMode") == 1, run_payload
    assert not any(
        ((c.get("payload") or {}).get("newMode", (c.get("payload") or {}).get("NewMode")) == 2)
        for c in live_cmds
        if c.get("cluster_id") == agent.RVC_RUN
    ), live_cmds
    assert not any(c.get("cluster_id") == agent.RVC_CLEAN for c in live_cmds), live_cmds

    calls.clear()
    agent._live_devices = [dict(vac)]

    def clean_fail_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command in ("get_node", "read_attribute"):
            return {
                "ok": True,
                "result": node_attrs if command == "read_attribute" else {"node_id": 40, "attributes": node_attrs},
            }
        if command == "device_command" and (args or {}).get("cluster_id") == agent.RVC_CLEAN:
            return {"ok": False, "error": "Vacuum rejected ChangeToMode (135)", "status": 0x87}
        if command == "device_command":
            apply_change_to_mode(agent, node_attrs, args or {})
            return {"ok": True}
        return {"ok": True, "result": None}

    agent.matter_rpc = clean_fail_rpc  # type: ignore[method-assign]
    start_anyway = agent.dispatch({
        "op": "command",
        "id": "40:1",
        "action": "start",
        "kind": "vacuum",
        "clean_mode": "vacuum",
        "areas": "all",
    })
    assert start_anyway.get("ok") is True, start_anyway
    start_cmds = [args for command, args in calls if command == "device_command"]
    assert any(
        c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
        for c in start_cmds
    ), start_cmds
    assert not any(c.get("cluster_id") == agent.SERVICE_AREA for c in start_cmds), start_cmds
    assert not any(c.get("command_name") == "Start" for c in start_cmds), start_cmds
    assert not any(c.get("cluster_id") == agent.RVC_CLEAN for c in start_cmds), start_cmds

    calls.clear()
    docked_attrs = dict(vacuum_node()["attributes"])
    docked_attrs["1/97/4"] = 0x42
    docked_attrs["1/84/1"] = 1

    def still_docked_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": docked_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": docked_attrs}
        if command == "device_command" and (args or {}).get("command_name") == "Start":
            return {"ok": False, "error": "Vacuum rejected Start (129)", "status": 0x81}
        if command == "device_command":
            apply_change_to_mode(agent, docked_attrs, args or {}, leave_docked=True)
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = still_docked_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    stuck = agent.dispatch({"op": "command", "id": "40:1", "action": "start", "kind": "vacuum", "areas": "all"})
    assert stuck.get("ok") is False, stuck
    assert stuck.get("on") is False, stuck
    assert stuck.get("vacuum_status") == "Docked", stuck
    assert "did not start" in str(stuck.get("error") or ""), stuck

    calls.clear()

    def tlv_fail_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command in ("get_node", "read_attribute"):
            return {"ok": True, "result": node_attrs if command == "read_attribute" else {"node_id": 40, "attributes": node_attrs}}
        if command == "device_command" and (args or {}).get("cluster_id") == agent.RVC_RUN:
            return {"ok": True, "result": {"0": 3, "1": "InvalidInMode"}}
        if command == "device_command":
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = tlv_fail_rpc  # type: ignore[method-assign]
    agent._live_devices = [dict(vac)]
    agent.dispatch({"op": "command", "id": "40:1", "action": "start", "kind": "vacuum", "areas": [2]})
    rejected = agent.vacuum_response_status({"0": 3, "1": "InvalidInMode"})
    assert rejected == 3, rejected
    assert agent.vacuum_response_status({"status": 0}) == 0

    calls.clear()
    s10_attrs = s10_node()["attributes"]
    s10_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [s10_node()]})}.get("50:1") or {}

    def s10_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 50, "attributes": s10_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": s10_attrs}
        if command == "device_command":
            apply_change_to_mode(agent, s10_attrs, args or {})
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = s10_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in s10_row.items() if k not in ("run_modes", "clean_modes", "areas")}]
    s10_start = agent.dispatch({"op": "command", "id": "50:1", "action": "start", "kind": "vacuum", "areas": "all"})
    assert s10_start.get("ok") is True, s10_start
    assert s10_start.get("vacuum_status") == "Cleaning", s10_start
    s10_cmds = [args for command, args in calls if command == "device_command"]
    run_payloads = [
        (c.get("payload") or {}).get("newMode", (c.get("payload") or {}).get("NewMode"))
        for c in s10_cmds
        if c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
    ]
    assert run_payloads == [0, 2], run_payloads
    assert 1 not in run_payloads, run_payloads
    assert all(c.get("endpoint_id") == 1 for c in s10_cmds if c.get("cluster_id") == agent.RVC_RUN), s10_cmds
    assert not any(c.get("command_name") == "Start" for c in s10_cmds), s10_cmds
    assert not any(c.get("cluster_id") == agent.ON_OFF for c in s10_cmds), s10_cmds

    calls.clear()
    s10_fail_attrs = s10_node()["attributes"]
    s10_fail_attrs["1/84/1"] = 0
    s10_fail_attrs["1/97/4"] = 0x42

    def s10_clean_reject(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 50, "attributes": s10_fail_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": s10_fail_attrs}
        if command == "device_command" and (args or {}).get("cluster_id") == agent.RVC_RUN:
            payload = (args or {}).get("payload") or {}
            mode = payload.get("newMode", payload.get("NewMode"))
            if mode == 2:
                return {"ok": True, "result": {"0": 3, "1": "InvalidInMode"}}
            apply_change_to_mode(agent, s10_fail_attrs, args or {})
            return {"ok": True, "result": {"status": 0}}
        if command == "device_command":
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = s10_clean_reject  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in s10_row.items() if k not in ("run_modes", "clean_modes", "areas")}]
    refused = agent.dispatch({"op": "command", "id": "50:1", "action": "start", "kind": "vacuum", "areas": "all"})
    refused_cmds = [args for command, args in calls if command == "device_command"]
    refused_modes = [
        (c.get("payload") or {}).get("newMode", (c.get("payload") or {}).get("NewMode"))
        for c in refused_cmds
        if c.get("cluster_id") == agent.RVC_RUN
    ]
    assert 1 not in refused_modes, refused_modes
    assert 2 in refused_modes, refused_modes
    assert refused.get("ok") is not True or refused.get("vacuum_status") != "Mapping", refused

    print("test_matter_vacuum_command.py ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
