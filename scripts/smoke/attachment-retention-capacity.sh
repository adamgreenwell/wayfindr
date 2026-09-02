#!/usr/bin/env bash
set -euo pipefail

mode="${1:-}"

if [[ "$mode" != "local" && "$mode" != "s3" ]]; then
    echo "Usage: $0 local|s3" >&2
    exit 2
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
server_dir="$repo_root/apps/server"
php_binary="${WAYFINDR_ATTACHMENT_RETENTION_PHP_BINARY:-$(command -v php)}"
objects="${WAYFINDR_ATTACHMENT_RETENTION_OBJECTS:-10000}"
object_bytes="${WAYFINDR_ATTACHMENT_RETENTION_BYTES:-1024}"
timestamp="$(date -u +%Y-%m-%dT%H-%M-%SZ)"
report="${WAYFINDR_ATTACHMENT_RETENTION_OUTPUT:-/tmp/wayfindr-attachment-retention-${mode}-${timestamp}.json}"
temporary_parent="${TMPDIR:-/tmp}"
temporary_dir="$(mktemp -d "${temporary_parent%/}/wayfindr-attachment-retention-XXXXXX")"
database="$temporary_dir/database.sqlite"
storage="$temporary_dir/storage"
container_name=""

cleanup() {
    if [[ -n "$container_name" ]]; then
        docker rm -f "$container_name" >/dev/null 2>&1 || true
    fi

    case "$temporary_dir" in
        */wayfindr-attachment-retention-??????)
            rm -rf -- "$temporary_dir"
            ;;
        *)
            echo "Refusing to remove unexpected temporary path: $temporary_dir" >&2
            ;;
    esac
}

trap cleanup EXIT INT TERM

if [[ ! -x "$php_binary" ]]; then
    echo "PHP binary is not executable: $php_binary" >&2
    exit 1
fi

if ! "$php_binary" -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);'; then
    echo "The attachment-retention harness requires PHP 8.4.1 or newer." >&2
    exit 1
fi

mkdir -p "$storage/app/private/attachments" "$storage/framework/cache" "$storage/framework/sessions" "$storage/framework/views" "$storage/logs"
touch "$database"

common_environment=(
    APP_ENV=local
    APP_DEBUG=false
    APP_URL=http://127.0.0.1
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
    APP_CONFIG_CACHE="$temporary_dir/config.php"
    DB_CONNECTION=sqlite
    DB_DATABASE="$database"
    LARAVEL_STORAGE_PATH="$storage"
    CACHE_STORE=array
    SESSION_DRIVER=array
    QUEUE_CONNECTION=sync
    BROADCAST_CONNECTION=log
    WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE=YES
    WAYFINDR_ATTACHMENT_PENDING_EXPIRY_HOURS=24
)

storage_environment=()

if [[ "$mode" == "local" ]]; then
    storage_environment=(
        WAYFINDR_ATTACHMENT_STORAGE_DISK=attachments
        WAYFINDR_ATTACHMENT_ORPHAN_GRACE_HOURS=1
        WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY="isolated local filesystem under LARAVEL_STORAGE_PATH"
    )
else
    minio_image="minio/minio@sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e"
    proposed_container_name="wayfindr-attachment-retention-$$"
    mkdir -p "$temporary_dir/minio"

    docker run --detach --rm \
        --name "$proposed_container_name" \
        --publish 127.0.0.1::9000 \
        --env MINIO_ROOT_USER=wayfindr-retention \
        --env MINIO_ROOT_PASSWORD=wayfindr-retention-secret \
        --volume "$temporary_dir/minio:/data" \
        "$minio_image" server /data --address :9000 >/dev/null

    container_name="$proposed_container_name"

    minio_port="$(docker port "$container_name" 9000/tcp | sed -E 's/^.*:([0-9]+)$/\1/')"

    for _ in {1..30}; do
        if curl --silent --fail "http://127.0.0.1:${minio_port}/minio/health/live" >/dev/null; then
            break
        fi

        sleep 1
    done

    if ! curl --silent --fail "http://127.0.0.1:${minio_port}/minio/health/live" >/dev/null; then
        echo "Disposable MinIO did not become healthy." >&2
        exit 1
    fi

    storage_environment=(
        WAYFINDR_ATTACHMENT_STORAGE_DISK=attachments-s3
        WAYFINDR_ATTACHMENT_ORPHAN_GRACE_HOURS=0
        WAYFINDR_ATTACHMENT_S3_KEY=wayfindr-retention
        WAYFINDR_ATTACHMENT_S3_SECRET=wayfindr-retention-secret
        WAYFINDR_ATTACHMENT_S3_REGION=us-east-1
        WAYFINDR_ATTACHMENT_S3_BUCKET=wayfindr-attachment-retention
        WAYFINDR_ATTACHMENT_S3_ENDPOINT="http://127.0.0.1:${minio_port}"
        WAYFINDR_ATTACHMENT_S3_USE_PATH_STYLE=true
        WAYFINDR_ATTACHMENT_S3_ROOT="runs/$(basename "$temporary_dir")"
        WAYFINDR_ATTACHMENT_S3_ACL=private
        WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY="MinIO RELEASE.2025-09-07T16-13-09Z in one disposable local Docker container"
    )
fi

artisan() {
    env "${common_environment[@]}" "${storage_environment[@]}" \
        "$php_binary" "$server_dir/artisan" "$@"
}

measurement_options=(
    --objects="$objects"
    --bytes="$object_bytes"
    --output="$report"
    --confirm-disposable
)

if [[ "${WAYFINDR_ATTACHMENT_RETENTION_ALLOW_DIRTY:-0}" == "1" ]]; then
    measurement_options+=(--allow-dirty)
fi

# This boots the application with the exact environment above and validates
# the database plus every disk the real sweep could reach. It must run before
# migrate:fresh: that command is intentionally destructive to its selected DB.
artisan wayfindr:measure-attachment-retention "${measurement_options[@]}" --preflight-only

artisan migrate:fresh --force
artisan wayfindr:seed-desk \
    --conversations=1 \
    --months=1 \
    --agents=1 \
    --sites=1 \
    --messages=1 \
    --fresh

artisan wayfindr:measure-attachment-retention "${measurement_options[@]}"

echo "Attachment-retention report: $report"
