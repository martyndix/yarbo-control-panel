#!/usr/bin/env python3
"""Pairing a Matter code refreshes the live device list."""

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


def mill_node() -> dict:
    return {
        "node_id": 40,
        "available": True,
        "attributes": {
            "0/40/3": "Mill Wi-Fi Panel Heater Gen4",
            "1/29/0": [{"deviceType": 0x0100, "revision": 1}],
            "1/6/0": False,
            "2/29/0": [{"deviceType": 0x0300, "revision": 1}],
            "2/513/0": 2100,
            "2/513/18": 2100,
            "2/513/28": 0,
        },
    }


def main() -> int:
    agent = load_agent()
    assert agent.AGENT_VERSION == 29, agent.AGENT_VERSION
    assert agent.node_id_from_any({"node_id": 40}) == 40
    assert agent.node_id_from_any(40) == 40
    assert agent.node_id_from_any({"result": {"nodeId": 7}}) == 7
    assert agent.node_id_from_any({"node_id": 0, "attributes": {}}) == 0

    hue = {"id": "1:2", "name": "Lamp", "kind": "light", "on": True}
    agent._live_devices = [hue]
    calls: list[tuple] = []

    def fake_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "commission_with_code":
            return {"ok": True, "result": mill_node()}
        if command == "get_nodes":
            return {"ok": True, "result": [mill_node()]}
        if command == "get_node":
            return {"ok": True, "result": mill_node()}
        if command == "interview_node":
            return {"ok": True}
        return {"ok": True, "result": None}

    agent.matter_rpc = fake_rpc  # type: ignore[method-assign]
    paired = agent.dispatch({"op": "commission", "code": "246220408083"})
    assert paired.get("ok") is True, paired
    assert paired.get("node_id") == 40, paired
    ids = {str(row.get("id") or "") for row in paired.get("devices") or []}
    assert "1:2" in ids, paired
    assert "40:2" in ids, paired
    live_ids = {str(row.get("id") or "") for row in agent.current_live_devices()}
    assert "40:2" in live_ids, live_ids
    assert any(command == "commission_with_code" for command, _ in calls), calls
    assert not any(command == "interview_node" for command, _ in calls), calls

    calls.clear()
    agent._live_devices = [hue]

    def id_only_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "commission_with_code":
            return {"ok": True, "result": 41}
        if command == "get_nodes":
            return {
                "ok": True,
                "result": [
                    {
                        "node_id": 1,
                        "available": True,
                        "attributes": {
                            "0/40/3": "Hue Bridge",
                            "2/29/0": [{"deviceType": 0x0100, "revision": 1}],
                            "2/6/0": True,
                        },
                    },
                    mill_node() | {"node_id": 41},
                ],
            }
        return {"ok": True, "result": None}

    agent.matter_rpc = id_only_rpc  # type: ignore[method-assign]
    by_id = agent.dispatch({"op": "commission", "code": "111111111111"})
    assert by_id.get("ok") is True, by_id
    assert any(command == "get_nodes" for command, _ in calls), calls
    got = {str(row.get("id") or "") for row in by_id.get("devices") or []}
    assert "41:2" in got, by_id

    print("ok: commission refreshes live devices")
    return 0


if __name__ == "__main__":
    sys.exit(main())
