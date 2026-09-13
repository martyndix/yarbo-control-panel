#!/usr/bin/env python3
"""Unofficial Lymow cloud bridge: Cognito SRP, REST, optional AWS IoT MQTT.

Auth and protobuf field numbers follow the MIT-licensed ha-lymow notes
(https://github.com/8408323/ha-lymow). Not affiliated with Lymow.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts" / "lib"))

from lymow_protocol import decode_pboutput, unwrap_envelope  # noqa: E402

CLIENT_ID = "3h1sqv3hishjiofbv8giskjgb0"
REGIONS = ["eu-west-1", "us-east-2", "ap-southeast-2", "ap-east-1"]
REGION_CONFIG: dict[str, dict[str, str | None]] = {
    "eu-west-1": {
        "client_id": CLIENT_ID,
        "user_pool_id": "eu-west-1_6qNPbnrrd",
        "identity_pool_id": "eu-west-1:c905a69c-0153-401a-a879-0c50b892015b",
        "iot_host": "a3j5zqqo5iuph9-ats.iot.eu-west-1.amazonaws.com",
        "api_device_list": "asjqh5wbtj",
        "api_device_info": "6ghz1zkccg",
        "api_map": "3q1zxz98l2",
    },
    "us-east-2": {
        "client_id": CLIENT_ID,
        "user_pool_id": None,
        "identity_pool_id": "us-east-2:037db699-5df0-4ed2-92b8-0dd0f1843918",
        "iot_host": "a3j5zqqo5iuph9-ats.iot.us-east-2.amazonaws.com",
        "api_device_list": "zt810q0p60",
        "api_device_info": "6r8m5rxeth",
        "api_map": "bpath65iid",
    },
    "ap-southeast-2": {
        "client_id": CLIENT_ID,
        "user_pool_id": "ap-southeast-2_vNriuUNeQ",
        "identity_pool_id": "ap-southeast-2:87d0fe24-16af-4189-b02f-984a7ed14ee0",
        "iot_host": "a3j5zqqo5iuph9-ats.iot.ap-southeast-2.amazonaws.com",
        "api_device_list": "vvikmtssjh",
        "api_device_info": "7k2iuc99h7",
        "api_map": "l2gobpcoqc",
    },
    "ap-east-1": {
        "client_id": CLIENT_ID,
        "user_pool_id": "ap-east-1_23Lf1WZer",
        "identity_pool_id": "ap-east-1:3e9265aa-f564-4083-8e1e-988e6cfdc446",
        "iot_host": "a3j5zqqo5iuph9-ats.iot.ap-east-1.amazonaws.com",
        "api_device_list": "08ydw34dfj",
        "api_device_info": "m35t3px95i",
        "api_map": "kdueg6qcwl",
    },
}

N_HEX = (
    "FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD1"
    "29024E088A67CC74020BBEA63B139B22514A08798E3404DD"
    "EF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245"
    "E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7ED"
    "EE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3D"
    "C2007CB8A163BF0598DA48361C55D39A69163FA8FD24CF5F"
    "83655D23DCA3AD961C62F356208552BB9ED529077096966D"
    "670C354E4ABC9804F1746C08CA18217C32905E462E36CE3B"
    "E39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9"
    "DE2BCBF6955817183995497CEA956AE515D2261898FA0510"
    "15728E5A8AAAC42DAD33170D04507A33A85521ABDF1CBA64"
    "ECFB850458DBEF0A8AEA71575D060C7DB3970F85A6E1E4C7"
    "ABF5AE8CDB0933D71E8C94E04A25619DCEE3D2261AD2EE6B"
    "F12FFA06D98A0864D87602733EC86A64521F2B18177B200C"
    "BBE117577A615D6C770988C0BAD946E208E24FA074E5AB31"
    "43DB5BFCE0FD108E4B82D120A93AD2CAFFFFFFFFFFFFFFFF"
)
G_HEX = "2"
INFO_BITS = b"Caldera Derived Key"


def emit(payload: dict[str, Any]) -> None:
    sys.stdout.write(json.dumps(payload, ensure_ascii=False) + "\n")
    sys.stdout.flush()


def _pad_hex(n: int) -> str:
    h = hex(n)[2:]
    if len(h) % 2:
        h = "0" + h
    if h[0] in "89abcdef":
        h = "00" + h
    return h


def _hex_hash(h: str) -> str:
    return hashlib.sha256(bytes.fromhex(h)).hexdigest()


class SRPClient:
    def __init__(self, username: str, password: str, pool_id: str) -> None:
        self.username = username
        self.password = password
        self.pool_name = pool_id.split("_", 1)[1]
        self.N = int(N_HEX, 16)
        self.g = int(G_HEX, 16)
        self.k = int(_hex_hash("00" + N_HEX + "0" + G_HEX), 16)
        self.a = int.from_bytes(os.urandom(128), "big") % self.N
        self.A = pow(self.g, self.a, self.N)

    @property
    def srp_a(self) -> str:
        return f"{self.A:x}"

    def process_challenge(
        self, username: str, salt_hex: str, srp_b_hex: str, secret_block_b64: str, timestamp: str
    ) -> str:
        B = int(srp_b_hex, 16)
        u = int(_hex_hash(_pad_hex(self.A) + _pad_hex(B)), 16)
        username_password_hash = hashlib.sha256(
            (self.pool_name + username + ":" + self.password).encode()
        ).hexdigest()
        x = int(_hex_hash(_pad_hex(int(salt_hex, 16)) + username_password_hash), 16)
        g_mod_pow_xn = pow(self.g, x, self.N)
        s = pow(B - self.k * g_mod_pow_xn, self.a + u * x, self.N)
        prk = hmac.new(bytearray.fromhex(_pad_hex(u)), bytearray.fromhex(_pad_hex(s)), hashlib.sha256).digest()
        hkdf = hmac.new(prk, INFO_BITS + b"\x01", hashlib.sha256).digest()[:16]
        msg = (
            bytearray(self.pool_name, "utf-8")
            + bytearray(username, "utf-8")
            + bytearray(base64.b64decode(secret_block_b64))
            + bytearray(timestamp, "utf-8")
        )
        return base64.b64encode(hmac.new(hkdf, msg, hashlib.sha256).digest()).decode()


def _http_json(url: str, payload: dict[str, Any] | None, headers: dict[str, str], method: str = "POST") -> dict[str, Any]:
    data = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    for key, value in headers.items():
        req.add_header(key, value)
    if data is not None and "Content-Type" not in headers:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            body = resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        err_body = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {exc.code}: {err_body[:400]}") from exc
    decoded = json.loads(body) if body else {}
    if not isinstance(decoded, dict) and not isinstance(decoded, list):
        raise RuntimeError("Unexpected JSON from Lymow")
    return decoded  # type: ignore[return-value]


def cognito_post(region: str, target: str, payload: dict[str, Any]) -> dict[str, Any]:
    return _http_json(
        f"https://cognito-idp.{region}.amazonaws.com/",
        payload,
        {
            "Content-Type": "application/x-amz-json-1.1",
            "X-Amz-Target": target,
        },
    )


def srp_login(username: str, password: str, region: str) -> dict[str, Any]:
    cfg = REGION_CONFIG[region]
    pool_id = cfg.get("user_pool_id")
    if not pool_id:
        raise RuntimeError(f"No Cognito user pool for {region}")
    client_id = cfg.get("client_id") or CLIENT_ID
    srp = SRPClient(username, password, pool_id)
    first = cognito_post(
        region,
        "AWSCognitoIdentityProviderService.InitiateAuth",
        {
            "AuthFlow": "USER_SRP_AUTH",
            "AuthParameters": {"USERNAME": username, "SRP_A": srp.srp_a},
            "ClientId": client_id,
        },
    )
    params = first["ChallengeParameters"]
    now = datetime.now(timezone.utc)
    timestamp = f"{now.strftime('%a %b')} {now.day} {now.strftime('%H:%M:%S UTC %Y')}"
    signature = srp.process_challenge(
        params["USER_ID_FOR_SRP"],
        params["SALT"],
        params["SRP_B"],
        params["SECRET_BLOCK"],
        timestamp,
    )
    second = cognito_post(
        region,
        "AWSCognitoIdentityProviderService.RespondToAuthChallenge",
        {
            "ChallengeName": "PASSWORD_VERIFIER",
            "ChallengeResponses": {
                "USERNAME": params["USER_ID_FOR_SRP"],
                "PASSWORD_CLAIM_SECRET_BLOCK": params["SECRET_BLOCK"],
                "TIMESTAMP": timestamp,
                "PASSWORD_CLAIM_SIGNATURE": signature,
            },
            "ClientId": client_id,
        },
    )
    result = second["AuthenticationResult"]
    result["region"] = region
    return result


def refresh_tokens(refresh_token: str, region: str) -> dict[str, Any]:
    cfg = REGION_CONFIG[region]
    client_id = cfg.get("client_id") or CLIENT_ID
    data = cognito_post(
        region,
        "AWSCognitoIdentityProviderService.InitiateAuth",
        {
            "AuthFlow": "REFRESH_TOKEN_AUTH",
            "AuthParameters": {"REFRESH_TOKEN": refresh_token},
            "ClientId": client_id,
        },
    )
    return data["AuthenticationResult"]


def aws_credentials(id_token: str, region: str) -> dict[str, Any]:
    cfg = REGION_CONFIG[region]
    pool_id = cfg["user_pool_id"]
    identity_pool_id = cfg["identity_pool_id"]
    url = f"https://cognito-identity.{region}.amazonaws.com/"
    login_key = f"cognito-idp.{region}.amazonaws.com/{pool_id}"
    get_id = _http_json(
        url,
        {"IdentityPoolId": identity_pool_id, "Logins": {login_key: id_token}},
        {"Content-Type": "application/x-amz-json-1.1", "X-Amz-Target": "AWSCognitoIdentityService.GetId"},
    )
    identity_id = get_id["IdentityId"]
    creds = _http_json(
        url,
        {"IdentityId": identity_id, "Logins": {login_key: id_token}},
        {
            "Content-Type": "application/x-amz-json-1.1",
            "X-Amz-Target": "AWSCognitoIdentityService.GetCredentialsForIdentity",
        },
    )
    return {"identity_id": identity_id, "credentials": creds["Credentials"]}


def api_url(region: str, gateway: str, path: str) -> str:
    gw = REGION_CONFIG[region].get(gateway)
    if not gw:
        raise RuntimeError(f"No {gateway} gateway for {region}")
    return f"https://{gw}.execute-api.{region}.amazonaws.com{path}"


def rest_get(url: str, access_token: str) -> Any:
    return _http_json(url, None, {"Authorization": access_token, "Accept": "application/json"}, method="GET")


def load_config(path: Path) -> dict[str, Any]:
    if not path.is_file():
        return {}
    data = json.loads(path.read_text(encoding="utf-8"))
    return data if isinstance(data, dict) else {}


def save_config(path: Path, data: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def ensure_tokens(config: dict[str, Any]) -> dict[str, Any]:
    region = str(config.get("region") or "eu-west-1")
    access = str(config.get("access_token") or "")
    refresh = str(config.get("refresh_token") or "")
    expires = int(config.get("access_expires_at") or 0)
    if access and expires > time.time() + 60:
        return config
    if refresh:
        tokens = refresh_tokens(refresh, region)
        config["access_token"] = tokens["AccessToken"]
        config["id_token"] = tokens.get("IdToken", config.get("id_token", ""))
        if tokens.get("RefreshToken"):
            config["refresh_token"] = tokens["RefreshToken"]
        config["access_expires_at"] = int(time.time()) + max(60, int(tokens.get("ExpiresIn") or 3600) - 60)
        return config
    email = str(config.get("email") or "")
    password = str(config.get("password") or "")
    if not email or not password:
        raise RuntimeError("Lymow email and password are required.")
    preferred = str(config.get("region") or "auto")
    order = REGIONS if preferred in ("", "auto") else [preferred] + [r for r in REGIONS if r != preferred]
    last_error = "Login failed"
    for candidate in order:
        if not REGION_CONFIG[candidate].get("user_pool_id"):
            continue
        try:
            tokens = srp_login(email, password, candidate)
            config["region"] = candidate
            config["access_token"] = tokens["AccessToken"]
            config["id_token"] = tokens.get("IdToken", "")
            config["refresh_token"] = tokens.get("RefreshToken", refresh)
            config["access_expires_at"] = int(time.time()) + max(60, int(tokens.get("ExpiresIn") or 86400) - 60)
            return config
        except Exception as exc:  # noqa: BLE001
            last_error = str(exc)
    raise RuntimeError(last_error)


def fetch_device_bundle(config: dict[str, Any]) -> dict[str, Any]:
    region = str(config["region"])
    access = str(config["access_token"])
    id_token = str(config.get("id_token") or "")
    if not config.get("identity_id"):
        ident = aws_credentials(id_token, region)
        config["identity_id"] = ident["identity_id"]
        creds = ident["credentials"]
        config["aws_access_key"] = creds.get("AccessKeyId", "")
        config["aws_secret_key"] = creds.get("SecretKey", "")
        config["aws_session_token"] = creds.get("SessionToken", "")
        config["aws_expires"] = creds.get("Expiration", "")
    identity_id = urllib.parse.quote(str(config["identity_id"]), safe="")
    devices = rest_get(
        api_url(region, "api_device_list", f"/prod/device-list-query?p=devices&identityId={identity_id}"),
        access,
    )
    if isinstance(devices, dict):
        devices = devices.get("devices") or devices.get("data") or []
    if not isinstance(devices, list) or not devices:
        raise RuntimeError("No Lymow mower on this account.")
    device = devices[0] if isinstance(devices[0], dict) else {}
    thing = str(device.get("deviceThingName") or config.get("device_thing_name") or "")
    if thing == "":
        raise RuntimeError("Lymow device list had no thing name.")
    info = rest_get(
        api_url(region, "api_device_info", f"/prod/get-device-info?deviceThingName={urllib.parse.quote(thing)}"),
        access,
    )
    if not isinstance(info, dict):
        info = {}
    history: dict[str, Any] = {}
    try:
        history = rest_get(
            api_url(region, "api_map", f"/prod/get-clean-history-collect?deviceThingName={urllib.parse.quote(thing)}&page=0&pageSize=1"),
            access,
        )
        if not isinstance(history, dict):
            history = {}
    except Exception:
        history = {}
    last_mow = None
    rows = history.get("clean_history") if isinstance(history.get("clean_history"), list) else []
    if rows and isinstance(rows[0], dict):
        last_mow = rows[0]
    summary = history.get("clean_summary") if isinstance(history.get("clean_summary"), dict) else {}
    return {
        "device": device,
        "info": info,
        "thing": thing,
        "last_mow": last_mow,
        "clean_summary": summary,
    }


def _hmac_sha256(key: bytes, data: str) -> bytes:
    return hmac.new(key, data.encode("utf-8"), hashlib.sha256).digest()


def build_presigned_ws_path(host: str, region: str, access_key: str, secret_key: str, session_token: str) -> str:
    now = datetime.now(timezone.utc)
    amz_date = now.strftime("%Y%m%dT%H%M%SZ")
    date_str = now.strftime("%Y%m%d")
    service = "iotdevicegateway"
    credential_scope = f"{date_str}/{region}/{service}/aws4_request"
    credential = f"{access_key}/{credential_scope}"
    parts = {
        "X-Amz-Algorithm": "AWS4-HMAC-SHA256",
        "X-Amz-Credential": credential,
        "X-Amz-Date": amz_date,
        "X-Amz-Expires": "86400",
        "X-Amz-SignedHeaders": "host",
    }
    canonical_qs = "&".join(f"{urllib.parse.quote(k, safe='')}={urllib.parse.quote(v, safe='')}" for k, v in sorted(parts.items()))
    canonical_request = f"GET\n/mqtt\n{canonical_qs}\nhost:{host}\n\nhost\n{hashlib.sha256(b'').hexdigest()}"
    string_to_sign = "\n".join(
        ["AWS4-HMAC-SHA256", amz_date, credential_scope, hashlib.sha256(canonical_request.encode()).hexdigest()]
    )
    k_date = _hmac_sha256(("AWS4" + secret_key).encode(), date_str)
    k_region = _hmac_sha256(k_date, region)
    k_service = _hmac_sha256(k_region, service)
    sig_key = _hmac_sha256(k_service, "aws4_request")
    signature = hmac.new(sig_key, string_to_sign.encode(), hashlib.sha256).hexdigest()
    final_qs = canonical_qs + f"&X-Amz-Signature={signature}"
    if session_token:
        final_qs += f"&X-Amz-Security-Token={urllib.parse.quote(session_token, safe='')}"
    return f"/mqtt?{final_qs}"


def mqtt_wait_state(config: dict[str, Any], thing: str, wait_s: float) -> dict[str, Any] | None:
    try:
        import paho.mqtt.client as mqtt
    except ImportError:
        try:
            import subprocess

            subprocess.run(
                [sys.executable, "-m", "pip", "install", "--disable-pip-version-check", "paho-mqtt"],
                check=False,
                capture_output=True,
                timeout=90,
            )
            import paho.mqtt.client as mqtt  # type: ignore
        except Exception:
            return None
    region = str(config["region"])
    host = str(REGION_CONFIG[region]["iot_host"])
    id_token = str(config.get("id_token") or "")
    ident = aws_credentials(id_token, region)
    creds = ident["credentials"]
    path = build_presigned_ws_path(
        host,
        region,
        str(creds.get("AccessKeyId") or ""),
        str(creds.get("SecretKey") or ""),
        str(creds.get("SessionToken") or ""),
    )
    got: dict[str, Any] = {}

    def on_message(_client: Any, _userdata: Any, message: Any) -> None:
        try:
            if str(message.topic).endswith("/notify-app"):
                data = json.loads(message.payload.decode("utf-8", errors="replace"))
                got["mqtt_online"] = str(data.get("robotState", "")).lower() == "online"
                return
            pb = unwrap_envelope(message.payload)
            got.update(decode_pboutput(pb))
        except Exception:
            return

    client = mqtt.Client(
        callback_api_version=mqtt.CallbackAPIVersion.VERSION2,
        client_id=f"yarbo-lymow-{uuid.uuid4().hex[:8]}",
        transport="websockets",
    )
    client.tls_set(cert_reqs=ssl.CERT_REQUIRED)
    client.ws_set_options(path=path)
    client.on_message = on_message
    try:
        client.connect(host, 443, 60)
        client.subscribe(f"/device/{thing}/pboutput", qos=1)
        client.subscribe(f"/device/{thing}/notify-app", qos=1)
        deadline = time.time() + wait_s
        while time.time() < deadline:
            client.loop(timeout=1.0)
            if "battery" in got or "workStatus" in got:
                break
    finally:
        try:
            client.disconnect()
        except Exception:
            pass
    return got or None


def merge_state(bundle: dict[str, Any], mqtt_state: dict[str, Any] | None) -> dict[str, Any]:
    info = bundle["info"] if isinstance(bundle.get("info"), dict) else {}
    device = bundle["device"] if isinstance(bundle.get("device"), dict) else {}
    mqtt_state = mqtt_state or {}
    last_mow = bundle.get("last_mow") if isinstance(bundle.get("last_mow"), dict) else None
    ip = str(mqtt_state.get("ipAddress") or info.get("ipAddress") or "")
    battery = mqtt_state.get("battery")
    work = mqtt_state.get("workStatus")
    return {
        "ok": True,
        "online": str(info.get("deviceState") or device.get("deviceState") or "").lower() == "online"
        or bool(mqtt_state.get("mqtt_online")),
        "device_thing_name": bundle.get("thing"),
        "device_name": str(device.get("deviceName") or device.get("deviceType") or "Lymow"),
        "sn": str(info.get("sn") or device.get("sn") or ""),
        "ip_address": ip,
        "software_version": str(info.get("softwareVersion") or mqtt_state.get("softwareVersion") or ""),
        "mcu_version": str(info.get("mcuVersion") or mqtt_state.get("mcuVersion") or ""),
        "battery": battery,
        "work_status": work,
        "is_charging": mqtt_state.get("isCharging"),
        "is_recharging": mqtt_state.get("isRecharging"),
        "error_code": mqtt_state.get("errorCode"),
        "error_codes": mqtt_state.get("errorCodes") or [],
        "rtk_status": mqtt_state.get("rtkStatus"),
        "rtk_satellites": mqtt_state.get("rtkSatellites"),
        "mow_progress": mqtt_state.get("mowProgress"),
        "wifi_signal": mqtt_state.get("wifiSignalQuality"),
        "lte_signal": mqtt_state.get("lteSignalQuality"),
        "last_mow": last_mow,
        "clean_summary": bundle.get("clean_summary") or {},
        "fetched_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }


def cmd_login(config_path: Path, state_path: Path) -> int:
    config = load_config(config_path)
    try:
        config = ensure_tokens(config)
        bundle = fetch_device_bundle(config)
        config["device_thing_name"] = bundle["thing"]
        config["last_error"] = ""
        save_config(config_path, config)
        mqtt_state = mqtt_wait_state(config, str(bundle["thing"]), 8.0)
        state = merge_state(bundle, mqtt_state)
        state_path.parent.mkdir(parents=True, exist_ok=True)
        state_path.write_text(json.dumps(state, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        emit({"ok": True, "message": "Signed in to Lymow.", "region": config.get("region"), **state})
        return 0
    except Exception as exc:  # noqa: BLE001
        emit({"ok": False, "error": str(exc)})
        return 1


def cmd_state(config_path: Path, state_path: Path, wait_s: float) -> int:
    config = load_config(config_path)
    try:
        config = ensure_tokens(config)
        bundle = fetch_device_bundle(config)
        config["device_thing_name"] = bundle["thing"]
        mqtt_state = mqtt_wait_state(config, str(bundle["thing"]), wait_s) if wait_s > 0 else None
        config["last_error"] = ""
        save_config(config_path, config)
        state = merge_state(bundle, mqtt_state)
        state_path.parent.mkdir(parents=True, exist_ok=True)
        state_path.write_text(json.dumps(state, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        emit(state)
        return 0
    except Exception as exc:  # noqa: BLE001
        emit({"ok": False, "error": str(exc)})
        return 1


def cmd_listen(config_path: Path, state_path: Path) -> int:
    while True:
        config = load_config(config_path)
        if not config.get("email") and not config.get("refresh_token"):
            time.sleep(8)
            continue
        try:
            config = ensure_tokens(config)
            bundle = fetch_device_bundle(config)
            thing = str(bundle["thing"])
            config["device_thing_name"] = thing
            save_config(config_path, config)
            mqtt_state = mqtt_wait_state(config, thing, 70)
            state = merge_state(bundle, mqtt_state)
            state_path.parent.mkdir(parents=True, exist_ok=True)
            state_path.write_text(json.dumps(state, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        except Exception as exc:  # noqa: BLE001
            err = {"ok": False, "error": str(exc), "fetched_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
            try:
                prev = json.loads(state_path.read_text(encoding="utf-8")) if state_path.is_file() else {}
            except Exception:
                prev = {}
            if isinstance(prev, dict) and prev.get("battery") is not None:
                prev["ok"] = False
                prev["error"] = str(exc)
                state_path.write_text(json.dumps(prev, indent=2) + "\n", encoding="utf-8")
            else:
                state_path.parent.mkdir(parents=True, exist_ok=True)
                state_path.write_text(json.dumps(err, indent=2) + "\n", encoding="utf-8")
            time.sleep(8)
            continue
        time.sleep(2)


def main() -> int:
    parser = argparse.ArgumentParser(description="Lymow unofficial cloud bridge")
    parser.add_argument("command", choices=["login", "state", "listen"])
    parser.add_argument("--config", required=True)
    parser.add_argument("--state", default="")
    parser.add_argument("--wait", type=float, default=8.0)
    args = parser.parse_args()
    config_path = Path(args.config)
    state_path = Path(args.state) if args.state else config_path.parent / "lymow-state.json"
    if args.command == "login":
        return cmd_login(config_path, state_path)
    if args.command == "state":
        return cmd_state(config_path, state_path, args.wait)
    return cmd_listen(config_path, state_path)


if __name__ == "__main__":
    raise SystemExit(main())
