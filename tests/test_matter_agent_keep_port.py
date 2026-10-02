#!/usr/bin/env python3
"""Prove PHP does not kill a listening Matter agent when ping is slow."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PORT = 18768

SLOW_STUB = r"""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json, sys, time

class H(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        return
    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(n) if n else b"{}"
        try:
            body = json.loads(raw.decode() or "{}")
        except json.JSONDecodeError:
            body = {}
        time.sleep(2.0)
        if body.get("op") == "ping":
            result = {"ok": True, "engine": "matter-agent", "version": 15, "features": ["color"]}
        else:
            result = {"ok": True, "id": body.get("id"), "on": True}
        data = json.dumps(result).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

httpd = ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1])), H)
print("slow-agent-ready", flush=True)
httpd.serve_forever()
"""


def port_open() -> bool:
    import socket

    probe = socket.socket()
    probe.settimeout(0.2)
    try:
        probe.connect(("127.0.0.1", PORT))
        return True
    except OSError:
        return False
    finally:
        probe.close()


def wait_ready(proc: subprocess.Popen, timeout: float = 4.0) -> None:
    deadline = time.time() + timeout
    while time.time() < deadline:
        if proc.poll() is not None:
            raise SystemExit("slow stub exited")
        if port_open():
            return
        time.sleep(0.05)
    raise SystemExit("slow stub did not listen")


def main() -> int:
    stub = subprocess.Popen(
        [sys.executable, "-c", SLOW_STUB, str(PORT)],
        cwd=str(ROOT),
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
    )
    php = None
    try:
        wait_ready(stub)
        php = subprocess.run(
            [
                "php",
                "-r",
                rf"""
require '{ROOT}/src/YarboMatterAgentClient.php';
$client = new Yarbo\YarboMatterAgentClient('127.0.0.1', {PORT});
$client->ensureStarted();
echo $client->portOpen() ? "port-open\n" : "port-closed\n";
""",
            ],
            cwd=str(ROOT),
            env={**os.environ, "YARBO_MATTER_AGENT_PORT": str(PORT)},
            capture_output=True,
            text=True,
            timeout=15,
        )
        if php.returncode != 0:
            sys.stderr.write(php.stdout + php.stderr)
            raise SystemExit(f"php ensureStarted failed: {php.returncode}")
        if stub.poll() is not None:
            raise SystemExit("ensureStarted killed the slow listening agent")
        if not port_open():
            raise SystemExit("ensureStarted closed the slow agent port")
        if "port-open" not in php.stdout:
            raise SystemExit("php did not see the port still open: " + php.stdout)
        print("ok: slow listening agent was not killed")
        return 0
    finally:
        stub.terminate()
        try:
            stub.wait(timeout=2)
        except subprocess.TimeoutExpired:
            stub.kill()
        subprocess.run(
            ["pkill", "-f", "[s]cripts/matter_agent.py"],
            check=False,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )


if __name__ == "__main__":
    sys.exit(main())
