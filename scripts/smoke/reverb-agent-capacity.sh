#!/usr/bin/env bash
set -euo pipefail

: "${WAYFINDR_BASE_URL:?Set WAYFINDR_BASE_URL to the disposable loopback HTTP origin}"
: "${WAYFINDR_REVERB_URL:?Set WAYFINDR_REVERB_URL to the disposable loopback ws:// or wss:// origin}"
: "${WAYFINDR_REVERB_APP_KEY:?Set WAYFINDR_REVERB_APP_KEY}"
: "${WAYFINDR_REVERB_PID:?Set WAYFINDR_REVERB_PID to the Reverb process to sample}"
: "${WAYFINDR_CAPACITY_DISPOSABLE:?Set WAYFINDR_CAPACITY_DISPOSABLE=YES after preparing the measurement desk}"
: "${WAYFINDR_CAPACITY_PHP_BINARY:?Set WAYFINDR_CAPACITY_PHP_BINARY to the PHP executable running Reverb}"

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
    echo "Missing required command: node" >&2
    exit 1
fi

node_major="$(node -p 'Number(process.versions.node.split(".")[0])')"

if (( node_major < 22 )); then
    echo "Node.js 22 or newer is required for built-in fetch and WebSocket support." >&2
    exit 1
fi

node "$script_dir/reverb-agent-capacity.cjs"
