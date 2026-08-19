#!/usr/bin/env python3
"""Persistent Yarbo MQTT agent — one live broker connection.

Does not claim get_controller on connect. Watching telemetry must not stop a job.
Controller is claimed for explicit Controller/Lights or for work commands.
Start plan / dock keep the robot awake until the job latches, then stop poking.

Talks JSON-lines over TCP 127.0.0.1:8765 (same protocol as scripts/mqtt_agent.php).

Usage:
  .venv/bin/python scripts/mqtt_agent.py
  # or via ./scripts/dev.sh
"""

from __future__ import annotations

import asyncio
import json
import os
import subprocess
import sys
import time
import traceback
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]

# Match home-assistant-yarbo / python-yarbo exactly (no refresh — refresh flickers).
COMMUNITY_LIGHTS_ON = {
    "led_head": 255,
    "led_left_w": 255,
    "led_right_w": 255,
    "body_left_r": 255,
    "body_right_r": 255,
    "tail_left_r": 255,
    "tail_right_r": 255,
}
COMMUNITY_LIGHTS_OFF = {k: 0 for k in COMMUNITY_LIGHTS_ON}

# Soft keepalive: re-wake / re-light without get_controller (get_controller speaks every time).
CONTROLLER_KEEPALIVE_S = 40.0
CONTROLLER_KEEPALIVE_MIN_GAP_S = 15.0
# Firmware drops back to idle ~0.5s after a single wake. Hold awake until the job latches.
WORK_STARTUP_S = 25.0
WORK_STARTUP_KEEPALIVE_GAP_S = 1.5
WORK_STARTUP_CMDS = frozenset({
    "start_plan",
    "cmd_recharge",
    "start_way_point",
    "resume",
})
JOB_LATCH_KEYS = (
    "on_going_planning",
    "on_going_recharging",
    "on_going_to_start_point",
)


def job_is_latched(raw: Any) -> bool:
    if not isinstance(raw, dict):
        return False
    state_msg = raw.get("StateMSG") if isinstance(raw.get("StateMSG"), dict) else {}
    for key in JOB_LATCH_KEYS:
        try:
            if int(state_msg.get(key) or 0) != 0:
                return True
        except (TypeError, ValueError):
            continue
    return False


def mark_job_from_raw(state: dict[str, Any], raw: Any) -> None:
    if not state.get("work_hold") or state.get("work_latched"):
        return
    if job_is_latched(raw):
        state["work_latched"] = True
        state["keepalive_soon"] = False
        log("Work job latched (planning/docking); stopping startup wake")


def wants_wakeup(state: dict[str, Any]) -> bool:
    """Soft-wake (set_working_state=1) for lights / drive / Controller On.

    After start_plan / dock, also wake until the job actually starts, then stop
    so keepalive does not keep re-entering app-awake over a running job.
    """
    if state.get("lights_on") or state.get("manual_drive") or state.get("control_session"):
        return True
    if state.get("work_latched"):
        return False
    if state.get("work_hold"):
        started = float(state.get("work_started_at") or 0)
        return started > 0 and (time.time() - started) < WORK_STARTUP_S
    return False


def keepalive_min_gap(state: dict[str, Any]) -> float:
    if state.get("work_hold") and not state.get("work_latched"):
        return WORK_STARTUP_KEEPALIVE_GAP_S
    return CONTROLLER_KEEPALIVE_MIN_GAP_S


def session_mode(req: dict[str, Any]) -> str:
    mode = str(req.get("session") or "control").strip().lower()
    return "work" if mode == "work" else "control"


def log(msg: str) -> None:
    print(f"[{time.strftime('%H:%M:%S', time.gmtime())}] {msg}", file=sys.stderr, flush=True)


def load_config() -> dict[str, Any]:
    config_php = ROOT / "config.php"
    if not config_php.is_file():
        raise SystemExit("config.php not found")
    raw = subprocess.check_output(
        ["php", "-r", "echo json_encode(require $argv[1]);", str(config_php)],
        cwd=str(ROOT),
    )
    data = json.loads(raw.decode())
    if not isinstance(data, dict):
        raise SystemExit("config.php did not return an array")
    return data


