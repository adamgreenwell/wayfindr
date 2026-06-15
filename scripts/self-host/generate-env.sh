#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEFAULT_OUTPUT="$ROOT_DIR/docker/self-hosting/.env"

OUTPUT_FILE="$DEFAULT_OUTPUT"
APP_URL=""
APP_NAME="Wayfindr"
MAIL_FROM_ADDRESS="support@example.com"
FORCE=0

usage() {
    cat <<'USAGE'
Usage:
  scripts/self-host/generate-env.sh --app-url <url> [options]

Options:
  --app-url <url>          Required public URL, such as https://support.example.com.
  --output <path>          Env file to write. Defaults to docker/self-hosting/.env.
  --app-name <name>        Application name. Defaults to Wayfindr.
  --mail-from <email>      Mail from address placeholder. Defaults to support@example.com.
  --force                  Overwrite the output file if it already exists.
  -h, --help               Show this help text.

The generated env is a starter file for the self-host Docker Compose prototype.
Review DNS, TLS, mail, scheduler, queue, Reverb, storage, and backups before
sending real visitor traffic to the instance.
USAGE
}

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Missing required command: $1" >&2
        exit 1
    fi
}

absolute_path() {
    local target="$1"
    local directory
    local basename

    directory="$(dirname "$target")"
    basename="$(basename "$target")"
    mkdir -p "$directory"

    directory="$(cd "$directory" && pwd -P)"
    printf '%s/%s\n' "$directory" "$basename"
}

url_scheme() {
    case "$APP_URL" in
        https://*) printf 'https\n' ;;
        http://*) printf 'http\n' ;;
        *)
            echo "--app-url must start with http:// or https://." >&2
            exit 1
            ;;
    esac
}

url_host() {
    local without_scheme
    local authority

    without_scheme="${APP_URL#*://}"
    authority="${without_scheme%%/*}"
    printf '%s\n' "${authority%%:*}"
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --app-url)
            APP_URL="${2:-}"
            shift 2
            ;;
        --output)
            OUTPUT_FILE="${2:-}"
            shift 2
            ;;
        --app-name)
            APP_NAME="${2:-}"
            shift 2
            ;;
        --mail-from)
            MAIL_FROM_ADDRESS="${2:-}"
            shift 2
            ;;
        --force)
            FORCE=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [ -z "$APP_URL" ]; then
    echo "--app-url is required." >&2
    usage >&2
    exit 1
fi

require_command openssl

OUTPUT_FILE="$(absolute_path "$OUTPUT_FILE")"
SCHEME="$(url_scheme)"
HOST="$(url_host)"

if [ -z "$HOST" ]; then
    echo "--app-url must include a host." >&2
    exit 1
fi

if [ -e "$OUTPUT_FILE" ] && [ "$FORCE" != "1" ]; then
    echo "$OUTPUT_FILE already exists. Re-run with --force to overwrite it." >&2
    exit 1
fi

APP_KEY="base64:$(openssl rand -base64 32)"
DB_PASSWORD="$(openssl rand -hex 24)"
REVERB_APP_KEY="$(openssl rand -hex 16)"
REVERB_APP_SECRET="$(openssl rand -hex 32)"
SECURE_COOKIE="true"
REVERB_PORT="443"

if [ "$SCHEME" = "http" ]; then
    SECURE_COOKIE="false"
    REVERB_PORT="8080"
fi

umask 077

cat > "$OUTPUT_FILE" <<ENV
WAYFINDR_IMAGE=wayfindr-server:local
WAYFINDR_ENV_FILE=$OUTPUT_FILE
WAYFINDR_HTTP_BIND=127.0.0.1:8000
WAYFINDR_REVERB_BIND=127.0.0.1:8080
WAYFINDR_PHP_VERSION=8.4
WAYFINDR_NODE_VERSION=24

APP_NAME=$APP_NAME
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=$APP_URL
WAYFINDR_VERSION=
WAYFINDR_COMMIT=

DB_DATABASE=wayfindr
DB_USERNAME=wayfindr
DB_PASSWORD=$DB_PASSWORD

POSTGRES_DB=wayfindr
POSTGRES_USER=wayfindr
POSTGRES_PASSWORD=$DB_PASSWORD

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=$SECURE_COOKIE
SESSION_SAME_SITE=lax

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=redis
REDIS_PASSWORD=
REDIS_PORT=6379

BROADCAST_CONNECTION=log
REVERB_APP_ID=wayfindr-production
REVERB_APP_KEY=$REVERB_APP_KEY
REVERB_APP_SECRET=$REVERB_APP_SECRET
REVERB_HOST=$HOST
REVERB_PORT=$REVERB_PORT
REVERB_SCHEME=$SCHEME
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_SERVER_PATH=

MAIL_MAILER=log
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_SCHEME=
MAIL_FROM_ADDRESS=$MAIL_FROM_ADDRESS
MAIL_FROM_NAME="\${APP_NAME}"
ENV

cat <<EOF
Generated $OUTPUT_FILE.

Next steps before real traffic:
- Review APP_URL, DNS, TLS, and WebSocket proxy routing.
- Configure outbound mail and run the Wayfindr mail smoke test.
- Start the Compose stack, run migrations, and confirm the scheduler.
- Visit /setup to create the first operator/account owner.
- Plan backups, restore drills, storage durability, logs, and upgrades.
EOF
