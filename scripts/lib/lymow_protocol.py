#!/usr/bin/env python3
"""Slim Lymow MQTT protobuf decode.

Field numbers from the MIT-licensed ha-lymow reverse-engineering
(https://github.com/8408323/ha-lymow). Copyright (c) 2026 Jonathan Haraldsson.
"""

from __future__ import annotations

import base64
import json
import struct
from typing import Any

PB_VERSION = 49

# MQTT userCtrl codes (ha-lymow / Android traffic capture).
USER_CTRL_CLEAN = 1  # start mow (with map payload = selected zones)
USER_CTRL_DOCK = 2  # dock and forget task progress (destructive)
USER_CTRL_PAUSE = 3
USER_CTRL_RESUME = 4
USER_CTRL_QUERY_MAP = 19
USER_CTRL_FORCE_REINIT = 28  # Cancel Task: stop in place, clear task (does not dock)
USER_CTRL_RECHARGE_DOCK = 33  # dock and keep task progress
USER_CTRL_QUERY_WIFI_4G = 52  # read-only status poke

USER_CTRL_ACTIONS = {
    "start": USER_CTRL_CLEAN,
    "pause": USER_CTRL_PAUSE,
    "resume": USER_CTRL_RESUME,
    "dock": USER_CTRL_RECHARGE_DOCK,
    "cancel": USER_CTRL_FORCE_REINIT,
}

# PbMap content field (inside PbOutput.f23 → f2 → f3).
_MAP_CONTENT_GO_ZONES = 1


def _encode_varint(value: int) -> bytes:
    value &= 0xFFFFFFFFFFFFFFFF
    out = bytearray()
    while True:
        byte = value & 0x7F
        value >>= 7
        if value:
            out.append(byte | 0x80)
        else:
            out.append(byte)
            break
    return bytes(out)


def _field_i32(field_no: int, value: int) -> bytes:
    return _encode_varint((field_no << 3) | 0) + _encode_varint(value & 0xFFFFFFFFFFFFFFFF)


def _field_bytes(field_no: int, data: bytes) -> bytes:
    return _encode_varint((field_no << 3) | 2) + _encode_varint(len(data)) + data


def _field_str(field_no: int, value: str) -> bytes:
    return _field_bytes(field_no, value.encode("utf-8"))


def wrap_envelope(pb_bytes: bytes) -> str:
    return json.dumps({"message": base64.b64encode(pb_bytes).decode()})


def encode_userctrl(command: int) -> bytes:
    return _field_i32(2, PB_VERSION) + _field_i32(5, command)


def encode_status_query() -> bytes:
    """Read-only Wi-Fi/4G query the app sends at startup; reply includes robot info."""
    return encode_userctrl(USER_CTRL_QUERY_WIFI_4G)


def encode_query_map(query_index: int = 0) -> bytes:
    """Ask the robot for the live map / zone list (USER_CTRL_QUERY_MAP=19)."""
    sub = _field_i32(1, query_index) + _field_i32(4, 1)
    return _field_i32(2, PB_VERSION) + _field_i32(5, USER_CTRL_QUERY_MAP) + _field_bytes(23, sub)


def encode_start_zones(zone_hash_ids: list[str]) -> bytes:
    """Start mowing specific go-zones by hashId (ha-lymow encode_start_zones)."""
    ids = [str(z).strip() for z in zone_hash_ids if str(z).strip()]
    if not ids:
        raise ValueError("Pick at least one Lymow zone")
    pb = _field_i32(2, PB_VERSION) + _field_i32(5, USER_CTRL_CLEAN)
    map_pb = b""
    for i, hash_id in enumerate(ids, start=1):
        basic_info = _field_str(3, hash_id) + _field_i32(8, i)
        zone = _field_bytes(1, basic_info)
        map_pb += _field_bytes(1, zone)
    return pb + _field_bytes(12, map_pb)


