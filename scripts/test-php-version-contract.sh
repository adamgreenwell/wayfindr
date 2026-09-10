#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() {
    printf '%s\n' "PHP version contract failed: $1" >&2
    exit 1
}

if ! composer_platform_specs="$(
    php -r '
        $composer = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        $requirements = $composer["require"] ?? [];
        $platform = [];

        foreach ($requirements as $package => $constraint) {
            if ($package === "php" || str_starts_with($package, "ext-") || str_starts_with($package, "lib-")) {
                $platform[$package] = $constraint;
            }
        }

        ksort($platform);

        foreach ($platform as $package => $constraint) {
            echo $package, "|", $constraint, PHP_EOL;
        }
    ' "$ROOT_DIR/apps/server/composer.json"
)"; then
    fail "composer.json does not contain a readable platform contract."
fi

composer_constraint="$(
    printf '%s\n' "$composer_platform_specs" \
        | awk -F'|' '$1 == "php" { print substr($0, index($0, "|") + 1) }'
)"

if [[ ! "$composer_constraint" =~ ^\^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
    fail "composer.json must declare one exact caret PHP floor; found '$composer_constraint'."
fi

MINIMUM_PHP_VERSION="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.${BASH_REMATCH[3]}"
IMAGE_PHP_SERIES="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"
MINIMUM_PHP_ID="$(
    php -r '
        $parts = array_map("intval", explode(".", $argv[1]));
        printf("%d%02d%02d", $parts[0], $parts[1], $parts[2]);
    ' "$MINIMUM_PHP_VERSION"
)"
runtime_extensions="$(
    printf '%s\n' "$composer_platform_specs" \
        | awk -F'|' '$1 ~ /^ext-/ { sub(/^ext-/, "", $1); print $1 }' \
        | LC_ALL=C sort
)"
libcurl_constraint="$(
    printf '%s\n' "$composer_platform_specs" \
        | awk -F'|' '$1 == "lib-curl" { print substr($0, index($0, "|") + 1) }'
)"
MINIMUM_LIBCURL_VERSION="${libcurl_constraint#>=}"

if [[ "$libcurl_constraint" != ">=$MINIMUM_LIBCURL_VERSION" \
    || ! "$MINIMUM_LIBCURL_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    fail "composer.json must declare one exact minimum lib-curl version; found '$libcurl_constraint'."
fi

grep -Fx "ARG PHP_VERSION=$IMAGE_PHP_SERIES" \
    "$ROOT_DIR/docker/self-hosting/server.Dockerfile" >/dev/null \
    || fail "the self-hosting image default is not PHP $IMAGE_PHP_SERIES."

grep -Fx "WAYFINDR_PHP_VERSION=$IMAGE_PHP_SERIES" \
    "$ROOT_DIR/docker/self-hosting/.env.example" >/dev/null \
    || fail "the self-hosting env example is not PHP $IMAGE_PHP_SERIES."

grep -Fx "WAYFINDR_PHP_VERSION=$IMAGE_PHP_SERIES" \
    "$ROOT_DIR/scripts/self-host/generate-env.sh" >/dev/null \
    || fail "the generated self-host env is not PHP $IMAGE_PHP_SERIES."

while IFS= read -r extension; do
    [ -n "$extension" ] || continue
    grep -E "^[[:space:]]+$extension \\\\$" \
        "$ROOT_DIR/docker/self-hosting/server.Dockerfile" >/dev/null \
        || fail "the self-hosting image does not install ext-$extension."
done <<< "$runtime_extensions"

zero_preflight="$(
    sed -n '/^# BEGIN WAYFINDR HOST PHP PREFLIGHT$/,/^# END WAYFINDR HOST PHP PREFLIGHT$/p' \
        "$ROOT_DIR/deploy/forge/zero-downtime-deploy.forge"
)"
standard_preflight="$(
    sed -n '/^# BEGIN WAYFINDR HOST PHP PREFLIGHT$/,/^# END WAYFINDR HOST PHP PREFLIGHT$/p' \
        "$ROOT_DIR/deploy/forge/standard-deploy.sh"
)"
zero_action_preflight="$(
    sed -n '/^# BEGIN WAYFINDR HOST ACTION PREFLIGHT$/,/^# END WAYFINDR HOST ACTION PREFLIGHT$/p' \
        "$ROOT_DIR/deploy/forge/zero-downtime-deploy.forge"
)"
standard_action_preflight="$(
    sed -n '/^# BEGIN WAYFINDR HOST ACTION PREFLIGHT$/,/^# END WAYFINDR HOST ACTION PREFLIGHT$/p' \
        "$ROOT_DIR/deploy/forge/standard-deploy.sh"
)"

