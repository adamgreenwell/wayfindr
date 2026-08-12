#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

SCENARIO="${WAYFINDR_EVIDENCE_SCENARIO:-clean-install-latest}"
APP_URL="${WAYFINDR_EVIDENCE_APP_URL:-http://127.0.0.1:18080}"
PROJECT_NAME="${WAYFINDR_EVIDENCE_PROJECT:-wayfindr-evidence-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}}"
INSTALLER_BASE="${WAYFINDR_EVIDENCE_INSTALLER_BASE:-https://raw.githubusercontent.com/adamgreenwell/wayfindr}"
KEEP_STACK="${WAYFINDR_EVIDENCE_KEEP:-0}"
TMP_ROOT="$(mktemp -d)"
TARGET_DIR="${WAYFINDR_EVIDENCE_TARGET_DIR:-$TMP_ROOT/wayfindr}"
ENV_FILE="$TARGET_DIR/.env"
COMPOSE_FILE="$TARGET_DIR/compose.yml"
COOKIE_JAR="$TMP_ROOT/cookies.txt"
AGENT_EMAIL="${WAYFINDR_EVIDENCE_AGENT_EMAIL:-agent+evidence-${GITHUB_RUN_ID:-$(date +%s)}@example.test}"
AGENT_PASSWORD="${WAYFINDR_EVIDENCE_AGENT_PASSWORD:-$(openssl rand -hex 18)}"
SITE_PUBLIC_KEY="${WAYFINDR_EVIDENCE_SITE_PUBLIC_KEY:-site_$(openssl rand -hex 16)}"
BACKUP_QUEUE_OVERRIDE="${WAYFINDR_EVIDENCE_BACKUP_QUEUE:-}"
MODE="${WAYFINDR_EVIDENCE_MODE:-}"
INSTALL_REF="${WAYFINDR_EVIDENCE_INSTALL_REF:-}"
INSTALLER_REF="${WAYFINDR_EVIDENCE_INSTALLER_REF:-}"
UPGRADE_CHAIN="${WAYFINDR_EVIDENCE_UPGRADE_CHAIN:-}"
SYNTHETIC_SKEW_RESTORE="${WAYFINDR_EVIDENCE_SYNTHETIC_SKEW_RESTORE:-0}"
IMAGE_ROLLBACK_RETRY="${WAYFINDR_EVIDENCE_IMAGE_ROLLBACK_RETRY:-0}"
ROLLBACK_IMAGE="${WAYFINDR_EVIDENCE_ROLLBACK_IMAGE:-ghcr.io/adamgreenwell/wayfindr:0.3.1}"
LAST_BACKUP_ARCHIVE=""
SKEW_RESTORE_ARCHIVE=""
SKEW_RESTORE_MARKER=""

