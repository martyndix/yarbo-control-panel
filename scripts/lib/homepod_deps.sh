#!/usr/bin/env bash
# Install pyatv into the project venv for HomePod / AirPlay sender (no SSH).
# Sourced by update.sh, install.sh, and panel_homepod_ensure.sh.

yarbo_homepod_log() {
  local root="${1:?}"
  echo "${root}/data/homepod-agent.log"
}

yarbo_homepod_python_usable() {
  local python="${1:?}"
  [[ -x "$python" ]] || return 1
  "$python" -c "import sys" >/dev/null 2>&1 || return 1
  # Prefer interpreters that can run pip or already have pyatv.
  if "$python" -c "import pyatv" >/dev/null 2>&1; then
    return 0
  fi
  "$python" -m pip --version >/dev/null 2>&1
}

yarbo_homepod_python() {
  local root="${1:?}"
  local candidate
  for candidate in "${root}/.venv/bin/python3" "${root}/.venv/bin/python"; do
    if yarbo_homepod_python_usable "$candidate"; then
      echo "$candidate"
      return 0
    fi
  done
  if command -v python3 >/dev/null 2>&1 && yarbo_homepod_python_usable "$(command -v python3)"; then
    command -v python3
    return 0
  fi
  if command -v python >/dev/null 2>&1 && yarbo_homepod_python_usable "$(command -v python)"; then
    command -v python
    return 0
  fi
  # Last resort: any python3 on PATH (pip install may still work with --break-system-packages).
  if command -v python3 >/dev/null 2>&1; then
    command -v python3
    return 0
  fi
  if command -v python >/dev/null 2>&1; then
    command -v python
    return 0
  fi
  return 1
}

yarbo_pyatv_installed() {
  local python="${1:?}"
  # 0.15.0 could light the HomePod with no audio; require 0.15.1+.
  "$python" -c "
import pyatv, sys
parts = []
for p in str(getattr(pyatv, '__version__', '0')).split('.'):
    try:
        parts.append(int(''.join(ch for ch in p if ch.isdigit()) or '0'))
    except Exception:
        parts.append(0)
while len(parts) < 3:
    parts.append(0)
sys.exit(0 if tuple(parts[:3]) >= (0, 15, 1) else 1)
" >/dev/null 2>&1
}

yarbo_try_apt_python_venv() {
  # Exact argv must match scripts/install.sh sudoers NOPASSWD lines.
  if ! command -v sudo >/dev/null 2>&1; then
    return 1
  fi
  sudo -n /usr/bin/apt-get update -qq || true
  sudo -n /usr/bin/apt-get install -y python3-pip python3-venv || true
  sudo -n /usr/bin/apt-get install -y python3-venv python3-pip || true
}

yarbo_homepod_ensure_venv() {
  local root="${1:?}"
  local base_python="${2:-python3}"
  local venv_py="${root}/.venv/bin/python3"
  local log="${3:-/dev/null}"

  if yarbo_homepod_python_usable "$venv_py" || yarbo_homepod_python_usable "${root}/.venv/bin/python"; then
    return 0
  fi

  # Broken partial venv (symlink only, no pip) — remove and recreate.
  if [[ -e "${root}/.venv" ]]; then
    echo "Removing unusable .venv" >>"$log"
    rm -rf "${root}/.venv"
  fi

  echo "Creating project venv at ${root}/.venv" >>"$log"
  if "$base_python" -m venv --upgrade-deps "${root}/.venv" >>"$log" 2>&1; then
    return 0
  fi
  if "$base_python" -m venv "${root}/.venv" >>"$log" 2>&1; then
    return 0
  fi

  echo "venv create failed — trying passwordless apt python3-venv/pip" >>"$log"
  yarbo_try_apt_python_venv >>"$log" 2>&1 || true
  rm -rf "${root}/.venv"
  if "$base_python" -m venv --upgrade-deps "${root}/.venv" >>"$log" 2>&1 \
    || "$base_python" -m venv "${root}/.venv" >>"$log" 2>&1; then
    return 0
  fi
  return 1
}

