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


def apply_select_areas(agent, attrs: dict, args: dict) -> None:
    if (args or {}).get("command_name") != "SelectAreas":
        return
    payload = (args or {}).get("payload") or {}
    areas = payload.get("newAreas")
    if areas is None:
        areas = payload.get("NewAreas")
    if areas is None:
        areas = payload.get("new_areas")
    if areas is None:
        return
    try:
        ep = int((args or {}).get("endpoint_id") or 1)
        ids = [int(item) for item in areas]
    except (TypeError, ValueError):
        return
    attrs[f"{ep}/336/2"] = ids


def apply_change_to_mode(
    agent, attrs: dict, args: dict, *, leave_docked: bool = False, keep_charging: bool = False
) -> None:
    apply_select_areas(agent, attrs, args or {})
    if (args or {}).get("command_name") != "ChangeToMode":
        return
    payload = (args or {}).get("payload") or {}
    mode = payload.get("newMode")
    if mode is None:
        mode = payload.get("NewMode")
    if mode is None:
        mode = payload.get("new_mode")
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
        elif keep_charging:
            if mode_n != 0:
                attrs[f"{ep}/97/4"] = 0x41
        else:
            attrs[f"{ep}/97/4"] = 0 if mode_n == 0 else 1
    elif cluster == agent.RVC_CLEAN:
        attrs[f"{ep}/85/1"] = mode_n


