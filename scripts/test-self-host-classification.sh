#!/usr/bin/env bash
#
# Differential test: the installer preflight's action classification against the
# artifact's.
#
# WHY THIS EXISTS
#
# ADR 0013 records that `upgrade_preflight` in scripts/self-host/install.sh is a
# second implementation of the guard's decision, in another language, running a
# version behind — and that a divergence is silent by construction: the preflight
# says "clear", the operator pulls, and the artifact refuses on a release that is
# already installed. Roughly a third of #648's review findings were the two
# disagreeing.
#
# The obvious fix — have the installer call the artifact's helper — is blocked.
# The preflight runs inside the image being upgraded FROM, so a method added by
# the release being installed does not exist there. Deleting one implementation
# is therefore not available; proving they agree is.
#
# WHAT IT DOES
#
# Lifts the installer's classification block out of install.sh verbatim, runs it
# against the same fixtures as `UpgradeRequirements::disposition()`, and fails if
# the two ever answer differently. install.sh gains nothing for this — no probe,
# no hook, no test-only branch — which is the point: the file operators curl into
# bash stays exactly as lean as it was.
#
# The three states are spelled STEP/NOW/DO in bash and named in
# App\Support\Release\ActionDisposition, whose enum VALUES are those codes so the
# comparison is direct rather than a translation anyone has to maintain.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALLER="$ROOT_DIR/scripts/self-host/install.sh"
SERVER_APP="$ROOT_DIR/apps/server/app"
PHP="${WAYFINDR_PHP:-php}"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

INSTALLER_PHP="$TMP_DIR/installer-classify.php"

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

# --- Lift the installer's classification block -------------------------------
#
# Bounded by markers rather than line numbers so that editing install.sh above or
# below the block does not silently shift what is being tested. If either marker
# stops matching, the extraction produces nothing and the guard below fails loudly
# rather than passing an empty program.
#
# The opening tag is added because install.sh feeds this to `php -r`, which does
# not want one; running it as a file does. Nothing else about the block is
# altered.
{
    printf '<?php\n'
    awk '
        /php_in_current_image .$/ && /manifest/ { capture = 1; next }
        capture && /^[[:space:]]*}. "WF_FROM=/ { print "}"; capture = 0; next }
        capture { print }
    ' "$INSTALLER"
} > "$INSTALLER_PHP"

grep -q 'FLOORUNKNOWN' "$INSTALLER_PHP" \
    || fail "could not lift the classification block from install.sh (markers moved?)"
grep -q 'STEP' "$INSTALLER_PHP" \
    || fail "lifted block does not classify — extraction is wrong"

# The block requires the artifact's classes from the container's /app. Point them
# at this checkout; the paths are the only thing about it that is container-bound.
#
# Verified rather than assumed: a silent no-op here would leave the requires
# pointing at /app, every run would fatal, and a test that never executes its
# subject reports success just as loudly as one that does.
before="$(grep -c '"/app/apps/server/' "$INSTALLER_PHP" || true)"
[ "$before" -gt 0 ] || fail "expected /app requires in the lifted block, found none"

sed -i.bak "s#\"/app/apps/server/#\"$ROOT_DIR/apps/server/#g" "$INSTALLER_PHP"
rm -f "$INSTALLER_PHP.bak"

grep -q '"/app/apps/server/' "$INSTALLER_PHP" \
    && fail "require rewrite did not take"

# --- The artifact's answer ---------------------------------------------------

cat > "$TMP_DIR/artifact-classify.php" <<PHP
<?php
require "$SERVER_APP/Support/Version/SemanticVersion.php";
require "$SERVER_APP/Support/Version/VersionComparator.php";
require "$SERVER_APP/Support/Release/ActionDisposition.php";
require "$SERVER_APP/Support/Release/ReleaseManifest.php";
require "$SERVER_APP/Support/Release/UpgradeRequirements.php";

\$action = json_decode((string) getenv("WF_ACTION"), true);
\$target = getenv("WF_TO") ?: null;
\$recorded = getenv("WF_RECORDED") ?: null;
\$profile = getenv("WF_PROFILE") ?: null;

if (! App\Support\Release\UpgradeRequirements::appliesToInstallationProfile(\$action, \$profile)) {
    echo "SKIP\n";
    exit(0);
}

echo App\Support\Release\UpgradeRequirements::disposition(\$action, \$target, \$recorded)->value, "\n";
PHP

