#!/usr/bin/env python3
"""Heater On/Off uses Thermostat SystemMode, not OnOff (UnsupportedCluster 0xc3)."""

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


def main() -> int:
    agent = load_agent()
    calls: list[tuple] = []

    def fake_rpc(command, args=None, timeout=20.0, channel="", listen=True):
        calls.append((command, args or {}))
        if command == "write_attribute":
            return {"ok": True}
        if command == "device_command":
            if (args or {}).get("cluster_id") == agent.ON_OFF:
                return {"ok": True}
            return {"ok": False, "error": "InteractionModelError: UnsupportedCluster (0xc3)"}
        return {"ok": True, "result": None}

    agent.matter_rpc = fake_rpc  # type: ignore[method-assign]
    agent._live_devices = [{"id": "25:1", "kind": "heater", "on": False}]

    ping = agent.dispatch({"op": "ping"})
    assert ping.get("ok") is True, ping
    assert ping.get("version") == agent.AGENT_VERSION, ping
    assert "thermostat" in (ping.get("features") or []), ping

    calls.clear()
    on = agent.dispatch({"op": "command", "id": "25:1", "action": "on"})
    assert on.get("ok") is True, on
    assert on.get("on") is True, on
    writes = [args for command, args in calls if command == "write_attribute"]
    assert writes, calls
    path = str(writes[0].get("attribute_path") or "")
    assert path.endswith(f"/{agent.THERMOSTAT}/{agent.ATTR_SYSTEM_MODE}"), writes
    assert writes[0].get("value") == agent.SYSTEM_MODE_HEAT, writes
    assert not any(command == "device_command" for command, _ in calls), calls

    calls.clear()
    off = agent.dispatch({"op": "command", "id": "25:1", "action": "off"})
    assert off.get("ok") is True, off
    off_writes = [args for command, args in calls if command == "write_attribute"]
    assert off_writes and off_writes[0].get("value") == agent.SYSTEM_MODE_OFF, off_writes

    calls.clear()
    setp = agent.dispatch({"op": "command", "id": "25:1", "action": "setpoint", "celsius": 22})
    assert setp.get("ok") is True, setp
    assert setp.get("heating_setpoint") == 22.0, setp
    set_paths = [str(args.get("attribute_path") or "") for command, args in calls if command == "write_attribute"]
    assert any(path.endswith(f"/{agent.THERMOSTAT}/{agent.ATTR_OCCUPIED_HEATING_SETPOINT}") for path in set_paths), set_paths

    agent._live_devices = [{"id": "12:4", "kind": "light", "on": False}]
    calls.clear()
    light = agent.dispatch({"op": "command", "id": "12:4", "action": "on"})
    assert light.get("ok") is True, light
    assert any(command == "device_command" for command, _ in calls), calls
    assert not any(command == "write_attribute" for command, _ in calls), calls

    agent._live_devices = [{"id": "31:1", "kind": "light", "name": "Boiler", "on": False, "dimmable": True}]
    calls.clear()
    bright = agent.dispatch({"op": "command", "id": "31:1", "action": "brightness", "brightness": 100})
    assert bright.get("ok") is True, bright
    assert bright.get("on") is True, bright
    clusters = [args.get("cluster_id") for command, args in calls if command == "device_command"]
    assert agent.LEVEL_CONTROL in clusters, calls
    assert agent.ON_OFF in clusters, calls
    assert not any(command == "write_attribute" for command, _ in calls), calls

    print("ok: heater commands use Thermostat SystemMode")
    return 0


if __name__ == "__main__":
    sys.exit(main())
