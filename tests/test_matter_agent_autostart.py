#!/usr/bin/env python3
"""Prove PHP starts the Matter agent again after it dies, then retries the command."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PORT = 18769

STUB = r"""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json, os, sys

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
        if body.get("op") == "ping":
            result = {"ok": True, "engine": "matter-agent", "version": 16, "features": ["color"]}
        else:
            result = {"ok": True, "id": body.get("id"), "on": True, "restarted": True}
        data = json.dumps(result).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

port = int(os.environ.get("YARBO_MATTER_AGENT_PORT") or "8766")
httpd = ThreadingHTTPServer(("127.0.0.1", port), H)
print("stub-ready", flush=True)
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


def kill_port() -> None:
    subprocess.run(
        f"lsof -ti tcp:{PORT} | xargs kill -9",
        shell=True,
        check=False,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    deadline = time.time() + 2.0
    while time.time() < deadline and port_open():
        time.sleep(0.05)


def main() -> int:
    kill_port()
    stub_path = Path(tempfile.gettempdir()) / f"yarbo-matter-stub-{PORT}.py"
    stub_path.write_text(STUB)
    php = subprocess.Popen(
        [
            "php",
            "-r",
            rf"""
require '{ROOT}/src/YarboMatterAgentClient.php';
$client = new Yarbo\YarboMatterAgentClient('127.0.0.1', {PORT});
$first = $client->request(['op' => 'ping'], 6.0);
echo json_encode($first), "\nREADY\n";
fflush(STDOUT);
fgets(STDIN);
$second = $client->request(['op' => 'command', 'id' => '1:1', 'action' => 'on'], 8.0);
echo json_encode($second), "\n";
""",
        ],
        cwd=str(ROOT),
        env={
            **os.environ,
            "YARBO_MATTER_AGENT_PORT": str(PORT),
            "YARBO_MATTER_AGENT_SCRIPT": str(stub_path),
        },
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
    )
    try:
        buf = ""
        deadline = time.time() + 12.0
        while "READY" not in buf:
            if php.poll() is not None:
                raise SystemExit("php exited before READY: " + buf)
            if time.time() > deadline:
                raise SystemExit("timeout waiting READY: " + buf)
            assert php.stdout is not None
            chunk = php.stdout.readline()
            buf += chunk
        first_line = buf.strip().splitlines()[0]
        first = json.loads(first_line)
        if first.get("ok") is not True:
            raise SystemExit("first ping failed: " + first_line)
        kill_port()
        if port_open():
            raise SystemExit("stub still listening after kill")
        assert php.stdin is not None
        php.stdin.write("go\n")
        php.stdin.flush()
        rest, _ = php.communicate(timeout=12)
        buf += rest
        lines = [line for line in buf.splitlines() if line.startswith("{")]
        if len(lines) < 2:
            raise SystemExit("missing second result: " + buf)
        second = json.loads(lines[-1])
        if second.get("ok") is not True:
            raise SystemExit("command after death failed: " + lines[-1])
        print("ok: dead Matter agent was restarted and the command retried")
        return 0
    finally:
        if php.poll() is None:
            php.kill()
            php.wait(timeout=2)
        kill_port()
        try:
            stub_path.unlink()
        except OSError:
            pass


if __name__ == "__main__":
    sys.exit(main())
