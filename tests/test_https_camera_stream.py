#!/usr/bin/env python3
"""Live pictures must stream through HTTPS without waiting for the stream to end."""

from __future__ import annotations

import importlib.util
import io
import sys
import threading
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def load():
    path = ROOT / "scripts" / "panel_https_proxy.py"
    spec = importlib.util.spec_from_file_location("panel_https_proxy_test", path)
    assert spec and spec.loader
    mod = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = mod
    spec.loader.exec_module(mod)
    return mod


class SlowBody:
    def __init__(self) -> None:
        self.calls = 0

    def read(self, _size: int) -> bytes:
        self.calls += 1
        if self.calls == 1:
            return b"data: hi\n\n"
        time.sleep(5)
        return b""


class Sink:
    def __init__(self) -> None:
        self.buf = io.BytesIO()

    def write(self, data: bytes) -> None:
        self.buf.write(data)

    def flush(self) -> None:
        return None


def main() -> None:
    proxy = load()
    assert proxy.is_camera_frames("/api/camera_live.php?action=frames")
    assert not proxy.is_camera_frames("/api/camera_live.php?action=status")
    assert not proxy.is_camera_frames("/api/status.php")
    assert proxy.camera_agent_port() != 8766

    sink = Sink()
    worker = threading.Thread(target=proxy.relay_upstream, args=(SlowBody(), sink), daemon=True)
    worker.start()
    deadline = time.time() + 0.8
    while time.time() < deadline and sink.buf.getvalue() != b"data: hi\n\n":
        time.sleep(0.02)
    assert sink.buf.getvalue() == b"data: hi\n\n", "first picture packet must arrive before the stream ends"
    print("ok")


if __name__ == "__main__":
    main()