def encode_action(action: str, zone_hash_ids: list[str] | None = None) -> bytes:
    """Encode Pause / Resume / Dock / Cancel, or Start with required zone hash IDs."""
    key = str(action or "").strip().lower()
    if key not in USER_CTRL_ACTIONS:
        raise ValueError(f"Unknown Lymow action: {action!r} (use start, pause, resume, dock, cancel)")
    if key == "start":
        return encode_start_zones(list(zone_hash_ids or []))
    return encode_userctrl(USER_CTRL_ACTIONS[key])


def encode_app_connect_heartbeat(session_id: str) -> bytes:
    """Register as a connected app so the robot streams pboutput (ha-lymow)."""
    return _field_i32(7, 2) + _field_bytes(27, session_id.encode())


def unwrap_envelope(payload: str | bytes) -> bytes:
    if isinstance(payload, bytes):
        payload = payload.decode("utf-8", errors="replace")
    obj = json.loads(payload)
    for key in ("message", "value", "data", "payload"):
        if key in obj:
            return base64.b64decode(obj[key])
    raise ValueError("No known envelope key")


def _decode_varint(data: bytes, pos: int) -> tuple[int, int]:
    result = 0
    shift = 0
    while True:
        b = data[pos]
        pos += 1
        result |= (b & 0x7F) << shift
        if not (b & 0x80):
            break
        shift += 7
    return result, pos


def _signed32(v: int) -> int:
    v &= 0xFFFFFFFF
    if v >= 0x80000000:
        v -= 0x100000000
    return v


def _decode_packed_int32s(data: bytes) -> list[int]:
    pos = 0
    values: list[int] = []
    while pos < len(data):
        v, pos = _decode_varint(data, pos)
        values.append(_signed32(v))
    return values


def _decode_fields(data: bytes) -> list[tuple[int, int, Any]]:
    pos = 0
    fields: list[tuple[int, int, Any]] = []
    while pos < len(data):
        try:
            tag, pos = _decode_varint(data, pos)
        except (IndexError, ValueError):
            break
        field_no = tag >> 3
        wire_type = tag & 0x07
        try:
            if wire_type == 0:
                value, pos = _decode_varint(data, pos)
            elif wire_type == 1:
                value = struct.unpack_from("<Q", data, pos)[0]
                pos += 8
            elif wire_type == 2:
                length, pos = _decode_varint(data, pos)
                value = data[pos : pos + length]
                pos += length
            elif wire_type == 5:
                value = struct.unpack_from("<I", data, pos)[0]
                pos += 4
            else:
                break
        except (IndexError, struct.error):
            break
        fields.append((field_no, wire_type, value))
    return fields


def _first(fields: list[tuple[int, int, Any]], field_no: int, default: Any = None) -> Any:
    for fn, _wt, val in fields:
        if fn == field_no:
            return val
    return default


def _all(fields: list[tuple[int, int, Any]], field_no: int) -> list[Any]:
    return [val for fn, _wt, val in fields if fn == field_no]


def _decode_f32(raw: int) -> float:
    return struct.unpack("<f", struct.pack("<I", raw & 0xFFFFFFFF))[0]


def decode_map_zones(pb_bytes: bytes) -> list[dict[str, Any]]:
    """Slim go-zone list from a QUERY_MAP pboutput (hashId, name, enabled, area).

    Navigation path matches ha-lymow: PbOutput.f23 → f2 → f3 → goZones(f1).
    Names are the ones stored on the robot / Lymow app map.
    """
    top = _decode_fields(pb_bytes)
    outer_raw = _first(top, 23)
    if not isinstance(outer_raw, bytes):
        return []
    wrapper_raw = _first(_decode_fields(outer_raw), 2)
    if not isinstance(wrapper_raw, bytes):
        return []
    content_raw = _first(_decode_fields(wrapper_raw), 3)
    if not isinstance(content_raw, bytes):
        return []
    content = _decode_fields(content_raw)
    zones: list[dict[str, Any]] = []
    for zone_raw in _all(content, _MAP_CONTENT_GO_ZONES):
        if not isinstance(zone_raw, bytes):
            continue
        zf = _decode_fields(zone_raw)
        bi_raw = _first(zf, 1)
        if not isinstance(bi_raw, bytes):
            continue
        bi = _decode_fields(bi_raw)
        hash_raw = _first(bi, 3)
        hash_id = hash_raw.decode("utf-8", errors="replace") if isinstance(hash_raw, bytes) else ""
        if not hash_id:
            continue
        name_raw = _first(bi, 2)
        name = name_raw.decode("utf-8", errors="replace") if isinstance(name_raw, bytes) and name_raw else ""
        zone: dict[str, Any] = {
            "hash_id": hash_id,
            "name": name or hash_id,
            "enabled": bool(_first(bi, 4, 1)),
        }
        pp_raw = _first(zf, 3)
        if isinstance(pp_raw, bytes):
            area = _first(_decode_fields(pp_raw), 3)
            if area is not None:
                try:
                    zone["area_m2"] = float(area)
                except (TypeError, ValueError):
                    pass
        zones.append(zone)
    return zones