# --- Fixtures ----------------------------------------------------------------
#
# Each row: description | action release | depends_on_release | recorded | target | expected
#
# `expected` is asserted as well as the two agreeing, so that a change making
# BOTH implementations wrong in the same direction is still caught. Two
# implementations agreeing on the wrong answer is exactly the failure a pure
# differential test cannot see.
FIXTURES="
the target's own work is ordinary|0.3.0|code|0.2.0|0.3.0|DO
work needing no release code is ordinary however far it came|0.2.0|none|0.1.0|0.3.0|DO
work for the running release is possible until the pull|0.2.0|code|0.2.0|0.3.0|NOW
work for a skipped release is unreachable|0.2.0|code|0.1.0|0.3.0|STEP
an unrecorded origin cannot claim to have run it|0.2.0|code||0.3.0|STEP
being newer is not evidence of having run it|0.2.0|code|0.4.0|0.5.0|STEP
a development origin does not order|0.2.0|code|0.2.0-dev+abc|0.3.0|STEP
schema dependency strands exactly as code does|0.2.0|schema|0.1.0|0.3.0|STEP
schema dependency on the running release is still reachable|0.2.0|schema|0.2.0|0.3.0|NOW
"

checked=0

printf '\n  Installer preflight vs artifact guard — action classification\n\n'

while IFS='|' read -r description release depends recorded target expected; do
    [ -n "${description:-}" ] || continue

    action_json="$(printf '{"id":"thing","summary":"s","detail":"d","phase":"after-start","depends_on_release":"%s","applicability":{"type":"always"},"verification":{"type":"attest"},"release":"%s"}' \
        "$depends" "$release")"

    manifest="$(printf '{"schema":1,"version":"%s","commit":"abc","requires_operator_action":true,"minimum_upgrade_from":null,"actions":[%s]}' \
        "$release" "$action_json")"

    # The installer reads its manifest from stdin and everything else from the
    # environment, exactly as php_in_current_image() passes it.
    installer_out="$(printf '%s' "$manifest" | WF_FROM="$recorded" WF_TO="$target" WF_ACK="" \
        WF_ORIGIN_KNOWN=1 WF_RECORDED="$recorded" WF_TAG="v$release" \
        "$PHP" "$INSTALLER_PHP" 2>&1)" || fail "installer block errored: $installer_out"

    installer_code="$(printf '%s\n' "$installer_out" | grep -E '^(STEP|NOW|DO)\|' | head -1 | cut -d'|' -f1 || true)"

    [ -n "$installer_code" ] \
        || fail "$description: installer emitted no classification (output: $installer_out)"

    artifact_code="$(WF_ACTION="$action_json" WF_TO="$target" WF_RECORDED="$recorded" WF_PROFILE="image" \
        "$PHP" "$TMP_DIR/artifact-classify.php" 2>&1)" || fail "artifact errored: $artifact_code"

    if [ "$installer_code" != "$artifact_code" ]; then
        fail "$description: installer says $installer_code, artifact says $artifact_code"
    fi

    if [ "$installer_code" != "$expected" ]; then
        fail "$description: both say $installer_code, expected $expected"
    fi

    printf '    ok  %-58s %s\n' "$description" "$installer_code"
    checked=$((checked + 1))
done <<< "$FIXTURES"

# A loop that silently matched nothing would print no failures and exit 0.
[ "$checked" -eq 9 ] || fail "expected 9 fixtures, ran $checked"

printf '\n  %d classifications agree.\n\n' "$checked"

# A host-only action is still part of the release's global action verdict, but
# the image installer must not stop on host work the target image already bakes.
scoped_action_json='{"id":"host-php","summary":"s","detail":"d","phase":"before-pull","depends_on_release":"none","applicability":{"type":"always"},"verification":{"type":"attest"},"installation_profiles":["host"],"release":"0.3.0"}'
scoped_manifest="$(printf '{"schema":1,"version":"0.3.0","commit":"abc","requires_operator_action":true,"minimum_upgrade_from":null,"actions":[%s]}' "$scoped_action_json")"

installer_out="$(printf '%s' "$scoped_manifest" | WF_FROM="0.2.0" WF_TO="0.3.0" WF_ACK="" \
    WF_ORIGIN_KNOWN=1 WF_RECORDED="0.2.0" WF_TAG="v0.3.0" \
    "$PHP" "$INSTALLER_PHP" 2>&1)" || fail "installer block errored on host-only action: $installer_out"
