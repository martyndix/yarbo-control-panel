#!/usr/bin/env bash
# Shared helpers for optional yarbo-data-sdk (cloud map/plan reads).
# Sourced by scripts/install.sh and scripts/update.sh.

yarbo_python_bin() {
  if command -v python3 >/dev/null 2>&1; then
    echo python3
  elif command -v python >/dev/null 2>&1; then
    echo python
  fi
}

yarbo_sdk_installed() {
  local python="${1:?}"
  "$python" -c "
try:
    import yarbo_robot_sdk
except ImportError:
    import yarbo_data_sdk
" 2>/dev/null
}

ensure_python_pip() {
  local python="${1:?}"

  if "$python" -m pip --version >/dev/null 2>&1; then
    return 0
  fi

  if [[ "${EUID:-$(id -u)}" -eq 0 ]] && command -v apt-get >/dev/null 2>&1; then
    echo "    Installing python3-pip (apt)"
    export DEBIAN_FRONTEND=noninteractive
    apt-get install -y python3-pip >/dev/null
  fi

  "$python" -m pip --version >/dev/null 2>&1
}

install_yarbo_data_sdk() {
  local python="${1:?}"
  local err_file

  if yarbo_sdk_installed "$python"; then
    return 0
  fi

  if ! ensure_python_pip "$python"; then
    echo "    pip is not available for ${python}" >&2
    echo "    Install with: sudo apt install python3-pip" >&2
    return 1
  fi

  err_file="$(mktemp)"
  if "$python" -m pip install --user yarbo-data-sdk 2>"$err_file"; then
    rm -f "$err_file"
    yarbo_sdk_installed "$python"
    return $?
  fi

  if grep -qi 'externally-managed-environment' "$err_file" 2>/dev/null; then
    rm -f "$err_file"
    if "$python" -m pip install --user --break-system-packages yarbo-data-sdk; then
      yarbo_sdk_installed "$python"
      return $?
    fi
    return 1
  fi

  if [[ -s "$err_file" ]]; then
    sed 's/^/    /' "$err_file" >&2
  fi
  rm -f "$err_file"
  return 1
}
