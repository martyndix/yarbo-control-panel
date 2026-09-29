#!/usr/bin/env python3
"""Prove PHP replaces a pre-colour Matter agent that returns Unknown Matter command."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PORT = 18767

OLD_STUB = r"""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json, sys

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
        result = {"ok": True, "engine": "matter-agent"} if body.get("op") == "ping" else {"ok": False, "error": "Unknown Matter command"}
        data = json.dumps(result).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

httpd = ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1])), H)
print("old-agent-ready", flush=True)
httpd.serve_forever()
"""


def post(body: dict) -> dict:
    req = urllib.request.Request(
        f"http://127.0.0.1:{PORT}/",
        data=json.dumps(body).encode(),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=8) as resp:
        return json.loads(resp.read().decode())


def wait_old(proc: subprocess.Popen, timeout: float = 4.0) -> None:
    deadline = time.time() + timeout
    while time.time() < deadline:
        if proc.poll() is not None:
            raise SystemExit("old stub exited")
        try:
            ping = post({"op": "ping"})
            if ping.get("ok"):
                return
        except OSError:
            time.sleep(0.05)
    raise SystemExit("old stub did not start")


def main() -> int:
    stub = subprocess.Popen(
        [sys.executable, "-c", OLD_STUB, str(PORT)],
        cwd=str(ROOT),
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
    )
    php = None
    try:
        wait_old(stub)
        stale = post({"op": "command", "id": "1:1", "action": "color", "hex": "#ff0000"})
        assert stale.get("error") == "Unknown Matter command", stale
        php = subprocess.run(
            [
                "php",
                "-r",
                rf"""
require '{ROOT}/src/YarboMatterAgentClient.php';
$client = new Yarbo\YarboMatterAgentClient('127.0.0.1', {PORT});
$client->ensureStarted();
$ping = $client->ping();
echo json_encode($ping), "\n";
if (!Yarbo\YarboMatterAgentClient::agentSupportsColor($ping)) {{
    fwrite(STDERR, "recycle failed: " . json_encode($ping) . "\n");
    exit(1);
}}
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
            raise SystemExit(f"php recycle failed: {php.returncode}")
        ping = json.loads(php.stdout.strip().splitlines()[-1])
        assert "color" in (ping.get("features") or []), ping
        color = post({"op": "command", "id": "1:1", "action": "color", "hex": "#ff0000"})
        err = str(color.get("error") or "")
        assert "Unknown Matter command" not in err, color
        print("ok: PHP replaced the old agent; colour is no longer unknown")
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