installer_code="$(printf '%s\n' "$installer_out" | grep -E '^(STEP|NOW|DO)\|' | head -1 | cut -d'|' -f1 || true)"
artifact_code="$(WF_ACTION="$scoped_action_json" WF_TO="0.3.0" WF_RECORDED="0.2.0" WF_PROFILE="image" \
    "$PHP" "$TMP_DIR/artifact-classify.php" 2>&1)" || fail "artifact errored on host-only action: $artifact_code"

[ -z "$installer_code" ] || fail "host-only action: installer says $installer_code, expected SKIP"
[ "$artifact_code" = "SKIP" ] || fail "host-only action: artifact says $artifact_code, expected SKIP"

printf '    ok  %-58s %s\n\n' 'host-only work does not block a Wayfindr image' 'SKIP'

for malformed_profiles in '["bogus"]' '["host","host"]'; do
    malformed_action_json="$(printf '{"id":"host-php","summary":"s","detail":"d","phase":"before-pull","depends_on_release":"none","applicability":{"type":"always"},"verification":{"type":"attest"},"installation_profiles":%s,"release":"0.3.0"}' "$malformed_profiles")"
    malformed_manifest="$(printf '{"schema":1,"version":"0.3.0","commit":"abc","requires_operator_action":true,"minimum_upgrade_from":null,"actions":[%s]}' "$malformed_action_json")"

    installer_out="$(printf '%s' "$malformed_manifest" | WF_FROM="0.2.0" WF_TO="0.3.0" WF_ACK="" \
        WF_ORIGIN_KNOWN=1 WF_RECORDED="0.2.0" WF_TAG="v0.3.0" \
        "$PHP" "$INSTALLER_PHP" 2>&1)" || fail "installer block errored on malformed scope: $installer_out"
    installer_code="$(printf '%s\n' "$installer_out" | grep -E '^(INVALID$|STEP\||NOW\||DO\|)' | head -1 | cut -d'|' -f1 || true)"

    [ "$installer_code" = "INVALID" ] \
        || fail "malformed profile scope $malformed_profiles: installer says ${installer_code:-SKIP}, expected INVALID"
done

printf '    ok  %-58s %s\n\n' 'malformed profile scopes fail closed in the installer' 'INVALID'

# --- The installer's release-state profile binding --------------------------
#
# A satisfied-through marker is only proof for the packaging profile that
# assessed it. Lift the real function so a state written by host PHP cannot
# narrow an image upgrade past future image-only work (and old state with no
# profile fails in the same conservative direction).
STATE_FUNCTIONS="$TMP_DIR/installer-release-state.sh"
awk '
    /^release_state\(\) \{$/ { capture = 1 }
    capture { print }
    capture && /^}$/ { exit }
' "$INSTALLER" > "$STATE_FUNCTIONS"

grep -q '^release_state() {' "$STATE_FUNCTIONS" \
    || fail "could not lift release_state() from install.sh"
grep -q 'installation_profile' "$STATE_FUNCTIONS" \
    || fail "the installer release state is not bound to an installation profile"

# shellcheck source=/dev/null
. "$STATE_FUNCTIONS"

php_in_current_image() {
    local code="$1"
    shift
    code="$(printf '%s' "$code" | sed "s#/app/apps/server#$ROOT_DIR/apps/server#g")"
    env "$@" "$PHP" -r "$code"
}

state_path="$TMP_DIR/release-state.json"
export WAYFINDR_RELEASE_STATE_PATH="$state_path"

assert_release_state() {
    local description="$1" json="$2" expected="$3" actual
    printf '%s\n' "$json" > "$state_path"
    actual="$(release_state)"
    [ "$actual" = "$expected" ] \
        || fail "$description: release_state returned '$actual', expected '$expected'"
    printf '    ok  %s\n' "$description"
}

printf '  Installer release-state profile binding\n\n'
assert_release_state \
    'an image marker narrows a later image upgrade' \
    '{"version":"0.9.0","satisfied_through":"0.9.0","installation_profile":"image"}' \
    '0.9.0|0.9.0|1'
assert_release_state \
    'a host marker reopens the image history' \
    '{"version":"0.9.0","satisfied_through":"0.9.0","installation_profile":"host"}' \
    '0.9.0||0'
assert_release_state \
    'a pre-profile marker reopens the image history' \
    '{"version":"0.9.0","satisfied_through":"0.9.0"}' \
    '0.9.0||0'

unset WAYFINDR_RELEASE_STATE_PATH

grep -F 'if [ "$from" != "$to" ] || [ -z "$span_origin" ]; then' "$INSTALLER" >/dev/null \
    || fail "an unknown profile span can omit a same-version target manifest"

printf '\n  profile transitions cannot inherit another packaging path\x27s clean marker.\n'