def fix_buzzer_payload(cmd: str, payload: dict[str, Any]) -> dict[str, Any]:
    if cmd != "cmd_buzzer":
        return payload
    out = dict(payload)
    ts = out.get("timeStamp", out.get("timestamp"))
    if ts is None or (isinstance(ts, (int, float)) and ts < 10_000_000_000):
        out["timeStamp"] = int(time.time() * 1000)
    return out


async def force_controller(client: Any) -> dict[str, Any]:
    """Force get_controller — robot announces. Only for explicit Controller ON."""
    try:
        client._controller_acquired = False  # noqa: SLF001
    except Exception:
        pass
    result = await client.get_controller()
    await asyncio.sleep(0.45)
    raw = getattr(result, "raw", None) or {}
    return raw if isinstance(raw, dict) else {"msg": str(result)}


async def wake_for_control(client: Any) -> None:
    """Leave idle so lights/actions are not dropped after ~0.5s (no voice prompt)."""
    await client.publish_command(
        "set_working_state",
        {"state": 1, "source": "smart_home"},
    )
    await asyncio.sleep(0.2)


async def sleep_from_control(client: Any) -> None:
    """Return robot to idle when panel releases controller hold."""
    try:
        await client.publish_command("set_working_state", {"state": 0})
    except Exception as e:
        log(f"set_working_state 0 failed: {e}")


async def ensure_controller_role(client: Any) -> None:
    """Claim get_controller if the SDK flag was lost — no working_state change."""
    if getattr(client, "controller_acquired", False):
        return
    await client.get_controller()
    await asyncio.sleep(0.45)


async def ensure_session(client: Any) -> None:
    """Keep MQTT command session usable without re-announcing controller.

    get_controller only if the SDK flag was lost (reconnect). Otherwise wake only.
    """
    await ensure_controller_role(client)
    await wake_for_control(client)


async def claim_controller(client: Any) -> dict[str, Any]:
    """Explicit Controller ON: announce once + wake."""
    ack = await force_controller(client)
    await wake_for_control(client)
    return ack


async def assert_lights_on(client: Any) -> None:
    await ensure_session(client)
    await client.publish_command("light_ctrl", COMMUNITY_LIGHTS_ON)


async def apply_command_session(
    client: Any,
    state: dict[str, Any],
    session: str,
    cmds: list[str] | None = None,
) -> None:
    """Claim controller, then wake for control or hold awake until a job latches."""
    if session == "work":
        await ensure_controller_role(client)
        if state.get("manual_drive"):
            try:
                await client.publish_command("cmd_vel", {"vel": 0.0, "rev": 0.0})
            except Exception as e:
                log(f"cmd_vel stop before work failed: {e}")
            state["manual_drive"] = False
        # Firmware drops start_plan / cmd_recharge while idle (working_state 0).
        await wake_for_control(client)
        state["hold_controller"] = True
        state["control_session"] = False
        state["work_hold"] = True
        state["last_keepalive"] = time.time()
        cmd_names = [str(c) for c in (cmds or []) if c]
        if any(c in WORK_STARTUP_CMDS for c in cmd_names):
            state["work_latched"] = False
            state["work_started_at"] = time.time()
            state["keepalive_soon"] = True
            state["quiet_until"] = time.time() + 4.0
            log("Work session: controller + wake; hold awake until the job latches")
        else:
            state["work_latched"] = True
            state["keepalive_soon"] = False
            log("Work session: controller + wake once (no startup hold)")
        return
    state["work_hold"] = False
    state["work_latched"] = False
    await ensure_session(client)
    state["hold_controller"] = True
    state["control_session"] = True
    state["last_keepalive"] = time.time()


def parse_controller_reality(raw: dict[str, Any]) -> dict[str, Any]:
    state_msg = raw.get("StateMSG") if isinstance(raw.get("StateMSG"), dict) else {}
    working = state_msg.get("working_state")
    led = raw.get("LedInfoMSG") if isinstance(raw.get("LedInfoMSG"), dict) else {}
    white = 0.0
    for channel in ("led_head", "led_left_w", "led_right_w"):
        if channel in led and isinstance(led[channel], (int, float)):
            white = max(white, float(led[channel]))
    # joy_usb/joy_state are hub health flags on this firmware — not "gamepad holding control".
    return {
        "car_controller": bool(state_msg.get("car_controller")),
        "machine_controller": (
            int(state_msg["machine_controller"])
            if state_msg.get("machine_controller") is not None
            else None
        ),
        "working_state": int(working) if working is not None else None,
        "control_awake": int(working or 0) == 1,
        "white_leds": white,
        "joy_connected": False,
    }


