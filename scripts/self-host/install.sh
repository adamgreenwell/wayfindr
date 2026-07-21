#!/usr/bin/env bash
#
# Wayfindr one-line installer.
#
#   curl -fsSL https://raw.githubusercontent.com/adamgreenwell/wayfindr/main/scripts/self-host/install.sh \
#     | bash -s -- --app-url https://support.example.com
#
# Sets up the official Docker Compose stack in a directory, mints secrets,
# starts the services, waits for health, and prints the /setup URL. With an
# https:// URL, FrankenPHP obtains TLS certificates automatically — DNS for
# the hostname must already point at this machine and ports 80/443 must be
# free. Re-running converges; `--upgrade` pulls the newer image and restarts.
set -euo pipefail

RAW_BASE_DEFAULT="https://raw.githubusercontent.com/adamgreenwell/wayfindr"
RELEASES_API="https://api.github.com/repos/adamgreenwell/wayfindr/releases/latest"
TAGS_API="https://api.github.com/repos/adamgreenwell/wayfindr/tags?per_page=100"
REF=""
IMAGE_TAG=""
PRERELEASE=0
APP_URL=""
MAIL_FROM="support@example.com"
BEHIND_PROXY=0
TARGET_DIR="$PWD/wayfindr"
SOURCE_DIR=""
UPGRADE=0
NO_START=0

usage() {
    cat <<'USAGE'
Usage:
  install.sh --app-url <url> [options]
  install.sh --upgrade [--dir <path>]

Options:
  --app-url <url>   Public URL (https://support.example.com for automatic TLS,
                    http://... for smoke tests or behind your own proxy).
  --dir <path>      Install directory. Defaults to ./wayfindr.
  --mail-from <a>   Mail from address placeholder. Defaults to support@example.com.
  --behind-proxy    Your own reverse proxy terminates TLS; every bind stays on
                    loopback and you point the proxy at 127.0.0.1:8000.
  --ref <git-ref>   Git ref to fetch stack files from. Defaults to the latest
                    release tag (main before the first release), keeping the
                    stack files aligned with the image the install pulls.
  --upgrade         Pull the newer image and restart an existing install.
  --no-start        Prepare files and env but do not start the stack.
  --source-dir <p>  Internal: copy stack files from a local checkout instead
                    of downloading (used by the repo smoke tests).
  -h, --help        Show this help.
USAGE
}

say() { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

while [ "$#" -gt 0 ]; do
    case "$1" in
        --app-url) APP_URL="${2:-}"; shift 2 ;;
        --dir) TARGET_DIR="${2:-}"; shift 2 ;;
        --mail-from) MAIL_FROM="${2:-}"; shift 2 ;;
        --behind-proxy) BEHIND_PROXY=1; shift ;;
        --ref) REF="${2:-}"; shift 2 ;;
        --source-dir) SOURCE_DIR="${2:-}"; shift 2 ;;
        --upgrade) UPGRADE=1; shift ;;
        --no-start) NO_START=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; die "Unknown option: $1" ;;
    esac
done

command -v docker >/dev/null 2>&1 || die "Docker is required. Install it from https://docs.docker.com/engine/install/ first."
docker compose version >/dev/null 2>&1 || die "The Docker Compose plugin is required (docker compose)."
docker info >/dev/null 2>&1 || die "The Docker daemon is not reachable. Is it running, and can your user access it?"

COMPOSE_FILE="$TARGET_DIR/compose.yml"
ENV_FILE="$TARGET_DIR/.env"