[ -n "$zero_preflight" ] \
    || fail "the zero-downtime Forge script has no host PHP preflight."
[ "$zero_preflight" = "$standard_preflight" ] \
    || fail "the two Forge scripts have drifted to different host PHP preflights."
[ -n "$zero_action_preflight" ] \
    || fail "the zero-downtime Forge script has no host action preflight."
[ "$zero_action_preflight" = "$standard_action_preflight" ] \
    || fail "the two Forge scripts have drifted to different host action preflights."

recipe_platform_specs="$(
    printf '%s\n' "$standard_preflight" \
        | awk -F"'" '/^[[:space:]]*for spec in / { for (field = 2; field <= NF; field += 2) print $field }'
)"

[ -n "$recipe_platform_specs" ] \
    || fail "the Forge preflight has no Composer platform specs."
[ "$(printf '%s\n' "$recipe_platform_specs" | LC_ALL=C sort)" = \
    "$(printf '%s\n' "$composer_platform_specs" | LC_ALL=C sort)" ] \
    || fail "the Forge Composer platform probe has drifted from composer.json."

direct_runtime_extensions="$(
    printf '%s\n' "$standard_preflight" \
        | sed -n 's/.*foreach (\[\(.*\)\] as \$extension).*/\1/p' \
        | tr ',' '\n' \
        | sed -E 's/["[:space:]]//g' \
        | sed '/^$/d' \
        | LC_ALL=C sort
)"

[ -n "$direct_runtime_extensions" ] \
    || fail "the Forge direct-PHP extension probe could not be inspected."

[ "$direct_runtime_extensions" = "$runtime_extensions" ] \
    || fail "the Forge direct-PHP extension probe has drifted from composer.json."

printf '%s\n' "$standard_preflight" | grep -F "PHP_VERSION_ID < $MINIMUM_PHP_ID" >/dev/null \
    || fail "the Forge direct-PHP floor has drifted from composer.json."
printf '%s\n' "$standard_preflight" \
    | grep -F "version_compare(\$version, \"$MINIMUM_LIBCURL_VERSION\", \"<\")" >/dev/null \
    || fail "the Forge direct-PHP libcurl floor has drifted from composer.json."

current_manifest="$(
    php "$ROOT_DIR/scripts/release/build-manifest.php" \
        --version="$(tr -d '[:space:]' < "$ROOT_DIR/VERSION")" \
        --commit=php-contract-test
)" || fail "the current release declaration could not be built."

expected_host_actions="$(
    printf '%s' "$current_manifest" | php -r '
        $current = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $history = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        $keys = [];

        foreach (array_merge($history["releases"] ?? [], [$current]) as $manifest) {
            $release = $manifest["version"] ?? null;

            if (! is_string($release)) {
                continue;
            }

            foreach ($manifest["actions"] ?? [] as $action) {
                $profiles = $action["installation_profiles"] ?? null;
                $appliesToHost = $profiles === null
                    || (is_array($profiles) && in_array("host", $profiles, true));

                if (($action["phase"] ?? null) === "before-pull"
                    && $appliesToHost
                    && is_string($action["id"] ?? null)) {
                    $keys[] = $release."/".$action["id"];
                }
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        echo implode(PHP_EOL, $keys);
    ' "$ROOT_DIR/releases/history.json"
)" || fail "the host before-pull actions could not be derived from release history."

recipe_host_actions="$(
    printf '%s\n' "$standard_action_preflight" \
        | sed -n "s/^[[:space:]]*local action_key='\([^']*\)'$/\1/p" \
        | LC_ALL=C sort -u
)"

