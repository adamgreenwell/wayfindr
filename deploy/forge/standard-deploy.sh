#!/usr/bin/env bash
set -euo pipefail

if [[ "${FORGE_DEPLOY_MESSAGE:-}" =~ \[skip[[:space:]]deploy\] ]]; then
    echo "Skipping deploy because commit message contains [skip deploy]."
    exit 0
fi

forge_composer() {
    # Forge may set this to "php8.4 /usr/local/bin/composer".
    ${FORGE_COMPOSER:-composer} "$@"
}

forge_php() {
    "${FORGE_PHP:-php}" "$@"
}

prepare_laravel_runtime_directories() {
    mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs
}

link_laravel_environment_file() {
    if [[ -e .env ]]; then
        return
    fi

    if [[ ! -f ../../.env ]]; then
        echo "Expected Forge environment file at ../../.env, but it was not found." >&2
        exit 1
    fi

    ln -s ../../.env .env
}

cd "$FORGE_SITE_PATH"
git pull origin "$FORGE_SITE_BRANCH"

cd apps/server

link_laravel_environment_file
forge_composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

if [[ -f package-lock.json ]]; then
    npm ci
    npm run build
else
    echo "No package-lock.json found; skipping frontend asset build."
fi

maintenance_enabled=0
restore_application() {
    if [[ "$maintenance_enabled" -eq 1 ]]; then
        forge_php artisan up || true
    fi
}
trap restore_application EXIT

if forge_php artisan down --retry=60; then
    maintenance_enabled=1
fi

prepare_laravel_runtime_directories
forge_php artisan storage:link || true
# Identity, declaration and config cache all precede `migrate`, because the
# upgrade guard runs INSIDE migrate and reads all three (ADR 0013).
#
# Run them after it, as this script used to, and the guard decides this upgrade
# from the PREVIOUS deploy's facts: `bootstrap/cache/config.php` still holds the
# last release's identity, so it evaluates the wrong target, and still holds the
# last release's acknowledgements, so anything the operator added in response to
# a refusal is invisible to the retry.
bash ../../deploy/forge/write-release-identity.sh
bash ../../deploy/forge/write-release-manifest.sh
forge_php artisan config:cache

# A guard refusal (exit 78) must NOT be followed by `artisan up`.
#
# This path replaces the source in place, so the new code is already on disk by
# the time the guard refuses. Bringing the site back would serve that new code
# against the un-migrated schema — exactly what refusing to migrate was
# protecting against. The serving gate cannot catch it either: that gates
# after-start requirements, and a refusal here is a before-pull or after-pull one.
#
# So the site is left in maintenance, on the previous schema, deliberately. The
# zero-downtime path needs none of this — `set -e` aborts it before
# `$ACTIVATE_RELEASE()`, so the old release simply keeps serving.
migrate_status=0
forge_php artisan migrate --force || migrate_status=$?

if [[ "$migrate_status" -eq 78 ]]; then
    maintenance_enabled=0
    trap - EXIT

    echo >&2
    echo "The upgrade guard refused this release: an operator requirement is outstanding." >&2
    echo "The site has been LEFT IN MAINTENANCE MODE, still on the previous schema." >&2
    echo "Do what the refusal above asks, then deploy again. To abandon the upgrade," >&2
    echo "check out the previous commit, deploy it, and run 'php artisan up'." >&2

    exit 78
fi

# Any other failure keeps the previous behaviour: the trap restores the site.
if [[ "$migrate_status" -ne 0 ]]; then
    exit "$migrate_status"
fi

forge_php artisan route:cache
forge_php artisan view:cache
forge_php artisan queue:restart
forge_php artisan reverb:restart
forge_php artisan up
maintenance_enabled=0
trap - EXIT
