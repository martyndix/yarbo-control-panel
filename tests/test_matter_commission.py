#!/usr/bin/env python3
"""Pairing a Matter code refreshes the live device list."""

from __future__ import annotations

import importlib.util
import sys
import time
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


def wait_job(agent, job_id: str, timeout: float = 5.0) -> dict:
    deadline = time.time() + timeout
    while time.time() < deadline:
        snap = agent.dispatch({"op": "commission_status", "job_id": job_id})
        status = str(snap.get("status") or "")
        if status in ("ok", "error"):
            return snap
        time.sleep(0.05)
    raise AssertionError(f"job {job_id} did not finish: {agent.commission_job_snapshot()}")


def main() -> int:
    agent = load_agent()
    assert agent.AGENT_VERSION == 78, agent.AGENT_VERSION
    assert "Thread/mDNS" in agent.friendly_commission_error("Pairing timed out while discovering")
    assert callable(agent.ensure_matter_primary_interface)
    assert callable(agent.discovery_preflight_error)
    assert callable(agent.container_command_tokens)
    assert callable(agent.start_commission_job)
    assert callable(agent.commission_job_snapshot)
    assert callable(agent.run_commission_with_code)
    # Healthy server + transient unavailable must not say "Set up Matter server".
    agent.matter_port_up = lambda: True  # type: ignore[method-assign]
    soft = agent.friendly_commission_error("Matter server unavailable")
    assert "Set up Matter server" not in soft, soft
    assert "fresh" in soft.lower() or "again" in soft.lower(), soft
    agent.matter_port_up = lambda: False  # type: ignore[method-assign]
    hard = agent.friendly_commission_error("Matter server unavailable")
    assert "Set up Matter server" in hard, hard
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

    # Async start + status (and reject concurrent start while running).
    slow_gate = {"hold": True}

    def slow_rpc(command, args=None, timeout=20.0, channel="", listen=True, **_kwargs):
        if command == "commission_with_code":
            while slow_gate["hold"]:
                time.sleep(0.02)
            return {"ok": True, "result": mill_node() | {"node_id": 42}}
        if command == "get_nodes":
            return {"ok": True, "result": [mill_node() | {"node_id": 42}]}
        return {"ok": True, "result": None}

    agent.matter_rpc = slow_rpc  # type: ignore[method-assign]
    agent.prepare_for_commission = lambda: None  # type: ignore[method-assign]
    agent.cleanup_failed_commission_node = lambda _err: None  # type: ignore[method-assign]
    with agent._commission_job_lock:
        agent._commission_job = None
    started = agent.dispatch({"op": "commission_start", "code": "31436531015"})
    assert started.get("ok") is True, started
    job_id = str(started.get("job_id") or "")
    assert job_id, started
    assert started.get("status") in ("pending", "running"), started
    busy = agent.dispatch({"op": "commission_start", "code": "31436531015"})
    assert busy.get("ok") is False, busy
    assert "progress" in str(busy.get("error") or "").lower(), busy
    mid = agent.dispatch({"op": "commission_status", "job_id": job_id})
    assert mid.get("status") in ("pending", "running"), mid
    slow_gate["hold"] = False
    done = wait_job(agent, job_id)
    assert done.get("ok") is True, done
    assert done.get("status") == "ok", done
    assert done.get("node_id") == 42, done

    # Wrong job id after completion of a different job.
    other = agent.dispatch({"op": "commission_status", "job_id": "not-this-job"})
    assert other.get("ok") is False, other

    print("ok: commission refreshes live devices")
    return 0


if __name__ == "__main__":
    sys.exit(main())
