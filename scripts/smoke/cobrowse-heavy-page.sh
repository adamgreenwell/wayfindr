#!/usr/bin/env bash
set -euo pipefail

: "${WAYFINDR_BASE_URL:?Set WAYFINDR_BASE_URL, for example http://127.0.0.1:8000}"
: "${WAYFINDR_AGENT_EMAIL:?Set WAYFINDR_AGENT_EMAIL to a disposable measurement-desk agent}"
: "${WAYFINDR_AGENT_PASSWORD:?Set WAYFINDR_AGENT_PASSWORD}"

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v npx >/dev/null 2>&1; then
    echo "Missing required command: npx" >&2
    exit 1
fi

WAYFINDR_COBROWSE_SCRIPT="$script_dir/cobrowse-heavy-page.cjs" \
    npx --yes --package playwright --call 'NODE_PATH="$(dirname "$(dirname "$(command -v playwright)")")" node "$WAYFINDR_COBROWSE_SCRIPT"'
