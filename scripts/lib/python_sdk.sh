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

yarbo_venv_python() {
  local root="${1:?}"
  local venv_py="${root}/.venv/bin/python3"
  if [[ -x "$venv_py" ]]; then
    echo "$venv_py"
  fi
}

yarbo_resolve_python() {
  local root="${1:?}"
  local venv_py
  venv_py="$(yarbo_venv_python "$root" || true)"
  if [[ -n "$venv_py" ]]; then
    echo "$venv_py"
    return 0
  fi
  yarbo_python_bin
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
    apt-get install -y python3-pip python3-venv >/dev/null
  fi

  "$python" -m pip --version >/dev/null 2>&1
}

ensure_yarbo_venv() {
  local root="${1:?}"
  local base_python="${2:-}"
  local venv_py err_file

  if [[ -z "$base_python" ]]; then
    base_python="$(yarbo_python_bin || true)"
  fi
  if [[ -z "$base_python" ]]; then
    echo "    Python 3 not found — cannot create venv" >&2
    return 1
  fi

  if ! ensure_python_pip "$base_python"; then
    echo "    pip is not available for ${base_python}" >&2
    echo "    Install with: sudo apt install python3-pip python3-venv" >&2
    return 1
  fi

  venv_py="${root}/.venv/bin/python3"
  if [[ ! -x "$venv_py" ]]; then
    echo "    Creating project venv at ${root}/.venv"
    if ! "$base_python" -m venv "${root}/.venv"; then
      echo "    Failed to create venv" >&2
      return 1
    fi
  fi

  if yarbo_sdk_installed "$venv_py"; then
    return 0
  fi

  echo "    Installing yarbo-data-sdk + python-yarbo into project venv"
  err_file="$(mktemp)"
  if "$venv_py" -m pip install --upgrade pip >/dev/null 2>"$err_file" \
    && "$venv_py" -m pip install yarbo-data-sdk python-yarbo 2>"$err_file"; then
    rm -f "$err_file"
    yarbo_sdk_installed "$venv_py"
    return $?
  fi

  if grep -qi 'externally-managed-environment' "$err_file" 2>/dev/null; then
    rm -f "$err_file"
    if "$venv_py" -m pip install yarbo-data-sdk python-yarbo; then
      yarbo_sdk_installed "$venv_py"
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

install_yarbo_data_sdk() {
  local python="${1:?}"
  local root="${2:-}"
  local err_file

  if [[ -n "$root" ]] && ensure_yarbo_venv "$root" "$python"; then
    return 0
  fi

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
