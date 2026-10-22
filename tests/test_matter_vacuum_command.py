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


def apply_start(attrs: dict, args: dict, *, leave_charger: bool = True) -> bool:
    if (args or {}).get("command_name") != "Start":
        return False
    try:
        ep = int((args or {}).get("endpoint_id") or 1)
    except (TypeError, ValueError):
        ep = 1
    if leave_charger:
        attrs[f"{ep}/97/4"] = 1
    return True


def change_to_mode_values(cmds: list, agent) -> list:
    return [
        (c.get("payload") or {}).get("newMode", (c.get("payload") or {}).get("NewMode"))
        for c in cmds
        if c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
    ]


def main() -> int:
    agent = load_agent()
    agent.VACUUM_MODE_SETTLE_S = 0
    agent.VACUUM_START_SETTLE_S = 0
    assert agent.ATTR_OPERATIONAL_STATE == 4, agent.ATTR_OPERATIONAL_STATE
    assert agent.AGENT_VERSION == 57, agent.AGENT_VERSION
    rows = {d["id"]: d for d in agent.flatten_nodes({"nodes": [vacuum_node()]})}
    vac = rows.get("40:1") or {}
    if vac.get("kind") != "vacuum":
        raise SystemExit(f"kind {vac}")
    if vac.get("can_mop") is not True:
        raise SystemExit(f"can_mop {vac}")
    if vac.get("can_vacuum_mop") is not True:
        raise SystemExit(f"can_vacuum_mop {vac}")
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
    if charging.get("vacuum_status") != "Docked" or charging.get("on") is True:
        raise SystemExit(f"charging idle should be Docked, got {charging}")
    full_charge = vacuum_node()
    full_charge["attributes"]["1/97/4"] = 0x41
    full_charge["attributes"]["1/84/1"] = 0
    full_charge["attributes"]["1/47/14"] = 2
    full_charge["attributes"]["1/47/12"] = 200
    full_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [full_charge]})}.get("40:1") or {}
    if full_row.get("vacuum_status") != "Docked" or full_row.get("on") is True:
        raise SystemExit(f"charging+full should be Docked, got {full_row}")
    if full_row.get("vacuum_charge_complete") is not True:
        raise SystemExit(f"charge complete {full_row}")
    if full_row.get("vacuum_battery") != 100:
        raise SystemExit(f"battery {full_row.get('vacuum_battery')}")
    root_full = vacuum_node()
    root_full["attributes"]["1/97/4"] = 0x41
    root_full["attributes"]["0/47/14"] = 2
    root_full["attributes"]["0/47/12"] = 200
    root_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [root_full]})}.get("40:1") or {}
    if root_row.get("vacuum_status") != "Docked" or root_row.get("vacuum_charge_complete") is not True:
        raise SystemExit(f"root PowerSource full {root_row}")
    still_charging = vacuum_node()
    still_charging["attributes"]["1/97/4"] = 0x41
    still_charging["attributes"]["1/47/14"] = 1
    still_charging["attributes"]["1/47/12"] = 80
    still_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [still_charging]})}.get("40:1") or {}
    if still_row.get("vacuum_status") != "Docked" or still_row.get("on") is True:
        raise SystemExit(f"still charging should be Docked, got {still_row}")
    if still_row.get("vacuum_battery") != 40:
        raise SystemExit(f"charging battery {still_row.get('vacuum_battery')}")
    if vac.get("vacuum_battery") not in (None,):
        raise SystemExit(f"missing battery should be None, got {vac.get('vacuum_battery')}")
    wrapped_bat = vacuum_node()
    wrapped_bat["attributes"]["1/47/12"] = {"0": 160}
    wrapped_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [wrapped_bat]})}.get("40:1") or {}
    if wrapped_row.get("vacuum_battery") != 80:
        raise SystemExit(f"wrapped battery {wrapped_row.get('vacuum_battery')}")
    value_bat = vacuum_node()
    value_bat["attributes"]["0/47/14"] = 0
    value_bat["attributes"]["1/47/12"] = {"value": 140}
    value_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [value_bat]})}.get("40:1") or {}
    if value_row.get("vacuum_battery") != 70:
        raise SystemExit(f"value-wrapped battery {value_row.get('vacuum_battery')}")
    zero_bat = vacuum_node()
    zero_bat["attributes"]["1/47/12"] = 0
    zero_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [zero_bat]})}.get("40:1") or {}
    if zero_row.get("vacuum_battery") not in (None,):
        raise SystemExit(f"zero battery should be omitted, got {zero_row.get('vacuum_battery')}")
    trickle = vacuum_node()
    trickle["attributes"]["1/97/4"] = 0x41
    trickle["attributes"]["1/47/14"] = 1
    trickle["attributes"]["1/47/12"] = 200
    trickle_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [trickle]})}.get("40:1") or {}
    if trickle_row.get("vacuum_status") != "Docked" or trickle_row.get("vacuum_charge_complete") is not True:
        raise SystemExit(f"100% while Charging should be Docked, got {trickle_row}")
    charging_idle["attributes"]["1/84/1"] = 1
    leaving = {d["id"]: d for d in agent.flatten_nodes({"nodes": [charging_idle]})}.get("40:1") or {}
    if leaving.get("vacuum_status") != "Docked" or leaving.get("on") is True:
        raise SystemExit(f"charging+cleaning leftover should be Docked, got {leaving}")
    error_node = vacuum_node()
    error_node["attributes"]["1/97/4"] = 3
    error_node["attributes"]["1/97/5"] = {"errorStateID": 3}
    errored = {d["id"]: d for d in agent.flatten_nodes({"nodes": [error_node]})}.get("40:1") or {}
    if errored.get("vacuum_status") != "Error" or errored.get("on") is True:
        raise SystemExit(f"error {errored}")
    if "invalid" not in str(errored.get("vacuum_error") or "").lower():
        raise SystemExit(f"error text {errored.get('vacuum_error')}")
    noerror_node = vacuum_node()
    noerror_node["attributes"]["1/97/4"] = 3
    noerror_node["attributes"]["1/97/5"] = {"errorStateID": 0}
    noerror_node["attributes"]["1/84/1"] = 1
    noerr = {d["id"]: d for d in agent.flatten_nodes({"nodes": [noerror_node]})}.get("40:1") or {}
    if noerr.get("vacuum_status") == "Error":
        raise SystemExit(f"NoError should not show Error, got {noerr}")
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
    mop = agent.dispatch({
        "op": "command",
        "id": "40:1",
        "action": "mop",
        "kind": "vacuum",
    })
    assert mop.get("ok") is True, mop
    mop_cmds = [args for command, args in calls if command == "device_command"]
    assert all(c.get("cluster_id") == agent.RVC_CLEAN for c in mop_cmds), mop_cmds
    assert (mop_cmds[0].get("payload") or {}).get("newMode") == 1, mop_cmds

    calls.clear()
    rooms = agent.dispatch({
        "op": "command",
        "id": "40:1",
        "action": "select_areas",
        "kind": "vacuum",
        "areas": [2],
    })
    assert rooms.get("ok") is True, rooms
    room_cmds = [args for command, args in calls if command == "device_command"]
    assert all(c.get("cluster_id") == agent.SERVICE_AREA for c in room_cmds), room_cmds
    payload = (room_cmds[0].get("payload") or {})
    assert payload.get("newAreas") == [2] or payload.get("new_areas") == [2], room_cmds

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
    assert agent.SERVICE_AREA not in clusters, cmds
    assert agent.RVC_CLEAN not in clusters, cmds
    assert agent.RVC_RUN in clusters, cmds
    assert agent.ON_OFF not in clusters, cmds
    assert not any(c.get("command_name") == "Start" for c in cmds), cmds
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
    charge_modes = change_to_mode_values(charge_cmds, agent)
    assert not any(c.get("command_name") == "Start" for c in charge_cmds), charge_cmds
    assert charge_modes == [1], charge_modes
    assert 2 not in charge_modes, charge_modes
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
            apply_change_to_mode(agent, leftover_attrs, args)
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = leftover_charge_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    leftover = agent.dispatch({"op": "command", "id": "40:1", "action": "start", "kind": "vacuum", "areas": "all"})
    assert leftover.get("ok") is True, leftover
    assert leftover.get("vacuum_status") == "Cleaning", leftover
    leftover_cmds = [args for command, args in calls if command == "device_command"]
    leftover_modes = change_to_mode_values(leftover_cmds, agent)
    assert not any(c.get("command_name") == "Start" for c in leftover_cmds), leftover_cmds
    assert leftover_modes == [1], leftover_modes
    assert 2 not in leftover_modes, leftover_modes
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
    assert leftover_stuck.get("ok") is True, leftover_stuck
    assert leftover_stuck.get("on") is True, leftover_stuck
    assert leftover_stuck.get("vacuum_status") == "Cleaning", leftover_stuck
    assert leftover_stuck.get("vacuum_activity") == "cleaning", leftover_stuck
    stuck_modes = change_to_mode_values([c for command, c in calls if command == "device_command"], agent)
    assert 2 not in stuck_modes, stuck_modes
    assert 0 not in stuck_modes, stuck_modes

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
    run_payloads = change_to_mode_values(s10_cmds, agent)
    assert not any(c.get("command_name") == "Start" for c in s10_cmds), s10_cmds
    assert run_payloads == [2], run_payloads
    assert 1 not in run_payloads, run_payloads
    assert 0 not in run_payloads, run_payloads
    assert all(c.get("endpoint_id") == 1 for c in s10_cmds if c.get("cluster_id") == agent.RVC_RUN), s10_cmds
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
        if command == "device_command" and (args or {}).get("command_name") == "Start":
            return {"ok": True, "result": {"status": 0}}
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
    assert 0 not in refused_modes, refused_modes
    assert refused.get("ok") is not True, refused

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
    bath_modes = change_to_mode_values(bath_ok_cmds, agent)
    assert not any(c.get("command_name") == "Start" for c in bath_ok_cmds), bath_ok_cmds
    assert bath_modes == [1], bath_modes
    assert 2 not in bath_modes, bath_modes
    assert 0 not in bath_modes, bath_modes

    calls.clear()
    # Apple Home Start from the charger: ChangeToMode Cleaning only. Never Mapping.
    undock_attrs = dict(vacuum_node()["attributes"])
    undock_attrs["0/40/3"] = "K11+"
    undock_attrs["1/97/4"] = 0x41
    undock_attrs["1/84/1"] = 0

    def k11_start_undock_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        args = args or {}
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": undock_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": undock_attrs}
        if command == "device_command":
            apply_change_to_mode(agent, undock_attrs, args)
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = k11_start_undock_rpc  # type: ignore[method-assign]
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
    assert not any(c.get("command_name") == "Start" for c in undock_cmds), undock_cmds
    assert not any(c.get("cluster_id") == agent.SERVICE_AREA for c in undock_cmds), undock_cmds
    undock_modes = change_to_mode_values(undock_cmds, agent)
    assert undock_modes == [1], undock_modes
    assert 2 not in undock_modes, undock_modes
    assert 0 not in undock_modes, undock_modes
    assert not any(c.get("command_name") == "Resume" for c in undock_cmds), undock_cmds
    assert not any(c.get("command_name") == "Stop" for c in undock_cmds), undock_cmds

    calls.clear()
    # Start is a no-op and Cleaning is refused: fail without Mapping or Idle.
    abort_attrs = dict(vacuum_node()["attributes"])
    abort_attrs["1/97/4"] = 0x41
    abort_attrs["1/84/1"] = 0

    def k11_start_noop_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        args = args or {}
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": abort_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": abort_attrs}
        if command == "device_command":
            if args.get("command_name") == "Start":
                return {"ok": True, "result": {"status": 0}}
            if args.get("cluster_id") == agent.RVC_RUN and args.get("command_name") == "ChangeToMode":
                payload = args.get("payload") or {}
                mode = payload.get("newMode", payload.get("NewMode"))
                if mode == 1:
                    return {"ok": True, "result": {"0": 2, "1": "GenericFailure"}}
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = k11_start_noop_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    aborted = agent.dispatch({
        "op": "command",
        "id": "40:1",
        "action": "start",
        "kind": "vacuum",
        "areas": "all",
    })
    assert aborted.get("ok") is False, aborted
    abort_cmds = [args for command, args in calls if command == "device_command"]
    abort_modes = change_to_mode_values(abort_cmds, agent)
    assert abort_modes == [1], abort_modes
    assert 2 not in abort_modes, abort_modes
    assert 0 not in abort_modes, abort_modes
    assert not any(c.get("command_name") == "Start" for c in abort_cmds), abort_cmds
    assert aborted.get("vacuum_status") == "Docked", aborted
    assert "while charging" not in str(aborted.get("error") or "").lower(), aborted

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
    assert "generic failure" in err or "dock" in err, bathroom
    assert "while charging" not in err, bathroom
    assert bathroom.get("vacuum_status") == "Docked", bathroom
    k11_cmds = [args for command, args in calls if command == "device_command"]
    area_payloads = [
        (c.get("payload") or {}).get("newAreas", (c.get("payload") or {}).get("NewAreas"))
        for c in k11_cmds
        if c.get("cluster_id") == agent.SERVICE_AREA
    ]
    assert area_payloads == [], area_payloads
    k11_modes = [
        (c.get("payload") or {}).get("newMode", (c.get("payload") or {}).get("NewMode"))
        for c in k11_cmds
        if c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
    ]
    assert k11_modes == [1], k11_modes
    assert 2 not in k11_modes, k11_modes
    assert not any(c.get("command_name") == "Start" for c in k11_cmds), k11_cmds
    assert all(
        "timed_request_timeout_ms" not in c
        for c in k11_cmds
        if c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
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

    calls.clear()
    # Charging + PowerSource full: webpage was Charging while the app said
    # charging complete. Show Docked, send Cleaning only.
    full_start_attrs = dict(vacuum_node()["attributes"])
    full_start_attrs["1/97/4"] = 0x41
    full_start_attrs["1/84/1"] = 0
    full_start_attrs["1/47/14"] = 2
    full_start_attrs["1/47/12"] = 200

    def full_start_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        args = args or {}
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": full_start_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": full_start_attrs}
        if command == "device_command":
            if args.get("command_name") == "Start":
                return {"ok": True, "result": {"status": 0}}
            apply_change_to_mode(agent, full_start_attrs, args)
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = full_start_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    from_full = agent.dispatch({"op": "command", "id": "40:1", "action": "start", "kind": "vacuum", "areas": "all"})
    assert from_full.get("ok") is True, from_full
    assert from_full.get("vacuum_status") == "Cleaning", from_full
    full_cmds = [args for command, args in calls if command == "device_command"]
    full_modes = change_to_mode_values(full_cmds, agent)
    assert not any(c.get("command_name") == "Start" for c in full_cmds), full_cmds
    assert full_modes == [1], full_modes
    assert 2 not in full_modes, full_modes
    assert 0 not in full_modes, full_modes
    assert not any(c.get("cluster_id") == agent.SERVICE_AREA for c in full_cmds), full_cmds

    calls.clear()
    # Full battery that still refuses Cleaning: Docked, never "while charging".
    full_fail_attrs = dict(vacuum_node()["attributes"])
    full_fail_attrs["1/97/4"] = 0x41
    full_fail_attrs["1/84/1"] = 0
    full_fail_attrs["1/47/14"] = 2
    full_fail_attrs["1/47/12"] = 200

    def full_fail_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        args = args or {}
        if command == "get_node":
            return {"ok": True, "result": {"node_id": 40, "attributes": full_fail_attrs}}
        if command == "read_attribute":
            return {"ok": True, "result": full_fail_attrs}
        if command == "device_command":
            if args.get("command_name") == "Start":
                return {"ok": True, "result": {"status": 0}}
            if args.get("cluster_id") == agent.RVC_RUN and args.get("command_name") == "ChangeToMode":
                payload = args.get("payload") or {}
                mode = payload.get("newMode", payload.get("NewMode"))
                if mode == 1:
                    return {"ok": True, "result": {"0": 2, "1": "GenericFailure"}}
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = full_fail_rpc  # type: ignore[method-assign]
    agent._live_devices = [{k: v for k, v in vac.items() if k not in ("run_modes", "clean_modes", "areas")}]
    full_fail = agent.dispatch({"op": "command", "id": "40:1", "action": "start", "kind": "vacuum", "areas": "all"})
    assert full_fail.get("ok") is False, full_fail
    assert full_fail.get("vacuum_status") == "Docked", full_fail
    err = str(full_fail.get("error") or "").lower()
    assert "while charging" not in err, full_fail
    full_fail_cmds = [args for command, args in calls if command == "device_command"]
    full_fail_modes = change_to_mode_values(full_fail_cmds, agent)
    assert not any(c.get("command_name") == "Start" for c in full_fail_cmds), full_fail_cmds
    assert full_fail_modes == [1], full_fail_modes
    assert 2 not in full_fail_modes, full_fail_modes
    assert 0 not in full_fail_modes, full_fail_modes

    agent._live_devices = [{
        "id": "40:1",
        "node_id": 40,
        "kind": "vacuum",
        "vacuum_op": 0x41,
        "vacuum_status": "Charging",
        "vacuum_run_mode": 0,
        "run_modes": [
            {"label": "Idle", "mode": 0, "tags": [0x4000]},
            {"label": "Cleaning", "mode": 1, "tags": [0x4001]},
        ],
    }]
    agent.apply_attribute_event([40, "0/47/14", 2])
    live_full = agent.live_row("40:1") or {}
    assert live_full.get("vacuum_charge_complete") is True, live_full
    assert live_full.get("vacuum_status") == "Docked", live_full
    agent.apply_attribute_event([40, "1/97/4", 0x41])
    live_op = agent.live_row("40:1") or {}
    assert live_op.get("vacuum_status") == "Docked", live_op
    agent.apply_attribute_event([40, "1/47/14", 1])
    agent.apply_attribute_event([40, "1/47/12", 80])
    live_charge = agent.live_row("40:1") or {}
    assert live_charge.get("vacuum_charge_complete") is not True, live_charge
    assert live_charge.get("vacuum_status") == "Docked", live_charge
    assert live_charge.get("vacuum_battery") == 40, live_charge

    agent._live_devices = [{
        "id": "40:1",
        "node_id": 40,
        "kind": "vacuum",
        "vacuum_op": 1,
        "vacuum_status": "Cleaning",
        "vacuum_activity": "cleaning",
        "vacuum_run_mode": 1,
        "run_modes": [
            {"label": "Idle", "mode": 0, "tags": [0x4000]},
            {"label": "Cleaning", "mode": 1, "tags": [0x4001]},
        ],
    }]
    agent.apply_attribute_event([40, "1/97/4", 0x41])
    live_lag = agent.live_row("40:1") or {}
    assert live_lag.get("vacuum_status") == "Cleaning", live_lag
    assert live_lag.get("on") is True, live_lag
    agent.apply_attribute_event([40, "1/97/4", 0x40])
    live_seek = agent.live_row("40:1") or {}
    assert live_seek.get("vacuum_status") == "Returning", live_seek
    assert live_seek.get("vacuum_activity") == "returning", live_seek
    agent.apply_attribute_event([40, "1/97/4", 0x41])
    live_home = agent.live_row("40:1") or {}
    assert live_home.get("vacuum_status") == "Docked", live_home
    assert live_home.get("on") is False, live_home
    agent.apply_attribute_event([40, "1/97/4", 0x40])
    agent.apply_attribute_event([40, "0/47/14", 1])
    live_plug = agent.live_row("40:1") or {}
    assert live_plug.get("vacuum_status") == "Docked", live_plug

    poll_calls: list[tuple[str, dict]] = []
    agent._live_devices = [{
        "id": "40:1",
        "node_id": 40,
        "endpoint": 1,
        "kind": "vacuum",
        "areas": [{"id": 1, "name": "Kitchen"}],
        "run_modes": [{"label": "Cleaning", "mode": 1, "tags": [0x4001]}],
    }]

    def poll_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        poll_calls.append((command, args or {}))
        return {"ok": True, "result": {"1/97/4": 0x42, "1/47/12": 160}}

    agent.matter_rpc = poll_rpc  # type: ignore[method-assign]
    agent.poll_vacuum_attributes()
    assert any(command == "read_attribute" for command, _ in poll_calls), poll_calls
    polled = agent.live_row("40:1") or {}
    assert polled.get("vacuum_status") == "Docked", polled
    assert polled.get("vacuum_battery") == 80, polled

    agent._live_devices = [{
        "id": "40:1",
        "node_id": 40,
        "kind": "vacuum",
        "vacuum_op": 1,
        "vacuum_status": "Cleaning",
        "vacuum_activity": "cleaning",
        "vacuum_run_mode": 1,
        "run_modes": [
            {"label": "Idle", "mode": 0, "tags": [0x4000]},
            {"label": "Cleaning", "mode": 1, "tags": [0x4001]},
        ],
    }]
    agent.apply_attribute_event([40, "1/97/4", 3])
    live_op_first = agent.live_row("40:1") or {}
    assert live_op_first.get("vacuum_status") == "Cleaning", live_op_first
    agent.apply_attribute_event([40, "1/97/5", {"errorStateID": 0}])
    live_noerr = agent.live_row("40:1") or {}
    assert live_noerr.get("vacuum_status") == "Cleaning", live_noerr
    assert live_noerr.get("on") is True, live_noerr
    agent.apply_attribute_event([40, "1/97/5", {"errorStateID": 0x41}])
    live_stuck = agent.live_row("40:1") or {}
    assert live_stuck.get("vacuum_status") == "Error", live_stuck
    assert "stuck" in str(live_stuck.get("vacuum_error") or "").lower(), live_stuck

    combo = vacuum_node()
    combo["attributes"]["1/85/0"].append(
        {"label": "Vac + mop", "mode": 2, "modeTags": [{"value": 0x4000}, {"value": 0x4001}]}
    )
    combo["attributes"]["1/85/1"] = 2
    combo["attributes"]["1/84/1"] = 1
    combo["attributes"]["1/97/4"] = 1
    combo_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [combo]})}.get("40:1") or {}
    if combo_row.get("vacuum_clean") != "vacuum_mop" or combo_row.get("can_vacuum_mop") is not True:
        raise SystemExit(f"dual-tag vac+mop {combo_row}")

    refill = vacuum_node()
    refill["attributes"]["1/84/1"] = 1
    refill["attributes"]["1/97/4"] = 3
    refill["attributes"]["1/97/5"] = {"errorStateID": 0x44}
    refill_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [refill]})}.get("40:1") or {}
    if refill_row.get("vacuum_status") == "Error":
        raise SystemExit(f"water tank empty must not be Error {refill_row}")
    if refill_row.get("vacuum_status") != "Refilling":
        raise SystemExit(f"water tank empty should be Refilling {refill_row}")
    if refill_row.get("vacuum_clean") != "vacuum_mop":
        raise SystemExit(f"refill should count as vac+mop {refill_row}")
    if refill_row.get("on") is not True:
        raise SystemExit(f"refill is still a job {refill_row}")

    filling = vacuum_node()
    filling["attributes"]["1/84/1"] = 1
    filling["attributes"]["1/97/4"] = 0x45
    filling_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [filling]})}.get("40:1") or {}
    if filling_row.get("vacuum_status") != "Refilling":
        raise SystemExit(f"FillingWaterTank {filling_row}")

    emptying = vacuum_node()
    emptying["attributes"]["1/97/4"] = 0x43
    emptying_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [emptying]})}.get("40:1") or {}
    if emptying_row.get("vacuum_status") != "Emptying":
        raise SystemExit(f"EmptyingDustBin {emptying_row}")

    washing = vacuum_node()
    washing["attributes"]["1/97/4"] = 0x44
    washing_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [washing]})}.get("40:1") or {}
    if washing_row.get("vacuum_status") != "Washing":
        raise SystemExit(f"CleaningMop {washing_row}")

    agent._live_devices = [{
        "id": "40:1",
        "node_id": 40,
        "kind": "vacuum",
        "vacuum_op": 1,
        "vacuum_status": "Cleaning",
        "vacuum_activity": "cleaning",
        "vacuum_run_mode": 1,
        "vacuum_clean": "vacuum",
        "vacuum_clean_mode": 0,
        "can_mop": True,
        "run_modes": [
            {"label": "Idle", "mode": 0, "tags": [0x4000]},
            {"label": "Cleaning", "mode": 1, "tags": [0x4001]},
        ],
        "clean_modes": [
            {"label": "Vacuum", "mode": 0, "tags": [0x4000]},
            {"label": "Mop", "mode": 1, "tags": [0x4001]},
            {"label": "Vac + mop", "mode": 2, "tags": [0x4000, 0x4001]},
        ],
    }]
    agent.apply_attribute_event([40, "1/97/5", {"errorStateID": 0x44}])
    live_refill = agent.live_row("40:1") or {}
    assert live_refill.get("vacuum_status") != "Error", live_refill
    assert live_refill.get("vacuum_status") == "Refilling", live_refill
    assert live_refill.get("vacuum_clean") == "vacuum_mop", live_refill
    agent.apply_attribute_event([40, "1/85/1", 2])
    live_combo = agent.live_row("40:1") or {}
    assert live_combo.get("vacuum_clean") == "vacuum_mop", live_combo
    agent.apply_attribute_event([40, "1/85/1", 1])
    live_mop = agent.live_row("40:1") or {}
    assert live_mop.get("vacuum_clean") == "mop", live_mop

    s10_combo = s10_node()
    s10_combo["attributes"]["1/85/0"].append(
        {"label": "Vac&Mop", "mode": 2, "modeTags": [{"value": 0x4003}]}
    )
    s10_combo["attributes"]["1/85/1"] = 2
    s10_combo_row = {d["id"]: d for d in agent.flatten_nodes({"nodes": [s10_combo]})}.get("50:1") or {}
    if s10_combo_row.get("vacuum_clean") != "vacuum_mop":
        raise SystemExit(f"s10 Vac&Mop {s10_combo_row}")

    print("test_matter_vacuum_command.py ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