# Stack files and image must describe the same release: without an explicit
# --ref, pin both to the latest release tag. Before the first release the
# API has none and everything follows main/latest.
resolve_release() {
    local latest

    if [ -n "$REF" ]; then
        # An explicit release tag pins the image too; branches and SHAs have
        # no matching published image, so they run :latest deliberately.
        case "$REF" in
            v[0-9]*)
                IMAGE_TAG="${REF#v}"
                say "Pinned to $REF (stack files and image)."
                ;;
            *)
                say "Using ref $REF with the :latest image (no matching published image for non-tag refs)."
                ;;
        esac

        return
    fi

    latest="$(curl -fsSL "$RELEASES_API" 2>/dev/null | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p' | head -n 1)" || true

    # A bare v* git tag publishes an image without creating a GitHub
    # Release — resolve through the tags API before ever considering main.
    if [ -z "$latest" ]; then
        latest="$(curl -fsSL "$TAGS_API" 2>/dev/null | sed -n 's/.*"name": *"\(v[0-9][^"]*\)".*/\1/p' | sort -V | tail -n 1)" || true
    fi

    if [ -n "$latest" ]; then
        REF="$latest"
        IMAGE_TAG="${latest#v}"
        say "Pinned to release $latest."
    else
        REF="main"
        PRERELEASE=1
        say "No published release found; using main (pre-release mode)."
    fi
}

pin_image() {
    # An operator-supplied WAYFINDR_IMAGE wins; otherwise a resolved release
    # tag pins the published image.
    if [ -n "${WAYFINDR_IMAGE:-}" ]; then
        sed -i.bak "s#^WAYFINDR_IMAGE=.*#WAYFINDR_IMAGE=$WAYFINDR_IMAGE#" "$ENV_FILE"
        rm -f "$ENV_FILE.bak"
    elif [ -n "$IMAGE_TAG" ] && grep -q '^WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:' "$ENV_FILE"; then
        sed -i.bak "s#^WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:.*#WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:$IMAGE_TAG#" "$ENV_FILE"
        rm -f "$ENV_FILE.bak"
    fi
}

migrate_env() {
    # Installs generated before release identity was baked carry blank
    # WAYFINDR_VERSION= / WAYFINDR_COMMIT= lines, and env_file entries
    # override the image ENV — leaving them would keep /operator blank
    # forever. Drop ONLY the empty ones; a value the operator set is theirs.
    if grep -qE '^WAYFINDR_(VERSION|COMMIT)=$' "$ENV_FILE"; then
        sed -i.bak -E '/^WAYFINDR_(VERSION|COMMIT)=$/d' "$ENV_FILE"
        rm -f "$ENV_FILE.bak"
        say "Removed blank release-identity overrides so /operator reports the image version."
    fi
}

require_runnable_image() {
    # Before the first release no ghcr image exists: a fresh install would
    # fail on the pull with a confusing error, so fail early with the way
    # forward instead.
    if [ "$PRERELEASE" = "1" ] && [ -z "${WAYFINDR_IMAGE:-}" ]; then
        die "No published Wayfindr release exists yet, so there is no image to pull. Either set WAYFINDR_IMAGE to an image you have built, or clone the repo and use the compose.build.yml overlay (see docker/self-hosting/README.md)."
    fi
}

compose() {
    # The compose file pins the project name (wayfindr-self-hosting), so
    # repeated runs and upgrades always converge on the same stack.
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

fetch() {
    local path="$1" dest="$2"

    if [ -n "$SOURCE_DIR" ]; then
        cp "$SOURCE_DIR/$path" "$dest"
    else
        curl -fsSL "$RAW_BASE_DEFAULT/$REF/$path" -o "$dest"
    fi
}

if [ "$UPGRADE" = "1" ]; then
    [ -f "$COMPOSE_FILE" ] && [ -f "$ENV_FILE" ] || die "No install found in $TARGET_DIR (use --dir to point at it)."
    resolve_release
    say "Refreshing stack files at $REF."
    fetch docker/self-hosting/compose.yml "$COMPOSE_FILE"
    fetch scripts/self-host/install.sh "$TARGET_DIR/install.sh"
    chmod +x "$TARGET_DIR/install.sh"
    migrate_env
    pin_image
    say "Pulling the release image."
    compose pull web || say "Pull failed; keeping the current image (pre-release or locally built installs)."
    say "Restarting the stack (migrations run automatically)."
    compose up -d
    say "Upgrade complete."
    exit 0
fi

[ -n "$APP_URL" ] || die "--app-url is required, e.g. --app-url https://support.example.com"

case "$APP_URL" in
    http://*|https://*) ;;
    *) die "--app-url must start with http:// or https://" ;;
esac

mkdir -p "$TARGET_DIR"

resolve_release
say "Fetching the Wayfindr stack files (${SOURCE_DIR:+local checkout}${SOURCE_DIR:-ref $REF})."
fetch docker/self-hosting/compose.yml "$COMPOSE_FILE"
fetch scripts/self-host/generate-env.sh "$TARGET_DIR/generate-env.sh"
fetch scripts/self-host/install.sh "$TARGET_DIR/install.sh"
chmod +x "$TARGET_DIR/generate-env.sh" "$TARGET_DIR/install.sh"

if [ -f "$ENV_FILE" ]; then
    say "Keeping the existing $ENV_FILE (secrets preserved)."
else
    say "Generating $ENV_FILE with fresh secrets."
    generate_args=(--app-url "$APP_URL" --mail-from "$MAIL_FROM" --output "$ENV_FILE")
    [ "$BEHIND_PROXY" = "1" ] && generate_args+=(--behind-proxy)
    "$TARGET_DIR/generate-env.sh" "${generate_args[@]}" >/dev/null
    pin_image
fi

if [ "$NO_START" = "1" ]; then
    say "Stack prepared in $TARGET_DIR (not started, per --no-start)."
    exit 0
fi

require_runnable_image

say "Starting the stack (first run downloads the application image)."
compose up -d

# sed exits 0 whether or not the key exists, unlike grep under pipefail —
# a hand-written env without WAYFINDR_LOCAL_BIND must fall back, not abort.
LOCAL_BIND="$(sed -n 's/^WAYFINDR_LOCAL_BIND=//p' "$ENV_FILE")"
LOCAL_URL="http://${LOCAL_BIND:-127.0.0.1:8000}"

say "Waiting for the application to come up."
tries=0
until curl -fs --max-time 2 "$LOCAL_URL/up" >/dev/null 2>&1; do
    tries=$((tries + 1))
    [ "$tries" -lt 60 ] || {
        compose ps || true
        die "The web service did not become healthy. Inspect: docker compose -f $COMPOSE_FILE --env-file $ENV_FILE logs web"
    }
    sleep 2
done

cat <<DONE

  Wayfindr is running.

  Create the first account:  $APP_URL/setup
  Environment file:          $ENV_FILE  (mail is set to 'log' — configure SMTP before real traffic)
  Logs:                      docker compose -f $COMPOSE_FILE --env-file $ENV_FILE logs -f
  Upgrade later:             $TARGET_DIR/install.sh --upgrade --dir $TARGET_DIR

  Readiness checks live at $APP_URL/dashboard/readiness after you sign in.

DONE
