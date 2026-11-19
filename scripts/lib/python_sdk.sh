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

  echo "    pip missing for ${python} — trying ensurepip"
  if "$python" -m ensurepip --upgrade >/dev/null 2>&1; then
    "$python" -m pip --version >/dev/null 2>&1 && return 0
  fi

  if [[ "${EUID:-$(id -u)}" -eq 0 ]] && command -v apt-get >/dev/null 2>&1; then
    echo "    Installing python3-pip (apt)"
    export DEBIAN_FRONTEND=noninteractive
    apt-get install -y python3-pip python3-venv >/dev/null
  fi

  if "$python" -m pip --version >/dev/null 2>&1; then
    return 0
  fi

  echo "    pip is not available for ${python}" >&2
  if [[ "$(uname -s)" == "Darwin" ]]; then
    echo "    On macOS: brew install python && python3 -m ensurepip --upgrade" >&2
  else
    echo "    Install with: sudo apt install python3-pip python3-venv" >&2
  fi
  return 1
}

ensure_venv_has_pip() {
  local venv_py="${1:?}"
  local base_python="${2:?}"
  local root="${3:?}"

  if "$venv_py" -m pip --version >/dev/null 2>&1; then
    return 0
  fi

  echo "    Project venv is missing pip — trying ensurepip"
  if "$venv_py" -m ensurepip --upgrade >/dev/null 2>&1 \
    && "$venv_py" -m pip --version >/dev/null 2>&1; then
    return 0
  fi

  echo "    Recreating venv with pip (python -m venv --upgrade-deps)"
  if "$base_python" -m venv --upgrade-deps "${root}/.venv" \
    && "$venv_py" -m pip --version >/dev/null 2>&1; then
    return 0
  fi

  echo "    Could not install pip into ${root}/.venv" >&2
  return 1
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
    if ! "$base_python" -m venv --upgrade-deps "${root}/.venv" 2>/dev/null; then
      if ! "$base_python" -m venv "${root}/.venv"; then
        echo "    Failed to create venv" >&2
        return 1
      fi
    fi
  fi

  if ! ensure_venv_has_pip "$venv_py" "$base_python" "$root"; then
    return 1
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

# Agora's Python server SDK carries the live camera pictures. It is not
# committed. Panel updates and Start live view both install it.
python_is_310() {
  local py="${1:?}"
  "$py" -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 10) else 1)' >/dev/null 2>&1
}

agora_pick_python() {
  local root="${1:?}"
  local candidate path
  # Never use the Yarbo .venv. The video library stays in its own environment
  # so installing it cannot knock the robot connection offline.
  if [[ -x "${root}/.venv-camera/bin/python3" ]] && python_is_310 "${root}/.venv-camera/bin/python3"; then
    echo "${root}/.venv-camera/bin/python3"
    return 0
  fi
  for candidate in python3.13 python3.12 python3.11 python3.10 python3; do
    path="$(command -v "$candidate" 2>/dev/null || true)"
    if [[ -n "$path" ]] && python_is_310 "$path"; then
      echo "$path"
      return 0
    fi
  done
  return 1
}

install_agora_camera_sdk() {
  local root="${1:?}"
  local base err_file
  base="$(agora_pick_python "$root" || true)"
  if [[ -z "$base" ]]; then
    echo "    Live cameras need Python 3.10 or newer. Install it with: sudo apt install python3.11 python3.11-venv" >&2
    return 1
  fi
  if [[ "$base" != "${root}/.venv-camera/"* ]]; then
    echo "    Creating ${root}/.venv-camera for the video library"
    if ! "$base" -m venv "${root}/.venv-camera"; then
      echo "    Could not create a Python 3.10+ environment for live cameras" >&2
      return 1
    fi
    base="${root}/.venv-camera/bin/python3"
  fi
  if ! "$base" -m pip --version >/dev/null 2>&1; then
    "$base" -m ensurepip --upgrade >/dev/null 2>&1 || true
  fi
  if "$base" -c 'import agora.rtc.agora_service' >/dev/null 2>&1; then
    mkdir -p "${root}/data"
    printf '%s\n' "$base" > "${root}/data/camera-python.txt"
    return 0
  fi
  err_file="$(mktemp)"
  if ! "$base" -m pip install --disable-pip-version-check agora_python_server_sdk >"$err_file" 2>&1; then
    if ! "$base" -m pip install --disable-pip-version-check --break-system-packages agora_python_server_sdk >"$err_file" 2>&1; then
      echo "    Live camera library install failed:" >&2
      sed 's/^/    /' "$err_file" >&2
      rm -f "$err_file"
      return 1
    fi
  fi
  rm -f "$err_file"
  if ! "$base" -c 'import agora.rtc.agora_service' >/dev/null 2>&1; then
    echo "    The video library installed but this Python cannot load it" >&2
    return 1
  fi
  mkdir -p "${root}/data"
  printf '%s\n' "$base" > "${root}/data/camera-python.txt"
}
