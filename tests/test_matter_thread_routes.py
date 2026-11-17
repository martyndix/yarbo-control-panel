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
    assert agent.AGENT_VERSION == 93, agent.AGENT_VERSION
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

    import threading
    echo = socket.socket(socket.AF_INET6, socket.SOCK_DGRAM)
    echo.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
    echo.bind(("::1", 0))
    echo_port = echo.getsockname()[1]

    def echo_thread() -> None:
        data, addr = echo.recvfrom(1500)
        echo.sendto(b"pong:" + data, addr)

    threading.Thread(target=echo_thread, daemon=True).start()
    listen = socket.socket(socket.AF_INET6, socket.SOCK_DGRAM)
    listen.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
    listen.bind(("::1", 0))
    out = socket.socket(socket.AF_INET6, socket.SOCK_DGRAM)
    out.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
    out.bind(("::1", 0))
    relay = agent.UdpRelay(listen, out, "::1", echo_port, 0)
    relay.start()
    try:
        client = socket.socket(socket.AF_INET6, socket.SOCK_DGRAM)
        client.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
        client.bind(("::1", 0))
        client.settimeout(2)
        client.sendto(b"ping", listen.getsockname())
        reply, _ = client.recvfrom(1500)
        assert reply == b"pong:ping", reply
    finally:
        relay.close()
        echo.close()
    print("ok: thread route helpers")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
