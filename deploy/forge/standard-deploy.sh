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

# BEGIN WAYFINDR HOST PHP PREFLIGHT
assert_wayfindr_php_runtime() {
    # Host-runtime requirements are a before-pull release action. This function
    # must run before the deployment mutates or creates a checkout.
    local runtime_probe='
        $problems = [];

        if (PHP_VERSION_ID < 80401) {
            $problems[] = "PHP ".PHP_VERSION." is older than 8.4.1";
        }

        foreach (["curl", "gd", "intl"] as $extension) {
            if (! extension_loaded($extension)) {
                $problems[] = "missing ext-{$extension}";
            }
        }

        if (extension_loaded("curl")) {
            $version = curl_version()["version"] ?? null;

            if (! is_string($version) || version_compare($version, "7.59.0", "<")) {
                $problems[] = "libcurl ".(is_string($version) ? $version : "unknown")." is older than 7.59.0";
            }
        }

        if ($problems !== []) {
            fwrite(STDERR, "Wayfindr host PHP preflight failed before the checkout changed:\n - ".implode("\n - ", $problems)."\n");
            exit(78);
        }
'
    local php_failed=0

    if ! forge_php -r "$runtime_probe"; then
        php_failed=1
    fi

    # Ask Composer about the live platform from an empty declaration. Running
    # this in apps/server would trust config.platform.php=8.4.1 even when the
    # Composer process itself is older; the temporary declaration prevents that
    # simulation from masking the real interpreter or extensions.
    local spec package constraint composer_probe_dir composer_declaration composer_home composer_failed=0

    if ! composer_probe_dir="$(mktemp -d "${TMPDIR:-/tmp}/wayfindr-composer-platform.XXXXXX")"; then
        echo "Could not create a temporary directory for the Composer runtime check." >&2
        composer_failed=1
    else
        composer_declaration="$composer_probe_dir/composer.json"
        composer_home="$composer_probe_dir/home"
    fi

    if [[ "$composer_failed" -eq 0 ]] \
        && { ! mkdir "$composer_home" || ! printf '{}\n' > "$composer_declaration"; }; then
        echo "Could not prepare the isolated Composer runtime check." >&2
        composer_failed=1
    fi

    if [[ "$composer_failed" -eq 0 ]]; then
        for spec in 'php|^8.4.1' 'ext-curl|*' 'ext-gd|*' 'ext-intl|*' 'lib-curl|>=7.59.0'; do
            package="${spec%%|*}"
            constraint="${spec#*|}"

            if ! COMPOSER="$composer_declaration" COMPOSER_HOME="$composer_home" \
                forge_composer --no-interaction --no-plugins show --platform "$package" "$constraint" >/dev/null 2>&1; then
                echo "Composer's actual PHP runtime does not provide $package $constraint." >&2
                composer_failed=1
            fi
        done
    fi

    if [[ -n "${composer_probe_dir:-}" ]]; then
        if ! rm -rf -- "$composer_probe_dir"; then
            echo "Could not remove the temporary Composer runtime directory: $composer_probe_dir" >&2
            composer_failed=1
        fi
    fi

    if [[ "$php_failed" -ne 0 || "$composer_failed" -ne 0 ]]; then
        echo "Select PHP 8.4.1 or newer for FORGE_PHP and FORGE_COMPOSER," >&2
        echo "make sure Composer 2 runs, and install or enable curl, gd, and intl." >&2
        echo "On Debian/Ubuntu with PHP 8.4, the extension packages are normally" >&2
        echo "php8.4-curl, php8.4-gd, and php8.4-intl." >&2
        echo "Reload PHP-FPM and restart queue and Reverb processes," >&2
        echo "then run the deploy again." >&2
        exit 78
    fi
}
# END WAYFINDR HOST PHP PREFLIGHT

