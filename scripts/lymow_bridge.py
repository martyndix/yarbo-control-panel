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

from lymow_protocol import (  # noqa: E402
    decode_pboutput,
    encode_app_connect_heartbeat,
    encode_status_query,
    unwrap_envelope,
    wrap_envelope,
)

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


def resolved_region(config: dict[str, Any]) -> str:
    region = str(config.get("region") or "").strip().lower()
    if region in REGION_CONFIG:
        return region
    identity = str(config.get("identity_id") or "")
    for candidate in REGIONS:
        if identity.startswith(candidate + ":"):
            return candidate
    return "eu-west-1"


def save_config(path: Path, data: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def ensure_tokens(config: dict[str, Any]) -> dict[str, Any]:
    access = str(config.get("access_token") or "")
    refresh = str(config.get("refresh_token") or "")
    expires = int(config.get("access_expires_at") or 0)
    if access and expires > time.time() + 60:
        config["region"] = resolved_region(config)
        return config
    if refresh:
        preferred = str(config.get("region") or "").strip().lower()
        order = [preferred] + [r for r in REGIONS if r != preferred] if preferred in REGION_CONFIG else list(REGIONS)
        last_error = "Refresh failed"
        for candidate in order:
            if not REGION_CONFIG[candidate].get("user_pool_id"):
                continue
            try:
                tokens = refresh_tokens(refresh, candidate)
                config["region"] = candidate
                config["access_token"] = tokens["AccessToken"]
                config["id_token"] = tokens.get("IdToken", config.get("id_token", ""))
                if tokens.get("RefreshToken"):
                    config["refresh_token"] = tokens["RefreshToken"]
                config["access_expires_at"] = int(time.time()) + max(60, int(tokens.get("ExpiresIn") or 3600) - 60)
                return config
            except Exception as exc:  # noqa: BLE001
                last_error = str(exc)
        if not str(config.get("email") or "") or not str(config.get("password") or ""):
            raise RuntimeError(last_error)
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
    region = resolved_region(config)
    config["region"] = region
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


def ensure_paho() -> tuple[Any | None, str | None]:
    try:
        import paho.mqtt.client as mqtt
        import websocket  # noqa: F401  # websocket-client, required for paho websockets

        return mqtt, None
    except ImportError:
        pass
    import subprocess

    packages = ["paho-mqtt", "websocket-client"]
    in_venv = sys.prefix != sys.base_prefix
    if in_venv:
        attempts = [
            [sys.executable, "-m", "pip", "install", "--disable-pip-version-check", *packages],
        ]
    else:
        attempts = [
            [
                sys.executable,
                "-m",
                "pip",
                "install",
                "--disable-pip-version-check",
                "--break-system-packages",
                *packages,
            ],
            [
                sys.executable,
                "-m",
                "pip",
                "install",
                "--disable-pip-version-check",
                "--user",
                "--break-system-packages",
                *packages,
            ],
        ]
    last = "paho-mqtt / websocket-client missing"
    for cmd in attempts:
        try:
            result = subprocess.run(cmd, check=False, capture_output=True, timeout=120)
            blob = result.stderr or result.stdout or b""
            last = blob.decode("utf-8", errors="replace")[-240:] or last
            import importlib

            importlib.invalidate_caches()
            import paho.mqtt.client as mqtt  # type: ignore
            import websocket  # noqa: F401

            return mqtt, None
        except Exception as exc:  # noqa: BLE001
            last = str(exc)
    return None, (
        "Could not import paho-mqtt + websocket-client (needed for live battery). On the Pi: "
        "python3 -m pip install --break-system-packages paho-mqtt websocket-client"
        + (f" ({last.strip()})" if last.strip() else "")
    )


def _sigv4_iot_headers(
    method: str,
    host: str,
    uri: str,
    region: str,
    access_key: str,
    secret_key: str,
    session_token: str,
    payload: bytes = b"",
) -> dict[str, str]:
    now = datetime.now(timezone.utc)
    amz_date = now.strftime("%Y%m%dT%H%M%SZ")
    date_str = now.strftime("%Y%m%d")
    payload_hash = hashlib.sha256(payload).hexdigest()
    canonical = (
        f"{method}\n{uri}\n\n"
        f"host:{host}\n"
        f"x-amz-content-sha256:{payload_hash}\n"
        f"x-amz-date:{amz_date}\n"
        f"x-amz-security-token:{session_token}\n\n"
        "host;x-amz-content-sha256;x-amz-date;x-amz-security-token\n"
        f"{payload_hash}"
    )
    scope = f"{date_str}/{region}/iotdata/aws4_request"
    sts = f"AWS4-HMAC-SHA256\n{amz_date}\n{scope}\n{hashlib.sha256(canonical.encode()).hexdigest()}"
    k = _hmac_sha256(("AWS4" + secret_key).encode(), date_str)
    k = _hmac_sha256(k, region)
    k = _hmac_sha256(k, "iotdata")
    k = _hmac_sha256(k, "aws4_request")
    signature = hmac.new(k, sts.encode(), hashlib.sha256).hexdigest()
    return {
        "Authorization": (
            f"AWS4-HMAC-SHA256 Credential={access_key}/{scope}, "
            "SignedHeaders=host;x-amz-content-sha256;x-amz-date;x-amz-security-token, "
            f"Signature={signature}"
        ),
        "x-amz-date": amz_date,
        "x-amz-content-sha256": payload_hash,
        "x-amz-security-token": session_token,
    }


def extract_shadow_state(obj: Any) -> dict[str, Any]:
    found: dict[str, Any] = {}

    def consider_battery(value: Any) -> None:
        if isinstance(value, bool) or not isinstance(value, (int, float)):
            return
        pct = int(round(float(value)))
        if 0 <= pct <= 100:
            found["battery"] = pct

    def walk(node: Any) -> None:
        if isinstance(node, dict):
            for key, value in node.items():
                lk = str(key).lower().replace("_", "")
                if lk in {"battery", "batterylevel", "batterypercent", "soc", "batterysoc"}:
                    consider_battery(value)
                elif lk in {"workstatus", "workstate"} and isinstance(value, int):
                    found["workStatus"] = value
                elif lk in {"ischarging", "charging"}:
                    found["isCharging"] = bool(value)
                elif lk in {"isrecharging", "recharging"}:
                    found["isRecharging"] = bool(value)
                elif lk in {"message", "payload", "pboutput"} and isinstance(value, str) and len(value) > 8:
                    try:
                        found.update(decode_pboutput(base64.b64decode(value)))
                    except Exception:
                        walk(value)
                else:
                    walk(value)
        elif isinstance(node, list):
            for item in node:
                walk(item)

    if isinstance(obj, dict) and isinstance(obj.get("state"), dict):
        reported = obj["state"].get("reported", obj["state"])
        walk(reported)
    else:
        walk(obj)
    return found


def fetch_iot_shadow(config: dict[str, Any], thing: str) -> dict[str, Any]:
    region = resolved_region(config)
    config["region"] = region
    id_token = str(config.get("id_token") or "")
    try:
        ident = aws_credentials(id_token, region)
    except Exception as exc:  # noqa: BLE001
        return {"shadow_error": f"AWS credentials failed: {exc}"}
    creds = ident["credentials"]
    access_key = str(creds.get("AccessKeyId") or "")
    secret_key = str(creds.get("SecretKey") or "")
    session_token = str(creds.get("SessionToken") or "")
    mqtt_host = str(REGION_CONFIG[region]["iot_host"])
    hosts = [
        mqtt_host.replace(".iot.", ".data.iot.", 1),
        mqtt_host,
        f"data.iot.{region}.amazonaws.com",
        f"data.ats.iot.{region}.amazonaws.com",
    ]
    uri = f"/things/{urllib.parse.quote(thing, safe='')}/shadow"
    last = "no IoT shadow host answered"
    for host in hosts:
        try:
            headers = _sigv4_iot_headers("GET", host, uri, region, access_key, secret_key, session_token)
            req = urllib.request.Request(f"https://{host}{uri}", method="GET")
            for key, value in headers.items():
                req.add_header(key, value)
            with urllib.request.urlopen(req, timeout=12) as resp:
                body = resp.read().decode("utf-8", errors="replace")
            decoded = json.loads(body) if body else {}
            extracted = extract_shadow_state(decoded)
            if extracted:
                return extracted
            last = f"{host} returned a shadow without battery"
        except Exception as exc:  # noqa: BLE001
            last = f"{host}: {exc}"
    return {"shadow_error": last}


def collect_live_state(
    config: dict[str, Any],
    thing: str,
    wait_s: float,
    stop_on_battery: bool = True,
) -> dict[str, Any]:
    got = fetch_iot_shadow(config, thing)
    if stop_on_battery and "battery" in got:
        return got
    mqtt = mqtt_wait_state(config, thing, wait_s, stop_on_battery=stop_on_battery)
    for key, value in mqtt.items():
        if value is not None:
            got[key] = value
    if "battery" in got or "workStatus" in got:
        got.pop("mqtt_error", None)
        got.pop("shadow_error", None)
    elif got.get("mqtt_error") and got.get("shadow_error"):
        got["mqtt_error"] = f"{got['mqtt_error']} ({got['shadow_error']})"
    elif got.get("shadow_error") and not got.get("mqtt_error"):
        got["mqtt_error"] = got["shadow_error"]
    return got


def mqtt_wait_state(
    config: dict[str, Any],
    thing: str,
    wait_s: float,
    stop_on_battery: bool = True,
) -> dict[str, Any]:
    mqtt, import_error = ensure_paho()
    if mqtt is None:
        return {"mqtt_error": import_error or "paho-mqtt is not installed"}
    region = resolved_region(config)
    config["region"] = region
    host = str(REGION_CONFIG[region]["iot_host"])
    id_token = str(config.get("id_token") or "")
    try:
        ident = aws_credentials(id_token, region)
    except Exception as exc:  # noqa: BLE001
        return {"mqtt_error": f"AWS credentials failed: {exc}"}
    creds = ident["credentials"]
    path = build_presigned_ws_path(
        host,
        region,
        str(creds.get("AccessKeyId") or ""),
        str(creds.get("SecretKey") or ""),
        str(creds.get("SessionToken") or ""),
    )
    got: dict[str, Any] = {}
    session_id = uuid.uuid4().hex
    query = wrap_envelope(encode_status_query()).encode()
    heartbeat = wrap_envelope(encode_app_connect_heartbeat(session_id)).encode()
    client_id = f"yarbo-lymow-{uuid.uuid4().hex[:8]}"

    def on_connect(client: Any, _userdata: Any, *_args: Any) -> None:
        rc = 0
        if len(_args) >= 2:
            code = _args[1]
            rc = int(getattr(code, "value", code) or 0)
        if rc not in (0,):
            got["mqtt_error"] = f"MQTT connect failed ({rc})"
            return
        client.subscribe(f"/device/{thing}/pboutput", qos=1)
        client.subscribe(f"/device/{thing}/notify-app", qos=1)
        client.publish(f"/device/{thing}/pbinput", heartbeat, qos=1)
        client.publish(f"/device/{thing}/pbinput", query, qos=1)

    def on_message(_client: Any, _userdata: Any, message: Any) -> None:
        try:
            if str(message.topic).endswith("/notify-app"):
                raw = message.payload
                text = raw.decode("utf-8", errors="replace") if isinstance(raw, (bytes, bytearray)) else str(raw)
                data = json.loads(text)
                got["mqtt_online"] = str(data.get("robotState", "")).lower() == "online"
                return
            pb = unwrap_envelope(message.payload)
            got.update(decode_pboutput(pb))
            got.pop("mqtt_error", None)
        except Exception as exc:  # noqa: BLE001
            got["mqtt_error"] = f"MQTT decode failed: {exc}"

    kwargs: dict[str, Any] = {
        "client_id": client_id,
        "transport": "websockets",
        "protocol": mqtt.MQTTv311,
    }
    if hasattr(mqtt, "CallbackAPIVersion"):
        kwargs["callback_api_version"] = mqtt.CallbackAPIVersion.VERSION2
    client = mqtt.Client(**kwargs)
    client.tls_set(cert_reqs=ssl.CERT_REQUIRED)
    try:
        client.ws_set_options(path=path, headers={"Host": host})
    except TypeError:
        client.ws_set_options(path=path)
    client.on_connect = on_connect
    client.on_message = on_message
    last_beat = 0.0
    try:
        client.connect(host, 443, 60)
        deadline = time.time() + max(3.0, wait_s)
        while time.time() < deadline:
            client.loop(timeout=1.0)
            now = time.time()
            if now - last_beat >= 10:
                client.publish(f"/device/{thing}/pbinput", heartbeat, qos=1)
                last_beat = now
            if stop_on_battery and ("battery" in got or "workStatus" in got):
                break
    except Exception as exc:  # noqa: BLE001
        got["mqtt_error"] = str(exc)
    finally:
        try:
            client.disconnect()
        except Exception:
            pass
    if "battery" not in got and "workStatus" not in got and "mqtt_error" not in got:
        got["mqtt_error"] = (
            "MQTT connected but no battery yet (Lymow heartbeats about every 60s). "
            "Leave the panel running, or tap Sign in / Test Lymow again."
        )
    return got


def write_state(state_path: Path, state: dict[str, Any]) -> None:
    state_path.parent.mkdir(parents=True, exist_ok=True)
    tmp = state_path.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(state, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    tmp.replace(state_path)


def mqtt_listen_session(
    config: dict[str, Any],
    thing: str,
    bundle: dict[str, Any],
    state_path: Path,
    max_s: float = 3600.0,
) -> None:
    """Stay on MQTT and write lymow-state.json as soon as each pboutput arrives."""
    mqtt, import_error = ensure_paho()
    if mqtt is None:
        raise RuntimeError(import_error or "paho-mqtt is not installed")
    region = resolved_region(config)
    config["region"] = region
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
    last_key: tuple[Any, ...] | None = None
    session_id = uuid.uuid4().hex
    query = wrap_envelope(encode_status_query()).encode()
    heartbeat = wrap_envelope(encode_app_connect_heartbeat(session_id)).encode()
    client_id = f"yarbo-lymow-{uuid.uuid4().hex[:8]}"

    def persist() -> None:
        nonlocal last_key
        state = merge_state(bundle, got, read_previous_state(state_path))
        key = (
            state.get("battery"),
            state.get("work_status"),
            state.get("mow_progress"),
            state.get("is_charging"),
            state.get("is_recharging"),
            state.get("error_code"),
        )
        if key == last_key:
            return
        last_key = key
        write_state(state_path, state)

    def on_connect(client: Any, _userdata: Any, *_args: Any) -> None:
        rc = 0
        if len(_args) >= 2:
            code = _args[1]
            rc = int(getattr(code, "value", code) or 0)
        if rc not in (0,):
            got["mqtt_error"] = f"MQTT connect failed ({rc})"
            return
        client.subscribe(f"/device/{thing}/pboutput", qos=1)
        client.subscribe(f"/device/{thing}/notify-app", qos=1)
        client.publish(f"/device/{thing}/pbinput", heartbeat, qos=1)
        client.publish(f"/device/{thing}/pbinput", query, qos=1)

    def on_message(_client: Any, _userdata: Any, message: Any) -> None:
        try:
            if str(message.topic).endswith("/notify-app"):
                raw = message.payload
                text = raw.decode("utf-8", errors="replace") if isinstance(raw, (bytes, bytearray)) else str(raw)
                data = json.loads(text)
                got["mqtt_online"] = str(data.get("robotState", "")).lower() == "online"
                persist()
                return
            pb = unwrap_envelope(message.payload)
            got.update(decode_pboutput(pb))
            got.pop("mqtt_error", None)
            persist()
        except Exception as exc:  # noqa: BLE001
            got["mqtt_error"] = f"MQTT decode failed: {exc}"

    kwargs: dict[str, Any] = {
        "client_id": client_id,
        "transport": "websockets",
        "protocol": mqtt.MQTTv311,
    }
    if hasattr(mqtt, "CallbackAPIVersion"):
        kwargs["callback_api_version"] = mqtt.CallbackAPIVersion.VERSION2
    client = mqtt.Client(**kwargs)
    client.tls_set(cert_reqs=ssl.CERT_REQUIRED)
    try:
        client.ws_set_options(path=path, headers={"Host": host})
    except TypeError:
        client.ws_set_options(path=path)
    client.on_connect = on_connect
    client.on_message = on_message
    last_beat = 0.0
    last_query = 0.0
    try:
        client.connect(host, 443, 60)
        deadline = time.time() + max(60.0, max_s)
        while time.time() < deadline:
            client.loop(timeout=0.5)
            now = time.time()
            if now - last_beat >= 10:
                client.publish(f"/device/{thing}/pbinput", heartbeat, qos=1)
                last_beat = now
            if now - last_query >= 15:
                client.publish(f"/device/{thing}/pbinput", query, qos=1)
                last_query = now
    finally:
        try:
            client.disconnect()
        except Exception:
            pass


def _lymow_nickname(device: dict[str, Any], info: dict[str, Any]) -> str:
    for src in (device, info):
        if not isinstance(src, dict):
            continue
        for key in ("deviceName", "nickname", "nickName", "alias", "customName", "name"):
            val = str(src.get(key) or "").strip()
            if val:
                return val
    return ""


def merge_state(
    bundle: dict[str, Any],
    mqtt_state: dict[str, Any] | None,
    previous: dict[str, Any] | None = None,
) -> dict[str, Any]:
    info = bundle["info"] if isinstance(bundle.get("info"), dict) else {}
    device = bundle["device"] if isinstance(bundle.get("device"), dict) else {}
    mqtt_state = mqtt_state or {}
    previous = previous if isinstance(previous, dict) else {}
    last_mow = bundle.get("last_mow") if isinstance(bundle.get("last_mow"), dict) else None
    ip = str(mqtt_state.get("ipAddress") or info.get("ipAddress") or previous.get("ip_address") or "")
    battery = mqtt_state.get("battery")
    if battery is None:
        battery = previous.get("battery")
    work = mqtt_state.get("workStatus")
    if work is None:
        work = previous.get("work_status")
    charging = mqtt_state.get("isCharging")
    if charging is None:
        charging = previous.get("is_charging")
    recharging = mqtt_state.get("isRecharging")
    if recharging is None:
        recharging = previous.get("is_recharging")
    return {
        "ok": True,
        "online": str(info.get("deviceState") or device.get("deviceState") or "").lower() == "online"
        or bool(mqtt_state.get("mqtt_online")),
        "device_thing_name": bundle.get("thing"),
        "device_name": _lymow_nickname(device, info),
        "sn": str(info.get("sn") or device.get("sn") or ""),
        "ip_address": ip,
        "software_version": str(info.get("softwareVersion") or mqtt_state.get("softwareVersion") or ""),
        "mcu_version": str(info.get("mcuVersion") or mqtt_state.get("mcuVersion") or ""),
        "battery": battery,
        "work_status": work,
        "is_charging": charging,
        "is_recharging": recharging,
        "error_code": mqtt_state.get("errorCode"),
        "error_codes": mqtt_state.get("errorCodes") or previous.get("error_codes") or [],
        "rtk_status": mqtt_state.get("rtkStatus"),
        "rtk_satellites": mqtt_state.get("rtkSatellites"),
        "mow_progress": mqtt_state.get("mowProgress", previous.get("mow_progress")),
        "wifi_signal": mqtt_state.get("wifiSignalQuality"),
        "lte_signal": mqtt_state.get("lteSignalQuality"),
        "last_mow": last_mow,
        "clean_summary": bundle.get("clean_summary") or {},
        "mqtt_error": mqtt_state.get("mqtt_error") if battery is None else None,
        "fetched_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }


def read_previous_state(state_path: Path) -> dict[str, Any]:
    if not state_path.is_file():
        return {}
    try:
        data = json.loads(state_path.read_text(encoding="utf-8"))
    except Exception:
        return {}
    return data if isinstance(data, dict) else {}


def cmd_deps() -> int:
    mqtt, err = ensure_paho()
    if mqtt is None:
        emit({"ok": False, "error": err or "Could not install paho-mqtt / websocket-client."})
        return 1
    emit({"ok": True, "message": "Lymow MQTT libraries ready."})
    return 0


def cmd_login(config_path: Path, state_path: Path) -> int:
    mqtt, err = ensure_paho()
    if mqtt is None:
        emit({"ok": False, "error": err or "Could not install paho-mqtt / websocket-client."})
        return 1
    config = load_config(config_path)
    try:
        config = ensure_tokens(config)
        bundle = fetch_device_bundle(config)
        config["device_thing_name"] = bundle["thing"]
        config["last_error"] = ""
        save_config(config_path, config)
        mqtt_state = collect_live_state(config, str(bundle["thing"]), 35.0)
        state = merge_state(bundle, mqtt_state, read_previous_state(state_path))
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
        mqtt_state = collect_live_state(config, str(bundle["thing"]), wait_s) if wait_s > 0 else {}
        config["last_error"] = ""
        save_config(config_path, config)
        state = merge_state(bundle, mqtt_state, read_previous_state(state_path))
        write_state(state_path, state)
        emit(state)
        return 0
    except Exception as exc:  # noqa: BLE001
        emit({"ok": False, "error": str(exc)})
        return 1


def cmd_listen(config_path: Path, state_path: Path) -> int:
    mqtt, err = ensure_paho()
    if mqtt is None:
        emit({"ok": False, "error": err or "Could not install paho-mqtt / websocket-client."})
        return 1
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
            mqtt_listen_session(config, thing, bundle, state_path, max_s=3600.0)
        except Exception as exc:  # noqa: BLE001
            err = {"ok": False, "error": str(exc), "fetched_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
            prev = read_previous_state(state_path)
            if prev.get("battery") is not None:
                prev["ok"] = False
                prev["error"] = str(exc)
                write_state(state_path, prev)
            else:
                write_state(state_path, err)
            time.sleep(8)
            continue


def main() -> int:
    parser = argparse.ArgumentParser(description="Lymow unofficial cloud bridge")
    parser.add_argument("command", choices=["deps", "login", "state", "listen"])
    parser.add_argument("--config", required=True)
    parser.add_argument("--state", default="")
    parser.add_argument("--wait", type=float, default=8.0)
    args = parser.parse_args()
    config_path = Path(args.config)
    state_path = Path(args.state) if args.state else config_path.parent / "lymow-state.json"
    if args.command == "deps":
        return cmd_deps()
    if args.command == "login":
        return cmd_login(config_path, state_path)
    if args.command == "state":
        return cmd_state(config_path, state_path, args.wait)
    return cmd_listen(config_path, state_path)


if __name__ == "__main__":
    raise SystemExit(main())
