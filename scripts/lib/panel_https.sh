#!/usr/bin/env bash
# HTTPS helper for scripts/panel.sh — self-signed cert + TLS proxy on :8443.
# shellcheck shell=bash

yarbo_panel_https_enabled() {
  local flag="${YARBO_PANEL_HTTPS:-1}"
  case "${flag}" in
    0|false|FALSE|off|OFF|no|NO) return 1 ;;
    *) return 0 ;;
  esac
}

yarbo_panel_https_port() {
  echo "${YARBO_PANEL_HTTPS_PORT:-8443}"
}

yarbo_panel_cert_dir() {
  echo "${1%/}/data/certs"
}

yarbo_panel_collect_sans() {
  local sans=("DNS:localhost" "DNS:yarbo-panel.local" "IP:127.0.0.1")
  local ip
  if command -v hostname >/dev/null 2>&1; then
    # hostname -I (Linux) or ifconfig-style fallbacks
    for ip in $(hostname -I 2>/dev/null || true); do
      [[ "$ip" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || continue
      sans+=("IP:${ip}")
    done
  fi
  if command -v ip >/dev/null 2>&1; then
    while read -r ip; do
      [[ "$ip" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || continue
      [[ "$ip" == "127.0.0.1" ]] && continue
      local already=0
      local s
      for s in "${sans[@]}"; do
        if [[ "$s" == "IP:${ip}" ]]; then already=1; break; fi
      done
      if [[ "$already" -eq 0 ]]; then
        sans+=("IP:${ip}")
      fi
    done < <(ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1)
  fi
  local IFS=,
  echo "${sans[*]}"
}

yarbo_panel_ensure_cert() {
  local root="$1"
  local dir
  dir="$(yarbo_panel_cert_dir "$root")"
  mkdir -p "$dir"
  local crt="${dir}/panel.crt"
  local key="${dir}/panel.key"
  local regen="${YARBO_PANEL_HTTPS_REGEN:-0}"
  if [[ "$regen" != "1" && -f "$crt" && -f "$key" ]]; then
    echo "$crt|$key"
    return 0
  fi
  if ! command -v openssl >/dev/null 2>&1; then
    echo "WARNING: openssl not found — HTTPS proxy disabled" >&2
    return 1
  fi
  local san
  san="$(yarbo_panel_collect_sans)"
  local conf
  conf="$(mktemp)"
  cat >"$conf" <<EOF
[req]
default_bits = 2048
prompt = no
default_md = sha256
distinguished_name = dn
x509_extensions = v3_req

[dn]
CN = yarbo-panel

[v3_req]
subjectAltName = ${san}
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
EOF
  if ! openssl req -x509 -newkey rsa:2048 -sha256 -days 825 -nodes \
    -keyout "$key" -out "$crt" -config "$conf" >/dev/null 2>&1; then
    rm -f "$conf"
    echo "WARNING: could not create panel TLS certificate — HTTPS proxy disabled" >&2
    return 1
  fi
  rm -f "$conf"
  chmod 600 "$key" 2>/dev/null || true
  chmod 644 "$crt" 2>/dev/null || true
  echo "$crt|$key"
}

yarbo_panel_start_https_proxy() {
  local root="$1"
  local upstream_port="${2:-8080}"
  if ! yarbo_panel_https_enabled; then
    echo "==> HTTPS proxy off (YARBO_PANEL_HTTPS=0)"
    return 0
  fi
  local py="${root}/.venv/bin/python"
  if [[ ! -x "$py" ]]; then
    py="$(command -v python3 || true)"
  fi
  if [[ -z "$py" ]]; then
    echo "WARNING: python3 not found — HTTPS proxy disabled" >&2
    return 0
  fi
  local pair
  if ! pair="$(yarbo_panel_ensure_cert "$root")"; then
    return 0
  fi
  local crt="${pair%%|*}"
  local key="${pair##*|}"
  local https_port
  https_port="$(yarbo_panel_https_port)"
  local listen_host="${YARBO_PANEL_HTTPS_HOST:-0.0.0.0}"
  local upstream_host="${YARBO_PANEL_HTTPS_UPSTREAM:-127.0.0.1}"
  mkdir -p "${root}/data"
  : >"${root}/data/panel-https.log"
  echo "==> Starting HTTPS proxy on https://0.0.0.0:${https_port} (self-signed)"
  echo "    Open https://<panel-ip>:${https_port} for mic / Announce Record (accept the certificate warning once)."
  echo "    Paper tablets keep using http://<panel-ip>:${upstream_port}"
  # Upstream is already up (panel.sh starts PHP first).
  "$py" "${root}/scripts/panel_https_proxy.py" \
    --listen-host "$listen_host" \
    --listen-port "$https_port" \
    --upstream-host "$upstream_host" \
    --upstream-port "$upstream_port" \
    --cert "$crt" \
    --key "$key" \
    --no-wait-upstream >>"${root}/data/panel-https.log" 2>&1 &
  HTTPS_PROXY_PID=$!
  sleep 0.5
  if ! kill -0 "${HTTPS_PROXY_PID}" 2>/dev/null; then
    echo "WARNING: HTTPS proxy failed to start — see data/panel-https.log" >&2
    if [[ -f "${root}/data/panel-https.log" ]]; then
      tail -n 20 "${root}/data/panel-https.log" >&2 || true
    fi
    HTTPS_PROXY_PID=""
    return 0
  fi
  # Confirm the TLS port answers (curl if present, else python).
  local ok=0
  if command -v curl >/dev/null 2>&1; then
    if curl -sk --max-time 3 -o /dev/null -w '' "https://127.0.0.1:${https_port}/"; then
      ok=1
    fi
  elif command -v "$py" >/dev/null 2>&1; then
    if "$py" -c "import socket,ssl,sys;c=ssl.create_default_context();c.check_hostname=False;c.verify_mode=ssl.CERT_NONE;s=c.wrap_socket(socket.create_connection(('127.0.0.1',${https_port}),timeout=2),server_hostname='localhost');s.sendall(b'HEAD / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n');sys.exit(0 if s.recv(64).startswith(b'HTTP/') else 1)"; then
      ok=1
    fi
  else
    ok=1
  fi
  if [[ "$ok" -ne 1 ]]; then
    echo "WARNING: HTTPS proxy is running but :${https_port} did not answer — see data/panel-https.log" >&2
    tail -n 30 "${root}/data/panel-https.log" >&2 || true
  fi
}
