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
    assert agent.AGENT_VERSION == 96, agent.AGENT_VERSION
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
    install = SCRIPT.read_text().split("def install_routes_from_matterc_hits", 1)[1].split("\ndef ", 1)[0]
    assert "add_ipv6_route" not in install
    assert "return []" in install
    query_pkt = agent._mdns_query("_meshcop")
    assert struct.unpack("!H", query_pkt[-2:])[0] == 0x8001
    assert b"_meshcop" in query_pkt
    assert "drop_static_fd_prefixes" in SCRIPT.read_text()
    query = struct.pack("!HHHHHH", 1, 0, 1, 0, 0, 0) + name + struct.pack("!HH", 12, 1)
    assert agent.parse_mdns_message(query)["matterc"] is False
    assert agent._matterc_line_is_real("no _matterc replies") is False
    assert agent._matterc_line_is_real("fe80::1 matterc fd=fd8d:fc03:c02b::/64 via=fe80::1 iface=eth0")
    body = SCRIPT.read_text().split("def wait_for_thread_route", 1)[1].split("def ipv6_route_sample", 1)[0]
    assert "while time.time() < deadline" not in body
    name = qname(["AABBCCDDEEFF", "_matterc", "_udp", "local"])
    target = qname(["AABBCCDDEEFF", "local"])
    aaaa = socket.inet_pton(socket.AF_INET6, "fdef:badd:6106:0:dbfc:813f:f2ae:dcd0")
    srv = struct.pack("!HHH", 0, 0, 5541) + target
    pkt = struct.pack("!HHHHHH", 1, 0x8400, 0, 2, 0, 0)
    pkt += name + struct.pack("!HHIH", 33, 1, 120, len(srv)) + srv
    pkt += name + struct.pack("!HHIH", 28, 1, 120, 16) + aaaa
    parsed = agent.parse_mdns_message(pkt)
    assert parsed["matterc"] is True, parsed
    assert parsed["port"] == 5541, parsed
    assert "fdef:badd:6106:0:dbfc:813f:f2ae:dcd0" in parsed["aaaa"], parsed

    calls: list[list[str]] = []

    class Result:
        def __init__(self, stdout: str = "") -> None:
            self.returncode = 0
            self.stdout = stdout
            self.stderr = ""

    def fake_run(cmd, **kwargs):
        calls.append(list(cmd))
        if "show" in cmd:
            return Result("unreachable fdef:badd:6106::/64 metric 1000\n")
        return Result()

    agent.subprocess.run = fake_run
    assert agent.clear_unreachable_fd_routes() == 1
    assert any("del" in c and "unreachable" in c and "fdef:badd:6106::/64" in c for c in calls), calls

    assert agent.eui64_to_mac("fe80::2a70:4eff:fe68:98ea") == "28:70:4e:68:98:ea"
    agent.subprocess.run = lambda cmd, **kwargs: Result(
        "fdef:badd:6106:0:dbfc:813f:f2ae:dcd0 dev lo proto kernel metric 256\n"
    )
    assert agent.thread_route_hint("eth0") is None

    calls.clear()

    shown = {"text": "fdef:badd:6106:0:dbfc:813f:f2ae:dcd0 dev eth0 metric 100\n"}

    def fake_install(cmd, **kwargs):
        calls.append(list(cmd))
        if "show" in cmd:
            return Result(shown["text"])
        return Result()

    agent.subprocess.run = fake_install
    agent._last_matterc_hits = [{
        "src": "fe80::2a70:4eff:fe68:98ea",
        "iface": "eth0",
        "aaaa": ["fdef:badd:6106:0:dbfc:813f:f2ae:dcd0"],
        "port": 5540,
        "matterc": True,
    }]
    accessory = {"fe80::2a70:4eff:fe68:98ea"}
    homepod = "fe80::1111:2222:3333:4444"
    assert agent.pick_border_router([*accessory, homepod], accessory) == homepod
    assert agent.pick_border_router(list(accessory), accessory) == ""
    agent.discover_border_router_via = lambda iface: homepod
    label = agent.install_border_router_route()
    assert label == f"br:{homepod}", label
    flat = [" ".join(c) for c in calls]
    assert any("route" in c and "del" in c and "fdef:badd:6106:0:dbfc:813f:f2ae:dcd0" in c and "dev eth0" in c for c in flat), flat
    assert any("neigh" in c and "del" in c and "fdef:badd:6106:0:dbfc:813f:f2ae:dcd0" in c for c in flat), flat
    assert any(
        "route" in c and "replace" in c and "fdef:badd:6106::/64" in c and "via" in c and homepod in c
        for c in flat
    ), flat
    assert not any("lladdr" in c and "28:70:4e:68:98:ea" in c for c in flat), flat
    calls.clear()
    agent.discover_border_router_via = lambda iface: ""
    agent.iface_fe80 = lambda iface: "fe80::aabb:ccff:fedd:eeff"
    label = agent.install_border_router_route()
    assert label == "llsrc:fe80::aabb:ccff:fedd:eeff", label
    flat = [" ".join(c) for c in calls]
    assert any(
        "route" in c and "replace" in c and "fdef:badd:6106:0:dbfc:813f:f2ae:dcd0/128" in c
        and "src" in c and "fe80::aabb:ccff:fedd:eeff" in c
        for c in flat
    ), flat
    assert any("neigh" in c and "lladdr" in c and "28:70:4e:68:98:ea" in c for c in flat), flat
    print("ok: thread route helpers")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
