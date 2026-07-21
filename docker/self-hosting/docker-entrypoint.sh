#!/usr/bin/env bash
set -euo pipefail

cd /app/apps/server

# The storage volume may start empty (first boot) — recreate the tree the app
# expects. Idempotent on every start.
mkdir -p \
    storage/app/public \
    storage/app/private/attachments \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Gated automatic migrations: the compose web service opts in so a fresh
# install and every upgrade converge without a manual exec; workers leave it
# off and simply wait on the web service's health.
if [ "${WAYFINDR_AUTO_MIGRATE:-0}" = "1" ] || [ "${WAYFINDR_AUTO_MIGRATE:-false}" = "true" ]; then
    tries=0
    until php artisan migrate --force --no-interaction; do
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
