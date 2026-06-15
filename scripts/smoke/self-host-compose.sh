#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/docker/self-hosting/compose.prototype.yml"
PROJECT_NAME="${WAYFINDR_SMOKE_PROJECT:-wayfindr-self-host-smoke}"
HTTP_PORT="${WAYFINDR_SMOKE_HTTP_PORT:-18080}"
REVERB_PORT="${WAYFINDR_SMOKE_REVERB_PORT:-18081}"
KEEP_STACK="${WAYFINDR_SMOKE_KEEP:-0}"
TMP_DIR="$(mktemp -d)"
ENV_FILE="$TMP_DIR/wayfindr-smoke.env"
SCHEDULE_FILE="$TMP_DIR/schedule-list.txt"

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Missing required command: $1" >&2
        exit 1
    fi
}

cleanup() {
    if [ "$KEEP_STACK" != "1" ]; then
        docker compose --project-name "$PROJECT_NAME" --env-file "$ENV_FILE" -f "$COMPOSE_FILE" down -v --remove-orphans >/dev/null 2>&1 || true
    else
        echo "Smoke stack left running because WAYFINDR_SMOKE_KEEP=1."
        echo "Stop it with: docker compose --project-name $PROJECT_NAME --env-file $ENV_FILE -f $COMPOSE_FILE down -v --remove-orphans"
    fi

    if [ "$KEEP_STACK" != "1" ]; then
        rm -rf "$TMP_DIR"
    else
        echo "Smoke env kept at: $ENV_FILE"
    fi
}

wait_for_http() {
    local url="$1"

    for _ in $(seq 1 60); do
        if curl --fail --silent --show-error --max-time 2 "$url" >/dev/null 2>&1; then
            return 0
        fi

        sleep 1
    done

    echo "Timed out waiting for $url" >&2
    return 1
}

retry_compose() {
    for _ in $(seq 1 30); do
        if docker compose --project-name "$PROJECT_NAME" --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"; then
            return 0
        fi

        sleep 2
    done

    return 1
}

compose() {
    docker compose --project-name "$PROJECT_NAME" --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

assert_services_running() {
    local missing=()
    local running_services

    for _ in $(seq 1 30); do
        missing=()

        for service in "$@"; do
            running_services="$(compose ps --services --status running "$service")"

            if ! grep -Fx "$service" <<< "$running_services" >/dev/null; then
                missing+=("$service")
            fi
        done

        if [ "${#missing[@]}" -eq 0 ]; then
            return 0
        fi

        sleep 1
    done

    echo "Expected services are not running: ${missing[*]}" >&2
    compose ps >&2 || true

    for service in "${missing[@]}"; do
        echo "--- Recent logs for $service ---" >&2
        compose logs --tail 80 "$service" >&2 || true
    done

    return 1
}

require_command docker
require_command curl
require_command openssl

docker info >/dev/null

APP_KEY="base64:$(openssl rand -base64 32)"
DB_PASSWORD="$(openssl rand -hex 24)"
REVERB_APP_KEY="$(openssl rand -hex 16)"
REVERB_APP_SECRET="$(openssl rand -hex 32)"

cat > "$ENV_FILE" <<ENV
WAYFINDR_IMAGE=wayfindr-server:local
WAYFINDR_ENV_FILE=$ENV_FILE
WAYFINDR_HTTP_BIND=127.0.0.1:$HTTP_PORT
WAYFINDR_REVERB_BIND=127.0.0.1:$REVERB_PORT
WAYFINDR_PHP_VERSION=8.4
WAYFINDR_NODE_VERSION=24

APP_NAME=Wayfindr
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=http://127.0.0.1:$HTTP_PORT
WAYFINDR_VERSION=smoke
WAYFINDR_COMMIT=local

DB_DATABASE=wayfindr
DB_USERNAME=wayfindr
DB_PASSWORD=$DB_PASSWORD

POSTGRES_DB=wayfindr
POSTGRES_USER=wayfindr
POSTGRES_PASSWORD=$DB_PASSWORD

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=redis
REDIS_PASSWORD=
REDIS_PORT=6379

BROADCAST_CONNECTION=log
REVERB_APP_ID=wayfindr-smoke
REVERB_APP_KEY=$REVERB_APP_KEY
REVERB_APP_SECRET=$REVERB_APP_SECRET
REVERB_HOST=127.0.0.1
REVERB_PORT=$REVERB_PORT
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_SERVER_PATH=

MAIL_MAILER=log
MAIL_FROM_ADDRESS=support@example.test
MAIL_FROM_NAME=Wayfindr
ENV

trap cleanup EXIT

echo "Rendering Compose config for $PROJECT_NAME."
docker compose --project-name "$PROJECT_NAME" --env-file "$ENV_FILE" -f "$COMPOSE_FILE" config >/dev/null

echo "Building the prototype Wayfindr image."
docker compose --project-name "$PROJECT_NAME" --env-file "$ENV_FILE" -f "$COMPOSE_FILE" build web

echo "Starting the self-host smoke stack."
docker compose --project-name "$PROJECT_NAME" --env-file "$ENV_FILE" -f "$COMPOSE_FILE" up -d postgres redis web queue scheduler reverb
assert_services_running queue scheduler reverb

echo "Running migrations."
retry_compose exec -T web php artisan migrate --force

echo "Inspecting scheduled tasks."
docker compose --project-name "$PROJECT_NAME" --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T web php artisan schedule:list | tee "$SCHEDULE_FILE"
grep -F "wayfindr:send-alert-digests" "$SCHEDULE_FILE" >/dev/null

echo "Checking /up."
wait_for_http "http://127.0.0.1:$HTTP_PORT/up"
assert_services_running queue scheduler reverb

echo "Self-host Compose smoke passed for $PROJECT_NAME."
