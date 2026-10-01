#!/usr/bin/env python3
"""Colour command routing for the Matter agent (no Hue hardware required)."""

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

    def fake_device_command(node_id, endpoint, cluster, name, payload):
        calls.append((node_id, endpoint, cluster, name, payload))
        return {"ok": True}

    agent.device_command = fake_device_command  # type: ignore[method-assign]
    agent.matter_rpc = lambda *args, **kwargs: {"ok": True, "result": None}  # type: ignore[method-assign]

    ping = agent.dispatch({"op": "ping"})
    assert ping.get("ok") is True, ping
    assert ping.get("version") == agent.AGENT_VERSION, ping
    assert "color" in (ping.get("features") or []), ping

    for action in ("color", "colour", "set_color"):
        calls.clear()
        result = agent.dispatch(
            {"op": "command", "id": "12:4", "action": action, "hex": "#ff0000"}
        )
        assert result.get("ok") is True, (action, result)
        names = [call[3] for call in calls]
        assert "On" in names, names
        assert "MoveToColor" in names or "MoveToHueAndSaturation" in names, names

    calls.clear()
    kelvin = agent.dispatch(
        {"op": "command", "id": "12:4", "action": "color_temp", "kelvin": 2700}
    )
    assert kelvin.get("ok") is True, kelvin
    assert "MoveToColorTemperature" in [call[3] for call in calls]

    unknown = agent.dispatch({"op": "command", "id": "12:4", "action": "sparkle"})
    assert unknown.get("ok") is False, unknown
    assert "Unknown Matter command" in str(unknown.get("error") or ""), unknown
    assert "sparkle" in str(unknown.get("error") or ""), unknown

    print("ok: colour commands are recognized")
    return 0


if __name__ == "__main__":
    sys.exit(main())
