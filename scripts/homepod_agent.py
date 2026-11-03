#!/usr/bin/env python3
"""Local HomePod / AirPlay sender agent for the Yarbo panel (pyatv RAOP).

JSON HTTP on 127.0.0.1:8767. Scan, pair, stream files/URLs, stop, volume.

Usage:
  python3 scripts/homepod_agent.py
"""

from __future__ import annotations

import asyncio
import json
import os
import shutil
import subprocess
import sys
import threading
import time
import traceback
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
HOST = "127.0.0.1"
AGENT_PORT = int(os.environ.get("YARBO_HOMEPOD_AGENT_PORT", "8767"))
DATA_DIR = ROOT / "data" / "homepod"
DEVICES_PATH = DATA_DIR / "devices.json"
LOG_PATH = ROOT / "data" / "homepod-agent.log"
CACHE_DIR = DATA_DIR / "cache"
AGENT_VERSION = 5
# Formats pyatv stream_file handles reliably as local files (announcements).
_DIRECT_SUFFIXES = {".wav", ".mp3", ".aac", ".m4a", ".flac", ".aiff", ".aif", ".caf"}
# Icecast / CDN streams often 401 without a browser-like UA.
_FFMPEG_UA = "Mozilla/5.0 (compatible; YarboHomepod/1.0)"

try:
    import pyatv
    from pyatv import exceptions as pyatv_exc
    from pyatv.const import DeviceModel, PairingRequirement, Protocol
    from pyatv.settings import MrpTunnel
    from pyatv.storage.memory_storage import MemoryStorage
except Exception as exc:  # pragma: no cover - import probed at runtime
    pyatv = None  # type: ignore
    pyatv_exc = None  # type: ignore
    Protocol = None  # type: ignore
    DeviceModel = None  # type: ignore
    PairingRequirement = None  # type: ignore
    MrpTunnel = None  # type: ignore
    MemoryStorage = None  # type: ignore
    _IMPORT_ERROR = str(exc)
else:
    _IMPORT_ERROR = ""


def log(msg: str) -> None:
    line = f"{time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())} {msg}"
    try:
        DATA_DIR.mkdir(parents=True, exist_ok=True)
        ROOT.joinpath("data").mkdir(parents=True, exist_ok=True)
        # Cap the agent log — unbounded growth stalled the Pi and blew shell ARG_MAX
        # when related scripts embedded log tails in php -r argv.
        if LOG_PATH.is_file() and LOG_PATH.stat().st_size > 512 * 1024:
            try:
                raw = LOG_PATH.read_bytes()
                LOG_PATH.write_bytes(raw[-128 * 1024 :])
            except OSError:
                pass
        with LOG_PATH.open("a", encoding="utf-8") as fh:
            fh.write(line + "\n")
    except OSError:
        pass
    sys.stderr.write(line + "\n")
    sys.stderr.flush()


