#!/usr/bin/env python3
from __future__ import annotations
import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "matter_agent.py"

def load():
    spec = importlib.util.spec_from_file_location("matter_agent", SCRIPT)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

def main() -> int:
    agent = load()
    assert agent.AGENT_VERSION == 85, agent.AGENT_VERSION
    sample = """
Soliciting ff02::2 on eth0...
Hop limit                 :           64
Router lifetime           :         1800
 Route                    : fd8d:fc03:c02b::/64
  Route preference        :       medium
  Route lifetime          :         1800
 from fe80::1826:a686:2a31:6d2e
"""
    rows = agent.parse_rdisc6_rios(sample)
    assert rows, rows
    assert rows[0]["prefix"].startswith("fd8d:"), rows
    assert rows[0]["via"].startswith("fe80:"), rows
    assert callable(agent.install_discovered_thread_routes)
    assert callable(agent.ensure_matter_host_network)
    assert callable(agent.add_ipv6_route)
    # Refuse Tailscale
    assert agent._is_tailscale_ula("fd7a:115c:a1e0::1")
    print("ok: thread route helpers")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
