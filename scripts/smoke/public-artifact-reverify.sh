#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

APP_URL="${WAYFINDR_EVIDENCE_APP_URL:-http://127.0.0.1:18080}"
PROJECT_NAME="${WAYFINDR_EVIDENCE_PROJECT:-wayfindr-evidence-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}}"
TARGET_DIR="${WAYFINDR_EVIDENCE_TARGET_DIR:-}"
REQUIRE_SUPPORT_LOOP="${WAYFINDR_EVIDENCE_REQUIRE_SUPPORT_LOOP:-0}"
AGENT_EMAIL="${WAYFINDR_EVIDENCE_AGENT_EMAIL:-}"
AGENT_PASSWORD="${WAYFINDR_EVIDENCE_AGENT_PASSWORD:-}"
SITE_PUBLIC_KEY="${WAYFINDR_EVIDENCE_SITE_PUBLIC_KEY:-}"
SUPPORT_LOOP_RESULT="skipped"

if [ -z "$TARGET_DIR" ]; then
    cat >&2 <<'USAGE'
WAYFINDR_EVIDENCE_TARGET_DIR is required.

Use this after rebooting a VM that was installed with a persistent target
directory, for example:

  WAYFINDR_EVIDENCE_TARGET_DIR=/opt/wayfindr-evidence \
  WAYFINDR_EVIDENCE_PROJECT=wayfindr-evidence-clean-01 \
  scripts/smoke/public-artifact-reverify.sh
USAGE
    exit 1
fi

ENV_FILE="$TARGET_DIR/.env"
COMPOSE_FILE="$TARGET_DIR/compose.yml"

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Missing required command: $1" >&2
        exit 1
    fi
}

compose() {
    COMPOSE_PROJECT_NAME="$PROJECT_NAME" docker compose \
        --project-name "$PROJECT_NAME" \
        --env-file "$ENV_FILE" \
        -f "$COMPOSE_FILE" \
        "$@"
}

compose_exec() {
    compose exec -T web "$@"
}

read_env_value() {
    local key="$1"

    grep -E "^[[:space:]]*(export[[:space:]]+)?$key=" "$ENV_FILE" 2>/dev/null \
        | tail -1 \
        | sed -E "s/^[[:space:]]*(export[[:space:]]+)?$key=//; s/^\"//; s/\"$//; s/^'//; s/'$//" || true
}

wait_for_http() {
    local url="$1"

    for _ in $(seq 1 60); do
        if curl --fail --silent --show-error --max-time 2 "$url" >/dev/null 2>&1; then
            return 0
        fi

        sleep 2
    done

    echo "Timed out waiting for $url" >&2
    return 1
}

assert_services_running() {
    local missing=()
    local running_services

    for _ in $(seq 1 40); do
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

        sleep 2
    done

    echo "Expected services are not running: ${missing[*]}" >&2
    compose ps >&2 || true

    for service in "${missing[@]}"; do
        echo "--- Recent logs for $service ---" >&2
        compose logs --tail 80 "$service" >&2 || true
    done

    return 1
}

verify_runtime() {
    local image

    echo
    echo "== Existing stack runtime verification =="
    assert_services_running web queue backup-queue scheduler reverb postgres redis
    compose ps

    image="$(read_env_value WAYFINDR_IMAGE)"
    echo "Configured image: ${image:-unknown}"
    if [ -n "$image" ]; then
        echo "Resolved image digest(s):"
        docker image inspect "$image" --format '{{range .RepoDigests}}{{println .}}{{end}}' | sed '/^$/d' || true
    fi

    echo "Release identity:"
    compose_exec sh -c 'test -f /etc/wayfindr/version && cat /etc/wayfindr/version || true'
    compose_exec php -r '$path = "/etc/wayfindr/release.json"; if (is_file($path)) { $j = json_decode(file_get_contents($path), true); echo ($j["version"] ?? "unknown")."\n".($j["commit"] ?? "unknown")."\n"; }'

    wait_for_http "$APP_URL/up"
    compose_exec php artisan migrate:status
    compose_exec php artisan queue:failed
    compose_exec php artisan schedule:list
    compose_exec php artisan wayfindr:upgrade-guard --json
}

run_support_loop_if_configured() {
    if [ -n "$SITE_PUBLIC_KEY" ] && [ -n "$AGENT_EMAIL" ] && [ -n "$AGENT_PASSWORD" ]; then
        echo
        echo "== Support-loop smoke after reboot/reconnect =="
        WAYFINDR_BASE_URL="$APP_URL" \
            WAYFINDR_SITE_PUBLIC_KEY="$SITE_PUBLIC_KEY" \
            WAYFINDR_AGENT_EMAIL="$AGENT_EMAIL" \
            WAYFINDR_AGENT_PASSWORD="$AGENT_PASSWORD" \
            WAYFINDR_SMOKE_SUBJECT="Disposable evidence reboot reverify" \
            WAYFINDR_SMOKE_MESSAGE="Hello from disposable evidence reboot reverify." \
            "$ROOT_DIR/scripts/smoke/support-loop.sh"
        SUPPORT_LOOP_RESULT="pass"

        return
    fi

    if [ "$REQUIRE_SUPPORT_LOOP" = "1" ]; then
        cat >&2 <<'MISSING'
Support-loop smoke is required but credentials are missing.

Set WAYFINDR_EVIDENCE_SITE_PUBLIC_KEY, WAYFINDR_EVIDENCE_AGENT_EMAIL, and
WAYFINDR_EVIDENCE_AGENT_PASSWORD to synthetic values from the original evidence
install, then rerun.
MISSING
        exit 1
    fi

    echo
    echo "Support-loop smoke skipped. Set WAYFINDR_EVIDENCE_REQUIRE_SUPPORT_LOOP=1"
    echo "with synthetic site and agent credentials when this is acceptance evidence."
}

require_command curl
require_command docker
require_command grep
require_command sed

[ -f "$ENV_FILE" ] || { echo "Missing env file: $ENV_FILE" >&2; exit 1; }
[ -f "$COMPOSE_FILE" ] || { echo "Missing Compose file: $COMPOSE_FILE" >&2; exit 1; }

docker info >/dev/null

echo "== Reboot/reconnect evidence baseline =="
echo "Date: $(date -Is)"
echo "Target directory: $TARGET_DIR"
echo "Compose project: $PROJECT_NAME"
echo "App URL: $APP_URL"
lsb_release -a 2>/dev/null || cat /etc/os-release
uname -a
docker version
docker compose version
df -h "$TARGET_DIR"

verify_runtime
run_support_loop_if_configured

result="pass"
if [ "$SUPPORT_LOOP_RESULT" != "pass" ]; then
    result="partial"
fi

cat <<SUMMARY

== Disposable reboot reverify summary ==
Result: $result
App URL: $APP_URL
Target directory: $TARGET_DIR
Compose project: $PROJECT_NAME
Support-loop smoke: $SUPPORT_LOOP_RESULT

Boundary: this re-verifies an existing persistent public-artifact stack after a
VM reboot or reconnect. It does not prove VM destruction/recreation, rollback,
offsite backup durability, DNS/TLS, real mail delivery, or production restore
posture unless those facts are recorded separately.
SUMMARY