yarbo_pip_install_pyatv() {
  local py="${1:?}"
  local log="${2:?}"
  local err_file
  err_file="$(mktemp)"

  if "$py" -m pip install --disable-pip-version-check --upgrade 'pyatv>=0.15.1' >>"$log" 2>"$err_file"; then
    cat "$err_file" >>"$log" 2>/dev/null || true
    rm -f "$err_file"
    yarbo_pyatv_installed "$py" && return 0
  else
    cat "$err_file" >>"$log" 2>/dev/null || true
  fi

  if "$py" -m pip install --disable-pip-version-check --break-system-packages 'pyatv>=0.15.1' >>"$log" 2>"$err_file" \
    || "$py" -m pip install --disable-pip-version-check --user --break-system-packages 'pyatv>=0.15.1' >>"$log" 2>"$err_file"; then
    cat "$err_file" >>"$log" 2>/dev/null || true
    rm -f "$err_file"
    yarbo_pyatv_installed "$py" && return 0
  else
    cat "$err_file" >>"$log" 2>/dev/null || true
  fi
  rm -f "$err_file"
  return 1
}

# Ensure project .venv exists and has pyatv. Logs to data/homepod-agent.log.
# Returns 0 when import pyatv works for the resolved HomePod python.
yarbo_ensure_pyatv() {
  local root="${1:?}"
  local log py base_python
  mkdir -p "${root}/data/homepod" "${root}/data"
  log="$(yarbo_homepod_log "$root")"

  {
    echo "==> yarbo_ensure_pyatv $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  } >>"$log"

  if command -v python3 >/dev/null 2>&1; then
    base_python=python3
  elif command -v python >/dev/null 2>&1; then
    base_python=python
  else
    echo "ERROR: no python3 for pyatv" >>"$log"
    return 1
  fi

  if yarbo_homepod_ensure_venv "$root" "$base_python" "$log"; then
    echo "venv ready" >>"$log"
  else
    echo "WARNING: could not create .venv — using system python" >>"$log"
  fi

  py="$(yarbo_homepod_python "$root" || true)"
  if [[ -z "$py" ]]; then
    echo "ERROR: no python3 for pyatv" >>"$log"
    return 1
  fi
  echo "python=${py}" >>"$log"

  if yarbo_pyatv_installed "$py"; then
    echo "pyatv >= 0.15.1 already importable" >>"$log"
    return 0
  fi
  echo "pyatv missing or older than 0.15.1 — upgrading" >>"$log"

  if ! "$py" -m pip --version >/dev/null 2>&1; then
    echo "pip missing — trying ensurepip / apt" >>"$log"
    "$py" -m ensurepip --upgrade >>"$log" 2>&1 || true
    if ! "$py" -m pip --version >/dev/null 2>&1; then
      yarbo_try_apt_python_venv >>"$log" 2>&1 || true
      # Recreate venv after apt if we were on a broken one.
      if [[ "$py" == "${root}/.venv/"* ]]; then
        yarbo_homepod_ensure_venv "$root" "$base_python" "$log" || true
        py="$(yarbo_homepod_python "$root" || true)"
      fi
    fi
  fi

  if [[ -z "$py" ]]; then
    echo "ERROR: no usable python after pip bootstrap" >>"$log"
    return 1
  fi

  echo "Installing pyatv into ${py}" >>"$log"
  if yarbo_pip_install_pyatv "$py" "$log"; then
    echo "pyatv installed ok" >>"$log"
    return 0
  fi

  # Final fallback: system python3 even if venv exists but pip failed there.
  if [[ "$py" == "${root}/.venv/"* ]] && command -v python3 >/dev/null 2>&1; then
    echo "Retrying pyatv on system python3" >>"$log"
    if yarbo_pip_install_pyatv "$(command -v python3)" "$log"; then
      echo "pyatv installed on system python3" >>"$log"
      return 0
    fi
  fi

  echo "ERROR: pyatv still not importable after pip" >>"$log"
  return 1
}
