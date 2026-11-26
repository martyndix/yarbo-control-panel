#!/usr/bin/env python3
"""A climate poll reads temperature as well as humidity, so battery sensors do not lag."""

from __future__ import annotations

import importlib.util
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


def run_poll(agent, reply_for_temp: dict, humidity_present: bool = True):
    calls: list[list[str]] = []

    def fake_read(node_id, paths, timeout=None):
        calls.append(list(paths))
        if len(calls) == 1:
            return reply_for_temp
        return {"ok": False, "error": "unsupported"}

    agent._poll_read = fake_read
    agent.poll_paused = lambda: False
    agent.climate_poll_targets = lambda: [(2042, [1, 2], "matter:2042")]
    agent._poll_node_cooling = lambda node_id, now: False
    agent._node_has_humidity = lambda node_id: humidity_present
    agent._climate_cursor = 0
    agent.poll_climate_attributes()
    return calls


def main() -> None:
    agent = load_agent()
    temp_cluster = agent.TEMP_MEASUREMENT
    temp_attr = agent.ATTR_MEASURED_TEMP

    calls = run_poll(agent, {"ok": True, "result": []})
    expected_first = [f"{ep}/{temp_cluster}/{temp_attr}" for ep in (1, 2)]
    assert calls and calls[0] == expected_first, f"first read must be temperature on each endpoint, got {calls}"
    assert len(calls) >= 2, "humidity read still runs after the temperature read"

    timed_out = run_poll(agent, {"ok": False, "error": "Timed out waiting for response"})
    assert len(timed_out) == 1, f"a timed-out temperature read ends the pass, got {timed_out}"

    print("ok: matter climate poll reads temperature")


if __name__ == "__main__":
    main()
