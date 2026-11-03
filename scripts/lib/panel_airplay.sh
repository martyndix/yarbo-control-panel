#!/usr/bin/env bash
# AirPlay receiver helper — shairport-sync → FIFO for Protect talkback bridge.
# shellcheck shell=bash

yarbo_airplay_dir() {
  echo "${1%/}/data/airplay"
}

yarbo_airplay_fifo() {
  echo "$(yarbo_airplay_dir "$1")/audio.fifo"
}

yarbo_airplay_conf() {
  echo "$(yarbo_airplay_dir "$1")/shairport-sync.conf"
}

yarbo_airplay_log() {
  echo "${1%/}/data/panel-airplay.log"
}

# Cap panel-airplay.log so ensure/status never hit ARG_MAX or stall the Pi on huge I/O.
yarbo_airplay_trim_log() {
  local root="${1:-}"
  local log max keep
  [[ -n "$root" ]] || return 0
  log="$(yarbo_airplay_log "$root")"
  [[ -f "$log" ]] || return 0
  max=$((512 * 1024))
  keep=$((128 * 1024))
  # Portable size check (busybox / GNU).
  local sz
  sz="$(wc -c <"$log" 2>/dev/null | tr -d ' ' || echo 0)"
  if [[ "${sz:-0}" -gt "$max" ]]; then
    tail -c "$keep" "$log" >"${log}.trim" 2>/dev/null && mv -f "${log}.trim" "$log" || true
    echo "==> panel_airplay trimmed log to last ${keep} bytes $(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$log"
  fi
}

yarbo_airplay_pidfile() {
  echo "$(yarbo_airplay_dir "$1")/shairport-sync.pid"
}

yarbo_airplay_nqptp_pidfile() {
  echo "$(yarbo_airplay_dir "$1")/nqptp.pid"
}

yarbo_airplay_name_default() {
  echo "Yarbo Speakers"
}

yarbo_airplay_read_name() {
  local root="$1"
  local cfg="$(yarbo_airplay_dir "$root")/config.json"
  local name=""
  if [[ -f "$cfg" ]] && command -v php >/dev/null 2>&1; then
    name="$(php -r '$j=@json_decode((string)@file_get_contents($argv[1]), true); echo is_array($j)&&isset($j["name"])?trim((string)$j["name"]):"";' "$cfg" 2>/dev/null || true)"
  fi
  if [[ -z "$name" ]]; then
    name="$(yarbo_airplay_name_default)"
  fi
  # Keep mDNS-safe-ish.
  name="$(printf '%s' "$name" | tr -cd 'A-Za-z0-9 _.-' | cut -c1-48)"
  if [[ -z "$name" ]]; then
    name="$(yarbo_airplay_name_default)"
  fi
  echo "$name"
}

yarbo_airplay_bin() {
  if [[ -x /usr/bin/shairport-sync ]]; then echo /usr/bin/shairport-sync; return 0; fi
  if [[ -x /usr/local/bin/shairport-sync ]]; then echo /usr/local/bin/shairport-sync; return 0; fi
  command -v shairport-sync 2>/dev/null || true
}

yarbo_airplay_nqptp_bin() {
  if [[ -x /usr/bin/nqptp ]]; then echo /usr/bin/nqptp; return 0; fi
  if [[ -x /usr/local/bin/nqptp ]]; then echo /usr/local/bin/nqptp; return 0; fi
  command -v nqptp 2>/dev/null || true
}

yarbo_airplay_installed() {
  local bin
  bin="$(yarbo_airplay_bin)"
  [[ -n "$bin" && -x "$bin" ]]
}

yarbo_airplay_ensure_fifo() {
  local root="$1"
  local dir fifo
  dir="$(yarbo_airplay_dir "$root")"
  fifo="$(yarbo_airplay_fifo "$root")"
  mkdir -p "$dir"
  if [[ -p "$fifo" ]]; then
    return 0
  fi
  rm -f "$fifo" 2>/dev/null || true
  mkfifo -m 666 "$fifo" 2>/dev/null || mkfifo "$fifo"
}

yarbo_airplay_metadata_fifo() {
  echo "$(yarbo_airplay_dir "$1")/metadata.fifo"
}

yarbo_airplay_metadata_pidfile() {
  echo "$(yarbo_airplay_dir "$1")/metadata.pid"
}

yarbo_airplay_hooks_flag() {
  echo "$(yarbo_airplay_dir "$1")/hooks.json"
}