[ "$recipe_host_actions" = "$expected_host_actions" ] \
    || fail "the Forge host preflight actions have drifted from release.json and retained history."

for action_release_marker in \
    'local action_release="${action_key%%/*}"' \
    '$actionRelease = $argv[4];' \
    'version_compare("{$parts[1]}.{$parts[2]}.{$parts[3]}", $actionRelease, ">")' \
    '"$action_key" "$action_release"'; do
    printf '%s\n' "$standard_action_preflight" | grep -F "$action_release_marker" >/dev/null \
        || fail "the Forge state bypass is not derived from its declared action key."
done

zero_preflight_line="$(grep -n '^assert_wayfindr_php_runtime$' "$ROOT_DIR/deploy/forge/zero-downtime-deploy.forge" | cut -d: -f1)"
zero_action_preflight_line="$(grep -n '^assert_wayfindr_host_action_acknowledged$' "$ROOT_DIR/deploy/forge/zero-downtime-deploy.forge" | cut -d: -f1)"
zero_mutation_line="$(grep -n '^\$CREATE_RELEASE()$' "$ROOT_DIR/deploy/forge/zero-downtime-deploy.forge" | cut -d: -f1)"
standard_preflight_line="$(grep -n '^assert_wayfindr_php_runtime$' "$ROOT_DIR/deploy/forge/standard-deploy.sh" | cut -d: -f1)"
standard_action_preflight_line="$(grep -n '^assert_wayfindr_host_action_acknowledged$' "$ROOT_DIR/deploy/forge/standard-deploy.sh" | cut -d: -f1)"
standard_mutation_line="$(grep -n '^git pull ' "$ROOT_DIR/deploy/forge/standard-deploy.sh" | cut -d: -f1)"

[ "$zero_preflight_line" -lt "$zero_mutation_line" ] \
    || fail "the zero-downtime PHP preflight runs after CREATE_RELEASE."
[ "$standard_preflight_line" -lt "$standard_mutation_line" ] \
    || fail "the standard PHP preflight runs after git pull."
[ "$zero_action_preflight_line" -lt "$zero_mutation_line" ] \
    || fail "the zero-downtime host action preflight runs after CREATE_RELEASE."
[ "$standard_action_preflight_line" -lt "$standard_mutation_line" ] \
    || fail "the standard host action preflight runs after git pull."

preflight_helpers="$({
    sed -n '/^forge_composer()/,/^}/p' "$ROOT_DIR/deploy/forge/standard-deploy.sh"
    sed -n '/^forge_php()/,/^}/p' "$ROOT_DIR/deploy/forge/standard-deploy.sh"
})"
preflight_runner="${preflight_helpers}"$'\n'"${standard_preflight}"$'\nassert_wayfindr_php_runtime'

wayfindr_test_composer_good() {
    [[ "${COMPOSER:-}" != "$ROOT_DIR/apps/server/composer.json" \
        && "${COMPOSER_HOME:-}" != "${HOSTILE_COMPOSER_HOME:-}" \
        && " $* " == *' --no-plugins '* ]]
}

wayfindr_test_composer_spoofed() {
    # Simulate a project platform override that would report success unless the
    # preflight replaces the repository declaration with its neutral one.
    [[ "${COMPOSER:-}" == "$ROOT_DIR/apps/server/composer.json" ]]
}

export ROOT_DIR HOSTILE_COMPOSER_HOME
export -f wayfindr_test_composer_good wayfindr_test_composer_spoofed

HOSTILE_COMPOSER_HOME="$ROOT_DIR/apps/server"
export HOSTILE_COMPOSER_HOME