# BEGIN WAYFINDR HOST ACTION PREFLIGHT
assert_wayfindr_host_action_acknowledged() {
    # This recipe is saved in Forge rather than loaded from the checkout it is
    # about to replace. Keep the one host-only action that belongs before the
    # pull here too, or an otherwise healthy host reaches Laravel only after its
    # source has changed and the in-place deploy has entered maintenance mode.
    local action_key='0.8.0/php-runtime-extensions'
    local action_release="${action_key%%/*}"
    local site_root="${FORGE_SITE_ROOT:-}"

    # Forge supplies FORGE_SITE_ROOT separately from FORGE_SITE_PATH. During a
    # zero-downtime deploy, the former owns the persistent .env while the latter
    # points at `current` (which does not exist before the first release). Keep a
    # cautious fallback for recipes copied to environments that expose only the
    # checkout path.
    if [[ -z "$site_root" ]]; then
        site_root="$FORGE_SITE_PATH"

        if [[ "${site_root##*/}" == 'current' ]]; then
            site_root="${site_root%/current}"
        fi
    fi

    local current_app="$FORGE_SITE_PATH/apps/server"

    # Accept Forge's zero-downtime and standard layouts, always reading the
    # release that is live NOW when one exists.
    if [[ ! -f "$current_app/vendor/autoload.php" \
        && -f "$site_root/current/apps/server/vendor/autoload.php" ]]; then
        current_app="$site_root/current/apps/server"
    elif [[ ! -f "$current_app/vendor/autoload.php" \
        && -f "$site_root/apps/server/vendor/autoload.php" ]]; then
        current_app="$site_root/apps/server"
    fi

    local environment_file="$site_root/.env"

    if [[ ! -f "$environment_file" && -f "$FORGE_SITE_PATH/.env" ]]; then
        environment_file="$FORGE_SITE_PATH/.env"
    elif [[ ! -f "$environment_file" && -f "$current_app/.env" ]]; then
        environment_file="$current_app/.env"
    fi

    # A missing vendor tree and state file cannot prove freshness: a restored
    # database, a legacy checkout, or a custom state path has the same shape.
    # Existing installs use their real phpdotenv below. On the first deploy there
    # is no autoloader yet, so accept only an explicit acknowledgement read with
    # the same practical dotenv spellings the installer accepts.
    if [[ ! -f "$current_app/vendor/autoload.php" ]]; then
        local raw_acknowledgements="${WAYFINDR_ACKNOWLEDGED_ACTIONS:-}"

        if [[ -z "$raw_acknowledgements" && -f "$environment_file" ]]; then
            raw_acknowledgements="$(
                grep -E '^[[:space:]]*(export[[:space:]]+)?WAYFINDR_ACKNOWLEDGED_ACTIONS=' \
                    "$environment_file" 2>/dev/null \
                    | tail -1 \
                    | sed -E 's/^[[:space:]]*(export[[:space:]]+)?WAYFINDR_ACKNOWLEDGED_ACTIONS=//' \
                    || true
            )"
            raw_acknowledgements="${raw_acknowledgements%$'\r'}"

            case "$raw_acknowledgements" in
                \"*)
                    raw_acknowledgements="${raw_acknowledgements#\"}"
                    raw_acknowledgements="${raw_acknowledgements%%\"*}"
                    ;;
                \'*)
                    raw_acknowledgements="${raw_acknowledgements#\'}"
                    raw_acknowledgements="${raw_acknowledgements%%\'*}"
                    ;;
                *)
                    case "$raw_acknowledgements" in
                        *[[:space:]]\#*) raw_acknowledgements="${raw_acknowledgements%%[[:space:]]\#*}" ;;
                    esac

                    while [[ "$raw_acknowledgements" == *[[:space:]] ]]; do
                        raw_acknowledgements="${raw_acknowledgements%?}"
                    done
                    ;;
            esac
        fi

        local acknowledgement

        while IFS= read -r acknowledgement; do
            acknowledgement="$(printf '%s' "$acknowledgement" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"

            if [[ "$acknowledgement" == "$action_key" ]]; then
                return
            fi
        done < <(printf '%s\n' "$raw_acknowledgements" | tr ',' '\n')

        echo "Wayfindr cannot prove this host action is settled before changing the checkout." >&2
        echo "Verify every PHP runtime, then add $action_key to WAYFINDR_ACKNOWLEDGED_ACTIONS and deploy again." >&2
        echo "If this is an existing install with a missing vendor tree, restore that tree first." >&2
        exit 78
    fi

    local action_probe='
        $appRoot = $argv[1];
        $environmentFile = $argv[2];
        $actionKey = $argv[3];
        $actionRelease = $argv[4];

        require $appRoot."/vendor/autoload.php";

        $values = [];

        if (is_file($environmentFile) && is_readable($environmentFile)) {
            try {
                $values = Dotenv\Dotenv::createArrayBacked(
                    dirname($environmentFile),
                    basename($environmentFile),
                )->safeLoad();
            } catch (Throwable) {
                fwrite(STDERR, "Wayfindr could not parse the current Forge environment before changing the checkout.\n");
                exit(78);
            }
        }

        $live = static function (string $key) use ($values): ?string {
            $process = getenv($key);

            if (is_string($process) && $process !== "") {
                return $process;
            }

            $value = $values[$key] ?? null;

            return is_string($value) && $value !== "" ? $value : null;
        };

        $statePath = $live("WAYFINDR_RELEASE_STATE_PATH")
            ?: $appRoot."/storage/app/release-state.json";
        $satisfiedThrough = null;
        $installationProfile = null;

        if (is_file($statePath) && is_readable($statePath)) {
            try {
                $state = json_decode(
                    (string) file_get_contents($statePath),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (Throwable) {
                $state = null;
            }

            if (is_array($state) && is_string($state["satisfied_through"] ?? null)) {
                $satisfiedThrough = trim($state["satisfied_through"]);
            }

            if (is_array($state) && is_string($state["installation_profile"] ?? null)) {
                $installationProfile = $state["installation_profile"];
            }
        }

        // Only a HOST marker strictly after the release named by the action key
        // proves the host-only action was traversed. An image can advance the
        // same marker while legitimately
        // filtering this action out; moving that state to Forge must reopen it.
        // VERSION also stays at 0.8.0 for the whole source-development cycle, so
        // an exact 0.8.0 state may predate the action itself.
        if ($installationProfile === "host"
            && is_string($satisfiedThrough)
            && preg_match(
                "/^v?(0|[1-9]\\d*)\\.(0|[1-9]\\d*)\\.(0|[1-9]\\d*)(?:\\+[0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*)?$/",
                $satisfiedThrough,
                $parts,
            ) === 1
            && version_compare("{$parts[1]}.{$parts[2]}.{$parts[3]}", $actionRelease, ">")) {
            exit(0);
        }

        $acknowledged = array_values(array_filter(
            array_map("trim", explode(",", $live("WAYFINDR_ACKNOWLEDGED_ACTIONS") ?? "")),
            static fn (string $entry): bool => $entry !== "",
        ));

        if (in_array($actionKey, $acknowledged, true)) {
            exit(0);
        }

        fwrite(STDERR, "Wayfindr host action preflight stopped before the checkout changed.\n");
        fwrite(STDERR, "Verify PHP 8.4.1+, curl, gd, intl, and libcurl 7.59.0+ in Composer, PHP-FPM, queue, scheduler, and Reverb runtimes.\n");
        fwrite(STDERR, "Reload or restart those runtimes, then add {$actionKey} to WAYFINDR_ACKNOWLEDGED_ACTIONS and deploy again.\n");
        exit(78);
    '

    forge_php -r "$action_probe" -- "$current_app" "$environment_file" "$action_key" "$action_release"
}
# END WAYFINDR HOST ACTION PREFLIGHT

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

assert_wayfindr_php_runtime
assert_wayfindr_host_action_acknowledged

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
# The immediate pass covers writes already committed. The queued pass runs two
# minutes later, after the documented 90-second default worker timeout, so an
# old worker finishing after queue:restart cannot strand its final refresh until
# the daily compatibility sweep.
forge_php artisan wayfindr:reconcile-agent-alert-publications
forge_php artisan wayfindr:reconcile-agent-alert-publications --after-worker-drain
forge_php artisan reverb:restart
forge_php artisan up
maintenance_enabled=0
trap - EXIT