yarbo_airplay_escape_conf_string() {
  # Escape double quotes for shairport-sync conf string values.
  printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

# Start avahi so shairport-sync can advertise on the LAN (iPhone AirPlay list).
yarbo_airplay_ensure_avahi() {
  local root="${1:-}"
  local log=""
  if [[ -n "$root" ]]; then
    log="$(yarbo_airplay_log "$root")"
  fi
  if pgrep -x avahi-daemon >/dev/null 2>&1; then
    return 0
  fi
  {
    echo "==> panel_airplay ensuring avahi-daemon $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    if command -v systemctl >/dev/null 2>&1; then
      if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
        sudo -n systemctl enable avahi-daemon >/dev/null 2>&1 || true
        sudo -n systemctl start avahi-daemon >/dev/null 2>&1 || true
      fi
      systemctl --user start avahi-daemon >/dev/null 2>&1 || true
      # Root helper (update / first install) can start it without password.
      if [[ -n "$root" && -x /usr/local/sbin/yarbo-matter-setup ]]; then
        sudo -n /usr/local/sbin/yarbo-matter-setup "$root" airplay-quiesce >/dev/null 2>&1 || true
      fi
    fi
    if command -v service >/dev/null 2>&1; then
      if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
        sudo -n service avahi-daemon start >/dev/null 2>&1 || true
      fi
    fi
  } >>"${log:-/dev/null}" 2>&1 || true
  pgrep -x avahi-daemon >/dev/null 2>&1
}

# Debian's shairport-sync.service often auto-starts with ALSA and steals the
# AirPlay name / ports. Stop+disable it so the panel's pipe receiver owns mDNS.
yarbo_airplay_quiesce_packaged_service() {
  local root="${1:-}"
  local log=""
  if [[ -n "$root" ]]; then
    log="$(yarbo_airplay_log "$root")"
  fi
  {
    echo "==> panel_airplay quiesce packaged shairport-sync $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    if command -v systemctl >/dev/null 2>&1; then
      if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
        sudo -n systemctl stop shairport-sync >/dev/null 2>&1 || true
        sudo -n systemctl disable shairport-sync >/dev/null 2>&1 || true
        # Mask stops apt/systemd from bringing the ALSA unit back on boot.
        sudo -n systemctl mask shairport-sync >/dev/null 2>&1 || true
      elif [[ -n "$root" && -x /usr/local/sbin/yarbo-matter-setup ]]; then
        sudo -n /usr/local/sbin/yarbo-matter-setup "$root" airplay-quiesce >/dev/null 2>&1 || true
      fi
    fi
    # Always clear stray binaries so our next start owns the name.
    pkill -x shairport-sync 2>/dev/null || true
    sleep 0.2
    pkill -KILL -x shairport-sync 2>/dev/null || true
  } >>"${log:-/dev/null}" 2>&1 || true
  return 0
}

yarbo_airplay_avahi_running() {
  pgrep -x avahi-daemon >/dev/null 2>&1
}

yarbo_airplay_write_conf() {
  local root="$1"
  local name fifo conf hook meta
  name="$(yarbo_airplay_escape_conf_string "$(yarbo_airplay_read_name "$root")")"
  fifo="$(yarbo_airplay_fifo "$root")"
  conf="$(yarbo_airplay_conf "$root")"
  hook="$(yarbo_airplay_escape_conf_string "${root}/scripts/panel_airplay_hook.sh")"
  meta="$(yarbo_airplay_metadata_fifo "$root")"
  yarbo_airplay_ensure_fifo "$root"
  mkdir -p "$(yarbo_airplay_dir "$root")"
  if [[ ! -p "$meta" ]]; then
    rm -f "$meta" 2>/dev/null || true
    mkfifo -m 666 "$meta" 2>/dev/null || mkfifo "$meta" || true
  fi
  chmod +x "${root}/scripts/panel_airplay_hook.sh" \
    "${root}/scripts/panel_airplay_bridge.php" \
    "${root}/scripts/panel_airplay_metadata.php" 2>/dev/null || true
  cat >"$conf" <<EOF
// Generated by Yarbo panel — do not edit by hand (overwritten on Start AirPlay).
general = {
  name = "${name}";
  interpolation = "basic";
  output_backend = "pipe";
  // Publish via Avahi so iPhones on the same LAN see this name in AirPlay.
  mdns_backend = "avahi";
  // Ignore volume from the Apple device; Protect volume is the panel slider.
  ignore_volume_control = "yes";
};

sessioncontrol = {
  allow_session_interruption = "yes";
  session_timeout = 120;
  // play_begins/ends track real streams — active_state can fire without a client.
  run_this_before_play_begins = "${hook} active";
  run_this_after_play_ends = "${hook} inactive";
};

metadata = {
  enabled = "yes";
  include_cover_art = "no";
  pipe_name = "${meta}";
};

pipe = {
  name = "${fifo}";
};
EOF
  echo "$conf"
}

yarbo_airplay_watch_pidfile() {
  echo "$(yarbo_airplay_dir "$1")/watch.pid"
}

# True when shairport-sync has an established remote TCP peer (AirPlay/RTSP client).
yarbo_airplay_has_network_client() {
  local root="$1"
  local pid
  pid="$(yarbo_airplay_pid "$root")"
  [[ -n "$pid" ]] || return 1
  if command -v ss >/dev/null 2>&1; then
    if ss -tnH state established -p 2>/dev/null | grep -F "pid=${pid}," >/dev/null 2>&1; then
      return 0
    fi
    if ss -tnH state established -p 2>/dev/null | grep -E "pid=${pid}[^0-9]" >/dev/null 2>&1; then
      return 0
    fi
  fi
  if command -v lsof >/dev/null 2>&1; then
    if lsof -a -p "$pid" -iTCP -sTCP:ESTABLISHED 2>/dev/null | grep -qv '^COMMAND'; then
      return 0
    fi
  fi
  # /proc fallback: any ESTABLISHED socket inode owned by the process.
  if [[ -d "/proc/${pid}/fd" ]]; then
    local inodes="" inode
    inodes="$(find "/proc/${pid}/fd" -lname 'socket:*' -printf '%l\n' 2>/dev/null | sed -n 's/.*\[\(.*\)\]/\1/p' | tr '\n' ' ')"
    if [[ -n "$inodes" && -r /proc/net/tcp ]]; then
      for inode in $inodes; do
        if awk -v ino="$inode" 'NR>1 {
          # local 00000000 = 0.0.0.0 listen; skip LISTEN (state 0A)
          split($2, a, ":"); split($3, b, ":");
          if ($10 == ino && $4 == "01" && b[1] != "00000000") { found=1; exit }
        } END { exit found ? 0 : 1 }' /proc/net/tcp /proc/net/tcp6 2>/dev/null; then
          return 0
        fi
      done
    fi
  fi
  return 1
}