FORGE_PHP=/usr/bin/true FORGE_COMPOSER=wayfindr_test_composer_good \
    COMPOSER_HOME="$HOSTILE_COMPOSER_HOME" \
    bash -c "$preflight_runner" >/dev/null 2>&1 \
    || fail "the Forge preflight rejects a compliant or isolated application and Composer PHP runtime."

if spoofed_output="$(
    FORGE_PHP=/usr/bin/true FORGE_COMPOSER=wayfindr_test_composer_spoofed \
        bash -c "$preflight_runner" 2>&1
)"; then
    fail "the Forge preflight let a project platform override mask Composer's actual runtime."
fi

printf '%s\n' "$spoofed_output" | grep -F "Composer's actual PHP runtime does not provide php ^8.4.1." >/dev/null \
    || fail "the Forge preflight did not identify the failing Composer PHP runtime."

action_preflight_runner='set -euo pipefail'$'\n'"${preflight_helpers}"$'\n'"${standard_action_preflight}"$'\nassert_wayfindr_host_action_acknowledged\nprintf "%s\\n" MUTATION_REACHED'
fixture_site="$(mktemp -d "${TMPDIR:-/tmp}/wayfindr-forge-action.XXXXXX")"
fixture_current="$fixture_site/current"
fixture_app="$fixture_current/apps/server"
fixture_state="$fixture_app/storage/app/release-state.json"
compatible_php=''

cleanup_forge_fixture() {
    rm -rf -- "$fixture_site"
}
trap cleanup_forge_fixture EXIT

# A real Composer regression for the exact bypass that prompted the isolated
# COMPOSER_HOME. It is meaningful only when the PHP running Composer is below
# the floor: the hostile global config then makes every platform probe look
# compliant, while the isolated preflight must still see the real interpreter.
if command -v composer >/dev/null 2>&1 \
    && php -r 'exit(PHP_VERSION_ID < 80401 ? 0 : 1);' >/dev/null 2>&1; then
    hostile_home="$fixture_site/hostile-composer-home"
    hostile_declaration="$fixture_site/hostile-composer.json"
    composer_binary="$(command -v composer)"
    mkdir -p "$hostile_home"
    printf '%s\n' '{"config":{"platform":{"php":"8.4.1","ext-curl":"8.4.1","ext-gd":"8.4.1","ext-intl":"8.4.1","lib-curl":"8.10.0"}}}' > "$hostile_home/config.json"
    printf '{}\n' > "$hostile_declaration"

    for spec in 'php|^8.4.1' 'ext-curl|*' 'ext-gd|*' 'ext-intl|*' 'lib-curl|>=7.59.0'; do
        package="${spec%%|*}"
        constraint="${spec#*|}"
        COMPOSER="$hostile_declaration" COMPOSER_HOME="$hostile_home" \
            php "$composer_binary" --no-interaction --no-plugins \
                show --platform "$package" "$constraint" >/dev/null 2>&1 \
            || fail "the hostile global Composer platform fixture cannot spoof $package $constraint."
    done

    if hostile_output="$(
        FORGE_PHP=/usr/bin/true FORGE_COMPOSER="php $composer_binary" \
            COMPOSER_HOME="$hostile_home" bash -c "$preflight_runner" 2>&1
    )"; then
        fail "a hostile global Composer platform override bypassed the Forge preflight."
    fi

    printf '%s\n' "$hostile_output" | grep -F "Composer's actual PHP runtime does not provide php ^8.4.1." >/dev/null \
        || fail "the isolated Composer preflight did not expose the real unsupported interpreter."
fi

for php_candidate in \
    "${WAYFINDR_TEST_PHP:-}" \
    php \
    /opt/homebrew/opt/php/bin/php \
    php8.4 \
    php8.5; do
    if [[ -n "$php_candidate" ]] \
        && command -v "$php_candidate" >/dev/null 2>&1 \
        && "$php_candidate" -r 'exit(PHP_VERSION_ID >= 80401 ? 0 : 1);' >/dev/null 2>&1; then
        compatible_php="$php_candidate"
        break
    fi
done