def state_flags(state: dict[str, Any], client: Any | None = None) -> dict[str, Any]:
    out: dict[str, Any] = {
        "hold_controller": bool(state.get("hold_controller")),
        "lights_on": bool(state.get("lights_on")),
    }
    if client is not None:
        out["controller_acquired"] = bool(getattr(client, "controller_acquired", False))
    if isinstance(state.get("last_raw"), dict):
        out.update(parse_controller_reality(state["last_raw"]))
    return out


async def read_controller_reality(client: Any, state: dict[str, Any]) -> dict[str, Any]:
    try:
        snap = await client.get_status(timeout=2.5, acquire_controller=False)
        if snap and getattr(snap, "raw", None):
            state["last_raw"] = snap.raw
            mark_job_from_raw(state, snap.raw)
            return parse_controller_reality(snap.raw)
    except Exception:
        pass
    if isinstance(state.get("last_raw"), dict):
        return parse_controller_reality(state["last_raw"])
    return {
        "car_controller": False,
        "machine_controller": None,
        "working_state": None,
        "control_awake": False,
        "white_leds": 0.0,
        "joy_connected": False,
    }


async def soft_keepalive(client: Any, state: dict[str, Any]) -> None:
    """Quiet hold: wake (+ lights) without get_controller speech."""
    if state.get("work_hold") and not state.get("lights_on") and not state.get("control_session"):
        await wake_for_control(client)
        return
    await ensure_session(client)
    if state.get("lights_on"):
        await client.publish_command("light_ctrl", COMMUNITY_LIGHTS_ON)


def dock_block_reason(raw: dict[str, Any] | None) -> str | None:
    """Only trust StateMSG.charging_status — BodyMsg.recharge_state is unreliable."""
    if not isinstance(raw, dict):
        return None
    state_msg = raw.get("StateMSG") if isinstance(raw.get("StateMSG"), dict) else {}
    try:
        charging = int(state_msg.get("charging_status") or 0)
    except (TypeError, ValueError):
        return None
    if charging > 0:
        return (
            f"Robot reports charging_status={charging}. "
            "Unplug / leave the charger before manual drive."
        )
    return None


def motion_fault_warning(raw: dict[str, Any] | None) -> str | None:
    """Surface firmware fault flags that often coincide with dead cmd_vel / buzzer."""
    if not isinstance(raw, dict):
        return None
    body = raw.get("BodyMsg") if isinstance(raw.get("BodyMsg"), dict) else {}
    abnormal = raw.get("abnormal_msg") if isinstance(raw.get("abnormal_msg"), dict) else {}
    power = body.get("power_fault_state")
    if power is None:
        power = abnormal.get("power_fault")
    try:
        power_i = int(power) if power is not None else 0
    except (TypeError, ValueError):
        power_i = 0
    if power_i > 0:
        return (
            f"Robot reports power_fault={power_i}. "
            "Chassis/buzzer may stay locked until this clears (check app / reboot robot)."
        )
    return None