yarbo_airplay_stop_watch() {
  local root="$1"
  local pf pid
  pf="$(yarbo_airplay_watch_pidfile "$root")"
  if [[ -f "$pf" ]]; then
    pid="$(tr -d ' \n' <"$pf" 2>/dev/null || true)"
    if [[ -n "$pid" ]]; then
      kill -TERM "$pid" 2>/dev/null || true
      sleep 0.1
      kill -KILL "$pid" 2>/dev/null || true
    fi
    rm -f "$pf"
  fi
  pkill -f "panel_airplay_watch.sh" 2>/dev/null || true
}

yarbo_airplay_start_watch() {
  local root="$1"
  local log pf
  yarbo_airplay_stop_watch "$root"
  log="$(yarbo_airplay_log "$root")"
  pf="$(yarbo_airplay_watch_pidfile "$root")"
  mkdir -p "$(yarbo_airplay_dir "$root")"
  chmod +x "${root}/scripts/panel_airplay_watch.sh" 2>/dev/null || true
  nohup bash "${root}/scripts/panel_airplay_watch.sh" >>"$log" 2>&1 &
  echo $! >"$pf"
}

yarbo_airplay_stop_metadata_reader() {
  local root="$1"
  local pf pid
  pf="$(yarbo_airplay_metadata_pidfile "$root")"
  if [[ -f "$pf" ]]; then
    pid="$(tr -d ' \n' <"$pf" 2>/dev/null || true)"
    if [[ -n "$pid" ]]; then
      kill -TERM "$pid" 2>/dev/null || true
      sleep 0.1
      kill -KILL "$pid" 2>/dev/null || true
    fi
    rm -f "$pf"
  fi
  pkill -f "panel_airplay_metadata.php" 2>/dev/null || true
}

yarbo_airplay_start_metadata_reader() {
  local root="$1"
  local log pf
  yarbo_airplay_stop_metadata_reader "$root"
  log="$(yarbo_airplay_log "$root")"
  pf="$(yarbo_airplay_metadata_pidfile "$root")"
  mkdir -p "$(yarbo_airplay_dir "$root")"
  nohup php "${root}/scripts/panel_airplay_metadata.php" >>"$log" 2>&1 &
  echo $! >"$pf"
}

yarbo_airplay_running() {
  local root="$1"
  local pf pid
  pf="$(yarbo_airplay_pidfile "$root")"
  if [[ -f "$pf" ]]; then
    pid="$(tr -d ' \n' <"$pf" 2>/dev/null || true)"
    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
      return 0
    fi
  fi
  pgrep -x shairport-sync >/dev/null 2>&1
}