[[ -n "$compatible_php" ]] \
    || fail "no PHP 8.4.1+ binary is available for the Forge action preflight fixture."

# The fixture owns both values. Do not let a developer or CI runner's exported
# release settings override its .env and state-path cases.
unset WAYFINDR_ACKNOWLEDGED_ACTIONS WAYFINDR_RELEASE_STATE_PATH

mkdir -p "$fixture_app/storage/app" "$fixture_app/vendor"
# Keep this fixture runnable in the self-hosting CI job, which intentionally has
# no Composer install. Production still loads the current application's real
# phpdotenv; this tiny double supplies only the createArrayBacked/safeLoad seam
# exercised by the shell gate.
cat > "$fixture_app/vendor/autoload.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Dotenv;

use RuntimeException;

final class Dotenv
{
    private function __construct(private readonly string $path) {}

    public static function createArrayBacked(string $directory, string $name): self
    {
        return new self(rtrim($directory, '/').'/'.$name);
    }

    /** @return array<string, string> */
    public function safeLoad(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $values = parse_ini_file($this->path, false, INI_SCANNER_RAW);

        if (! is_array($values)) {
            throw new RuntimeException('Could not parse fixture environment.');
        }

        return $values;
    }
}
PHP
printf '%s\n' '{"version":"0.7.0","commit":"abc","satisfied_through":"0.7.0","fresh_install":false}' > "$fixture_state"
printf '%s\n' 'OTHER=value' > "$fixture_site/.env"

if missing_ack_output="$(
    FORGE_SITE_ROOT="$fixture_site" FORGE_SITE_PATH="$fixture_current" FORGE_PHP="$compatible_php" \
        bash -c "$action_preflight_runner" 2>&1
)"; then
    fail "an existing pre-0.8 Forge install passed without its host action acknowledgement."
else
    missing_ack_status=$?
fi

[[ "$missing_ack_status" -eq 78 ]] \
    || fail "a missing Forge host action acknowledgement exited $missing_ack_status instead of 78: $missing_ack_output"
printf '%s\n' "$missing_ack_output" | grep -F 'stopped before the checkout changed' >/dev/null \
    || fail "the Forge action preflight does not explain its pre-mutation refusal."
printf '%s\n' "$missing_ack_output" | grep -F '0.8.0/php-runtime-extensions' >/dev/null \
    || fail "the Forge action preflight does not print the exact acknowledgement key."
printf '%s\n' "$missing_ack_output" | grep -F 'WAYFINDR_ACKNOWLEDGED_ACTIONS' >/dev/null \
    || fail "the Forge action preflight does not name the acknowledgement setting."
if printf '%s\n' "$missing_ack_output" | grep -F 'MUTATION_REACHED' >/dev/null; then
    fail "the Forge recipe reached its mutation sentinel after refusing the acknowledgement."
fi

printf '%s\n' 'WAYFINDR_ACKNOWLEDGED_ACTIONS="0.7.0/other, 0.8.0/php-runtime-extensions"' > "$fixture_site/.env"
acknowledged_output="$(
    FORGE_SITE_ROOT="$fixture_site" FORGE_SITE_PATH="$fixture_current" FORGE_PHP="$compatible_php" \
        bash -c "$action_preflight_runner" 2>&1
)" || fail "an exact Forge host action acknowledgement did not clear the preflight."
[[ "$(printf '%s\n' "$acknowledged_output" | grep -Fc 'MUTATION_REACHED')" -eq 1 ]] \
    || fail "the acknowledged Forge upgrade did not reach its mutation sentinel exactly once."

printf '%s\n' 'WAYFINDR_ACKNOWLEDGED_ACTIONS=0.8.0/php-runtime-extensions-extra' > "$fixture_site/.env"
if substring_ack_output="$(
    FORGE_SITE_ROOT="$fixture_site" FORGE_SITE_PATH="$fixture_current" FORGE_PHP="$compatible_php" \
        bash -c "$action_preflight_runner" 2>&1
)"; then
    fail "a substring of the Forge host action acknowledgement cleared the preflight."
