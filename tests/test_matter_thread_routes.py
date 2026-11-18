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
    assert agent.AGENT_VERSION == 117, agent.AGENT_VERSION
    agent.browse_mdns_sources = lambda label, seconds=1.0: []
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
    assert parsed["hosts"] == ["AABBCCDDEEFF.local"], parsed

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
    agent.subprocess.run = lambda cmd, **kwargs: Result(
        "fd58:b000:1::1 dev eth0 proto kernel metric 256\n"
    )
    assert agent.thread_route_hint("eth0") is None
    agent.subprocess.run = lambda cmd, **kwargs: Result(
        "fdef:badd:6106::/64 dev eth0 proto kernel metric 256\n"
    )
    hinted64 = agent.thread_route_hint("eth0") or ""
    assert hinted64.startswith("fdef:badd:6106::/64"), hinted64
    agent.subprocess.run = lambda cmd, **kwargs: Result(
        "fd58:b000:1::1 dev eth0 proto kernel metric 256\n"
        "fdef:badd:6106::/64 via fe80::1111:2222:3333:4444 dev eth0 metric 100\n"
    )
    hinted = agent.thread_route_hint("eth0") or ""
    assert "via fe80::1111:2222:3333:4444" in hinted, hinted
    pio = socket.inet_pton(socket.AF_INET6, "fd11:2233:4455::")
    rio_raw = socket.inet_pton(socket.AF_INET6, "fd99:1:2::")
    pio_opt = bytes([3, 4, 64, 0xC0]) + struct.pack("!II", 86400, 14400) + (b"\x00" * 4) + pio
    rio_opt = bytes([24, 2, 64, 0]) + struct.pack("!I", 1800) + rio_raw[:8]
    ra = bytes([134, 0, 0, 0, 64, 0]) + struct.pack("!HII", 1800, 0, 0) + pio_opt + rio_opt
    parsed_ra = agent.parse_ra_packet(ra, "fe80::abcd")
    assert parsed_ra["pios"] and parsed_ra["pios"][0]["onlink"] and parsed_ra["pios"][0]["prefix"].startswith("fd11:2233:4455::")
    assert parsed_ra["rios"] and parsed_ra["rios"][0]["prefix"].startswith("fd99:1:2::")
    assert parsed_ra["rios"][0]["via"] == "fe80::abcd"

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
    assert agent.pick_border_router(["2001:db8::1"], accessory) == "2001:db8::1"
    agent.discover_border_router_via = lambda iface: homepod
    agent.solicit_routers = lambda iface: {"sent": True, "rios": [], "pios": []}
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
    ra_pkt = agent.icmpv6_ra_packet("fdef:badd:6106::/64", bytes.fromhex("001122334455"))
    assert ra_pkt[0] == 134 and ra_pkt[16:18] == b"\x01\x01" and ra_pkt[24:28] == b"\x03\x04\x40\x80", ra_pkt[:32]
    assert ra_pkt[-16:-12] == bytes([24, 2, 64, 0x08]), ra_pkt[-16:]
    assert struct.unpack("!H", ra_pkt[6:8])[0] == 0
    routed = agent.icmpv6_ra_packet("fd58:b000:2::/64", bytes.fromhex("001122334455"), 90)
    assert struct.unpack("!H", routed[6:8])[0] == 90, routed[:8]
    assert routed[-16] == 24
    calls.clear()
    agent.discover_border_router_via = lambda iface: ""
    agent.advertise_prefix = lambda iface, prefix: True
    label = agent.install_border_router_route()
    assert label == "pfxra:fdef:badd:6106::5050", label
    flat = [" ".join(c) for c in calls]
    assert any(
        "addr" in c and "replace" in c and "fdef:badd:6106::5050/64" in c and "dev eth0" in c
        for c in flat
    ), flat
    assert any(
        "neigh" in c and "replace" in c and "fdef:badd:6106:0:dbfc:813f:f2ae:dcd0" in c
        and "lladdr" in c and "28:70:4e:68:98:ea" in c
        for c in flat
    ), flat
    assert not any("fdef:badd:6106:0:dbfc:813f:f2ae:dcd0/128" in c and "replace" in c for c in flat), flat
    assert not any("fd58:b000:1::1" in c and "addr" in c and "replace" in c for c in flat), flat
    calls.clear()
    border = "fe80::18e3:8d10:e524:10a9"
    agent.solicit_routers = lambda iface: {
        "sent": True,
        "rios": [{"prefix": "fd10:d687:e7bf:4ba8::/64", "via": border}],
        "pios": [],
    }
    label = agent.install_border_router_route()
    assert label == f"ra:{border}", label
    flat = [" ".join(c) for c in calls]
    assert any(
        "fd10:d687:e7bf:4ba8::/64" in c and "replace" in c and border in c and "fd58:" not in c
        for c in flat
    ), flat
    assert any(
        "fdef:badd:6106::/64" in c and "via" in c and border in c and "fd10:" not in c and "fd58:" not in c
        for c in flat
    ), flat
    assert not any(
        "fd58:b000:2::253" in c and any(word in c.split() for word in ("replace", "add", "src"))
        for c in flat
    ), flat
    calls.clear()
    agent._last_matterc_hits[0]["src"] = "fe80::18e3:8d10:e524:10a9"
    label = agent.install_border_router_route()
    assert label == "ra:fe80::18e3:8d10:e524:10a9", label
    flat = [" ".join(c) for c in calls]
    assert any(
        "fdef:badd:6106::/64" in c and "via" in c and "fe80::18e3:8d10:e524:10a9" in c
        and "fd10:" not in c and "fd58:" not in c
        for c in flat
    ), flat
    assert not any(
        "fd58:b000:2::253" in c and any(word in c.split() for word in ("replace", "add", "src"))
        for c in flat
    ), flat
    calls.clear()
    other = "fe80::1007:c231:5072:1418"
    homepod_ad = "fe80::18e3:8d10:e524:10a9"
    agent.solicit_routers = lambda iface: {
        "sent": True,
        "rios": [{"prefix": "fd10:d687:e7bf:4ba8::/64", "via": other}],
        "pios": [],
    }
    agent._last_matterc_hits = [
        {
            "matterc": True,
            "src": other,
            "aaaa": ["fe80::1007:c231:5072:1418"],
            "iface": "eth0",
            "port": 5540,
        },
        {
            "matterc": True,
            "src": homepod_ad,
            "aaaa": ["fdef:badd:6106:0:89b5:1f3d:906e:51bc"],
            "iface": "eth0",
            "port": 5540,
        },
    ]
    assert agent.matterc_router_via() == homepod_ad
    label = agent.install_border_router_route()
    assert label == f"ra:{homepod_ad}", label
    flat = [" ".join(c) for c in calls]
    assert any(
        "del" in c and "fdef:badd:6106::/64" in c and "via" not in c
        for c in flat
    ), flat
    assert any(
        "replace" in c and "fdef:badd:6106::/64" in c and homepod_ad in c
        and "metric 50" in c and "fd10:" not in c and "fd58:" not in c and other not in c
        for c in flat
    ), flat
    assert any(
        "replace" in c and "fd10:d687:e7bf:4ba8::/64" in c and other in c and homepod_ad not in c
        for c in flat
    ), flat
    assert not any(
        "fd58:b000:2::253" in c and any(word in c.split() for word in ("replace", "add", "src"))
        for c in flat
    ), flat
    agent.subprocess.run = lambda cmd, **kwargs: Result(
        "fdef:badd:6106::/64 via fe80::1007:c231:5072:1418 dev eth0 proto ra metric 100 pref medium\n"
        "fdef:badd:6106::/64 via fe80::18e3:8d10:e524:10a9 dev eth0 proto static metric 50\n"
    )
    hinted = agent.thread_route_hint("eth0") or ""
    assert "fe80::18e3:8d10:e524:10a9" in hinted and "fe80::1007" not in hinted, hinted
    agent.subprocess.run = fake_install
    saved: list[list[str]] = []
    saved_add_route = agent.add_ipv6_route
    agent.add_ipv6_route = lambda prefix, via, iface, src="", metric=100: saved.append(
        ["replace", prefix, "via", via, "src", src]
    ) or True
    agent.ensure_pio_source = lambda iface, pios: "fd11:2233:4455::5050"
    advertised = agent.install_advertised_routes("eth0", {
        "rios": [{"prefix": "fd99:1:2::/64", "via": "fe80::abcd"}],
        "pios": [{"prefix": "fd11:2233:4455::/64", "onlink": True}],
    })
    assert advertised == "ra:fe80::abcd", advertised
    assert saved and saved[0][1] == "fd99:1:2::/64" and "dbfc" not in " ".join(saved[0]), saved
    agent.add_ipv6_route = saved_add_route
    agent._last_matterc_hits = [{
        "matterc": True,
        "aaaa": ["fdef:badd:6106:0:89b5:1f3d:906e:51bc"],
        "a": [],
        "src": "fe80::18e3:8d10:e524:10a9",
        "iface": "eth0",
        "port": 5540,
    }]
    agent._last_rio_prefixes = ["fd10:d687:e7bf:4ba8::/64"]
    assert agent.accessory_lan_address() == ""
    agent._last_matterc_hits[0]["a"] = ["192.168.9.40"]
    agent._last_matterc_hits[0]["aaaa"] = [
        "fdef:badd:6106:0:89b5:1f3d:906e:51bc",
        "fd10:d687:e7bf:4ba8:1111:2222:3333:4444",
    ]
    assert agent.accessory_lan_address() == "192.168.9.40"
    agent._last_matterc_hits[0]["a"] = []
    assert agent.accessory_lan_address() == "fd10:d687:e7bf:4ba8:1111:2222:3333:4444"
    agent._last_matterc_hits[0]["aaaa"] = ["fdef:badd:6106:0:89b5:1f3d:906e:51bc"]
    assert agent.accessory_lan_address() == ""
    assert agent.accessory_omr_address() == "fd10:d687:e7bf:4ba8:89b5:1f3d:906e:51bc"
    agent._last_matterc_hits[0]["aaaa"] = ["fe80::18e3:8d10:e524:10a9"]
    assert agent.accessory_omr_address() == ""
    body = "2412950753"
    for digit in "0123456789":
        if agent.verhoeff_ok(body + digit):
            assert agent.manual_pairing_pin(body + digit) == 12345679
            break
    else:
        raise SystemExit("verhoeff check digit missing")
    agent.subprocess.run = lambda cmd, **kwargs: Result(
        "2: eth0    inet6 fd10:d687:e7bf:4ba8:2ecf:67ff:fe70:97e7/64 scope global\n"
        "2: eth0    inet6 2a02:1234:9::253/64 scope global\n"
    )
    assert agent.pairing_source_address(
        "eth0", ["fd10:d687:e7bf:4ba8::/64", "fdef:badd:6106::/64"]
    ) == "2a02:1234:9::253"
    agent.subprocess.run = lambda cmd, **kwargs: Result(
        "2: eth0    inet6 fd10:d687:e7bf:4ba8:2ecf:67ff:fe70:97e7/64 scope global\n"
    )
    assert agent.pairing_source_address("eth0", ["fdef:badd:6106::/64"]) == ""
    assert agent.homepod_ula_prefixes("eth0") == ["fd10:d687:e7bf:4ba8::/64"]
    agent._last_matterc_hits = [{
        "matterc": True,
        "src": "fe80::1007:c231:5072:1418",
        "aaaa": ["fdef:badd:6106:0:89b5:1f3d:906e:51bc"],
        "iface": "eth0",
        "port": 5540,
    }]
    hop, alts = agent.choose_thread_hop(
        ["fe80::1007:c231:5072:1418"],
        ["fe80::18e3:8d10:e524:10a9"],
    )
    assert hop == "fe80::18e3:8d10:e524:10a9", (hop, alts)
    assert any(item.startswith("fe80::1007") for item in alts), alts
    calls.clear()
    reflector = "fe80::1007:c231:5072:1418"
    border_agent = "fe80::18e3:8d10:e524:10a9"
    agent.browse_mdns_sources = lambda label, seconds=1.0: [border_agent, "fd10:d687:e7bf:4ba8::1"]
    agent._last_matterc_hits = [{
        "matterc": True,
        "src": reflector,
        "aaaa": ["fdef:badd:6106:0:89b5:1f3d:906e:51bc"],
        "iface": "eth0",
        "port": 5540,
    }]
    agent.solicit_routers = lambda iface: {
        "sent": True,
        "rios": [{"prefix": "fdef:badd:6106::/64", "via": border_agent}],
        "pios": [{
            "prefix": "fd10:d687:e7bf:4ba8::/64",
            "onlink": True,
            "auto": True,
        }],
    }

    def fake_pio(cmd, **kwargs):
        calls.append(list(cmd))
        if "addr" in cmd and "show" in cmd:
            return Result(
                "2: eth0    inet6 fd10:d687:e7bf:4ba8:2ecf:67ff:fe70:97e7/64 scope global\n"
            )
        if "show" in cmd:
            return Result("")
        return Result()

    agent.subprocess.run = fake_pio
    label = agent.install_border_router_route()
    assert border_agent in label, label
    flat = [" ".join(c) for c in calls]
    assert any(
        "replace" in c and "fdef:badd:6106::/64" in c and border_agent in c
        and "src fd10:d687:e7bf:4ba8:2ecf:67ff:fe70:97e7" in c
        for c in flat
    ), flat
    assert not any("replace" in c and "fdef:badd:6106::/64" in c and reflector in c for c in flat), flat
    assert not any(
        "fd58:b000:2::253" in c and any(word in c.split() for word in ("replace", "add", "src"))
        for c in flat
    ), flat
    assert agent.format_pio_label(
        [{"prefix": "fdef:badd:6106::/64", "auto": True, "onlink": False}],
        "fdef:badd:6106::/64",
    ) == "same"
    assert agent._last_rio_rows and agent._last_rio_rows[0]["via"] == border_agent
    assert agent._last_pio_label.startswith("fd10:d687:e7bf:4ba8::/64,AL"), agent._last_pio_label
    assert border_agent in agent._last_mesh_sources
    assert "fd10:d687:e7bf:4ba8::1" in agent._last_mesh_sources
    text = agent.format_discovery_diag({
        "agent": agent.AGENT_VERSION,
        "thread_route": "fdef:badd:6106::/64 via " + border_agent,
        "tailscale": False,
        "matterc_seen": True,
        "fd": "fdef:badd:6106::/64",
        "via": border_agent,
        "ra_proof": "ra",
        "v4": "none",
        "addrs": ["fdef:badd:6106:0:89b5:1f3d:906e:51bc"],
        "mdns5353": "free",
        "network_mode": "host",
        "iface": "eth0",
        "br": border_agent,
        "pase_target": "ra:" + border_agent,
        "pair_src": "fd10:d687:e7bf:4ba8:2ecf:67ff:fe70:97e7",
        "ra_adv": "none",
        "alt_hops": [reflector],
        "port": 5540,
        "shield": "ok",
        "rio": agent.format_rio_label(agent._last_rio_rows),
        "pio": agent._last_pio_label,
        "mesh": ",".join(agent._last_mesh_sources),
    })
    assert "rio=fdef:badd:6106::/64 via " + border_agent in text, text
    assert "pio=fd10:d687:e7bf:4ba8::/64,AL" in text, text
    assert "mesh=" + border_agent in text, text
    assert "fd58:b000:2::253" not in text
    print("ok: thread route helpers")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