yarbo_airplay_pid() {
  local root="$1"
  local pf pid
  pf="$(yarbo_airplay_pidfile "$root")"
  if [[ -f "$pf" ]]; then
    pid="$(tr -d ' \n' <"$pf" 2>/dev/null || true)"
    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
      echo "$pid"
      return 0
    fi
  fi
  pgrep -x shairport-sync 2>/dev/null | head -n1 || true
}

yarbo_airplay_stop_receiver() {
  local root="$1"
  local pf pid
  yarbo_airplay_stop_watch "$root"
  yarbo_airplay_stop_metadata_reader "$root"
  pf="$(yarbo_airplay_pidfile "$root")"
  if [[ -f "$pf" ]]; then
    pid="$(tr -d ' \n' <"$pf" 2>/dev/null || true)"
    if [[ -n "$pid" ]]; then
      kill -TERM "$pid" 2>/dev/null || true
      sleep 0.2
      kill -KILL "$pid" 2>/dev/null || true
    fi
    rm -f "$pf"
  fi
  pkill -x shairport-sync 2>/dev/null || true
  pf="$(yarbo_airplay_nqptp_pidfile "$root")"
  if [[ -f "$pf" ]]; then
    pid="$(tr -d ' \n' <"$pf" 2>/dev/null || true)"
    if [[ -n "$pid" ]]; then
      kill -TERM "$pid" 2>/dev/null || true
      sleep 0.1
      kill -KILL "$pid" 2>/dev/null || true
    fi
    rm -f "$pf"
  fi
  rm -f "$(yarbo_airplay_hooks_flag "$root")" 2>/dev/null || true
}

yarbo_airplay_start_nqptp() {
  local root="$1"
  local bin log pf
  bin="$(yarbo_airplay_nqptp_bin)"
  [[ -n "$bin" ]] || return 0
  if pgrep -x nqptp >/dev/null 2>&1; then
    return 0
  fi
  log="$(yarbo_airplay_log "$root")"
  pf="$(yarbo_airplay_nqptp_pidfile "$root")"
  mkdir -p "$(yarbo_airplay_dir "$root")"
  # nqptp needs privileges for PTP ports 319/320 on many builds.
  if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
    nohup sudo -n "$bin" >>"$log" 2>&1 &
  else
    nohup "$bin" >>"$log" 2>&1 &
  fi
  echo $! >"$pf"
  sleep 0.3
}