else
    substring_ack_status=$?
fi
[[ "$substring_ack_status" -eq 78 ]] \
    || fail "an inexact Forge host action acknowledgement did not exit 78."
if printf '%s\n' "$substring_ack_output" | grep -F 'MUTATION_REACHED' >/dev/null; then
    fail "the Forge recipe reached its mutation sentinel with an inexact acknowledgement."
fi

printf '%s\n' '{"version":"0.8.0","commit":"def","satisfied_through":"0.8.0","fresh_install":false}' > "$fixture_state"
printf '%s\n' 'OTHER=value' > "$fixture_site/.env"
if same_cycle_output="$(
    FORGE_SITE_ROOT="$fixture_site" FORGE_SITE_PATH="$fixture_current" FORGE_PHP="$compatible_php" \
        bash -c "$action_preflight_runner" 2>&1
)"; then
    fail "an exact 0.8.0 development-cycle state bypassed a newly added 0.8.0 host action."
else
    same_cycle_status=$?
fi
[[ "$same_cycle_status" -eq 78 ]] \
    || fail "an exact 0.8.0 development-cycle state did not exit 78."
if printf '%s\n' "$same_cycle_output" | grep -F 'MUTATION_REACHED' >/dev/null; then
    fail "the Forge recipe mutated an exact 0.8.0 development-cycle install without acknowledgement."
fi

printf '%s\n' '{"version":"0.9.0","commit":"ghi","satisfied_through":"0.7.0","fresh_install":false}' > "$fixture_state"
if outstanding_prior_output="$(
    FORGE_SITE_ROOT="$fixture_site" FORGE_SITE_PATH="$fixture_current" FORGE_PHP="$compatible_php" \
        bash -c "$action_preflight_runner" 2>&1
)"; then
    fail "a later running version bypassed an action its clean-state marker still owes."
else
    outstanding_prior_status=$?
fi
[[ "$outstanding_prior_status" -eq 78 ]] \
    || fail "a later running version with an older clean-state marker did not exit 78."
if printf '%s\n' "$outstanding_prior_output" | grep -F 'MUTATION_REACHED' >/dev/null; then
    fail "the Forge recipe mutated an install whose clean-state marker still owes the action."
fi

printf '%s\n' '{"version":"0.9.0","commit":"jkl","satisfied_through":"0.9.0","fresh_install":false}' > "$fixture_state"
if unknown_profile_output="$(
    FORGE_SITE_ROOT="$fixture_site" FORGE_SITE_PATH="$fixture_current" FORGE_PHP="$compatible_php" \
        bash -c "$action_preflight_runner" 2>&1
)"; then
    fail "a clean marker with no installation profile bypassed the host-only action."
else
    unknown_profile_status=$?
fi
[[ "$unknown_profile_status" -eq 78 ]] \
    || fail "a clean marker with no installation profile did not exit 78."
if printf '%s\n' "$unknown_profile_output" | grep -F 'MUTATION_REACHED' >/dev/null; then
    fail "the Forge recipe trusted a clean marker whose installation profile is unknown."
fi

printf '%s\n' '{"version":"0.9.0","commit":"jkl","satisfied_through":"0.9.0","installation_profile":"image","fresh_install":false}' > "$fixture_state"
if image_profile_output="$(
    FORGE_SITE_ROOT="$fixture_site" FORGE_SITE_PATH="$fixture_current" FORGE_PHP="$compatible_php" \
        bash -c "$action_preflight_runner" 2>&1
)"; then
    fail "an image clean marker bypassed the host-only action on Forge."
else
    image_profile_status=$?
fi
[[ "$image_profile_status" -eq 78 ]] \
    || fail "an image clean marker used on Forge did not exit 78."
if printf '%s\n' "$image_profile_output" | grep -F 'MUTATION_REACHED' >/dev/null; then
    fail "the Forge recipe trusted an image marker for host-only work."
fi

