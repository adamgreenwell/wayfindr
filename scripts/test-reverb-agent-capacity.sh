#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
harness="$root_dir/scripts/smoke/reverb-agent-capacity.cjs"
output="$(mktemp)"
trap 'rm -f "$output"' EXIT

common_environment=(
    WAYFINDR_REVERB_URL=ws://127.0.0.1:8080
    WAYFINDR_REVERB_APP_KEY=test-key
    WAYFINDR_REVERB_PID=1
    WAYFINDR_CAPACITY_DISPOSABLE=YES
    WAYFINDR_CAPACITY_PHP_BINARY=php
    WAYFINDR_CAPACITY_DATABASE_PLACEMENT=disposable
    WAYFINDR_CAPACITY_REDIS_PLACEMENT=disposable
    WAYFINDR_CAPACITY_REVERSE_PROXY=none
    WAYFINDR_CAPACITY_PHP_WORKERS=1
    WAYFINDR_CAPACITY_QUEUE_WORKERS=0
    WAYFINDR_CAPACITY_REVERB_PROCESSES=1
    WAYFINDR_CAPACITY_REVERB_CONFIGURATION=test
    WAYFINDR_CAPACITY_REVERB_PING_INTERVAL_SECONDS=60
)

node --check "$harness"

if env "${common_environment[@]}" \
    WAYFINDR_BASE_URL=https://support.example.com \
    node "$harness" >"$output" 2>&1; then
    echo "Capacity harness accepted a remote HTTP target." >&2
    exit 1
fi

grep -F 'HTTP target must be loopback' "$output" >/dev/null

if env "${common_environment[@]}" \
    WAYFINDR_BASE_URL=http://127.0.0.1:8000 \
    WAYFINDR_REVERB_URL=wss://support.example.com \
    node "$harness" >"$output" 2>&1; then
    echo "Capacity harness accepted a remote WebSocket target." >&2
    exit 1
fi

grep -F 'WebSocket target must be loopback' "$output" >/dev/null

if env "${common_environment[@]}" \
    WAYFINDR_BASE_URL=http://127.0.0.1:8000 \
    WAYFINDR_CAPACITY_DISPOSABLE=NO \
    node "$harness" >"$output" 2>&1; then
    echo "Capacity harness ran without the disposable-target confirmation." >&2
    exit 1
fi

grep -F 'Set WAYFINDR_CAPACITY_DISPOSABLE=YES' "$output" >/dev/null

if env "${common_environment[@]}" \
    WAYFINDR_BASE_URL=http://127.0.0.1:8000 \
    WAYFINDR_CAPACITY_HOLD_SECONDS=69 \
    node "$harness" >"$output" 2>&1; then
    echo "Capacity harness accepted a hold too short to exercise the keepalive boundary." >&2
    exit 1
fi

grep -F 'requires at least a 70-second keepalive hold' "$output" >/dev/null

echo "Reverb capacity harness safety guards are intact."