def decode_pboutput(pb_bytes: bytes) -> dict[str, Any]:
    state: dict[str, Any] = {}
    fields = _decode_fields(pb_bytes)

    error_raw = _first(fields, 3)
    if isinstance(error_raw, bytes) and error_raw:
        state["errorCodes"] = _decode_packed_int32s(error_raw)
        state["errorCode"] = state["errorCodes"][0] if state["errorCodes"] else 0
    elif isinstance(error_raw, int):
        code = _signed32(error_raw)
        state["errorCodes"] = [code] if code else []
        state["errorCode"] = code
    else:
        # Missing field 3 is "no current codes" (ha-lymow), not "keep the last E77".
        state["errorCodes"] = []
        state["errorCode"] = 0

    robot_info_raw = _first(fields, 5)
    if isinstance(robot_info_raw, bytes):
        ri = _decode_fields(robot_info_raw)
        rs_raw = _first(ri, 1)
        if rs_raw is not None:
            # robotState (f1) is live pause/error; workStatus (f6) stays MOWING through both.
            state["robotState"] = _signed32(rs_raw)
        ws_raw = _first(ri, 6)
        state["workStatus"] = _signed32(ws_raw) if ws_raw is not None else -1
        battery = _first(ri, 2)
        if battery is not None:
            state["battery"] = _signed32(battery)
        state["isCharging"] = bool(_first(ri, 8, 0))
        state["isRecharging"] = bool(_first(ri, 7, 0))
        wifi_sig = _first(ri, 3)
        if wifi_sig is not None:
            state["wifiSignalQuality"] = _signed32(wifi_sig)
        lte_sig = _first(ri, 4)
        if lte_sig is not None:
            state["lteSignalQuality"] = _signed32(lte_sig)

    profile_raw = _first(fields, 10)
    if isinstance(profile_raw, bytes):
        dp = _decode_fields(profile_raw)
        for field_no, key in ((1, "fwVersion"), (2, "mcuVersion"), (3, "softwareVersion"), (5, "ipAddress")):
            val = _first(dp, field_no)
            if isinstance(val, bytes):
                state[key] = val.decode("utf-8", errors="replace")

    rtk_raw = _first(fields, 6)
    if isinstance(rtk_raw, bytes):
        rtk = _decode_fields(rtk_raw)
        sats = _first(rtk, 1)
        if sats is not None:
            state["rtkSatellites"] = _signed32(sats)
        rtk_status = _first(rtk, 4)
        if rtk_status is not None:
            state["rtkStatus"] = _signed32(rtk_status)

    area_raw = _first(fields, 12)
    if isinstance(area_raw, bytes):
        area_fields = _decode_fields(area_raw)
        progress_raw = _first(area_fields, 5)
        if isinstance(progress_raw, int):
            pct = _decode_f32(progress_raw)
            if 0.0 <= pct <= 1.05:
                state["mowProgress"] = round(min(pct, 1.0) * 100, 1)
            elif 1.05 < pct <= 100.0:
                state["mowProgress"] = round(pct, 1)

    return state