async def handle_request(
    client: Any,
    req: dict[str, Any],
    state: dict[str, Any],
) -> dict[str, Any]:
    from yarbo.exceptions import YarboNotControllerError, YarboTimeoutError

    op = str(req.get("op") or "")

    if op == "ping":
        return {
            "ok": True,
            "controller": bool(getattr(client, "controller_acquired", False)),
            "connected": bool(getattr(client, "is_connected", False)),
            "engine": "python-yarbo",
            "telemetry": True,
            **state_flags(state, client),
        }

    if not getattr(client, "is_connected", False):
        return {"ok": False, "error": "MQTT not connected"}

    try:
        if op == "telemetry":
            timeout = float(req.get("timeout") or 4.0)
            want_wifi = bool(req.get("wifi", True))
            # Quiet window after lights / work start — do not poke the robot.
            quiet_until = float(state.get("quiet_until") or 0)
            if time.time() < quiet_until:
                if isinstance(state.get("last_raw"), dict):
                    return {
                        "ok": True,
                        "op": "telemetry",
                        "raw": state["last_raw"],
                        "wifi": state.get("last_wifi"),
                        "cached": True,
                        **state_flags(state, client),
                    }
                return {
                    "ok": False,
                    "error": "telemetry quiet after command",
                    "transient": True,
                    **state_flags(state, client),
                }

            status = await client.get_status(timeout=timeout, acquire_controller=False)
            if status is None:
                if isinstance(state.get("last_raw"), dict):
                    return {
                        "ok": True,
                        "op": "telemetry",
                        "raw": state["last_raw"],
                        "wifi": state.get("last_wifi"),
                        "cached": True,
                        **state_flags(state, client),
                    }
                return {"ok": False, "error": "telemetry timeout", "transient": True}
            raw = getattr(status, "raw", None) or {}
            state["last_raw"] = raw
            mark_job_from_raw(state, raw)
            working_state = (raw.get("StateMSG") or {}).get("working_state")
            # Quiet soft-wake soon if a lights/drive session fell idle (not a latched job).
            if wants_wakeup(state) and working_state == 0:
                state["keepalive_soon"] = True
            if (
                state.get("hold_controller")
                and not state.get("work_hold")
                and not getattr(client, "controller_acquired", False)
            ):
                try:
                    await ensure_controller_role(client)
                    log("Re-claimed controller after session loss (quiet)")
                except Exception as e:
                    log(f"Quiet re-claim failed: {e}")
            wifi = None
            if want_wifi:
                try:
                    wifi = await client.get_connected_wifi(timeout=min(1.5, timeout))
                    state["last_wifi"] = wifi
                except Exception:
                    wifi = state.get("last_wifi")
            return {
                "ok": True,
                "op": "telemetry",
                "raw": raw,
                "wifi": wifi,
                **state_flags(state, client),
            }

        if op == "controller":
            on = bool(req.get("on"))
            if on:
                result = await claim_controller(client)
                state["hold_controller"] = True
                state["control_session"] = True
                state["work_hold"] = False
                state["work_latched"] = False
                state["manual_drive"] = False
                state["keepalive_soon"] = False
                state["last_keepalive"] = time.time()
                robot = await read_controller_reality(client, state)
                log(
                    "Controller HOLD on "
                    f"(single get_controller + wake; awake={robot.get('control_awake')} "
                    f"working={robot.get('working_state')} msg={result.get('msg')!r})"
                )
                return {
                    "ok": True,
                    "op": "controller",
                    "on": True,
                    "ack_msg": result.get("msg"),
                    **state_flags(state, client),
                    **robot,
                }

            # Releasing a lights/drive session returns to idle. A work hold
            # must not send set_working_state 0 — that would stop a running plan.
            had_control_session = bool(
                state.get("control_session")
                or state.get("lights_on")
                or state.get("manual_drive")
            )
            if state.get("lights_on"):
                try:
                    await client.publish_command("light_ctrl", COMMUNITY_LIGHTS_OFF)
                except Exception as e:
                    log(f"Lights off during controller release failed: {e}")
                state["lights_on"] = False
            if had_control_session:
                await sleep_from_control(client)
            state["hold_controller"] = False
            state["control_session"] = False
            state["work_hold"] = False
            state["work_latched"] = False
            state["manual_drive"] = False
            state["keepalive_soon"] = False
            try:
                client._controller_acquired = False  # noqa: SLF001
            except Exception:
                pass
            log(
                "Controller HOLD off"
                + (" (idle + stop keepalive)" if had_control_session else " (quiet; work state unchanged)")
            )
            robot = await read_controller_reality(client, state)
            return {
                "ok": True,
                "op": "controller",
                "on": False,
                **state_flags(state, client),
                **robot,
            }

        if op == "lights":
            on = bool(req.get("on"))

            # Charging (any status > 0): firmware often only flashes lights ~0.5s then drops them.
            charging_status = None
            try:
                snap = await client.get_status(timeout=2.5, acquire_controller=False)
                if snap and getattr(snap, "raw", None):
                    state["last_raw"] = snap.raw
                    charging_status = (snap.raw.get("StateMSG") or {}).get("charging_status")
            except Exception:
                pass

            if on:
                await assert_lights_on(client)
                state["lights_on"] = True
                state["hold_controller"] = True
                state["control_session"] = True
                state["keepalive_soon"] = False
                state["last_keepalive"] = time.time()
                log(f"Lights ON (wake + light_ctrl) charging_status={charging_status}")
            else:
                await ensure_session(client)
                await client.publish_command("light_ctrl", COMMUNITY_LIGHTS_OFF)
                state["lights_on"] = False
                state["keepalive_soon"] = False
                log(f"Lights OFF charging_status={charging_status}")

            state["quiet_until"] = time.time() + 3.0
            await asyncio.sleep(0.15)
            return {
                "ok": True,
                "op": "lights",
                "on": on,
                "charging_status": charging_status,
                "charging": bool(charging_status),
                **state_flags(state, client),
            }

        if op == "drive":
            linear = float(req.get("linear") or 0)
            angular = float(req.get("angular") or 0)
            moving = abs(linear) > 1e-6 or abs(angular) > 1e-6
            if not state.get("hold_controller"):
                return {
                    "ok": False,
                    "error": "Connect the Controller first, then use the drive pad.",
                }
            # Never get_controller here — that re-triggers "app controller connected".
            warning = None
            if moving:
                if not state.get("manual_drive"):
                    await ensure_session(client)
                    # FW 3.13 ignores string state "manual" (working_state unchanged).
                    # Keep awake with int 1; unlock any latched e-stop once per hold.
                    await client.publish_command(
                        "set_working_state",
                        {"state": 1, "source": "app"},
                    )
                    try:
                        await client.publish_command("emergency_unlock", {})
                    except Exception as e:
                        log(f"emergency_unlock failed: {e}")
                    await asyncio.sleep(0.15)
                    state["manual_drive"] = True
                    state["last_keepalive"] = time.time()
                    warning = dock_block_reason(
                        state.get("last_raw") if isinstance(state.get("last_raw"), dict) else None
                    )
                    if warning is None:
                        warning = motion_fault_warning(
                            state.get("last_raw") if isinstance(state.get("last_raw"), dict) else None
                        )
                    if warning:
                        log(f"Drive warning: {warning}")
                # Burst: robot often needs several cmd_vel frames (~10 Hz) before motion.
                for _ in range(3):
                    await client.publish_command("cmd_vel", {"vel": linear, "rev": angular})
                    await asyncio.sleep(0.05)
            else:
                await client.publish_command("cmd_vel", {"vel": 0.0, "rev": 0.0})

            out = {
                "ok": True,
                "op": "drive",
                "linear": linear,
                "angular": angular,
                **state_flags(state, client),
            }
            if warning:
                out["warning"] = warning
            return out

        if op == "buzzer":
            if not state.get("hold_controller"):
                return {
                    "ok": False,
                    "error": "Connect the Controller first, then use Buzzer.",
                }
            await ensure_session(client)
            state["last_keepalive"] = time.time()
            # Official SDK shape (ACK: "Sound settings updated successfully.") first.
            await client.publish_command(
                "set_sound_param",
                {"enable": True, "vol": 1.0, "mode": 0},
            )
            await asyncio.sleep(0.1)
            try:
                await client.set_sound_param(100, 1)
                await asyncio.sleep(0.05)
                await client.set_sound(100, 0)
                await asyncio.sleep(0.05)
            except Exception as e:
                log(f"set_sound* before buzzer failed: {e}")

            # Official Data SDK play-sound button path.
            await client.publish_command("song_cmd", {"song_name": "find yarbo"})
            await asyncio.sleep(0.4)
            # HA beep: hold buzzer on briefly (fire-and-forget, no data_feedback).
            await client.buzzer(state=1)
            await asyncio.sleep(2.0)
            await client.buzzer(state=0)
            for song_id in (0, 1, 2):
                try:
                    await client.play_song(song_id)
                    await asyncio.sleep(0.3)
                except Exception as e:
                    log(f"play_song({song_id}) failed: {e}")
            log("Buzzer: set_sound_param + find yarbo + hold 2s + songId 0..2")
            return {
                "ok": True,
                "op": "buzzer",
                "cmd": "cmd_buzzer",
                "note": (
                    "Sound commands sent. If you still hear nothing, check the robot "
                    "volume in the Yarbo app and whether power_fault is non-zero."
                ),
                **state_flags(state, client),
            }

        if op == "publish":
            cmd = str(req.get("cmd") or "")
            if not cmd:
                return {"ok": False, "error": "cmd required"}
            payload = req.get("payload") if isinstance(req.get("payload"), dict) else {}
            payload = fix_buzzer_payload(cmd, payload)
            await apply_command_session(client, state, session_mode(req), [cmd])
            await client.publish_command(cmd, payload)
            if cmd in WORK_STARTUP_CMDS:
                await asyncio.sleep(0.35)
                await wake_for_control(client)
                state["last_keepalive"] = time.time()
            await asyncio.sleep(0.15)
            return {"ok": True, "op": "publish", "cmd": cmd, **state_flags(state, client)}

        if op == "publish_variants":
            variants = req.get("variants")
            if not isinstance(variants, list) or not variants:
                return {"ok": False, "error": "variants required"}
            cmds: list[str] = []
            prepared: list[tuple[str, dict[str, Any]]] = []
            for variant in variants:
                if not isinstance(variant, dict) or "cmd" not in variant:
                    continue
                cmd = str(variant["cmd"])
                payload = variant.get("payload") if isinstance(variant.get("payload"), dict) else {}
                payload = fix_buzzer_payload(cmd, payload)
                cmds.append(cmd)
                prepared.append((cmd, payload))
            await apply_command_session(client, state, session_mode(req), cmds)
            last_cmd = ""
            for cmd, payload in prepared:
                await client.publish_command(cmd, payload)
                last_cmd = cmd
            if last_cmd in WORK_STARTUP_CMDS:
                await asyncio.sleep(0.35)
                await wake_for_control(client)
                state["last_keepalive"] = time.time()
            await asyncio.sleep(0.2)
            return {
                "ok": True,
                "op": "publish_variants",
                "cmd": last_cmd,
                **state_flags(state, client),
            }

        return {
            "ok": False,
            "error": "Unknown op. Valid: ping, telemetry, controller, lights, buzzer, drive, publish, publish_variants",
        }
    except YarboNotControllerError as e:
        return {
            "ok": False,
            "error": "Robot did not grant controller role. Close the Yarbo mobile app and try again.",
            "detail": str(e),
        }
    except YarboTimeoutError as e:
        try:
            client._controller_acquired = False  # noqa: SLF001
        except Exception:
            pass
        return {"ok": False, "error": str(e)}
    except Exception as e:
        log(f"Command error: {e}")
        traceback.print_exc(file=sys.stderr)
        try:
            client._controller_acquired = False  # noqa: SLF001
        except Exception:
            pass
        return {"ok": False, "error": str(e)}


