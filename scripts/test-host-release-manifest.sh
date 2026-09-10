#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixture="$(mktemp -d "${TMPDIR:-/tmp}/wayfindr-host-manifest.XXXXXX")"

cleanup() {
    rm -rf -- "$fixture"
}
trap cleanup EXIT

fail() {
    printf '%s\n' "Host release manifest test failed: $1" >&2
    exit 1
}

mkdir -p \
    "$fixture/deploy" \
    "$fixture/scripts/release" \
    "$fixture/apps/server/app/Support/Release" \
    "$fixture/apps/server/app/Support/Version"

cp "$ROOT_DIR/deploy/write-release-manifest.sh" "$fixture/deploy/"
cp "$ROOT_DIR/scripts/release/build-manifest.php" "$fixture/scripts/release/"
cp "$ROOT_DIR/apps/server/app/Support/Release/ReleaseManifest.php" \
    "$fixture/apps/server/app/Support/Release/"
cp "$ROOT_DIR/apps/server/app/Support/Version/SemanticVersion.php" \
    "$ROOT_DIR/apps/server/app/Support/Version/VersionComparator.php" \
    "$fixture/apps/server/app/Support/Version/"

printf '%s\n' '0.8.0' > "$fixture/VERSION"
printf '%s\n' '{"actions":[]}' > "$fixture/release.json"
printf '%s\n' '/release-manifest.json' > "$fixture/.gitignore"

git -C "$fixture" init -q
git -C "$fixture" config user.name 'Wayfindr test'
git -C "$fixture" config user.email 'test@wayfindr.invalid'
git -C "$fixture" add .
git -C "$fixture" commit -qm 'Fixture release'
expected_commit="$(git -C "$fixture" rev-parse HEAD)"

(
    cd "$fixture"
    WAYFINDR_PHP="$(command -v php)" bash deploy/write-release-manifest.sh
)

php -r '
    $manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    exit(($manifest["commit"] ?? null) === $argv[2] ? 0 : 1);
' "$fixture/release-manifest.json" "$expected_commit" \
    || fail 'a clean checkout did not record its exact commit.'

# Same HEAD, different declaration: the manifest must not claim that old commit
# or UpgradeGuard could inherit clean state across newly introduced work.
printf '%s\n' \
    '{' \
    '  "actions": [' \
    '    {' \
    '      "id": "dirty-action",' \
    '      "summary": "Do the newly authored work.",' \
    '      "detail": "This action is deliberately uncommitted.",' \
    '      "phase": "after-start",' \
    '      "depends_on_release": "none",' \
    '      "applicability": {"type": "always"},' \
    '      "verification": {"type": "attest"}' \
    '    }' \
    '  ]' \
    '}' > "$fixture/release.json"

(
    cd "$fixture"
    WAYFINDR_PHP="$(command -v php)" bash deploy/write-release-manifest.sh
) 2>/dev/null

php -r '
    $manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    $valid = ($manifest["commit"] ?? null) === ""
        && ($manifest["actions"][0]["id"] ?? null) === "dirty-action";
    exit($valid ? 0 : 1);
' "$fixture/release-manifest.json" \
    || fail 'a dirty same-HEAD declaration retained the old commit identity.'

mutation_sentinel="$fixture/migration-would-have-run"
set +e
(
    set -euo pipefail
    cd "$fixture"
    WAYFINDR_PHP="$(command -v php)" WAYFINDR_REQUIRE_CLEAN=1 \
        bash deploy/write-release-manifest.sh
    touch "$mutation_sentinel"
) >/dev/null 2>&1
refusal_status=$?
set -e

if [ "$refusal_status" -eq 0 ]; then
    fail 'required-clean mode accepted a dirty checkout.'
fi

if [ -e "$mutation_sentinel" ]; then
    fail 'deploy shell continued to a mutation after the clean-manifest gate failed.'
fi

printf '%s\n' 'Host release manifest clean/dirty identity and fail-fast checks passed.'
