#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/docker/self-hosting/compose.yml"
TMP_DIR="$(mktemp -d)"
ENV_FILE="$TMP_DIR/wayfindr-smoke.env"
CONFIG_FILE="$TMP_DIR/compose-rendered.yml"
CONFIG_JSON_FILE="$TMP_DIR/compose-rendered.json"

cleanup() {
    rm -rf "$TMP_DIR"
}

trap cleanup EXIT

if ! command -v python3 >/dev/null 2>&1; then
    echo "Missing required command: python3" >&2
    exit 1
fi

cat > "$ENV_FILE" <<'ENV'
WAYFINDR_IMAGE=wayfindr-server:local
WAYFINDR_ENV_FILE=__ENV_FILE__
SERVER_NAME=:80
WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:18080
WAYFINDR_PUBLIC_HTTPS_BIND=127.0.0.1:18443
WAYFINDR_LOCAL_BIND=127.0.0.1:18000
WAYFINDR_PHP_VERSION=8.4
WAYFINDR_NODE_VERSION=24

APP_NAME=Wayfindr
APP_ENV=production
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
APP_DEBUG=false
APP_URL=http://127.0.0.1:18000

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

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=wayfindr-smoke
REVERB_APP_KEY=wayfindr-smoke-public-key
REVERB_APP_SECRET=wayfindr-smoke-private-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=18000
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
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" config --format json > "$CONFIG_JSON_FILE"

grep -F "$ENV_FILE" "$CONFIG_FILE" >/dev/null

python3 - "$CONFIG_JSON_FILE" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    config = json.load(handle)


def assert_port(service, expected):
    ports = config["services"][service]["ports"]

    for port in ports:
        actual = {
            "host_ip": port.get("host_ip"),
            "published": str(port.get("published")),
            "target": int(port.get("target")),
        }

        if actual == expected:
            return

    raise SystemExit(
        f"{service} does not expose {expected}; rendered ports were {ports}"
    )


# The three web binds: proxy-safe HTTP/HTTPS on loopback high ports and the
# local plain-HTTP bind Caddy actually serves in SERVER_NAME=:80 mode.
assert_port(
    "web",
    {
        "host_ip": "127.0.0.1",
        "published": "18080",
        "target": 80,
    },
)
assert_port(
    "web",
    {
        "host_ip": "127.0.0.1",
        "published": "18443",
        "target": 443,
    },
)
assert_port(
    "web",
    {
        "host_ip": "127.0.0.1",
        "published": "18000",
        "target": 8000,
    },
)

# Reverb publishes no host port: websockets ride the web service's Caddy
# under /app and /apps.
if config["services"]["reverb"].get("ports"):
    raise SystemExit("reverb should not publish host ports; Caddy proxies it")

# Workers must not inherit the base image healthcheck (Caddy-admin probe).
for service in ("queue", "scheduler"):
    healthcheck = config["services"][service].get("healthcheck") or {}
    if not healthcheck.get("disable"):
        raise SystemExit(f"{service} should disable the image healthcheck")

# Split horizon: the server posts events to the reverb service, browsers get
# the public client values from the env file.
web_env = config["services"]["web"]["environment"]
if web_env.get("REVERB_HOST") != "reverb":
    raise SystemExit("web should post events to the reverb service internally")
if web_env.get("REVERB_CLIENT_HOST") != "127.0.0.1":
    raise SystemExit(f"web browser host should come from the env file, got {web_env.get('REVERB_CLIENT_HOST')!r}")
PY

# A pre-client-vars env (public REVERB_* only) must still surface its public
# values to browsers: the compose interpolation falls back to them instead of
# leaking the internal reverb override.
LEGACY_ENV_FILE="$TMP_DIR/wayfindr-legacy.env"
LEGACY_JSON_FILE="$TMP_DIR/compose-legacy.json"

cat > "$LEGACY_ENV_FILE" <<'ENV'
WAYFINDR_IMAGE=wayfindr-server:local
WAYFINDR_ENV_FILE=__ENV_FILE__
POSTGRES_PASSWORD=legacy-password
REVERB_HOST=support.example.com
REVERB_PORT=443
REVERB_SCHEME=https
ENV

sed -i.bak "s#__ENV_FILE__#$LEGACY_ENV_FILE#g" "$LEGACY_ENV_FILE"
rm -f "$LEGACY_ENV_FILE.bak"

docker compose --env-file "$LEGACY_ENV_FILE" -f "$COMPOSE_FILE" config --format json > "$LEGACY_JSON_FILE"

python3 - "$LEGACY_JSON_FILE" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    config = json.load(handle)

web_env = config["services"]["web"]["environment"]

if web_env.get("REVERB_CLIENT_HOST") != "support.example.com":
    raise SystemExit(
        "legacy env should surface its public host to browsers, got "
        f"{web_env.get('REVERB_CLIENT_HOST')!r}"
    )

ports = config["services"]["web"]["ports"]
published = [str(port.get("published")) for port in ports]

if published.count("8000") > 1:
    raise SystemExit(f"legacy env double-maps port 8000: {ports}")
PY

echo "Self-host Compose template renders with an isolated env file and smoke ports."