case "$SCENARIO" in
    clean-install-latest)
        MODE="${MODE:-clean}"
        INSTALL_REF="${INSTALL_REF:-}"
        INSTALLER_REF="${INSTALLER_REF:-main}"
        ;;
    upgrade-v0.2.0-latest-custom-backup-queue)
        MODE="${MODE:-upgrade}"
        INSTALL_REF="${INSTALL_REF:-v0.2.0}"
        INSTALLER_REF="${INSTALLER_REF:-$INSTALL_REF}"
        UPGRADE_CHAIN="${UPGRADE_CHAIN:-latest}"
        BACKUP_QUEUE_OVERRIDE="${BACKUP_QUEUE_OVERRIDE:-evidence-backups}"
        ;;
    recovery-latest-synthetic-skew-restore)
        MODE="${MODE:-clean}"
        INSTALL_REF="${INSTALL_REF:-}"
        INSTALLER_REF="${INSTALLER_REF:-main}"
        SYNTHETIC_SKEW_RESTORE="${WAYFINDR_EVIDENCE_SYNTHETIC_SKEW_RESTORE:-1}"
        ;;
    recovery-latest-v0.3.1-image-rollback-retry)
        MODE="${MODE:-clean}"
        INSTALL_REF="${INSTALL_REF:-}"
        INSTALLER_REF="${INSTALLER_REF:-main}"
        IMAGE_ROLLBACK_RETRY="${WAYFINDR_EVIDENCE_IMAGE_ROLLBACK_RETRY:-1}"
        ROLLBACK_IMAGE="${WAYFINDR_EVIDENCE_ROLLBACK_IMAGE:-ghcr.io/adamgreenwell/wayfindr:0.3.1}"
        ;;
    upgrade-v0.1.0-latest)
        MODE="${MODE:-upgrade}"
        INSTALL_REF="${INSTALL_REF:-v0.1.0}"
        INSTALLER_REF="${INSTALLER_REF:-$INSTALL_REF}"
        UPGRADE_CHAIN="${UPGRADE_CHAIN:-latest}"
        ;;
    upgrade-v0.1.0-v0.2.0-latest)
        MODE="${MODE:-upgrade}"
        INSTALL_REF="${INSTALL_REF:-v0.1.0}"
        INSTALLER_REF="${INSTALLER_REF:-$INSTALL_REF}"
        UPGRADE_CHAIN="${UPGRADE_CHAIN:-v0.2.0,latest}"
        ;;
    custom)
        MODE="${MODE:-clean}"
        INSTALLER_REF="${INSTALLER_REF:-${INSTALL_REF:-main}}"
        ;;
    *)
        echo "Unknown WAYFINDR_EVIDENCE_SCENARIO: $SCENARIO" >&2
        echo "Use clean-install-latest, upgrade-v0.2.0-latest-custom-backup-queue, recovery-latest-synthetic-skew-restore, recovery-latest-v0.3.1-image-rollback-retry, upgrade-v0.1.0-latest, upgrade-v0.1.0-v0.2.0-latest, or custom." >&2
        exit 1
        ;;
esac

cleanup() {
    if [ "$KEEP_STACK" = "1" ]; then
        echo "Evidence stack left running because WAYFINDR_EVIDENCE_KEEP=1."
        echo "Target directory: $TARGET_DIR"
        echo "Compose project: $PROJECT_NAME"
        return
    fi

    if [ -f "$ENV_FILE" ] && [ -f "$COMPOSE_FILE" ]; then
        COMPOSE_PROJECT_NAME="$PROJECT_NAME" docker compose \
            --project-name "$PROJECT_NAME" \
            --env-file "$ENV_FILE" \
            -f "$COMPOSE_FILE" \
            down -v --remove-orphans >/dev/null 2>&1 || true
    fi

    rm -rf "$TMP_ROOT"
}

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

