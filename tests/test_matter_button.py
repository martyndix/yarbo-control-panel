#!/usr/bin/env python3
"""Generic Switch (Bilresa) flattens to a button. Hue tap dial stays a switch."""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "matter_agent.py"
DESCRIPTOR = 29
ON_OFF = 6
SWITCH = 59
DEVTYPE_GENERIC_SWITCH = 0x000F
DEVTYPE_DIMMER_SWITCH = 0x0105


def load_agent():
    spec = importlib.util.spec_from_file_location("matter_agent_button", SCRIPT)
    if spec is None or spec.loader is None:
        raise SystemExit(f"Could not load {SCRIPT}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def node(attributes: dict) -> dict:
    return {
        "node_id": 9,
        "available": True,
        "attributes": {
            "0/40/1": "IKEA of Sweden",
            "0/40/3": "BILRESA",
            "0/40/5": "Button",
            **attributes,
        },
    }


def main() -> int:
    agent = load_agent()
    assert agent.button_gesture(1, {}) is None
    assert agent.button_gesture(2, {}) == "hold"
    assert agent.button_gesture(3, {}) == "press"
    assert agent.button_gesture(5, {"totalNumberOfPressesCounted": 2}) == "double"
    assert agent.button_gesture(6, {"1": 1}) == "press"

    generic = agent.flatten_nodes({"nodes": [node({
        "1/29/0": [{"0": DEVTYPE_GENERIC_SWITCH, "1": 1}],
        "1/59/0": 0,
        "1/57/5": "Bilresa",
    })]})
    if len(generic) != 1 or generic[0]["kind"] != "button":
        raise SystemExit(f"generic switch should be a button {generic}")
    if generic[0].get("has_on_off"):
        raise SystemExit("button must not look like a light")

    cluster_only = agent.flatten_nodes({"nodes": [node({
        "1/29/0": [{"0": 0x0013, "1": 1}],
        "1/59/0": 0,
    })]})
    if len(cluster_only) != 1 or cluster_only[0]["kind"] != "button":
        raise SystemExit(f"switch cluster without OnOff should be a button {cluster_only}")

    dial = agent.flatten_nodes({"nodes": [node({
        "1/29/0": [{"0": DEVTYPE_DIMMER_SWITCH, "1": 1}],
        "1/6/0": False,
        "1/57/5": "Tap dial",
    })]})
    if len(dial) != 1 or dial[0]["kind"] != "switch":
        raise SystemExit(f"Hue tap dial stays a switch {dial}")

    print("ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