def load_devices() -> dict[str, Any]:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    if not DEVICES_PATH.is_file():
        return {"devices": []}
    try:
        raw = json.loads(DEVICES_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {"devices": []}
    if not isinstance(raw, dict):
        return {"devices": []}
    devices = raw.get("devices")
    if not isinstance(devices, list):
        devices = []
    return {"devices": devices}


def save_devices(payload: dict[str, Any]) -> None:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    DEVICES_PATH.write_text(
        json.dumps(payload, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )


def upsert_device(entry: dict[str, Any]) -> dict[str, Any]:
    data = load_devices()
    devices = list(data.get("devices") or [])
    ident = str(entry.get("id") or "")
    out: list[dict[str, Any]] = []
    found = False
    for row in devices:
        if not isinstance(row, dict):
            continue
        if str(row.get("id") or "") == ident:
            merged = dict(row)
            merged.update(entry)
            out.append(merged)
            found = True
        else:
            out.append(row)
    if not found:
        out.append(entry)
    payload = {"devices": out}
    save_devices(payload)
    return payload


def remove_device(ident: str) -> dict[str, Any]:
    data = load_devices()
    devices = [
        row
        for row in (data.get("devices") or [])
        if isinstance(row, dict) and str(row.get("id") or "") != ident
    ]
    payload = {"devices": devices}
    save_devices(payload)
    return payload


def is_homepod_like(dev: Any) -> bool:
    name = (getattr(dev, "name", None) or "").lower()
    model = getattr(dev, "device_info", None)
    model_name = ""
    if model is not None:
        model_name = str(getattr(model, "model_str", None) or getattr(model, "model", "") or "").lower()
        raw_model = getattr(model, "model", None)
        if DeviceModel is not None and raw_model in (
            getattr(DeviceModel, "HomePod", None),
            getattr(DeviceModel, "HomePodMini", None),
            getattr(DeviceModel, "Gen2HomePod", None),
        ):
            return True
    blob = f"{name} {model_name}"
    if "homepod" in blob:
        return True
    # Also allow generic AirPlay speakers the user explicitly pairs.
    return bool(getattr(dev, "all_services", lambda: [])())


class HomepodRuntime:
    def __init__(self) -> None:
        self.loop = asyncio.new_event_loop()
        self._thread = threading.Thread(target=self._run_loop, name="homepod-asyncio", daemon=True)
        self._thread.start()
        self._pairings: dict[str, Any] = {}
        self._streams: dict[str, dict[str, Any]] = {}
        # ffmpeg helpers: asyncio.subprocess.Process or subprocess.Popen
        self._remux: dict[str, Any] = {}
        self._temps: dict[str, list[Path]] = {}
        self._lock = threading.Lock()

    def _run_loop(self) -> None:
        asyncio.set_event_loop(self.loop)
        self.loop.run_forever()

    def call(self, coro: Any, timeout: float = 60.0) -> Any:
        fut = asyncio.run_coroutine_threadsafe(coro, self.loop)
        return fut.result(timeout=timeout)

    def _ffmpeg_bin(self) -> str:
        return shutil.which("ffmpeg") or "/usr/bin/ffmpeg"

    def _cleanup_helpers(self, ident: str) -> None:
        proc = self._remux.pop(ident, None)
        if proc is not None:
            try:
                if getattr(proc, "returncode", None) is None:
                    proc.kill()
            except Exception:
                try:
                    proc.terminate()
                except Exception:
                    pass
            # Best-effort reap without blocking the event loop hard.
            try:
                if isinstance(proc, subprocess.Popen):
                    proc.wait(timeout=1)
            except Exception:
                pass
        for path in self._temps.pop(ident, []) or []:
            try:
                if path.is_file():
                    path.unlink()
            except OSError:
                pass

    def _needs_file_transcode(self, path: Path) -> bool:
        suffix = path.suffix.lower()
        if suffix in _DIRECT_SUFFIXES:
            return False
        # Browser announce clips are usually webm/ogg/opus — HomePods reject those.
        return True

    def _is_local_http(self, source: str) -> bool:
        """True for panel AirPlay-bridge MP3 listeners on loopback."""
        lower = source.lower()
        return lower.startswith("http://127.0.0.1:") or lower.startswith("http://localhost:")

    async def _prepare_playable(self, ident: str, source: str) -> Any:
        """Return a local file path or an asyncio StreamReader for stream_file.

        Announcements stay as WAV files (that path already works). Radio and the
        AirPlay bridge are HTTP — pyatv's InternetSource often fails on ice/CDN
        and on ffmpeg -listen URLs, so we always decode via ffmpeg → stdout pipe
        and hand the StreamReader to RAOP.
        """
        self._cleanup_helpers(ident)
        CACHE_DIR.mkdir(parents=True, exist_ok=True)

        if source.startswith(("http://", "https://")):
            return await self._ffmpeg_pipe(ident, source)

        path = Path(source)
        if not path.is_file():
            raise RuntimeError(f"Audio source not found: {source}")
        if not self._needs_file_transcode(path):
            return str(path.resolve())

        ffmpeg = self._ffmpeg_bin()
        if not os.path.isfile(ffmpeg) and shutil.which("ffmpeg") is None:
            raise RuntimeError("ffmpeg is required to convert announcement audio for HomePods")
        out = CACHE_DIR / f"announce-{ident.replace(':', '')}-{int(time.time())}.wav"
        cmd = [
            ffmpeg,
            "-nostdin",
            "-hide_banner",
            "-loglevel",
            "error",
            "-y",
            "-i",
            str(path),
            "-ac",
            "2",
            "-ar",
            "44100",
            "-f",
            "wav",
            str(out),
        ]
        log(f"transcode announce id={ident} src={path.name} -> {out.name}")
        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.DEVNULL,
            stderr=asyncio.subprocess.PIPE,
        )
        _stdout, stderr = await proc.communicate()
        if proc.returncode != 0 or not out.is_file() or out.stat().st_size < 64:
            detail = (stderr or b"").decode("utf-8", errors="replace").strip()
            raise RuntimeError(
                "Could not convert announcement for HomePod"
                + (f": {detail}" if detail else "")
            )
        self._temps.setdefault(ident, []).append(out)
        return str(out)

    async def _ffmpeg_pipe(self, ident: str, source: str) -> asyncio.StreamReader:
        """Decode any HTTP/file-like source to MP3 on stdout for stream_file."""
        ffmpeg = self._ffmpeg_bin()
        if not os.path.isfile(ffmpeg) and shutil.which("ffmpeg") is None:
            raise RuntimeError("ffmpeg is required to stream radio/AirPlay to HomePods")

        cmd: list[str] = [
            ffmpeg,
            "-nostdin",
            "-hide_banner",
            "-loglevel",
            "warning",
        ]
        # Remote ice/CDN need a UA; loopback AirPlay-bridge MP3 does not.
        if source.startswith(("http://", "https://")) and not self._is_local_http(source):
            cmd += [
                "-user_agent",
                _FFMPEG_UA,
                "-headers",
                "Icy-MetaData: 1\r\n",
            ]
        # Live inputs: give ffmpeg a few seconds to open slow listeners / ice.
        if self._is_local_http(source):
            cmd += ["-rw_timeout", "15000000"]  # 15s in microseconds
        cmd += [
            "-i",
            source,
            "-vn",
            "-ac",
            "2",
            "-ar",
            "44100",
            "-c:a",
            "libmp3lame",
            "-b:a",
            "128k",
            "-f",
            "mp3",
            "pipe:1",
        ]
        log(f"ffmpeg pipe id={ident} src={source}")
        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        self._remux[ident] = proc
        if proc.stdout is None:
            self._cleanup_helpers(ident)
            raise RuntimeError("ffmpeg produced no stdout pipe")

        # Confirm the process is alive and producing data before RAOP attaches.
        # Drain a tiny probe via wait — if ffmpeg dies immediately, surface stderr.
        await asyncio.sleep(0.35)
        if proc.returncode is not None:
            err = b""
            try:
                err = await asyncio.wait_for(proc.stderr.read(), timeout=0.5) if proc.stderr else b""
            except Exception:
                pass
            self._remux.pop(ident, None)
            detail = err.decode("utf-8", errors="replace").strip().splitlines()
            tail = detail[-1] if detail else "ffmpeg exited"
            raise RuntimeError(f"Could not start audio pipe for HomePod: {tail[:240]}")
        return proc.stdout

    async def scan(self, timeout: float = 5.0) -> list[dict[str, Any]]:
        if pyatv is None:
            raise RuntimeError(f"pyatv not installed: {_IMPORT_ERROR}")
        devices = await pyatv.scan(self.loop, timeout=timeout)
        saved = {str(d.get("id")): d for d in load_devices().get("devices") or [] if isinstance(d, dict)}
        out: list[dict[str, Any]] = []
        for dev in devices:
            services = []
            for svc in dev.services:
                services.append(
                    {
                        "protocol": str(svc.protocol.name if hasattr(svc.protocol, "name") else svc.protocol),
                        "port": getattr(svc, "port", None),
                        "requires_password": bool(getattr(svc, "requires_password", False)),
                        "pairing": str(getattr(svc, "pairing", "")),
                    }
                )
            protocols = {str(s.get("protocol") or "").lower() for s in services}
            if "raop" not in protocols and "airplay" not in protocols:
                continue
            ident = str(dev.identifier)
            info = getattr(dev, "device_info", None)
            model = ""
            if info is not None:
                model = str(getattr(info, "model_str", None) or getattr(info, "model", "") or "")
            name = str(dev.name or ident)
            # Prefer HomePods; still list other RAOP speakers so AirPort Express works.
            kind = "homepod" if "homepod" in f"{name} {model}".lower() else "airplay_speaker"
            pairing_req = self._best_pairing_requirement(dev)
            saved_row = saved.get(ident) if isinstance(saved.get(ident), dict) else None
            row = {
                "id": ident,
                "name": name,
                "address": str(dev.address) if getattr(dev, "address", None) else None,
                "model": model,
                "kind": kind,
                "services": services,
                "pairing_requirement": pairing_req,
                # Saved in devices.json counts as added (credentials optional when LAN access is open).
                "paired": saved_row is not None,
                "enabled": bool((saved_row or {}).get("enabled", True)) if saved_row else False,
            }
            out.append(row)
        out.sort(key=lambda r: (0 if r["kind"] == "homepod" else 1, r["name"].lower()))
        return out

    def _best_pairing_requirement(self, conf: Any) -> str:
        """Return the most relevant RAOP/AirPlay pairing requirement name."""
        if Protocol is None or PairingRequirement is None:
            return "unknown"
        reqs: list[Any] = []
        for protocol in (Protocol.RAOP, Protocol.AirPlay):
            svc = conf.get_service(protocol)
            if svc is None:
                continue
            req = getattr(svc, "pairing", None)
            if req is not None:
                reqs.append(req)
        if not reqs:
            return "unknown"
        # Prefer the strongest requirement we might need to satisfy.
        order = {
            PairingRequirement.Mandatory: 4,
            PairingRequirement.Optional: 3,
            PairingRequirement.NotNeeded: 2,
            PairingRequirement.Disabled: 1,
            PairingRequirement.Unsupported: 0,
        }
        best = max(reqs, key=lambda r: order.get(r, -1))
        return str(getattr(best, "name", best))

    def _pick_protocol(self, conf: Any) -> Any:
        if conf.get_service(Protocol.RAOP) is not None:
            return Protocol.RAOP
        if conf.get_service(Protocol.AirPlay) is not None:
            return Protocol.AirPlay
        raise RuntimeError("Device has no RAOP/AirPlay service")

    def _save_device_entry(
        self,
        *,
        identifier: str,
        name: str,
        address: str | None,
        model: str,
        protocol: Any,
        credentials: Any,
    ) -> dict[str, Any]:
        entry = {
            "id": identifier,
            "name": name,
            "address": address,
            "model": model,
            "kind": "homepod" if "homepod" in f"{name} {model}".lower() else "airplay_speaker",
            "protocol": protocol.name if hasattr(protocol, "name") else str(protocol),
            "credentials": credentials,
            "enabled": True,
            "paired_at": int(time.time()),
        }
        upsert_device(entry)
        public = dict(entry)
        public["credentials"] = bool(credentials)
        return public

    async def pair_start(self, identifier: str) -> dict[str, Any]:
        if pyatv is None:
            raise RuntimeError(f"pyatv not installed: {_IMPORT_ERROR}")
        devices = await pyatv.scan(self.loop, timeout=6.0)
        conf = next((d for d in devices if str(d.identifier) == identifier), None)
        if conf is None:
            raise RuntimeError("Device not found on the LAN — scan again")
        protocol = self._pick_protocol(conf)
        service = conf.get_service(protocol)
        pairing_req = getattr(service, "pairing", None) if service is not None else None
        req_name = str(getattr(pairing_req, "name", pairing_req) or "unknown")
        name = str(conf.name or identifier)
        address = str(conf.address) if getattr(conf, "address", None) else None
        model = str(getattr(getattr(conf, "device_info", None), "model_str", "") or "")

        # With Home app “Anyone on the Same Network”, RAOP is often NotNeeded —
        # HomePods will not speak a PIN. Just add the speaker and stream.
        if PairingRequirement is not None and pairing_req in (
            PairingRequirement.NotNeeded,
            PairingRequirement.Unsupported,
        ):
            public = self._save_device_entry(
                identifier=identifier,
                name=name,
                address=address,
                model=model,
                protocol=protocol,
                credentials=None,
            )
            return {
                "ok": True,
                "id": identifier,
                "name": name,
                "protocol": protocol.name,
                "pin_required": False,
                "paired": True,
                "pairing_requirement": req_name,
                "device": public,
                "message": (
                    f"Added {name} — no PIN needed with current AirPlay access. "
                    "It is ready in the Radio Player checklist."
                ),
            }

        if PairingRequirement is not None and pairing_req == PairingRequirement.Disabled:
            raise RuntimeError(
                "AirPlay pairing is disabled on this HomePod. In the Home app: "
                "Home Settings → Speakers & TVs → Allow Speaker Access → "
                "Anyone on the Same Network (turn off Require Password), then tap Pair again."
            )

        pairing = await pyatv.pair(conf, protocol, self.loop)
        await pairing.begin()
        provides_pin = bool(getattr(pairing, "device_provides_pin", True))
        self._pairings[identifier] = {
            "pairing": pairing,
            "conf": conf,
            "protocol": protocol,
            "name": name,
            "address": address,
            "model": model,
            "started_at": time.time(),
        }
        if not provides_pin:
            # Rare on HomePods: we would show a PIN for the user to enter on the device.
            # Still collect a code from the user field if the protocol expects one later.
            return {
                "ok": True,
                "id": identifier,
                "name": name,
                "protocol": protocol.name,
                "pin_required": True,
                "device_provides_pin": False,
                "pairing_requirement": req_name,
                "message": (
                    "This device expects you to enter a PIN on the speaker/TV. "
                    "If nothing appears, set Home app speaker access to Anyone on the Same Network "
                    "and tap Pair again (often no PIN is needed then)."
                ),
            }
        return {
            "ok": True,
            "id": identifier,
            "name": name,
            "protocol": protocol.name,
            "pin_required": True,
            "device_provides_pin": True,
            "pairing_requirement": req_name,
            "message": (
                "If the HomePod speaks a code, enter it below. "
                "If it stays silent, Cancel and set Home app → Speakers & TVs → "
                "Anyone on the Same Network (no password), then Pair again — "
                "open access usually needs no PIN."
            ),
        }

    async def pair_finish(self, identifier: str, pin: str) -> dict[str, Any]:
        state = self._pairings.get(identifier)
        if not state:
            raise RuntimeError("No pairing in progress for that device — tap Pair first")
        pairing = state["pairing"]
        pin = str(pin).strip()
        if pin:
            pairing.pin(pin)
        await pairing.finish()
        if not pairing.has_paired:
            raise RuntimeError("Pairing did not complete")
        credentials = None
        try:
            service = state["conf"].get_service(state["protocol"])
            credentials = getattr(service, "credentials", None) if service else None
        except Exception:
            credentials = None
        # Also try storage from pairing object.
        if not credentials:
            credentials = getattr(pairing, "service", None)
            credentials = getattr(credentials, "credentials", None) if credentials else None
        public = self._save_device_entry(
            identifier=identifier,
            name=state["name"],
            address=state["address"],
            model=state["model"],
            protocol=state["protocol"],
            credentials=credentials,
        )
        try:
            await pairing.close()
        except Exception:
            pass
        self._pairings.pop(identifier, None)
        return {"ok": True, "device": public, "pin_required": False, "message": f"Paired {public['name']}"}

    async def add_without_pin(self, identifier: str) -> dict[str, Any]:
        """Save a HomePod for streaming when open LAN access means no PIN is spoken."""
        if pyatv is None:
            raise RuntimeError(f"pyatv not installed: {_IMPORT_ERROR}")
        # Cancel any in-progress pairing session for this id.
        state = self._pairings.pop(identifier, None)
        if state and state.get("pairing") is not None:
            try:
                await state["pairing"].close()
            except Exception:
                pass
        devices = await pyatv.scan(self.loop, timeout=6.0)
        conf = next((d for d in devices if str(d.identifier) == identifier), None)
        if conf is None:
            raise RuntimeError("Device not found on the LAN — scan again")
        protocol = self._pick_protocol(conf)
        public = self._save_device_entry(
            identifier=identifier,
            name=str(conf.name or identifier),
            address=str(conf.address) if getattr(conf, "address", None) else None,
            model=str(getattr(getattr(conf, "device_info", None), "model_str", "") or ""),
            protocol=protocol,
            credentials=None,
        )
        return {
            "ok": True,
            "pin_required": False,
            "paired": True,
            "device": public,
            "message": (
                f"Added {public['name']} without a PIN. "
                "Play a short announcement to confirm audio — if it fails, "
                "turn on Anyone on the Same Network in the Home app and try again."
            ),
        }

    def _disable_non_stream_protocols(self, conf: Any) -> None:
        """Drop Companion/MRP/DMAP so connect does not need remote-control pairing.

        HomePods often advertise those services; setting them up fails with
        "Failed to set up remote control channel" even when RAOP streaming works.
        """
        if Protocol is None:
            return
        for proto in (
            getattr(Protocol, "Companion", None),
            getattr(Protocol, "MRP", None),
            getattr(Protocol, "DMAP", None),
        ):
            if proto is None:
                continue
            service = conf.get_service(proto)
            if service is not None:
                try:
                    service.enabled = False
                except Exception:
                    pass

    async def _connect(self, ident: str) -> Any:
        if pyatv is None:
            raise RuntimeError(f"pyatv not installed: {_IMPORT_ERROR}")
        saved = next(
            (
                d
                for d in load_devices().get("devices") or []
                if isinstance(d, dict) and str(d.get("id")) == ident
            ),
            None,
        )
        if not saved:
            raise RuntimeError("Device is not paired — pair it in Settings → UniFi")
        # Scan for RAOP/AirPlay only — avoids pulling in Companion noise.
        scan_protocol = None
        if Protocol is not None:
            scan_protocol = {Protocol.RAOP, Protocol.AirPlay}
        devices = await pyatv.scan(self.loop, timeout=6.0, protocol=scan_protocol)
        conf = next((d for d in devices if str(d.identifier) == ident), None)
        if conf is None:
            # Fallback: full scan in case identifier lives on another service.
            devices = await pyatv.scan(self.loop, timeout=6.0)
            conf = next((d for d in devices if str(d.identifier) == ident), None)
        if conf is None:
            raise RuntimeError(f"{saved.get('name') or ident} not found on the LAN")
        # Prefer RAOP — that is what stream_file needs.
        try:
            protocol = self._pick_protocol(conf)
        except Exception:
            protocol = Protocol.RAOP if Protocol is not None else None
        creds = saved.get("credentials")
        if creds:
            for proto in (Protocol.RAOP, Protocol.AirPlay):
                try:
                    conf.set_credentials(proto, creds)
                except Exception:
                    pass

        self._disable_non_stream_protocols(conf)

        # Disable AirPlay→MRP remote-control tunnel (common HomePod connect failure).
        storage = None
        if MemoryStorage is not None and MrpTunnel is not None:
            storage = MemoryStorage()
            settings = await storage.get_settings(conf)
            settings.protocols.airplay.mrp_tunnel = MrpTunnel.Disable

        try:
            atv = await pyatv.connect(conf, self.loop, storage=storage)
        except Exception as first_exc:
            # Last resort: disable AirPlay service entirely if RAOP is present.
            msg = str(first_exc)
            airplay_svc = conf.get_service(Protocol.AirPlay) if Protocol else None
            raop_svc = conf.get_service(Protocol.RAOP) if Protocol else None
            if (
                airplay_svc is not None
                and raop_svc is not None
                and "remote control" in msg.lower()
            ):
                log(f"retry connect RAOP-only after: {first_exc}")
                try:
                    airplay_svc.enabled = False
                except Exception:
                    pass
                atv = await pyatv.connect(conf, self.loop, storage=storage)
            else:
                raise

        # Persist credentials discovered after an open-LAN connect so later
        # streams keep working if HomeKit tightens access.
        try:
            if protocol is not None:
                service = conf.get_service(protocol)
                new_creds = getattr(service, "credentials", None) if service else None
                if new_creds and new_creds != creds:
                    saved_row = dict(saved)
                    saved_row["credentials"] = new_creds
                    upsert_device(saved_row)
        except Exception as exc:
            log(f"credential refresh skipped for {ident}: {exc}")
        return atv

    async def stream(self, ident: str, source: str, volume: int | None = None) -> dict[str, Any]:
        await self.stop(ident)
        source = str(source or "").strip()
        if not source:
            raise RuntimeError("No audio source for HomePod stream")
        if not source.startswith(("http://", "https://", "-")) and not Path(source).is_file():
            raise RuntimeError(f"Audio source not found: {source}")

        # Connect first, then open ffmpeg pipes — otherwise stdout fills while
        # scan/connect runs and the decoder stalls.
        try:
            atv = await self._connect(ident)
        except Exception as exc:
            raise RuntimeError(f"Could not connect to HomePod: {exc}") from exc

        if not hasattr(atv, "stream") or atv.stream is None:
            try:
                atv.close()
            except Exception:
                pass
            raise RuntimeError("HomePod connection has no audio stream interface (RAOP)")

        error_box: dict[str, Any] = {"error": None}
        playable_box: dict[str, Any] = {"playable": None}

        async def _play() -> None:
            try:
                if volume is not None and hasattr(atv, "audio") and atv.audio is not None:
                    try:
                        await atv.audio.set_volume(float(max(0, min(100, volume))))
                    except Exception as exc:
                        log(f"volume set (pre) failed for {ident}: {exc}")

                async def _bump_volume() -> None:
                    if volume is None or not hasattr(atv, "audio") or atv.audio is None:
                        return
                    try:
                        await asyncio.sleep(0.4)
                        await atv.audio.set_volume(float(max(0, min(100, volume))))
                    except Exception as exc:
                        log(f"volume set (post) failed for {ident}: {exc}")

                asyncio.create_task(_bump_volume())
                playable = await self._prepare_playable(ident, source)
                playable_box["playable"] = playable
                label = playable if isinstance(playable, str) else f"pipe:{source}"
                log(f"stream_file start id={ident} source={label}")
                await atv.stream.stream_file(playable)
                log(f"stream_file finished id={ident}")
            except asyncio.CancelledError:
                raise
            except Exception as exc:
                error_box["error"] = str(exc)
                log(f"stream ended with error for {ident}: {exc}\n{traceback.format_exc()}")
            finally:
                try:
                    atv.close()
                except Exception:
                    pass
                self._cleanup_helpers(ident)
                with self._lock:
                    cur = self._streams.get(ident)
                    if cur and cur.get("task") is asyncio.current_task():
                        self._streams.pop(ident, None)

        task = asyncio.create_task(_play())
        with self._lock:
            self._streams[ident] = {
                "task": task,
                "atv": atv,
                "source": source,
                "orig_source": source,
                "started_at": time.time(),
                "volume": volume,
            }
        # Catch immediate *failures* (bad format, auth, pipe) — not short clips.
        await asyncio.sleep(1.6)
        if task.done():
            with self._lock:
                self._streams.pop(ident, None)
            err = error_box.get("error")
            if err:
                self._cleanup_helpers(ident)
                raise RuntimeError(err)
            return {
                "ok": True,
                "id": ident,
                "playing": False,
                "finished": True,
                "source": source,
            }
        return {"ok": True, "id": ident, "playing": True, "source": source}

    async def stop(self, ident: str | None = None) -> dict[str, Any]:
        with self._lock:
            if ident:
                items = [(ident, self._streams.get(ident))]
            else:
                items = list(self._streams.items())
        stopped: list[str] = []
        for key, state in items:
            if not state:
                self._cleanup_helpers(key)
                continue
            task = state.get("task")
            atv = state.get("atv")
            if task and not task.done():
                task.cancel()
                try:
                    await task
                except Exception:
                    pass
            if atv is not None:
                try:
                    atv.close()
                except Exception:
                    pass
            self._cleanup_helpers(key)
            with self._lock:
                self._streams.pop(key, None)
            stopped.append(key)
        if ident is None:
            # Belt-and-braces: clear any orphan remux processes.
            for key in list(self._remux.keys()):
                self._cleanup_helpers(key)
        return {"ok": True, "stopped": stopped}

    async def set_volume(self, ident: str | None, volume: int) -> dict[str, Any]:
        vol = max(0, min(100, int(volume)))
        with self._lock:
            if ident:
                items = [(ident, self._streams.get(ident))]
            else:
                items = list(self._streams.items())
        updated: list[str] = []
        errors: list[str] = []
        for key, state in items:
            if not state:
                if ident:
                    errors.append(f"not playing ({key})")
                continue
            atv = state.get("atv")
            if atv is None or not hasattr(atv, "audio") or atv.audio is None:
                errors.append(f"no volume control ({key})")
                continue
            try:
                await atv.audio.set_volume(float(vol))
                state["volume"] = vol
                updated.append(key)
            except Exception as exc:
                errors.append(f"{key}: {exc}")
        return {"ok": errors == [] or updated != [], "updated": updated, "errors": errors, "volume": vol}

    def status(self) -> dict[str, Any]:
        with self._lock:
            playing = [
                {
                    "id": key,
                    "source": row.get("source"),
                    "started_at": row.get("started_at"),
                    "volume": row.get("volume"),
                }
                for key, row in self._streams.items()
            ]
        return {
            "ok": True,
            "version": AGENT_VERSION,
            "pyatv": pyatv is not None,
            "import_error": _IMPORT_ERROR or None,
            "devices": load_devices().get("devices") or [],
            "playing": playing,
            "pairing": list(self._pairings.keys()),
        }


RUNTIME = HomepodRuntime()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt: str, *args: Any) -> None:
        log("http " + (fmt % args))

    def _read_json(self) -> dict[str, Any]:
        length = int(self.headers.get("Content-Length") or "0")
        raw = self.rfile.read(length) if length > 0 else b"{}"
        try:
            data = json.loads(raw.decode("utf-8") or "{}")
        except json.JSONDecodeError:
            return {}
        return data if isinstance(data, dict) else {}

    def _write(self, payload: dict[str, Any], code: int = 200) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:  # noqa: N802
        self._write(RUNTIME.status())

    def do_POST(self) -> None:  # noqa: N802
        body = self._read_json()
        op = str(body.get("op") or body.get("action") or "").strip().lower()
        try:
            if op in ("ping", "status"):
                self._write(RUNTIME.status())
                return
            if op == "scan":
                rows = RUNTIME.call(RUNTIME.scan(float(body.get("timeout") or 5.0)), timeout=30.0)
                self._write({"ok": True, "devices": rows})
                return
            if op == "devices":
                saved = load_devices().get("devices") or []
                public = []
                for row in saved:
                    if not isinstance(row, dict):
                        continue
                    item = dict(row)
                    item["credentials"] = bool(item.get("credentials"))
                    public.append(item)
                self._write({"ok": True, "devices": public})
                return
            if op == "enable":
                ident = str(body.get("id") or "")
                enabled = bool(body.get("enabled", True))
                data = load_devices()
                changed = False
                for row in data.get("devices") or []:
                    if isinstance(row, dict) and str(row.get("id")) == ident:
                        row["enabled"] = enabled
                        changed = True
                if not changed:
                    self._write({"ok": False, "error": "Unknown device"}, 404)
                    return
                save_devices(data)
                self._write({"ok": True, "id": ident, "enabled": enabled})
                return
            if op == "remove":
                ident = str(body.get("id") or "")
                RUNTIME.call(RUNTIME.stop(ident), timeout=15.0)
                payload = remove_device(ident)
                public = []
                for row in payload.get("devices") or []:
                    if isinstance(row, dict):
                        item = dict(row)
                        item["credentials"] = bool(item.get("credentials"))
                        public.append(item)
                self._write({"ok": True, "devices": public})
                return
            if op in ("pair_start", "pair-start"):
                ident = str(body.get("id") or "")
                result = RUNTIME.call(RUNTIME.pair_start(ident), timeout=45.0)
                self._write(result)
                return
            if op in ("pair_finish", "pair-finish", "pair_pin"):
                ident = str(body.get("id") or "")
                pin = str(body.get("pin") or body.get("code") or "")
                result = RUNTIME.call(RUNTIME.pair_finish(ident, pin), timeout=45.0)
                self._write(result)
                return
            if op in ("add", "add_without_pin", "pair_skip"):
                ident = str(body.get("id") or "")
                result = RUNTIME.call(RUNTIME.add_without_pin(ident), timeout=45.0)
                self._write(result)
                return
            if op == "stream":
                ident = str(body.get("id") or "")
                source = str(body.get("source") or body.get("path") or body.get("url") or "")
                if not ident or not source:
                    self._write({"ok": False, "error": "id and source required"}, 400)
                    return
                volume = body.get("volume")
                vol = int(volume) if volume is not None and str(volume).strip() != "" else None
                result = RUNTIME.call(RUNTIME.stream(ident, source, vol), timeout=30.0)
                self._write(result)
                return
            if op == "stop":
                ident = body.get("id")
                result = RUNTIME.call(
                    RUNTIME.stop(str(ident) if ident else None),
                    timeout=20.0,
                )
                self._write(result)
                return
            if op == "stop_all":
                result = RUNTIME.call(RUNTIME.stop(None), timeout=20.0)
                self._write(result)
                return
            if op in ("volume", "set_volume"):
                ident = body.get("id")
                volume = body.get("volume")
                if volume is None or str(volume).strip() == "":
                    self._write({"ok": False, "error": "volume required"}, 400)
                    return
                result = RUNTIME.call(
                    RUNTIME.set_volume(str(ident) if ident else None, int(volume)),
                    timeout=15.0,
                )
                self._write(result)
                return
            self._write({"ok": False, "error": f"Unknown op: {op}"}, 400)
        except Exception as exc:
            log(f"op={op} error: {exc}\n{traceback.format_exc()}")
            self._write({"ok": False, "error": str(exc)}, 500)


def main() -> int:
    if pyatv is None:
        log(f"WARNING: pyatv import failed: {_IMPORT_ERROR}")
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    server = ThreadingHTTPServer((HOST, AGENT_PORT), Handler)
    log(f"HomePod agent listening on http://{HOST}:{AGENT_PORT} version={AGENT_VERSION}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        try:
            RUNTIME.call(RUNTIME.stop(None), timeout=5.0)
        except Exception:
            pass
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
