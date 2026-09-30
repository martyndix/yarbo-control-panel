#!/usr/bin/env python3
"""Helpers used by the Pi-side Matter node-13 check."""

from __future__ import annotations

import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "matter_check_node.py"


def load():
    spec = importlib.util.spec_from_file_location("matter_check_node", SCRIPT)
    if spec is None or spec.loader is None:
        raise SystemExit(f"Could not load {SCRIPT}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def main() -> int:
    m = load()
    encoded = m.encode_dns_name("_matter._tcp.local")
    if encoded != b"\x07_matter\x04_tcp\x05local\x00":
        raise SystemExit(f"dns name {encoded!r}")
    packet = m.mdns_query_packet("_matter._tcp.local")
    if packet[:12] != b"\x12\x00\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00":
        raise SystemExit(f"header {packet[:12]!r}")
    if not packet.endswith(b"\x00\x0c\x00\x01"):
        raise SystemExit(f"qtype {packet[-4:]!r}")
    labels = m.decode_dns_labels(packet)
    if labels != ["_matter", "_tcp", "local"]:
        raise SystemExit(f"labels {labels}")
    print("ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