set_env_value() {
    local key="$1"
    local value="$2"

    if grep -qE "^[[:space:]]*(export[[:space:]]+)?$key=" "$ENV_FILE"; then
        sed -i.bak -E "s#^([[:space:]]*(export[[:space:]]+)?)$key=.*#\\1$key=$value#" "$ENV_FILE"
        rm -f "$ENV_FILE.bak"
    else
        printf '\n%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
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

print_baseline() {
    echo "== Evidence baseline =="
    echo "Scenario: $SCENARIO"
    echo "Mode: $MODE"
    echo "Date: $(date -Is)"
    echo "Target directory: $TARGET_DIR"
    echo "Compose project: $PROJECT_NAME"
    echo "App URL: $APP_URL"
    echo "Install ref: ${INSTALL_REF:-latest release}"
    echo "Installer ref: $INSTALLER_REF"
    echo "Upgrade chain: ${UPGRADE_CHAIN:-none}"
    echo "Custom BACKUP_QUEUE: ${BACKUP_QUEUE_OVERRIDE:-none}"
    echo "Image rollback retry: $IMAGE_ROLLBACK_RETRY"
    echo "Rollback image: ${ROLLBACK_IMAGE:-none}"
    lsb_release -a 2>/dev/null || cat /etc/os-release
    uname -a
    docker version
    docker compose version
    df -h .
}

install_wayfindr() {
    local install_ref="$1"
    local installer_ref="$2"
    local installer_url="$INSTALLER_BASE/$installer_ref/scripts/self-host/install.sh"
    local install_args=(--app-url "$APP_URL" --dir "$TARGET_DIR")

    if [ -n "$install_ref" ] && [ "$install_ref" != "latest" ]; then
        install_args+=(--ref "$install_ref")
    fi

    echo
    echo "== Installing Wayfindr from public artifacts =="
    echo "Installer source: $installer_url"
    echo "Requested install ref: ${install_ref:-latest release}"

    if ! curl -fsSL "$installer_url" \
        | COMPOSE_PROJECT_NAME="$PROJECT_NAME" bash -s -- "${install_args[@]}"; then
        echo "Installer failed. Capturing stack state before cleanup." >&2

        if [ -f "$ENV_FILE" ] && [ -f "$COMPOSE_FILE" ]; then
            compose ps >&2 || true
            compose logs --tail 120 storage-init web queue backup-queue scheduler reverb postgres redis >&2 || true
        fi

        return 1
    fi
}

bootstrap_instance() {
    echo
    echo "== Completing first-run setup with synthetic data =="
    wait_for_http "$APP_URL/up"
    curl -fsS -L -c "$COOKIE_JAR" "$APP_URL/setup" >/dev/null

    compose_exec php artisan wayfindr:bootstrap \
        --account="Evidence Account" \
        --slug="evidence-account" \
        --name="Evidence Agent" \
        --email="$AGENT_EMAIL" \
        --password="$AGENT_PASSWORD" \
        --site="Evidence Site" \
        --domain="127.0.0.1" \
        --site-public-key="$SITE_PUBLIC_KEY"

    curl -fsS -L "$APP_URL/login" >/dev/null
}

verify_runtime() {
    local label="$1"
    local image

    echo
    echo "== Runtime verification: $label =="
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

run_support_loop() {
    local label="$1"

    echo
    echo "== Support-loop smoke: $label =="
    WAYFINDR_BASE_URL="$APP_URL" \
        WAYFINDR_SITE_PUBLIC_KEY="$SITE_PUBLIC_KEY" \
        WAYFINDR_AGENT_EMAIL="$AGENT_EMAIL" \
        WAYFINDR_AGENT_PASSWORD="$AGENT_PASSWORD" \
        WAYFINDR_SMOKE_SUBJECT="Disposable evidence $SCENARIO" \
        WAYFINDR_SMOKE_MESSAGE="Hello from disposable evidence $SCENARIO." \
        "$ROOT_DIR/scripts/smoke/support-loop.sh"
}

seed_restore_marker() {
    RESTORE_MARKER="evidence-$(openssl rand -hex 4)"

    echo
    echo "== Seeding restore marker =="
    compose_exec php artisan tinker --execute="
\\Illuminate\\Support\\Facades\\DB::table('users')->insert(['name' => 'Restore Evidence', 'email' => '${RESTORE_MARKER}@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
\\Illuminate\\Support\\Facades\\Storage::disk('attachments')->put('evidence/${RESTORE_MARKER}.bin', 'EVIDENCE-BYTES-${RESTORE_MARKER}');
" >/dev/null
}

assert_restore_marker() {
    local expected="$1"
    local actual

    actual="$(compose_exec php artisan tinker --execute="echo \\Illuminate\\Support\\Facades\\DB::table('users')->where('email', '${RESTORE_MARKER}@example.test')->exists() ? 'present' : 'gone';")"

    if ! grep -q "$expected" <<< "$actual"; then
        echo "Expected restore marker to be $expected, got: $actual" >&2
        exit 1
    fi
}

create_backup_archive() {
    local label="$1"
    local backup_dir="$2"
    local archive

    seed_restore_marker

    echo
    echo "== $label =="
    compose_exec php artisan wayfindr:backup --path="$backup_dir"
    archive="$(compose_exec sh -c "find '$backup_dir' -name 'wayfindr-backup-*.tar.gz' 2>/dev/null | sort | tail -n1")"

    if [ -z "$archive" ]; then
        echo "Backup produced no archive." >&2
        exit 1
    fi

    compose_exec sh -c "tar -xzOf '$archive' ./database.sql | grep -q 'PostgreSQL database dump'"

    LAST_BACKUP_ARCHIVE="$archive"
}

make_synthetic_skew_archive() {
    local source_archive="$1"
    local target_archive="$2"
    local synthetic_version="$3"
    local synthetic_commit="$4"

    echo "Rewriting a copy of the backup manifest to simulate version skew."
    compose_exec sh -c "set -e
work=\"\$(mktemp -d)\"
cleanup_work() { rm -rf \"\$work\"; }
trap cleanup_work EXIT
tar -xzf '$source_archive' -C \"\$work\"
php -r '
\$manifestPath = \$argv[1];
\$manifest = json_decode(file_get_contents(\$manifestPath), true);
if (! is_array(\$manifest)) {
    fwrite(STDERR, \"Backup manifest is not valid JSON.\\n\");
    exit(1);
}
\$manifest[\"wayfindr_version\"] = \$argv[2];
\$manifest[\"wayfindr_commit\"] = \$argv[3];
file_put_contents(
    \$manifestPath,
    json_encode(\$manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
);
' \"\$work/manifest.json\" '$synthetic_version' '$synthetic_commit'
tar -czf '$target_archive' -C \"\$work\" .
tar -tzf '$target_archive' ./manifest.json >/dev/null"
}

prepare_synthetic_version_skew_restore_archive() {
    create_backup_archive \
        "Preparing source archive for synthetic version-skew restore drill" \
        "/app/apps/server/storage/app/evidence-skew-backup"

    SKEW_RESTORE_MARKER="$RESTORE_MARKER"
    SKEW_RESTORE_ARCHIVE="/tmp/wayfindr-evidence-synthetic-skew/wayfindr-backup-synthetic-skew.tar.gz"

    compose_exec mkdir -p "$(dirname "$SKEW_RESTORE_ARCHIVE")"
    make_synthetic_skew_archive \
        "$LAST_BACKUP_ARCHIVE" \
        "$SKEW_RESTORE_ARCHIVE" \
        "v0.2.0" \
        "synthetic-skew-restore-archive"

    echo "Synthetic version-skew restore archive prepared: $SKEW_RESTORE_ARCHIVE"
}

backup_restore_drill() {
    local archive
    local restore_out
    local restored_bytes

    create_backup_archive "Backup and restore drill" "/tmp/wayfindr-evidence-backup"
    archive="$LAST_BACKUP_ARCHIVE"

    echo "Quiescing app and deleting restore markers."
    compose_exec php artisan down >/dev/null
    compose stop queue scheduler >/dev/null
    compose_exec php artisan tinker --execute="
\\Illuminate\\Support\\Facades\\DB::table('users')->where('email', '${RESTORE_MARKER}@example.test')->delete();
\\Illuminate\\Support\\Facades\\Storage::disk('attachments')->delete('evidence/${RESTORE_MARKER}.bin');
" >/dev/null
    assert_restore_marker gone

    echo "Restoring archive."
    restore_out="$(compose_exec php artisan wayfindr:restore "$archive" --force)"
    printf '%s\n' "$restore_out"
    grep -F 'Restore complete.' <<< "$restore_out" >/dev/null
    grep -F 'Attachments verified present:' <<< "$restore_out" >/dev/null

    compose start queue scheduler >/dev/null
    compose_exec php artisan up >/dev/null
    assert_restore_marker present

    restored_bytes="$(compose_exec php artisan tinker --execute="echo \\Illuminate\\Support\\Facades\\Storage::disk('attachments')->get('evidence/${RESTORE_MARKER}.bin');")"
    grep -F "EVIDENCE-BYTES-${RESTORE_MARKER}" <<< "$restored_bytes" >/dev/null

    echo "Backup/restore drill passed."
}

version_skew_restore_drill() {
    local restore_out
    local restored_bytes

    if [ -z "$SKEW_RESTORE_ARCHIVE" ] || [ -z "$SKEW_RESTORE_MARKER" ]; then
        echo "Version-skew restore drill needs a pre-upgrade archive and marker." >&2
        exit 1
    fi

    echo
    echo "== Version-skew restore drill =="
    echo "Restoring skewed archive into the current runtime: $SKEW_RESTORE_ARCHIVE"
    compose_exec php artisan down >/dev/null
    compose stop queue scheduler >/dev/null

    RESTORE_MARKER="$SKEW_RESTORE_MARKER"
    compose_exec php artisan tinker --execute="
\\Illuminate\\Support\\Facades\\DB::table('users')->where('email', '${RESTORE_MARKER}@example.test')->delete();
\\Illuminate\\Support\\Facades\\Storage::disk('attachments')->delete('evidence/${RESTORE_MARKER}.bin');
" >/dev/null
    assert_restore_marker gone

    restore_out="$(compose_exec php artisan wayfindr:restore "$SKEW_RESTORE_ARCHIVE" --force)"
    printf '%s\n' "$restore_out"
    grep -F 'Version skew:' <<< "$restore_out" >/dev/null
    grep -F 'Restore complete.' <<< "$restore_out" >/dev/null
    grep -F 'Attachments verified present:' <<< "$restore_out" >/dev/null

    assert_restore_marker present

    echo "Running migrations after restoring the skewed archive."
    compose_exec php artisan migrate --force
    compose start queue scheduler >/dev/null
    compose_exec php artisan up >/dev/null

    restored_bytes="$(compose_exec php artisan tinker --execute="echo \\Illuminate\\Support\\Facades\\Storage::disk('attachments')->get('evidence/${RESTORE_MARKER}.bin');")"
    grep -F "EVIDENCE-BYTES-${RESTORE_MARKER}" <<< "$restored_bytes" >/dev/null

    echo "Version-skew restore drill passed."
}

image_rollback_retry_drill() {
    local current_image

    current_image="$(read_env_value WAYFINDR_IMAGE)"

    if [ -z "$current_image" ]; then
        echo "Image rollback/retry drill needs WAYFINDR_IMAGE in $ENV_FILE." >&2
        exit 1
    fi

    if [ -z "$ROLLBACK_IMAGE" ]; then
        echo "Image rollback/retry drill needs WAYFINDR_EVIDENCE_ROLLBACK_IMAGE." >&2
        exit 1
    fi

    echo
    echo "== Image rollback/retry drill =="
    echo "Current image before rollback: $current_image"
    echo "Rollback image: $ROLLBACK_IMAGE"

    set_env_value WAYFINDR_IMAGE "$ROLLBACK_IMAGE"
    compose pull web
    compose up -d
    verify_runtime "after image rollback to $ROLLBACK_IMAGE"
    run_support_loop "after image rollback to $ROLLBACK_IMAGE"

    echo
    echo "Retrying original image: $current_image"
    set_env_value WAYFINDR_IMAGE "$current_image"
    compose pull web
    compose up -d
    verify_runtime "after retry to $current_image"
    run_support_loop "after retry to $current_image"

    echo "Image rollback/retry drill passed."
}

restart_stack_check() {
    echo
    echo "== Service restart recovery check =="
    compose restart
    assert_services_running web queue backup-queue scheduler reverb postgres redis
    wait_for_http "$APP_URL/up"
}

upgrade_once() {
    local target="$1"
    local output_file="$TMP_ROOT/upgrade-${target//[^A-Za-z0-9_.-]/_}.log"
    local upgrade_args=(--upgrade --dir "$TARGET_DIR")
    local status

    if [ "$target" != "latest" ]; then
        upgrade_args+=(--ref "$target")
    fi

    echo
    echo "== Upgrading through ${target} =="
    set +e
    COMPOSE_PROJECT_NAME="$PROJECT_NAME" "$TARGET_DIR/install.sh" "${upgrade_args[@]}" 2>&1 | tee "$output_file"
    status=${PIPESTATUS[0]}
    set -e

    if [ "$status" -ne 0 ]; then
        echo "Upgrade to ${target} failed with exit status $status." >&2
        exit "$status"
    fi

    assert_services_running web queue backup-queue scheduler reverb postgres redis
    wait_for_http "$APP_URL/up"
}

wait_for_notice_retirement() {
    local guard_file="$TMP_ROOT/upgrade-guard-after-worker.json"

    echo
    echo "== Verifying backup queue advisory retires when worker is observed =="

    for _ in $(seq 1 40); do
        compose_exec php artisan wayfindr:upgrade-guard --json > "$guard_file"

        if ! grep -F 'backups-queue-consumer' "$guard_file" >/dev/null; then
            cat "$guard_file"
            echo "Backup queue advisory retired."
            return 0
        fi

        sleep 3
    done

    echo "Backup queue advisory did not retire after waiting for worker heartbeat." >&2
    cat "$guard_file" >&2
    return 1
}

run_upgrade_chain() {
    local chain="$1"
    local target

    IFS=',' read -r -a targets <<< "$chain"

    for target in "${targets[@]}"; do
        target="${target//[[:space:]]/}"
        [ -n "$target" ] || continue
        upgrade_once "$target"
        verify_runtime "after upgrade to $target"
        run_support_loop "after upgrade to $target"
    done

    if [ -n "$BACKUP_QUEUE_OVERRIDE" ]; then
        wait_for_notice_retirement
    fi
}

require_command curl
require_command docker
require_command grep
require_command openssl
require_command sed

docker info >/dev/null
trap cleanup EXIT

print_baseline

# Public-artifact evidence must not inherit a developer shell image override.
unset WAYFINDR_IMAGE

install_wayfindr "$INSTALL_REF" "$INSTALLER_REF"

if [ -n "$BACKUP_QUEUE_OVERRIDE" ]; then
    echo
    echo "== Configuring custom BACKUP_QUEUE before verification =="
    set_env_value BACKUP_QUEUE "$BACKUP_QUEUE_OVERRIDE"
    compose up -d
fi

bootstrap_instance
verify_runtime "after initial install"
run_support_loop "after initial install"

if [ "$MODE" = "upgrade" ]; then
    [ -n "$UPGRADE_CHAIN" ] || { echo "Upgrade mode requires WAYFINDR_EVIDENCE_UPGRADE_CHAIN." >&2; exit 1; }
    run_upgrade_chain "$UPGRADE_CHAIN"
fi

if [ "$SYNTHETIC_SKEW_RESTORE" = "1" ]; then
    prepare_synthetic_version_skew_restore_archive
    version_skew_restore_drill
    verify_runtime "after synthetic version-skew restore recovery"
    run_support_loop "after synthetic version-skew restore recovery"
fi

if [ "$IMAGE_ROLLBACK_RETRY" = "1" ]; then
    image_rollback_retry_drill
fi

backup_restore_drill
verify_runtime "after restore"
run_support_loop "after restore"
restart_stack_check

cat <<SUMMARY

== Disposable evidence summary ==
Scenario: $SCENARIO
Result: pass
Install ref: ${INSTALL_REF:-latest release}
Upgrade chain: ${UPGRADE_CHAIN:-none}
Rollback image: ${ROLLBACK_IMAGE:-none}
App URL: $APP_URL
Compose project: $PROJECT_NAME
Synthetic agent: $AGENT_EMAIL
Synthetic site public key: $SITE_PUBLIC_KEY

Boundary: this proves a fresh hosted runner path for public artifacts. It does
not prove a bare-metal VM reboot, operator-managed DNS/TLS, real mail delivery,
offsite backups, bare-metal rollback, or a production restore posture.
SUMMARY
