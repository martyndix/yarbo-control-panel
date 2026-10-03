#!/usr/bin/env python3
"""Re-insert Matter node stubs when the fabric JSON only has vendor_info."""

from __future__ import annotations

import importlib.util
import json
import os
import sys
import tempfile
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


def main() -> int:
    agent = load_agent()
    tmp = Path(tempfile.mkdtemp())
    storage = tmp / "data" / "matter-server"
    storage.mkdir(parents=True)
    agent.ROOT = tmp
    agent.STORAGE = storage
    agent.run_docker = lambda *args, **kwargs: __import__("subprocess").CompletedProcess(
        args=["docker"], returncode=1, stdout="", stderr="no docker in test"
    )

    (tmp / "data" / "home.json").write_text(
        json.dumps(
            {
                "names": {"13:2": "Landing", "13:3": "Hall"},
                "rooms": {"13:2": "r1"},
                "groups": {},
                "scenes": [
                    {
                        "id": "s1",
                        "actions": [{"id": "13:4", "on": True}, {"id": "scene:skip"}],
                    }
                ],
                "paper": {"tab1": ["13:5"]},
                "device_order": ["13:2", "1:1"],
                "last_devices": [{"id": "13:2", "node_id": 13, "endpoint": 2}],
            }
        ),
        encoding="utf-8",
    )
    ids = agent.node_ids_from_home_store()
    if ids != [1, 13]:
        raise SystemExit(f"home store node ids {ids}")

    fabric = storage / "1415963636765591517.json"
    fabric.write_text(json.dumps({"vendor_info": {"1": "Signify"}}), encoding="utf-8")
    (storage / "chip.json").write_text("{}", encoding="utf-8")
    (storage / "1415963636765591517.json.backup").write_text(
        json.dumps({"vendor_info": {"1": "Signify"}, "huge": "x" * 80}),
        encoding="utf-8",
    )
    chosen = agent.fabric_json_path()
    if chosen != fabric:
        raise SystemExit(f"fabric path {chosen}")
    if not agent.fabric_needs_node_restore():
        raise SystemExit("vendor_info-only fabric should need restore")

    written = agent.restore_nodes_into_fabric()
    if written != [1, 13]:
        raise SystemExit(f"written stubs {written}")
    saved = json.loads(fabric.read_text(encoding="utf-8"))
    if "vendor_info" not in saved:
        raise SystemExit("vendor_info was dropped")
    if set(saved["nodes"]) != {"1", "13"}:
        raise SystemExit(f"nodes {saved.get('nodes')}")
    if saved["nodes"]["13"]["interview_version"] != 0:
        raise SystemExit("expected empty interview so the server re-reads Hue")
    if saved["last_node_id"] != 13:
        raise SystemExit(f"last_node_id {saved.get('last_node_id')}")
    if not (storage / "1415963636765591517.json.nodes-restore").is_file():
        raise SystemExit("missing restore backup")

    again = agent.restore_nodes_into_fabric()
    if again != [1, 13]:
        raise SystemExit(f"empty stubs should be rewritten {again}")
    saved["nodes"]["13"]["attributes"] = {"0/40/5": "Hue Bridge"}
    fabric.write_text(json.dumps(saved), encoding="utf-8")
    if agent.restore_nodes_into_fabric() != [1]:
        raise SystemExit("should keep interviewed nodes and only fill missing stubs")

    unavailable = agent.node_unavailable({"ok": False, "error": "Node 13 is not (yet) available."})
    if not unavailable:
        raise SystemExit("unavailable detector missed NodeNotReady")
    missing = agent.node_unavailable({"ok": False, "error": "Node 13 does not exist or is not yet interviewed"})
    if missing:
        raise SystemExit("NodeNotExists should not look like NodeNotReady")

    calls = {"n": 0}

    def send_fail_then_ok():
        calls["n"] += 1
        if calls["n"] == 1:
            return {"ok": False, "error": "Node 13 is not (yet) available."}
        return {"ok": True}

    agent.start_background_recover = lambda: None  # type: ignore[method-assign]
    agent.recover_unavailable_node = lambda node_id: node_id  # type: ignore[method-assign]
    agent._recover_done.set()
    agent.wait_node_available = lambda node_id, timeout: True  # type: ignore[method-assign]
    retried = agent.command_with_reconnect(13, send_fail_then_ok)
    if not retried.get("ok") or calls["n"] != 2:
        raise SystemExit(f"reconnect {retried} calls={calls['n']}")

    owned = storage / "root-owned.json"
    secret = json.dumps({"vendor_info": {"9": "x"}, "keep": True})
    owned.write_text(secret, encoding="utf-8")
    os.chmod(owned, 0o000)

    def fake_docker(args, timeout=12.0):
        if args and args[0] == "cp" and len(args) >= 3:
            src, dest = str(args[1]), str(args[2])
            if ":/data/" in src:
                Path(dest).write_text(secret, encoding="utf-8")
                return __import__("subprocess").CompletedProcess(args=["docker", *args], returncode=0, stdout="", stderr="")
            target = storage / Path(dest.split("/")[-1])
            try:
                os.chmod(target, 0o644)
            except OSError:
                pass
            target.write_text(Path(src).read_text(encoding="utf-8"), encoding="utf-8")
            return __import__("subprocess").CompletedProcess(args=["docker", *args], returncode=0, stdout="", stderr="")
        return __import__("subprocess").CompletedProcess(args=["docker"], returncode=1, stdout="", stderr="no docker")

    agent.run_docker = fake_docker  # type: ignore[method-assign]
    pulled = agent.read_storage_file(owned)
    if pulled != secret:
        raise SystemExit(f"docker cp read fallback failed {pulled!r}")
    if not agent.write_storage_file(owned, json.dumps({"nodes": {"13": {"node_id": 13}}})):
        raise SystemExit("docker cp write fallback failed")
    copied = json.loads(owned.read_text(encoding="utf-8"))
    if "nodes" not in copied:
        raise SystemExit(f"root-owned fabric not replaced {copied}")

    print("ok: fabric node stubs restore from saved Home ids")
    return 0


if __name__ == "__main__":
    sys.exit(main())
