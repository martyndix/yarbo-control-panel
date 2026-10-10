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
            ],
            "1/84/1": 0,
            "1/85/0": [
                {"label": "Vacuum", "mode": 0, "modeTags": [{"value": 0x4000}]},
                {"label": "Mop", "mode": 1, "modeTags": [{"value": 0x4001}]},
            ],
            "1/85/1": 0,
            "1/97/0": 0,
            "1/336/0": [
                {"areaID": 1, "locationInfo": {"locationName": "Kitchen"}},
                {"0": 2, "2": {"0": "Living room"}},
            ],
            "1/336/2": [1],
        },
    }


def main() -> int:
    agent = load_agent()
    rows = {d["id"]: d for d in agent.flatten_nodes({"nodes": [vacuum_node()]})}
    vac = rows.get("40:1") or {}
    if vac.get("kind") != "vacuum":
        raise SystemExit(f"kind {vac}")
    if vac.get("can_mop") is not True:
        raise SystemExit(f"can_mop {vac}")
    names = [a["name"] for a in vac.get("areas") or []]
    if names != ["Kitchen", "Living room"]:
        raise SystemExit(f"areas {vac.get('areas')}")
    if vac.get("selected_areas") != [1]:
        raise SystemExit(f"selected {vac.get('selected_areas')}")
    if vac.get("vacuum_status") != "Idle":
        raise SystemExit(f"status {vac.get('vacuum_status')}")

    calls: list[tuple] = []

    def fake_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "device_command":
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
            "1/97/0": 0,
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
            return {"ok": True, "result": {"status": 0}}
        return {"ok": True, "result": None}

    agent.matter_rpc = live_rpc  # type: ignore[method-assign]
    start_all = agent.dispatch({"op": "command", "id": "40:1", "action": "start", "kind": "vacuum"})
    assert start_all.get("ok") is True, start_all
    live_cmds = [args for command, args in calls if command == "device_command"]
    assert not any(c.get("command_name") == "Resume" for c in live_cmds), live_cmds
    assert any(c.get("cluster_id") == agent.SERVICE_AREA for c in live_cmds), live_cmds
    assert any(
        c.get("cluster_id") == agent.RVC_RUN and c.get("command_name") == "ChangeToMode"
        for c in live_cmds
    ), live_cmds
    area_payload = next(c.get("payload") or {} for c in live_cmds if c.get("cluster_id") == agent.SERVICE_AREA)
    assert area_payload.get("newAreas") == [1, 2] or area_payload.get("NewAreas") == [1, 2], area_payload

    print("test_matter_vacuum_command.py ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