async def controller_keepalive_loop(
    client: Any,
    lock: asyncio.Lock,
    state: dict[str, Any],
) -> None:
    """Soft hold while lights/drive/Controller On, and until a started job latches."""
    while True:
        await asyncio.sleep(0.5)
        if not getattr(client, "is_connected", False):
            continue
        if not wants_wakeup(state):
            continue
        now = time.time()
        since = now - float(state.get("last_keepalive") or 0)
        min_gap = keepalive_min_gap(state)
        if since < min_gap:
            continue
        work_startup = bool(state.get("work_hold") and not state.get("work_latched"))
        repeat_s = WORK_STARTUP_KEEPALIVE_GAP_S if work_startup else CONTROLLER_KEEPALIVE_S
        due = bool(state.get("keepalive_soon")) or since >= repeat_s
        if not due:
            continue
        try:
            async with lock:
                if not wants_wakeup(state):
                    continue
                await soft_keepalive(client, state)
                state["last_keepalive"] = time.time()
                state["keepalive_soon"] = False
                if state.get("lights_on"):
                    state["quiet_until"] = time.time() + 2.0
                log(
                    "Controller keepalive: soft wake"
                    + (" + light_ctrl" if state.get("lights_on") else "")
                    + (" (work startup)" if work_startup else "")
                    + " (no get_controller)"
                )
        except Exception as e:
            log(f"Controller keepalive failed: {e}")
            try:
                client._controller_acquired = False  # noqa: SLF001
            except Exception:
                pass


