#!/usr/bin/env bash
# Ensure Chart.js is vendored under public/vendor/ for sensor history charts.
# Called from update.sh / install.sh so the UI update installs what it needs.

yarbo_chart_js_version() {
  echo "4.4.7"
}

yarbo_ensure_chart_js() {
  local root="${1:-.}"
  local ver
  ver="$(yarbo_chart_js_version)"
  local dir="${root}/public/vendor"
  local dest="${dir}/chart.umd.min.js"
  local stamp="${dir}/chart.version"
  mkdir -p "$dir"
  if [[ -f "$dest" && -f "$stamp" && "$(cat "$stamp" 2>/dev/null || true)" == "$ver" ]]; then
    return 0
  fi
  local url="https://cdn.jsdelivr.net/npm/chart.js@${ver}/dist/chart.umd.min.js"
  local tmp="${dest}.tmp"
  if command -v curl >/dev/null 2>&1; then
    if curl -fsSL --connect-timeout 15 --max-time 60 "$url" -o "$tmp"; then
      mv -f "$tmp" "$dest"
      echo "$ver" > "$stamp"
      return 0
    fi
  elif command -v wget >/dev/null 2>&1; then
    if wget -q -O "$tmp" "$url"; then
      mv -f "$tmp" "$dest"
      echo "$ver" > "$stamp"
      return 0
    fi
  fi
  rm -f "$tmp"
  # Keep a previously downloaded copy if the network failed.
  [[ -f "$dest" ]]
}
