#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXPECTED_VERSION="8.4"

fail() {
    printf '%s\n' "PHP version contract failed: $1" >&2
    exit 1
}

composer_constraint="$(
    php -r '
        $composer = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        echo $composer["require"]["php"] ?? "";
    ' "$ROOT_DIR/apps/server/composer.json"
)"

[ "$composer_constraint" = "^$EXPECTED_VERSION" ] \
    || fail "composer.json requires '$composer_constraint', expected '^$EXPECTED_VERSION'."

grep -Fx "ARG PHP_VERSION=$EXPECTED_VERSION" \
    "$ROOT_DIR/docker/self-hosting/server.Dockerfile" >/dev/null \
    || fail "the self-hosting image default is not PHP $EXPECTED_VERSION."

grep -Fx "WAYFINDR_PHP_VERSION=$EXPECTED_VERSION" \
    "$ROOT_DIR/docker/self-hosting/.env.example" >/dev/null \
    || fail "the self-hosting env example is not PHP $EXPECTED_VERSION."

grep -Fx "WAYFINDR_PHP_VERSION=$EXPECTED_VERSION" \
    "$ROOT_DIR/scripts/self-host/generate-env.sh" >/dev/null \
    || fail "the generated self-host env is not PHP $EXPECTED_VERSION."

grep -F 'PHP_VERSION: ${WAYFINDR_PHP_VERSION:-8.4}' \
    "$ROOT_DIR/docker/self-hosting/compose.build.yml" >/dev/null \
    || fail "the Compose source-build fallback is not PHP $EXPECTED_VERSION."

grep -F "PHP $EXPECTED_VERSION or newer" "$ROOT_DIR/CONTRIBUTING.md" >/dev/null \
    || fail "CONTRIBUTING.md does not declare PHP $EXPECTED_VERSION or newer."

grep -F "PHP $EXPECTED_VERSION or newer" "$ROOT_DIR/docs/development/local-setup.md" >/dev/null \
    || fail "local setup does not declare PHP $EXPECTED_VERSION or newer."

grep -F "PHP $EXPECTED_VERSION or newer" "$ROOT_DIR/docs/self-hosting/runtime-requirements.md" >/dev/null \
    || fail "runtime requirements do not declare PHP $EXPECTED_VERSION or newer."

grep -F "PHP $EXPECTED_VERSION+" "$ROOT_DIR/docs/self-hosting/laravel-forge.md" >/dev/null \
    || fail "the Forge guide does not declare PHP $EXPECTED_VERSION+."

stale_claims="$(
    cd "$ROOT_DIR"
    git grep -n -I -E 'PHP 8\.3|php 8\.3|\^8\.3' -- \
        '*.md' \
        'apps/server/composer.json' \
        'docker/self-hosting' \
        'scripts' || true
)"

if [ -n "$stale_claims" ]; then
    printf '%s\n' "$stale_claims" >&2
    fail "tracked files still claim the old PHP floor."
fi

printf '%s\n' "PHP $EXPECTED_VERSION is consistent across Composer, builds, and documentation."