yarbo_airplay_our_receiver_running() {
  # True only when the pidfile we wrote is still alive (not a packaged ALSA unit).
  local root="$1"
  local pf pid
  pf="$(yarbo_airplay_pidfile "$root")"
  if [[ ! -f "$pf" ]]; then
    return 1
  fi
  pid="$(tr -d ' \n' <"$pf" 2>/dev/null || true)"
  [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null
}

yarbo_airplay_start_receiver() {
  local root="$1"
  local bin conf log pf
  bin="$(yarbo_airplay_bin)"
  if [[ -z "$bin" ]]; then
    echo "shairport-sync not installed" >&2
    return 1
  fi
  yarbo_airplay_ensure_avahi "$root" || true
  yarbo_airplay_quiesce_packaged_service "$root"
  yarbo_airplay_ensure_fifo "$root"
  conf="$(yarbo_airplay_write_conf "$root")"
  log="$(yarbo_airplay_log "$root")"
  pf="$(yarbo_airplay_pidfile "$root")"
  mkdir -p "$(yarbo_airplay_dir "$root")"
  : >>"$log"
  # Only skip restart when *our* pipe receiver is already up with this conf.
  if yarbo_airplay_our_receiver_running "$root"; then
    # Still refresh conf on disk; caller (ensure) typically stopped us first.
    return 0
  fi
  # Clear any non-panel shairport that appeared after quiesce (systemd race).
  pkill -x shairport-sync 2>/dev/null || true
  sleep 0.15
  yarbo_airplay_start_nqptp "$root"
  echo "==> panel_airplay $(date -u +%Y-%m-%dT%H:%M:%SZ) starting shairport-sync name=$(yarbo_airplay_read_name "$root") avahi=$(yarbo_airplay_avahi_running && echo 1 || echo 0)" >>"$log"
  # Prefer config file; fall back to classic CLI pipe if the build rejects the conf.
  if nohup "$bin" -c "$conf" >>"$log" 2>&1 & then
    echo $! >"$pf"
  else
    return 1
  fi
  sleep 0.6
  local hooks=0
  if yarbo_airplay_our_receiver_running "$root"; then
    hooks=1
    yarbo_airplay_start_metadata_reader "$root"
  else
    # Classic AirPlay CLI fallback (no session hooks / metadata).
    local fifo name
    fifo="$(yarbo_airplay_fifo "$root")"
    name="$(yarbo_airplay_read_name "$root")"
    echo "==> panel_airplay falling back to classic CLI pipe" >>"$log"
    pkill -x shairport-sync 2>/dev/null || true
    sleep 0.1
    nohup "$bin" -a "$name" -o pipe -- "$fifo" >>"$log" 2>&1 &
    echo $! >"$pf"
    sleep 0.5
    hooks=0
  fi
  php -r 'file_put_contents($argv[1], json_encode(["hooks" => $argv[2] === "1", "updated_at" => time()], JSON_UNESCAPED_SLASHES) . "\n");' \
    "$(yarbo_airplay_hooks_flag "$root")" "$hooks"
  # Always run the TCP watcher as a safety net (and the only signal when hooks=0).
  if yarbo_airplay_our_receiver_running "$root"; then
    yarbo_airplay_start_watch "$root"
    if ! yarbo_airplay_avahi_running; then
      echo "==> panel_airplay WARNING: avahi-daemon is not running — iPhones will not see AirPlay" >>"$log"
    fi
    return 0
  fi
  return 1
}

# Stop packaged shairport + start Avahi. Safe to call as root from matter-setup.
yarbo_airplay_quiesce_as_root() {
  if command -v systemctl >/dev/null 2>&1; then
    systemctl enable avahi-daemon >/dev/null 2>&1 || true
    systemctl start avahi-daemon >/dev/null 2>&1 || true
    systemctl stop shairport-sync >/dev/null 2>&1 || true
    systemctl disable shairport-sync >/dev/null 2>&1 || true
    systemctl mask shairport-sync >/dev/null 2>&1 || true
  fi
  pkill -x shairport-sync 2>/dev/null || true
  return 0
}

# Install AirPlay host packages (must run as root). Used by update.sh / matter-setup.
yarbo_install_airplay_deps() {
  if ! command -v apt-get >/dev/null 2>&1; then
    echo "apt-get not available — skip AirPlay packages" >&2
    return 1
  fi
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    echo "yarbo_install_airplay_deps must run as root" >&2
    return 1
  fi
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y shairport-sync avahi-daemon
  yarbo_airplay_quiesce_as_root
  # Best-effort PTP / mDNS firewall holes for AirPlay 2.
  if command -v ufw >/dev/null 2>&1; then
    if ufw status 2>/dev/null | grep -qi 'Status: active'; then
      ufw allow 319,320/udp comment 'Yarbo AirPlay PTP' >/dev/null 2>&1 || true
      ufw allow 5353/udp comment 'Yarbo mDNS' >/dev/null 2>&1 || true
    fi
  fi
  return 0
}

# Try passwordless install via the panel's existing root helper (no SSH).
yarbo_try_install_airplay_deps() {
  local root="${1:-}"
  local log
  if yarbo_airplay_installed; then
    return 0
  fi
  if [[ -z "$root" ]]; then
    return 1
  fi
  log="$(yarbo_airplay_log "$root")"
  mkdir -p "$(dirname "$log")"
  {
    echo "==> yarbo_try_install_airplay_deps $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    # airplay-deps refreshes the wrapper then apt-installs (do NOT call matter-setup
    # without a subcommand — that starts the full Matter Docker stack).
    if [[ -x /usr/local/sbin/yarbo-matter-setup ]]; then
      if sudo -n /usr/local/sbin/yarbo-matter-setup "$root" airplay-deps; then
        echo "matter-setup airplay-deps ok"
      else
        echo "matter-setup airplay-deps failed (exit $?)"
      fi
    else
      echo "yarbo-matter-setup missing — cannot apt without SSH once"
    fi
    if ! yarbo_airplay_installed && command -v sudo >/dev/null 2>&1; then
      # Exact argv must match scripts/install.sh sudoers (NOPASSWD apt-get lines).
      echo "trying sudoers apt-get install…"
      sudo -n /usr/bin/apt-get update -qq || true
      sudo -n /usr/bin/apt-get install -y shairport-sync avahi-daemon || true
      # Also try reversed package order (second sudoers line from install.sh).
      if ! yarbo_airplay_installed; then
        sudo -n /usr/bin/apt-get install -y avahi-daemon shairport-sync || true
      fi
    fi
  } >>"$log" 2>&1 || true
  yarbo_airplay_installed
}
