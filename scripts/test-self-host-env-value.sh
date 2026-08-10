#!/usr/bin/env bash
#
# Differential test: install.sh's dotenv reading against Docker Compose's.
#
# WHY THIS EXISTS
#
# install.sh parses the environment file itself, and #647 recorded that as
# structural debt after five divergences were found and fixed one at a time —
# quoting, inline comments, trailing whitespace, `export`, and duplicate keys.
# Each failed quietly: keeping the quotes made an official image classify as a
# fork, so the preflight skipped silently; an acknowledgement never matched the
# key it was written for; a declared origin failed to parse.
#
# #647's proposed fix was for the installer to shell out to the artifact's own
# parser. That is not available, and the reason is worth stating so nobody
# re-proposes it: `php_in_current_image()` needs INSTALLED_IMAGE, which comes
# from `env_value WAYFINDR_IMAGE`. You cannot shell out to the artifact to learn
# which artifact to shell out to. Of six env_value call sites only two run after
# the image is known, so delegating would leave TWO parsers where there is one,
# with the most consequential key still on the bash side.
#
# So the divergence is closed by proving agreement instead of by deleting one
# implementation — and install.sh gains nothing for it: no probe, no hook, no
# test-only branch.
#
# THE ORACLE
#
# Docker Compose, not phpdotenv. This file is Compose's `--env-file`, and Compose
# is what resolves `${WAYFINDR_IMAGE}` into the image actually pulled. (phpdotenv
# is the wrong reference here — it rejects the `export` spelling outright, with a
# fatal that would take the whole file down, while both Compose and install.sh
# accept it.)

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALLER="$ROOT_DIR/scripts/self-host/install.sh"
GENERATOR="$ROOT_DIR/scripts/self-host/generate-env.sh"
COMPOSE_FILE="$ROOT_DIR/docker/self-hosting/compose.yml"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || fail "docker is required: Compose is the oracle"
docker compose version >/dev/null 2>&1 || fail "docker compose is required"

# --- Lift env_value() out of install.sh --------------------------------------
#
# Sourced rather than reimplemented, so this tests the operator's actual code
# path. install.sh cannot be sourced whole — it is a straight-line script that
# would run an install — and it must stay a single self-contained file, because
# operators curl it into bash.
FUNCTION_FILE="$TMP_DIR/env_value.sh"

awk '
    /^env_value\(\) \{$/ { capture = 1 }
    capture { print }
    capture && /^\}$/ { exit }
' "$INSTALLER" > "$FUNCTION_FILE"

grep -q 'env_value() {' "$FUNCTION_FILE" || fail "could not lift env_value() from install.sh"
grep -q '^}$' "$FUNCTION_FILE" || fail "lifted env_value() is not closed — extraction is wrong"

# shellcheck source=/dev/null
. "$FUNCTION_FILE"

# --- A complete env file, because Compose refuses a partial one --------------

BASE_ENV="$TMP_DIR/base.env"
"$GENERATOR" --output "$BASE_ENV" --app-url "https://support.example.test" >/dev/null

grep -q '^WAYFINDR_IMAGE=' "$BASE_ENV" || printf 'WAYFINDR_IMAGE=placeholder\n' >> "$BASE_ENV"

PINNED="ghcr.io/adamgreenwell/wayfindr:0.1.0"

# What Compose resolves the web service image to, given an env file.
#
# `config` renders the fully interpolated project, so this is Compose's own
# answer for the value install.sh has to predict — not a reimplementation of it.
compose_image() {
    docker compose --env-file "$1" -f "$COMPOSE_FILE" config --format json 2>/dev/null \
        | sed -n 's/.*"image": *"\([^"]*wayfindr[^"]*\)".*/\1/p' \
        | head -1
}

# Rewrite the env file with one spelling of WAYFINDR_IMAGE.
with_image_line() {
    local out="$1"
    shift
    grep -v '^[[:space:]]*\(export[[:space:]]\+\)\?WAYFINDR_IMAGE=' "$BASE_ENV" > "$out"
    printf '%s\n' "$@" >> "$out"
}

checked=0

printf '\n  install.sh env_value() vs Docker Compose — WAYFINDR_IMAGE spellings\n\n'

check() {
    local description="$1" expected="$2"
    shift 2

    local env_file="$TMP_DIR/case.env"
    with_image_line "$env_file" "$@"

    # env_value() reads the global ENV_FILE, exactly as it does inside install.sh.
    local mine theirs
    ENV_FILE="$env_file"
    mine="$(env_value WAYFINDR_IMAGE)"
    theirs="$(compose_image "$env_file")"

    [ -n "$theirs" ] || fail "$description: Compose resolved no image (env file rejected?)"

    if [ "$mine" != "$theirs" ]; then
        fail "$description: install.sh reads '$mine', Compose resolves '$theirs'"
    fi

    # Asserted as well as agreed, so that both being wrong the same way is still
    # caught — a pure differential cannot see that.
    if [ "$mine" != "$expected" ]; then
        fail "$description: both read '$mine', expected '$expected'"
    fi

    printf '    ok  %-52s %s\n' "$description" "$mine"
    checked=$((checked + 1))
}

check "bare value"                "$PINNED" "WAYFINDR_IMAGE=$PINNED"
check "double-quoted"             "$PINNED" "WAYFINDR_IMAGE=\"$PINNED\""
check "single-quoted"             "$PINNED" "WAYFINDR_IMAGE='$PINNED'"
check "quoted with inline comment" "$PINNED" "WAYFINDR_IMAGE=\"$PINNED\" # pinned by the installer"
check "bare with inline comment"  "$PINNED" "WAYFINDR_IMAGE=$PINNED # pinned by the installer"
check "trailing whitespace"       "$PINNED" "WAYFINDR_IMAGE=$PINNED   "
check "export prefix"             "$PINNED" "export WAYFINDR_IMAGE=$PINNED"
check "leading whitespace"        "$PINNED" "  WAYFINDR_IMAGE=$PINNED"
# The operator who appends a line rather than editing the one already there.
# Reading the earlier value made the installer act on a stale image, permit the
# pull, and hand over to an artifact that read the newer one.
check "duplicate keys, last wins" "$PINNED" \
    "WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:0.0.1" "WAYFINDR_IMAGE=$PINNED"
check "duplicate keys, last is quoted" "$PINNED" \
    "WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:0.0.1" "WAYFINDR_IMAGE=\"$PINNED\""

[ "$checked" -eq 10 ] || fail "expected 10 spellings, ran $checked"

printf '\n  %d spellings agree with Compose.\n\n' "$checked"
