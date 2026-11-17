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
    assert agent.AGENT_VERSION == 75, agent.AGENT_VERSION
    assert agent.normalize_pairing_code("2462-204-0808") == "24622040808"
    assert agent.normalize_pairing_code(" 2462 204 0808 ") == "24622040808"
    assert agent.normalize_pairing_code("Setup code 2462-204-0808") == "24622040808"
    assert agent.normalize_pairing_code("MT:ABCDEF").startswith("MT:")
    assert agent.verhoeff_ok("31436531015") is True
    assert agent.pairing_code_problem("31436531015") is None
    assert "checksum" in (agent.pairing_code_problem("24622040808") or "").lower()
    assert "11-digit" in (agent.pairing_code_problem("246220408083") or "")
    assert "checksum" in (agent.pairing_code_problem("31436531014") or "").lower()
    assert "error 28" in agent.friendly_commission_error("CHIP Error 0x0000001C: Uninitialized").lower()
    assert "in use" in agent.friendly_commission_error("CHIP Error 0x00000028: In use").lower()
    assert "never found" in agent.friendly_commission_error(
        "Commission with code failed for node 29. (error_code 1)"
    ).lower()
    assert agent.failed_commission_node_id("Commission with code failed for node 29.") == 29
    assert "Turn On Pairing Mode" in agent.friendly_commission_error("timeout while discovering")
    assert "error_code 1" in agent.format_matter_rpc_error(
        {"details": "Commission with code failed for node 29.", "error_code": 1}
    )
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
        if command == "start_listening":
            return {"ok": True, "result": []}
        return {"ok": True, "result": None}

    agent.matter_rpc = fake_rpc  # type: ignore[method-assign]
    agent.prepare_for_commission = lambda: None  # type: ignore[method-assign]
    agent.cleanup_failed_commission_node = lambda _err: None  # type: ignore[method-assign]
    paired = agent.dispatch({"op": "commission", "code": "3143-653-1015"})
    assert paired.get("ok") is True, paired
    assert paired.get("node_id") == 40, paired
    ids = {str(row.get("id") or "") for row in paired.get("devices") or []}
    assert "1:2" in ids, paired
    assert "40:2" in ids, paired
    live_ids = {str(row.get("id") or "") for row in agent.current_live_devices()}
    assert "40:2" in live_ids, live_ids
    assert any(command == "commission_with_code" for command, _ in calls), calls
    assert not any(command == "interview_node" for command, _ in calls), calls
    bad = agent.dispatch({"op": "commission", "code": "12345"})
    assert bad.get("ok") is False and "digit" in str(bad.get("error") or "").lower(), bad

    calls.clear()
    agent._live_devices = [hue]
    cleaned: list[str] = []

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
    agent.prepare_for_commission = lambda: None  # type: ignore[method-assign]
    agent.cleanup_failed_commission_node = cleaned.append  # type: ignore[method-assign]
    by_id = agent.dispatch({"op": "commission", "code": "31436531015"})
    assert by_id.get("ok") is True, by_id
    assert any(command == "get_nodes" for command, _ in calls), calls
    got = {str(row.get("id") or "") for row in by_id.get("devices") or []}
    assert "41:2" in got, by_id

    print("ok: commission refreshes live devices")
    return 0


if __name__ == "__main__":
    sys.exit(main())
