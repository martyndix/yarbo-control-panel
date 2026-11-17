#!/usr/bin/env python3
from __future__ import annotations
import importlib.util
import socket
import struct
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "matter_agent.py"

def load():
    spec = importlib.util.spec_from_file_location("matter_agent", SCRIPT)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

def qname(parts):
    return b"".join(bytes([len(part)]) + part.encode() for part in parts) + b"\x00"

def main() -> int:
    agent = load()
    assert agent.AGENT_VERSION == 90, agent.AGENT_VERSION
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
    name = qname(["_matterc", "_udp", "local"])
    aaaa = socket.inet_pton(socket.AF_INET6, "fd8d:fc03:c02b::1")
    pkt = struct.pack("!HHHHHH", 1, 0x8400, 0, 1, 0, 0) + name + struct.pack("!HHIH", 28, 1, 120, 16) + aaaa
    parsed = agent.parse_mdns_message(pkt)
    assert parsed["matterc"] is True, parsed
    assert agent.ipv6_prefix64(parsed["aaaa"][0]) == "fd8d:fc03:c02b::/64", parsed
    assert agent.ipv6_host128(parsed["aaaa"][0]) == "fd8d:fc03:c02b::1/128"
    install = SCRIPT.read_text().split("def install_routes_from_matterc_hits", 1)[1].split("def browse_matterc_mdns", 1)[0]
    assert "add_ipv6_route" not in install
    assert "return []" in install
    assert "drop_static_fd_prefixes" in SCRIPT.read_text()
    query = struct.pack("!HHHHHH", 1, 0, 1, 0, 0, 0) + name + struct.pack("!HH", 12, 1)
    assert agent.parse_mdns_message(query)["matterc"] is False
    assert agent._matterc_line_is_real("no _matterc replies") is False
    assert agent._matterc_line_is_real("fe80::1 matterc fd=fd8d:fc03:c02b::/64 via=fe80::1 iface=eth0")
    body = SCRIPT.read_text().split("def wait_for_thread_route", 1)[1].split("def ipv6_route_sample", 1)[0]
    assert "while time.time() < deadline" not in body
    print("ok: thread route helpers")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