printf '%s\n' '{"version":"0.9.0","commit":"jkl","satisfied_through":"0.9.0","installation_profile":"host","fresh_install":false}' > "$fixture_state"
already_settled_output="$(
    FORGE_SITE_ROOT="$fixture_site" FORGE_SITE_PATH="$fixture_current" FORGE_PHP="$compatible_php" \
        bash -c "$action_preflight_runner" 2>&1
)" || fail "a clean marker after 0.8.0 was asked to repeat the 0.8.0 host action."
[[ "$(printf '%s\n' "$already_settled_output" | grep -Fc 'MUTATION_REACHED')" -eq 1 ]] \
    || fail "an install clean past 0.8.0 did not reach its mutation sentinel exactly once."

rm "$fixture_app/vendor/autoload.php"
rmdir "$fixture_app/vendor"
rm "$fixture_state"
printf '%s\n' 'OTHER=value' > "$fixture_site/.env"
if ambiguous_install_output="$(
    FORGE_SITE_ROOT="$fixture_site" FORGE_SITE_PATH="$fixture_current" FORGE_PHP="$compatible_php" \
        bash -c "$action_preflight_runner" 2>&1
)"; then
    fail "an install with neither dependencies nor default state was guessed to be fresh."
else
    ambiguous_install_status=$?
fi
[[ "$ambiguous_install_status" -eq 78 ]] \
    || fail "an unproven fresh Forge install did not exit 78."
printf '%s\n' "$ambiguous_install_output" | grep -F 'cannot prove this host action is settled' >/dev/null \
    || fail "an unproven fresh Forge install does not explain why it stopped."
if printf '%s\n' "$ambiguous_install_output" | grep -F 'MUTATION_REACHED' >/dev/null; then
    fail "the Forge recipe mutated an install whose freshness was not proven."
fi

rm -rf -- "$fixture_current"
printf '%s\n' 'WAYFINDR_ACKNOWLEDGED_ACTIONS="0.7.0/other, 0.8.0/php-runtime-extensions"' > "$fixture_site/.env"
first_deploy_output="$(
    FORGE_SITE_ROOT="$fixture_site" FORGE_SITE_PATH="$fixture_current" FORGE_PHP="$compatible_php" \
        bash -c "$action_preflight_runner" 2>&1
)" || fail "an explicit first-deploy host acknowledgement did not clear the preflight."
[[ "$(printf '%s\n' "$first_deploy_output" | grep -Fc 'MUTATION_REACHED')" -eq 1 ]] \
    || fail "the acknowledged first Forge deploy did not reach its mutation sentinel exactly once."

grep -F "PHP_VERSION: \${WAYFINDR_PHP_VERSION:-$IMAGE_PHP_SERIES}" \
    "$ROOT_DIR/docker/self-hosting/compose.build.yml" >/dev/null \
    || fail "the Compose source-build fallback is not PHP $IMAGE_PHP_SERIES."

grep -F "PHP $MINIMUM_PHP_VERSION or newer" "$ROOT_DIR/CONTRIBUTING.md" >/dev/null \
    || fail "CONTRIBUTING.md does not declare PHP $MINIMUM_PHP_VERSION or newer."

grep -F "PHP $MINIMUM_PHP_VERSION or newer" "$ROOT_DIR/docs/development/local-setup.md" >/dev/null \
    || fail "local setup does not declare PHP $MINIMUM_PHP_VERSION or newer."

grep -F "PHP $MINIMUM_PHP_VERSION or newer" "$ROOT_DIR/docs/self-hosting/runtime-requirements.md" >/dev/null \
    || fail "runtime requirements do not declare PHP $MINIMUM_PHP_VERSION or newer."

grep -F "PHP $MINIMUM_PHP_VERSION+" "$ROOT_DIR/docs/self-hosting/laravel-forge.md" >/dev/null \
    || fail "the Forge guide does not declare PHP $MINIMUM_PHP_VERSION+."

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

printf '%s\n' "PHP $MINIMUM_PHP_VERSION is consistent across Composer and documentation; images track $IMAGE_PHP_SERIES."