async def handle_client(
    reader: asyncio.StreamReader,
    writer: asyncio.StreamWriter,
    client: Any,
    lock: asyncio.Lock,
    state: dict[str, Any],
) -> None:
    try:
        while True:
            line = await reader.readline()
            if not line:
                break
            text = line.decode("utf-8", errors="replace").strip()
            if not text:
                continue
            try:
                req = json.loads(text)
            except json.JSONDecodeError:
                resp: dict[str, Any] = {"ok": False, "error": "Invalid JSON"}
                writer.write((json.dumps(resp) + "\n").encode())
                await writer.drain()
                continue

            req_id = req.get("id") if isinstance(req, dict) else None
            async with lock:
                if not isinstance(req, dict):
                    resp = {"ok": False, "error": "Invalid JSON"}
                else:
                    resp = await handle_request(client, req, state)
            if req_id is not None:
                resp["id"] = req_id
            writer.write((json.dumps(resp, separators=(",", ":")) + "\n").encode())
            await writer.drain()
    except Exception as e:
        log(f"Client handler error: {e}")
    finally:
        try:
            writer.close()
            await writer.wait_closed()
        except Exception:
            pass


async def connect_mqtt(client: Any) -> None:
    delay = 1.0
    while True:
        try:
            if not getattr(client, "is_connected", False):
                await client.connect()
                log("MQTT connected (controller not held until panel requests it)")
            try:
                await client.start_polling(interval_seconds=15.0, acquire_controller=False)
                log("Telemetry polling started")
            except Exception as e:
                log(f"WARNING: start_polling failed: {e}")
            return
        except Exception as e:
            log(f"MQTT connect failed: {e} (retry in {delay:.0f}s)")
            await asyncio.sleep(delay)
            delay = min(15.0, delay * 1.5)