def main() -> int:
    agent = load_agent()
    agent.VACUUM_MODE_SETTLE_S = 0
    agent.VACUUM_START_SETTLE_S = 0
    assert agent.ATTR_OPERATIONAL_STATE == 4, agent.ATTR_OPERATIONAL_STATE
    assert agent.AGENT_VERSION == 43, agent.AGENT_VERSION
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
    charging_idle = vacuum_node()
    charging_idle["attributes"]["1/97/4"] = 0x41
    charging_idle["attributes"]["1/84/1"] = 0
    charging = {d["id"]: d for d in agent.flatten_nodes({"nodes": [charging_idle]})}.get("40:1") or {}
    if charging.get("vacuum_status") != "Charging":
        raise SystemExit(f"charging idle {charging}")
    charging_idle["attributes"]["1/84/1"] = 1
    leaving = {d["id"]: d for d in agent.flatten_nodes({"nodes": [charging_idle]})}.get("40:1") or {}
    if leaving.get("vacuum_status") != "Cleaning" or leaving.get("on") is not True:
        raise SystemExit(f"charging+cleaning should be Cleaning, got {leaving}")
    error_node = vacuum_node()
    error_node["attributes"]["1/97/4"] = 3
    error_node["attributes"]["1/97/5"] = {"errorStateID": 3}
    errored = {d["id"]: d for d in agent.flatten_nodes({"nodes": [error_node]})}.get("40:1") or {}
    if errored.get("vacuum_status") != "Error" or errored.get("on") is True:
        raise SystemExit(f"error {errored}")
    if "invalid" not in str(errored.get("vacuum_error") or "").lower():
        raise SystemExit(f"error text {errored.get('vacuum_error')}")
    returning_node = vacuum_node()
    returning_node["attributes"]["1/97/4"] = 0x40
    returning_node["attributes"]["1/84/1"] = 0
    returning = {d["id"]: d for d in agent.flatten_nodes({"nodes": [returning_node]})}.get("40:1") or {}
    if returning.get("vacuum_status") != "Returning" or returning.get("on") is True:
        raise SystemExit(f"returning {returning}")
    if agent.vacuum_command_status_label("ChangeToMode", 2) != "generic failure":
        raise SystemExit("ChangeToMode 2 should be generic failure")
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
    assert "timed_request_timeout_ms" not in run_cmd, run_cmd
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
            "0/40/3": "Alejandro",
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
    charge_attrs = dict(vacuum_node()["attributes"])
    charge_attrs["1/97/4"] = 0x41
    charge_attrs["1/84/1"] = 0

    def charge_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": charge_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": charge_attrs}
        if command == "device_command":
            args = args or {}
            if args.get("cluster_id") == agent.RVC_RUN and args.get("command_name") == "ChangeToMode":
                payload = args.get("payload") or {}
                mode = payload.get("newMode", payload.get("NewMode"))
                if mode == 1 and charge_attrs.get("1/97/4") == 0x41:
                    return {"ok": True, "result": {"0": 2, "1": "GenericFailure"}}
            apply_change_to_mode(agent, charge_attrs, args or {})
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = charge_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    from_charge = agent.dispatch({"op": "command", "id": "40:1", "action": "start", "kind": "vacuum", "areas": "all"})
    assert from_charge.get("ok") is True, from_charge
    assert from_charge.get("vacuum_status") == "Cleaning", from_charge
    charge_cmds = [args for command, args in calls if command == "device_command"]
    assert not any(c.get("cluster_id") == agent.SERVICE_AREA for c in charge_cmds), charge_cmds
    assert not any(c.get("cluster_id") == agent.RVC_CLEAN for c in charge_cmds), charge_cmds
    charge_modes = [
        (c.get("payload") or {}).get("newMode", (c.get("payload") or {}).get("NewMode"))
        for c in charge_cmds
        if c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
    ]
    assert charge_modes == [1, 2, 1], charge_modes
    assert 0 not in charge_modes, charge_modes

    calls.clear()
    # Second Start: leftover Cleaning+Charging must not send Idle (standby/dock).
    leftover_attrs = dict(vacuum_node()["attributes"])
    leftover_attrs["1/97/4"] = 0x41
    leftover_attrs["1/84/1"] = 1

    def leftover_charge_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        args = args or {}
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": leftover_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": leftover_attrs}
        if command == "device_command":
            if args.get("cluster_id") == agent.RVC_RUN and args.get("command_name") == "ChangeToMode":
                payload = args.get("payload") or {}
                mode = payload.get("newMode", payload.get("NewMode"))
                if mode == 1 and leftover_attrs.get("1/97/4") == 0x41:
                    return {"ok": True, "result": {"0": 2, "1": "GenericFailure"}}
            apply_change_to_mode(agent, leftover_attrs, args)
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = leftover_charge_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    leftover = agent.dispatch({"op": "command", "id": "40:1", "action": "start", "kind": "vacuum", "areas": "all"})
    assert leftover.get("ok") is True, leftover
    assert leftover.get("vacuum_status") == "Cleaning", leftover
    leftover_cmds = [args for command, args in calls if command == "device_command"]
    leftover_modes = [
        (c.get("payload") or {}).get("newMode", (c.get("payload") or {}).get("NewMode"))
        for c in leftover_cmds
        if c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
    ]
    assert leftover_modes == [2, 1], leftover_modes
    assert 0 not in leftover_modes, leftover_modes

    calls.clear()
    # Leftover Cleaning+Charging that never leaves the charger is not started.
    stuck_charge_attrs = dict(vacuum_node()["attributes"])
    stuck_charge_attrs["1/97/4"] = 0x41
    stuck_charge_attrs["1/84/1"] = 1

    def leftover_stuck_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": stuck_charge_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": stuck_charge_attrs}
        if command == "device_command":
            apply_change_to_mode(agent, stuck_charge_attrs, args or {}, keep_charging=True)
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = leftover_stuck_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    leftover_stuck = agent.dispatch({"op": "command", "id": "40:1", "action": "start", "kind": "vacuum", "areas": "all"})
    assert leftover_stuck.get("ok") is False, leftover_stuck
    assert leftover_stuck.get("on") is False, leftover_stuck
    assert leftover_stuck.get("vacuum_status") in ("Charging", "Docked", "Mapping"), leftover_stuck
    stuck_modes = [
        (c.get("payload") or {}).get("newMode", (c.get("payload") or {}).get("NewMode"))
        for command, c in calls
        if command == "device_command" and (c or {}).get("cluster_id") == agent.RVC_RUN
    ]
    assert 0 not in stuck_modes or leftover_stuck.get("vacuum_status") == "Mapping", stuck_modes

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
    assert run_payloads == [1, 2], run_payloads
    assert 0 not in run_payloads, run_payloads
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
    assert 1 in refused_modes, refused_modes
    assert 2 in refused_modes, refused_modes
    assert 0 in refused_modes, refused_modes
    assert refused.get("ok") is not True, refused
    assert refused.get("vacuum_status") != "Mapping", refused

    calls.clear()
    # Charging + Bathroom must skip SelectAreas (K11+ GenericFailure) and still
    # send timed ChangeToMode Cleaning. Idle Start still selects rooms above.
    bath_ok_attrs = dict(vacuum_node()["attributes"])
    bath_ok_attrs["1/97/4"] = 0x41
    bath_ok_attrs["1/84/1"] = 0

    def bath_charge_ok_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": bath_ok_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": bath_ok_attrs}
        if command == "device_command":
            args = args or {}
            if args.get("cluster_id") == agent.RVC_RUN and args.get("command_name") == "ChangeToMode":
                payload = args.get("payload") or {}
                mode = payload.get("newMode", payload.get("NewMode"))
                if mode == 1 and bath_ok_attrs.get("1/97/4") == 0x41:
                    return {"ok": True, "result": {"0": 2, "1": "GenericFailure"}}
            apply_change_to_mode(agent, bath_ok_attrs, args or {})
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = bath_charge_ok_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    bath_ok = agent.dispatch({
        "op": "command",
        "id": "40:1",
        "action": "start",
        "kind": "vacuum",
        "areas": [3],
    })
    assert bath_ok.get("ok") is True, bath_ok
    assert bath_ok.get("vacuum_status") == "Cleaning", bath_ok
    bath_ok_cmds = [args for command, args in calls if command == "device_command"]
    assert not any(c.get("cluster_id") == agent.SERVICE_AREA for c in bath_ok_cmds), bath_ok_cmds
    assert not any(c.get("cluster_id") == agent.RVC_CLEAN for c in bath_ok_cmds), bath_ok_cmds
    bath_modes = [
        (c.get("payload") or {}).get("newMode", (c.get("payload") or {}).get("NewMode"))
        for c in bath_ok_cmds
        if c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
    ]
    assert bath_modes == [1, 2, 1], bath_modes
    assert 0 not in bath_modes, bath_modes

    calls.clear()
    # Charging: Cleaning GenericFailure, Mapping leaves the dock, then Cleaning.
    undock_attrs = dict(vacuum_node()["attributes"])
    undock_attrs["0/40/3"] = "K11+"
    undock_attrs["1/97/4"] = 0x41
    undock_attrs["1/84/1"] = 0

    def k11_map_undock_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        args = args or {}
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": undock_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": undock_attrs}
        if command == "device_command":
            if args.get("cluster_id") == agent.RVC_RUN and args.get("command_name") == "ChangeToMode":
                payload = args.get("payload") or {}
                mode = payload.get("newMode", payload.get("NewMode"))
                if mode == 1 and undock_attrs.get("1/97/4") == 0x41:
                    return {"ok": True, "result": {"0": 2, "1": "GenericFailure"}}
                apply_change_to_mode(agent, undock_attrs, args)
                return {"ok": True, "result": {"status": 0}}
            apply_change_to_mode(agent, undock_attrs, args)
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = k11_map_undock_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    undocked = agent.dispatch({
        "op": "command",
        "id": "40:1",
        "action": "start",
        "kind": "vacuum",
        "areas": "all",
    })
    assert undocked.get("ok") is True, undocked
    assert undocked.get("vacuum_status") == "Cleaning", undocked
    undock_cmds = [args for command, args in calls if command == "device_command"]
    assert not any(c.get("cluster_id") == agent.SERVICE_AREA for c in undock_cmds), undock_cmds
    undock_modes = [
        (c.get("payload") or {}).get("newMode", (c.get("payload") or {}).get("NewMode"))
        for c in undock_cmds
        if c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
    ]
    assert undock_modes[:3] == [1, 2, 1], undock_modes
    assert not any(c.get("command_name") == "Resume" for c in undock_cmds), undock_cmds
    assert not any(c.get("command_name") == "Stop" for c in undock_cmds), undock_cmds

    calls.clear()
    # Mapping undocked but Cleaning still fails: Idle aborts remap.
    abort_attrs = dict(vacuum_node()["attributes"])
    abort_attrs["1/97/4"] = 0x41
    abort_attrs["1/84/1"] = 0

    def k11_map_abort_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        args = args or {}
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": abort_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": abort_attrs}
        if command == "device_command":
            if args.get("cluster_id") == agent.RVC_RUN and args.get("command_name") == "ChangeToMode":
                payload = args.get("payload") or {}
                mode = payload.get("newMode", payload.get("NewMode"))
                if mode == 1:
                    return {"ok": True, "result": {"0": 2, "1": "GenericFailure"}}
                apply_change_to_mode(agent, abort_attrs, args)
                return {"ok": True, "result": {"status": 0}}
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = k11_map_abort_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    aborted = agent.dispatch({
        "op": "command",
        "id": "40:1",
        "action": "start",
        "kind": "vacuum",
        "areas": "all",
    })
    assert aborted.get("ok") is False, aborted
    assert aborted.get("vacuum_status") != "Mapping", aborted
    abort_cmds = [args for command, args in calls if command == "device_command"]
    abort_modes = [
        (c.get("payload") or {}).get("newMode", (c.get("payload") or {}).get("NewMode"))
        for c in abort_cmds
        if c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
    ]
    assert abort_modes == [1, 2, 1, 0], abort_modes

    calls.clear()
    # Alejandro (K11+): Charging. Cleaning and Mapping both GenericFailure.
    # Resume/Stop are not used.
    k11_attrs = dict(vacuum_node()["attributes"])
    k11_attrs["0/40/3"] = "K11+"
    k11_attrs["1/97/4"] = 0x41
    k11_attrs["1/84/1"] = 0

    def k11_charge_ignore_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        args = args or {}
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": k11_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": k11_attrs}
        if command == "device_command":
            apply_select_areas(agent, k11_attrs, args)
            if args.get("command_name") == "Resume":
                k11_attrs["1/97/4"] = 3
                k11_attrs["1/97/5"] = {"errorStateID": 3}
                return {"ok": True, "result": {"status": 0}}
            if args.get("cluster_id") == agent.RVC_RUN and args.get("command_name") == "ChangeToMode":
                return {"ok": True, "result": {"0": 2, "1": "GenericFailure"}}
            if args.get("command_name") == "ChangeToMode":
                apply_change_to_mode(agent, k11_attrs, args, keep_charging=True)
            return {"ok": True, "result": {"status": 0}}
        if command == "write_attribute":
            k11_attrs["1/97/4"] = 3
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = k11_charge_ignore_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    bathroom = agent.dispatch({
        "op": "command",
        "id": "40:1",
        "action": "start",
        "kind": "vacuum",
        "areas": [3],
    })
    assert bathroom.get("ok") is False, bathroom
    assert bathroom.get("vacuum_status") != "Cleaning", bathroom
    assert bathroom.get("vacuum_status") != "Error", bathroom
    err = str(bathroom.get("error") or "").lower()
    assert "generic failure" in err or "charging" in err, bathroom
    assert bathroom.get("vacuum_status") in ("Charging", "Idle", "Docked"), bathroom
    k11_cmds = [args for command, args in calls if command == "device_command"]
    area_payloads = [
        (c.get("payload") or {}).get("newAreas", (c.get("payload") or {}).get("NewAreas"))
        for c in k11_cmds
        if c.get("cluster_id") == agent.SERVICE_AREA
    ]
    assert area_payloads == [], area_payloads
    run_modes_sent = [
        (c.get("payload") or {}).get("newMode", (c.get("payload") or {}).get("NewMode"))
        for c in k11_cmds
        if c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
    ]
    assert 1 in run_modes_sent, run_modes_sent
    assert 2 in run_modes_sent, run_modes_sent
    assert all(
        c.get("timed_request_timeout_ms") == 3000
        for c in k11_cmds
        if c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
        and (c.get("payload") or {}).get("newMode") == 1
    ), k11_cmds
    assert all(
        "newMode" in (c.get("payload") or {})
        for c in k11_cmds
        if c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
    ), k11_cmds
    assert not any(c.get("command_name") == "Resume" for c in k11_cmds), k11_cmds
    assert not any(c.get("command_name") == "Stop" for c in k11_cmds), k11_cmds
    assert not any(command == "write_attribute" for command, _args in calls), calls
    assert not any(c.get("cluster_id") == agent.ON_OFF for c in k11_cmds), k11_cmds
    assert not any(c.get("command_name") == "Start" for c in k11_cmds), k11_cmds

    calls.clear()
    err_attrs = dict(vacuum_node()["attributes"])
    err_attrs["1/97/4"] = 3
    err_attrs["1/97/5"] = {"errorStateID": 3}
    err_attrs["1/84/1"] = 0

    def k11_error_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        args = args or {}
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": err_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": err_attrs}
        if command == "device_command":
            if args.get("command_name") in ("Stop", "Resume"):
                err_attrs["1/97/4"] = 0x40
                return {"ok": True, "result": {"status": 0}}
            apply_change_to_mode(agent, err_attrs, args)
            apply_select_areas(agent, err_attrs, args)
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = k11_error_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    from_error = agent.dispatch({
        "op": "command",
        "id": "40:1",
        "action": "start",
        "kind": "vacuum",
        "areas": [3],
    })
    err_cmds = [args for command, args in calls if command == "device_command"]
    assert not any(c.get("command_name") == "Stop" for c in err_cmds), err_cmds
    assert not any(c.get("command_name") == "Resume" for c in err_cmds), err_cmds
    assert from_error.get("vacuum_status") != "Error", from_error
    assert from_error.get("ok") is True, from_error

    calls.clear()
    ret_attrs = dict(vacuum_node()["attributes"])
    ret_attrs["1/97/4"] = 0x40
    ret_attrs["1/84/1"] = 0

    def k11_returning_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        args = args or {}
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": ret_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": ret_attrs}
        if command == "device_command":
            apply_select_areas(agent, ret_attrs, args)
            if args.get("command_name") in ("Stop", "GoHome"):
                ret_attrs["1/97/4"] = 0x40
                return {"ok": True, "result": {"status": 0}}
            if args.get("cluster_id") == agent.RVC_RUN and args.get("command_name") == "ChangeToMode":
                payload = args.get("payload") or {}
                if "newMode" not in payload:
                    ret_attrs["1/84/1"] = 0
                    return {"ok": True, "result": {"status": 0}}
                return {"ok": True, "result": {"status": 0}}
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = k11_returning_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    from_return = agent.dispatch({
        "op": "command",
        "id": "40:1",
        "action": "start",
        "kind": "vacuum",
        "areas": [3],
    })
    assert from_return.get("ok") is False, from_return
    assert from_return.get("vacuum_status") == "Returning", from_return
    ret_cmds = [args for command, args in calls if command == "device_command"]
    assert not any(c.get("cluster_id") == agent.SERVICE_AREA for c in ret_cmds), ret_cmds
    assert not any(c.get("command_name") == "Stop" for c in ret_cmds), ret_cmds
    assert not any(c.get("command_name") == "GoHome" for c in ret_cmds), ret_cmds
    assert not any(c.get("command_name") == "Resume" for c in ret_cmds), ret_cmds
    ret_run = [
        c.get("payload") or {}
        for c in ret_cmds
        if c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
    ]
    assert ret_run and all("newMode" in payload and payload.get("newMode") == 1 for payload in ret_run), ret_run
    assert all(payload.get("newMode") != 2 for payload in ret_run), ret_run

    print("test_matter_vacuum_command.py ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
