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
USER_CTRL_QUERY_WIFI_4G = 52


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


def wrap_envelope(pb_bytes: bytes) -> str:
    return json.dumps({"message": base64.b64encode(pb_bytes).decode()})


def encode_userctrl(command: int) -> bytes:
    return _field_i32(2, PB_VERSION) + _field_i32(5, command)


def encode_status_query() -> bytes:
    """Read-only Wi-Fi/4G query the app sends at startup; reply includes robot info."""
    return encode_userctrl(USER_CTRL_QUERY_WIFI_4G)


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


def _decode_f32(raw: int) -> float:
    return struct.unpack("<f", struct.pack("<I", raw & 0xFFFFFFFF))[0]


def decode_pboutput(pb_bytes: bytes) -> dict[str, Any]:
    state: dict[str, Any] = {}
    fields = _decode_fields(pb_bytes)

    error_raw = _first(fields, 3)
    if isinstance(error_raw, bytes) and error_raw:
        state["errorCodes"] = _decode_packed_int32s(error_raw)
        state["errorCode"] = state["errorCodes"][0] if state["errorCodes"] else 0
    else:
        state["errorCodes"] = []
        state["errorCode"] = 0

    robot_info_raw = _first(fields, 5)
    if isinstance(robot_info_raw, bytes):
        ri = _decode_fields(robot_info_raw)
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