async def amain() -> int:
    try:
        from yarbo.local import YarboLocalClient
    except ImportError:
        log("python-yarbo is not installed. Run: .venv/bin/pip install python-yarbo")
        return 1

    config = load_config()
    host = str(config.get("broker_host") or "")
    port = int(config.get("broker_port") or 1883)
    serial = str(config.get("serial") or "")
    agent_port = int(os.environ.get("YARBO_MQTT_AGENT_PORT") or 8765)

    if not host or not serial:
        log("broker_host and serial must be set in config.php")
        return 1

    # Bind the local control port before MQTT connect so the panel cannot
    # spawn a PHP fallback agent onto 8765 while we are still reaching the robot.
    lock = asyncio.Lock()
    state: dict[str, Any] = {
        "hold_controller": False,
        "control_session": False,
        "work_hold": False,
        "work_latched": False,
        "work_started_at": 0.0,
        "lights_on": False,
        "manual_drive": False,
        "last_raw": None,
        "last_wifi": None,
        "quiet_until": 0.0,
        "last_keepalive": 0.0,
        "keepalive_soon": False,
    }
    client = YarboLocalClient(broker=host, sn=serial, port=port, auto_controller=False)

    async def _on_client(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        await handle_client(reader, writer, client, lock, state)

    try:
        server = await asyncio.start_server(_on_client, "127.0.0.1", agent_port)
    except OSError as e:
        log(f"Could not bind 127.0.0.1:{agent_port}: {e}")
        return 1

    sockets = ", ".join(str(s.getsockname()) for s in (server.sockets or []))
    log(f"Agent listening on {sockets} (MQTT connecting {host}:{port} SN={serial})")

    asyncio.create_task(connect_mqtt(client))
    asyncio.create_task(controller_keepalive_loop(client, lock, state))

    async with server:
        await server.serve_forever()

    return 0


def main() -> None:
    try:
        raise SystemExit(asyncio.run(amain()))
    except KeyboardInterrupt:
        raise SystemExit(0)


if __name__ == "__main__":
    main()
