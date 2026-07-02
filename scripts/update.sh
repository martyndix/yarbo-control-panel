#!/usr/bin/env bash
# Yarbo Control Panel — pull latest code, refresh dependencies, restart service
#
# Usage:
#   ./scripts/update.sh              Update to latest origin/main
#   ./scripts/update.sh --check-only Report if updates are available (no changes)
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SERVICE_NAME="yarbo-panel"
BRANCH="${YARBO_PANEL_BRANCH:-main}"
CHECK_ONLY=false
STEPS=()

for arg in "$@"; do
  case "$arg" in
    --check-only) CHECK_ONLY=true ;;
    -h|--help)
      echo "Usage: ./scripts/update.sh [--check-only]"
      exit 0
      ;;
  esac
done

fail() {
  local message="$1"
  php -r 'echo json_encode([
    "ok" => false,
    "error" => $argv[1],
    "steps" => array_values(array_filter(explode("\n", $argv[2]))),
  ], JSON_UNESCAPED_SLASHES) . "\n";' "$message" "$(printf '%s\n' "${STEPS[@]}")"
  exit 1
}

step() {
  STEPS+=("$1")
}

run_owner() {
  if [[ -n "${SUDO_USER:-}" && "${EUID}" -eq 0 ]]; then
    sudo -u "${SUDO_USER}" -H bash -c "cd '$ROOT' && $*"
  else
    bash -c "cd '$ROOT' && $*"
  fi
}

if [[ ! -d .git ]]; then
  fail "This folder is not a git clone. Reinstall with: git clone https://github.com/martyndix/yarbo-control-panel.git"
fi

if ! command -v git >/dev/null 2>&1; then
  fail "git is not installed"
fi

CURRENT="$(git rev-parse HEAD)"
CURRENT_SHORT="$(git rev-parse --short HEAD)"
step "Current commit: ${CURRENT_SHORT}"

if ! git remote get-url origin >/dev/null 2>&1; then
  fail "No git remote named origin configured"
fi

step "Fetching origin/${BRANCH}"
git fetch --quiet origin "${BRANCH}" 2>&1 || fail "git fetch failed"

REMOTE_REF="origin/${BRANCH}"
if ! git rev-parse --verify "${REMOTE_REF}" >/dev/null 2>&1; then
  fail "Remote branch ${REMOTE_REF} not found"
fi

REMOTE="$(git rev-parse "${REMOTE_REF}")"
REMOTE_SHORT="$(git rev-parse --short "${REMOTE_REF}")"
BEHIND=false
if [[ "$CURRENT" != "$REMOTE" ]]; then
  BEHIND=true
fi

CHANGELOG_VERSION=""
if [[ -f CHANGELOG.md ]]; then
  CHANGELOG_VERSION="$(grep '^## \[' CHANGELOG.md | grep -v '\[Unreleased\]' | head -n1 | sed 's/^## \[\([^]]*\)\].*/\1/' || true)"
fi

SYSTEMD_ACTIVE=false
if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet "${SERVICE_NAME}" 2>/dev/null; then
  SYSTEMD_ACTIVE=true
fi

if $CHECK_ONLY; then
  php -r 'echo json_encode([
    "ok" => true,
    "check_only" => true,
    "current_commit" => $argv[1],
    "current_commit_short" => $argv[2],
    "remote_commit" => $argv[3],
    "remote_commit_short" => $argv[4],
    "update_available" => $argv[5] === "true",
    "changelog_version" => $argv[6] !== "" ? $argv[6] : null,
    "systemd_active" => $argv[7] === "true",
    "steps" => array_values(array_filter(explode("\n", $argv[8]))),
  ], JSON_UNESCAPED_SLASHES) . "\n";' \
    "$CURRENT" "$CURRENT_SHORT" "$REMOTE" "$REMOTE_SHORT" "$($BEHIND && echo true || echo false)" \
    "$CHANGELOG_VERSION" "$($SYSTEMD_ACTIVE && echo true || echo false)" "$(printf '%s\n' "${STEPS[@]}")"
  exit 0
fi

if ! $BEHIND; then
  php -r 'echo json_encode([
    "ok" => true,
    "updated" => false,
    "message" => "Already on latest commit",
    "current_commit" => $argv[1],
    "current_commit_short" => $argv[2],
    "remote_commit" => $argv[3],
    "remote_commit_short" => $argv[4],
    "update_available" => false,
    "changelog_version" => $argv[5] !== "" ? $argv[5] : null,
    "systemd_active" => $argv[6] === "true",
    "restarted" => false,
    "steps" => array_values(array_filter(explode("\n", $argv[7]))),
  ], JSON_UNESCAPED_SLASHES) . "\n";' \
    "$CURRENT" "$CURRENT_SHORT" "$REMOTE" "$REMOTE_SHORT" "$CHANGELOG_VERSION" \
    "$($SYSTEMD_ACTIVE && echo true || echo false)" "$(printf '%s\n' "${STEPS[@]}")"
  exit 0
fi

step "Pulling origin/${BRANCH} (fast-forward only)"
if ! run_owner "git pull --ff-only origin ${BRANCH}"; then
  fail "git pull failed. Resolve local changes (e.g. git stash) and try again."
fi

NEW="$(git rev-parse HEAD)"
NEW_SHORT="$(git rev-parse --short HEAD)"
step "Now at commit: ${NEW_SHORT}"

if ! command -v composer >/dev/null 2>&1; then
  fail "composer is not installed"
fi

step "Running composer install"
if ! run_owner "composer install --no-dev --optimize-autoloader"; then
  fail "composer install failed"
fi

PYTHON=""
if command -v python3 >/dev/null 2>&1; then
  PYTHON=python3
elif command -v python >/dev/null 2>&1; then
  PYTHON=python
fi

if [[ -n "$PYTHON" ]]; then
  step "Checking optional yarbo-data-sdk"
  if ! run_owner "$PYTHON -c 'import yarbo_data_sdk'" 2>/dev/null; then
    run_owner "$PYTHON -m pip install --user yarbo-data-sdk" >/dev/null 2>&1 || true
  fi
fi

RESTARTED=false
if $SYSTEMD_ACTIVE; then
  step "Restarting systemd service ${SERVICE_NAME}"
  if sudo -n systemctl restart "${SERVICE_NAME}" 2>/dev/null; then
    RESTARTED=true
    step "Service restarted"
  else
    step "Could not restart automatically — run: sudo systemctl restart ${SERVICE_NAME}"
  fi
fi

php -r 'echo json_encode([
  "ok" => true,
  "updated" => true,
  "message" => "Panel updated successfully",
  "previous_commit" => $argv[1],
  "previous_commit_short" => $argv[2],
  "current_commit" => $argv[3],
  "current_commit_short" => $argv[4],
  "remote_commit" => $argv[3],
  "remote_commit_short" => $argv[4],
  "update_available" => false,
  "changelog_version" => $argv[5] !== "" ? $argv[5] : null,
  "systemd_active" => $argv[6] === "true",
  "restarted" => $argv[7] === "true",
  "steps" => array_values(array_filter(explode("\n", $argv[8]))),
], JSON_UNESCAPED_SLASHES) . "\n";' \
  "$CURRENT" "$CURRENT_SHORT" "$NEW" "$NEW_SHORT" "$CHANGELOG_VERSION" \
  "$($SYSTEMD_ACTIVE && echo true || echo false)" "$($RESTARTED && echo true || echo false)" \
  "$(printf '%s\n' "${STEPS[@]}")"
