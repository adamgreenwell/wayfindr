#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/docker/self-hosting/compose.prototype.yml"
TMP_DIR="$(mktemp -d)"
ENV_FILE="$TMP_DIR/wayfindr-smoke.env"
CONFIG_FILE="$TMP_DIR/compose-rendered.yml"

cleanup() {
    rm -rf "$TMP_DIR"
}

trap cleanup EXIT

cat > "$ENV_FILE" <<'ENV'
WAYFINDR_IMAGE=wayfindr-server:local
WAYFINDR_ENV_FILE=__ENV_FILE__
WAYFINDR_HTTP_BIND=127.0.0.1:18080
WAYFINDR_REVERB_BIND=127.0.0.1:18081
WAYFINDR_PHP_VERSION=8.4
WAYFINDR_NODE_VERSION=24

APP_NAME=Wayfindr
APP_ENV=production
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
APP_DEBUG=false
APP_URL=http://127.0.0.1:18080

DB_DATABASE=wayfindr
DB_USERNAME=wayfindr
DB_PASSWORD=wayfindr-smoke-password

POSTGRES_DB=wayfindr
POSTGRES_USER=wayfindr
POSTGRES_PASSWORD=wayfindr-smoke-password

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
REVERB_APP_KEY=wayfindr-smoke-public-key
REVERB_APP_SECRET=wayfindr-smoke-private-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=18081
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_SERVER_PATH=

MAIL_MAILER=log
MAIL_FROM_ADDRESS=support@example.test
MAIL_FROM_NAME="${APP_NAME}"
ENV

sed -i.bak "s#__ENV_FILE__#$ENV_FILE#g" "$ENV_FILE"
rm -f "$ENV_FILE.bak"

docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" config > "$CONFIG_FILE"

grep -F "$ENV_FILE" "$CONFIG_FILE" >/dev/null
grep -F "127.0.0.1:18080" "$CONFIG_FILE" >/dev/null
grep -F "127.0.0.1:18081" "$CONFIG_FILE" >/dev/null

echo "Self-host Compose template renders with an isolated env file and smoke ports."
