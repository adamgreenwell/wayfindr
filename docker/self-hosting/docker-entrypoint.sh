#!/usr/bin/env bash
set -euo pipefail

cd /app/apps/server

# Compiled views belong to this container's source, not the shared storage
# volume: an older image can leave newer cache timestamps behind on upgrade.
# Keep workers and overlapping releases from reading or clearing one another's
# templates. An empty override still gets the container-local default.
export VIEW_COMPILED_PATH="${VIEW_COMPILED_PATH:-$PWD/bootstrap/cache/views}"

# The storage volume may start empty (first boot) — recreate the tree the app
# expects. Idempotent on every start.
mkdir -p \
    storage/app/public \
    storage/app/private/attachments \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    "$VIEW_COMPILED_PATH"

# Gated automatic migrations: the compose web service opts in so a fresh
# install and every upgrade converge without a manual exec; workers leave it
# off and simply wait on the web service's health.
if [ "${WAYFINDR_AUTO_MIGRATE:-0}" = "1" ] || [ "${WAYFINDR_AUTO_MIGRATE:-false}" = "true" ]; then
    tries=0
    while true; do
        php artisan migrate --force --no-interaction && break

        # 78 is the upgrade guard refusing: this release needs the operator to do
        # something first (ADR 0013). It is not a transient failure, so retrying
        # would repeat the instructions thirty times and then report the wrong
        # cause. The guard has already printed what to do.
        status=$?
        if [ "$status" -eq 78 ]; then
            echo "wayfindr: upgrade requirements not met; refusing to start." >&2
            exit 78
        fi

        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "wayfindr: database not reachable after ${tries} attempts; giving up" >&2
            exit 1
        fi
        echo "wayfindr: waiting for the database (attempt ${tries})..." >&2
        sleep 2
    done
fi

exec "$@"
