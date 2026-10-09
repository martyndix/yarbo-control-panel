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

    def fake_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "write_attribute":
            return {"ok": True}
        if command == "device_command":
            if (args or {}).get("cluster_id") == agent.ON_OFF:
                return {"ok": True}
            return {"ok": False, "error": "InteractionModelError: UnsupportedCluster (0xc3)"}
        return {"ok": True, "result": None}

    agent.matter_rpc = fake_rpc  # type: ignore[method-assign]
    agent._live_devices = [{"id": "25:1", "kind": "heater", "on": False, "heating_setpoint": 21.0}]

    ping = agent.dispatch({"op": "ping"})
    assert ping.get("ok") is True, ping
    assert ping.get("version") == agent.AGENT_VERSION, ping
    assert ping.get("version") == 21, ping
    assert "thermostat" in (ping.get("features") or []), ping

    assert agent.write_status_code(None) == 0
    assert agent.write_status_code([{"Status": 0}]) == 0
    assert agent.write_status_code([{"status": 0x87}]) == 0x87
    assert agent.write_status_code([{"Path": "1/513/28", "Status": {"name": "ConstraintError"}}]) == 0x87

    calls.clear()
    on = agent.dispatch({"op": "command", "id": "25:1", "action": "on", "celsius": 21})
    assert on.get("ok") is True, on
    assert on.get("on") is True, on
    assert on.get("heating_setpoint") == 21.0, on
    writes = [args for command, args in calls if command == "write_attribute"]
    assert len(writes) >= 2, calls
    set_path = str(writes[0].get("attribute_path") or "")
    mode_path = str(writes[1].get("attribute_path") or "")
    assert set_path.endswith(f"/{agent.THERMOSTAT}/{agent.ATTR_OCCUPIED_HEATING_SETPOINT}"), writes
    assert writes[0].get("value") == 2100, writes
    assert mode_path.endswith(f"/{agent.THERMOSTAT}/{agent.ATTR_SYSTEM_MODE}"), writes
    assert writes[1].get("value") == agent.SYSTEM_MODE_HEAT, writes
    assert not any(command == "device_command" for command, _ in calls), calls

    calls.clear()
    off = agent.dispatch({"op": "command", "id": "25:1", "action": "off"})
    assert off.get("ok") is True, off
    off_writes = [args for command, args in calls if command == "write_attribute"]
    assert off_writes and off_writes[0].get("value") == agent.SYSTEM_MODE_OFF, off_writes
    assert not any(
        str(args.get("attribute_path") or "").endswith(f"/{agent.THERMOSTAT}/{agent.ATTR_OCCUPIED_HEATING_SETPOINT}")
        for command, args in calls
        if command == "write_attribute"
    ), calls

    calls.clear()
    setp = agent.dispatch({"op": "command", "id": "25:1", "action": "setpoint", "celsius": 22})
    assert setp.get("ok") is True, setp
    assert setp.get("heating_setpoint") == 22.0, setp
    set_paths = [str(args.get("attribute_path") or "") for command, args in calls if command == "write_attribute"]
    assert any(path.endswith(f"/{agent.THERMOSTAT}/{agent.ATTR_OCCUPIED_HEATING_SETPOINT}") for path in set_paths), set_paths
    assert any(
        str(args.get("attribute_path") or "").endswith(f"/{agent.THERMOSTAT}/{agent.ATTR_SYSTEM_MODE}")
        for command, args in calls
        if command == "write_attribute"
    ), calls

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

    def heat_rejected(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "write_attribute":
            path = str((args or {}).get("attribute_path") or "")
            value = (args or {}).get("value")
            if path.endswith(f"/{agent.THERMOSTAT}/{agent.ATTR_SYSTEM_MODE}") and value == agent.SYSTEM_MODE_HEAT:
                return {"ok": False, "error": "InteractionModelError: ConstraintError (0x87)"}
            if path.endswith(f"/{agent.THERMOSTAT}/{agent.ATTR_SYSTEM_MODE}") and value == agent.SYSTEM_MODE_AUTO:
                return {"ok": True, "result": [{"status": 0}]}
            if path.endswith(f"/{agent.THERMOSTAT}/{agent.ATTR_OCCUPIED_HEATING_SETPOINT}"):
                return {"ok": True, "result": [{"Status": 0}]}
            return {"ok": True}
        if command == "device_command":
            return {"ok": False, "error": "InteractionModelError: UnsupportedCluster (0xc3)"}
        return {"ok": True, "result": None}

    agent.matter_rpc = heat_rejected  # type: ignore[method-assign]
    agent._live_devices = [{"id": "26:2", "kind": "heater", "on": False}]
    calls.clear()
    auto_on = agent.dispatch({"op": "command", "id": "26:2", "action": "on"})
    assert auto_on.get("ok") is True, auto_on
    assert auto_on.get("on") is True, auto_on
    mode_values = [
        args.get("value")
        for command, args in calls
        if command == "write_attribute"
        and str(args.get("attribute_path") or "").endswith(f"/{agent.THERMOSTAT}/{agent.ATTR_SYSTEM_MODE}")
    ]
    assert mode_values == [agent.SYSTEM_MODE_HEAT, agent.SYSTEM_MODE_AUTO], mode_values
    assert not any(command == "device_command" for command, _ in calls), calls

    agent._live_devices = []
    calls.clear()
    hinted = agent.dispatch({"op": "command", "id": "26:2", "action": "on", "kind": "heater"})
    assert hinted.get("ok") is True, hinted
    assert any(command == "write_attribute" for command, _ in calls), calls
    assert not any(command == "device_command" for command, _ in calls), calls

    agent._live_devices = [
        {
            "id": "27:1",
            "name": "Mill Wi-Fi Panel Heater Gen4",
            "kind": "light",
            "has_thermostat": True,
            "heating_setpoint": 21.0,
            "on": False,
        }
    ]
    calls.clear()
    inferred = agent.dispatch({"op": "command", "id": "27:1", "action": "off"})
    assert inferred.get("ok") is True, inferred
    off_mode = [
        args.get("value")
        for command, args in calls
        if command == "write_attribute"
        and str(args.get("attribute_path") or "").endswith(f"/{agent.THERMOSTAT}/{agent.ATTR_SYSTEM_MODE}")
    ]
    assert off_mode == [agent.SYSTEM_MODE_OFF], off_mode
    assert not any(command == "device_command" for command, _ in calls), calls

    def status_failed(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "write_attribute":
            return {"ok": True, "result": [{"path": "2/513/28", "status": 0x87}]}
        return {"ok": True, "result": None}

    agent.matter_rpc = status_failed  # type: ignore[method-assign]
    agent._live_devices = [{"id": "26:2", "kind": "heater", "on": False}]
    calls.clear()
    rejected = agent.set_heater_power(26, 2, True)
    assert rejected.get("ok") is False, rejected
    assert "0x87" in str(rejected.get("error") or ""), rejected

    def setpoint_ok(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        calls.append((command, args or {}))
        if command == "write_attribute":
            path = str((args or {}).get("attribute_path") or "")
            value = (args or {}).get("value")
            if path.endswith(f"/{agent.THERMOSTAT}/{agent.ATTR_SYSTEM_MODE}") and value == agent.SYSTEM_MODE_HEAT:
                return {"ok": False, "error": "ConstraintError (0x87)"}
            return {"ok": True, "result": [{"status": 0}]}
        return {"ok": False, "error": "no"}

    agent.matter_rpc = setpoint_ok  # type: ignore[method-assign]
    calls.clear()
    warmed = agent.dispatch({"op": "command", "id": "26:2", "action": "setpoint", "celsius": 19.5})
    assert warmed.get("ok") is True, warmed
    assert warmed.get("heating_setpoint") == 19.5, warmed
    assert warmed.get("system_mode") == agent.SYSTEM_MODE_AUTO, warmed

    import time as time_mod

    agent._live_devices = [
        {
            "id": "26:2",
            "kind": "heater",
            "on": True,
            "heating_setpoint": 19.5,
            "system_mode": agent.SYSTEM_MODE_AUTO,
            "_patched_at": time_mod.time(),
            "_sticky_keys": ["on", "heating_setpoint", "system_mode"],
        }
    ]
    agent.remember_live_devices(
        [{"id": "26:2", "kind": "heater", "on": False, "heating_setpoint": 21.0, "system_mode": 0}]
    )
    held = agent.current_live_devices()
    assert held and held[0].get("on") is True, held
    assert held[0].get("heating_setpoint") == 19.5, held
    assert held[0].get("system_mode") == agent.SYSTEM_MODE_AUTO, held

    print("ok: heater commands use Thermostat SystemMode")
    return 0


if __name__ == "__main__":
    sys.exit(main())
