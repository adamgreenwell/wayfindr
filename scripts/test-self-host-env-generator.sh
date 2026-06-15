#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GENERATOR="$ROOT_DIR/scripts/self-host/generate-env.sh"
TMP_DIR="$(mktemp -d)"
ENV_FILE="$TMP_DIR/wayfindr.env"
CONFIG_JSON_FILE="$TMP_DIR/compose-rendered.json"

cleanup() {
    rm -rf "$TMP_DIR"
}

trap cleanup EXIT

"$GENERATOR" --output "$ENV_FILE" --app-url "https://support.example.test"

grep -E '^APP_KEY=base64:.+' "$ENV_FILE" >/dev/null
grep -E '^DB_PASSWORD=[0-9a-f]{48}$' "$ENV_FILE" >/dev/null
grep -E '^POSTGRES_PASSWORD=[0-9a-f]{48}$' "$ENV_FILE" >/dev/null
grep -E '^REVERB_APP_KEY=[0-9a-f]{32}$' "$ENV_FILE" >/dev/null
grep -E '^REVERB_APP_SECRET=[0-9a-f]{64}$' "$ENV_FILE" >/dev/null
grep -F 'APP_URL=https://support.example.test' "$ENV_FILE" >/dev/null
grep -F 'REVERB_HOST=support.example.test' "$ENV_FILE" >/dev/null
grep -F 'SESSION_SECURE_COOKIE=true' "$ENV_FILE" >/dev/null
grep -F 'MAIL_MAILER=log' "$ENV_FILE" >/dev/null

DB_PASSWORD="$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)"
POSTGRES_PASSWORD="$(grep '^POSTGRES_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)"

if [ "$DB_PASSWORD" != "$POSTGRES_PASSWORD" ]; then
    echo "DB_PASSWORD and POSTGRES_PASSWORD should match." >&2
    exit 1
fi

if "$GENERATOR" --output "$ENV_FILE" --app-url "https://support.example.test" >/dev/null 2>&1; then
    echo "Generator should refuse to overwrite an existing env file." >&2
    exit 1
fi

"$GENERATOR" --force --output "$ENV_FILE" --app-url "http://127.0.0.1:8000" >/dev/null
grep -F 'SESSION_SECURE_COOKIE=false' "$ENV_FILE" >/dev/null
grep -F 'REVERB_HOST=127.0.0.1' "$ENV_FILE" >/dev/null

docker compose --env-file "$ENV_FILE" -f "$ROOT_DIR/docker/self-hosting/compose.prototype.yml" config --format json > "$CONFIG_JSON_FILE"

python3 - "$CONFIG_JSON_FILE" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    config = json.load(handle)

for service in ("web", "queue", "scheduler", "reverb"):
    env = config["services"][service]["environment"]

    if env["APP_KEY"] == "base64:replace-with-generated-key":
        raise SystemExit(f"{service} kept the placeholder APP_KEY")

    if env["DB_PASSWORD"] != env["POSTGRES_PASSWORD"]:
        raise SystemExit(f"{service} rendered mismatched database passwords")

    if env["REVERB_APP_SECRET"] == "replace-with-private-reverb-secret":
        raise SystemExit(f"{service} kept the placeholder Reverb secret")
PY

echo "Self-host env generator creates safe starter values and renders through Compose."
