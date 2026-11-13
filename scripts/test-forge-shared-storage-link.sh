#!/usr/bin/env bash
#
# An in-place `git pull` must not leave a shared `apps/server/storage` symlink
# replaced by a directory of placeholders (#1202).
#
# Runs the REAL restore function, lifted from standard-deploy.sh between its
# markers, against real git repositories: an upstream that changes a tracked
# storage placeholder, and a deploy checkout whose storage is a shared link.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY_SCRIPT="$ROOT_DIR/deploy/forge/standard-deploy.sh"
fixture="$(mktemp -d "${TMPDIR:-/tmp}/wayfindr-shared-storage.XXXXXX")"
trap 'rm -rf -- "$fixture"' EXIT

pass=0
fail=0
check() {
    local label="$1" expected="$2" actual="$3"
    if [[ "$expected" == "$actual" ]]; then
        printf '  ok   %s\n' "$label"; pass=$((pass + 1))
    else
        printf '  FAIL %s\n       expected: %s\n       actual:   %s\n' "$label" "$expected" "$actual"
        fail=$((fail + 1))
    fi
}

fragment="$fixture/restore.sh"
awk '/^# BEGIN WAYFINDR SHARED STORAGE LINK$/,/^# END WAYFINDR SHARED STORAGE LINK$/' \
    "$DEPLOY_SCRIPT" > "$fragment"
grep -q '^restore_shared_storage_link()' "$fragment" \
    || { echo "Could not lift restore_shared_storage_link from standard-deploy.sh (markers moved?)" >&2; exit 1; }
# shellcheck source=/dev/null
. "$fragment"

git_quiet() { git -c user.name=t -c user.email=t@example.invalid -c init.defaultBranch=main "$@" >/dev/null 2>&1; }

# A fresh upstream, a deploy checkout of it with storage shared through a link,
# and an upstream commit that changes a tracked placeholder under storage.
setup() {
    local name="$1"
    local base="$fixture/$name"
    mkdir -p "$base/upstream/apps/server/storage/framework" "$base/upstream/apps/server/storage/logs"
    printf 'config.php\n' > "$base/upstream/apps/server/storage/framework/.gitignore"
    printf '*\n!.gitignore\n' > "$base/upstream/apps/server/storage/logs/.gitignore"
    printf 'app\n' > "$base/upstream/README"
    git_quiet -C "$base/upstream" init
    git_quiet -C "$base/upstream" add -A
    git_quiet -C "$base/upstream" commit -m initial

    git_quiet clone "$base/upstream" "$base/site"

    mkdir -p "$base/shared/app"
    printf '{"version":"1.2.0"}\n' > "$base/shared/app/release-state.json"
    rm -rf "$base/site/apps/server/storage"
    ln -s "$base/shared" "$base/site/apps/server/storage"

    printf 'config.php\nmanaged-upgrade.json\n' > "$base/upstream/apps/server/storage/framework/.gitignore"
    git_quiet -C "$base/upstream" commit -am "change a storage placeholder"

    printf '%s' "$base"
}

pull() { git_quiet -C "$1/site" pull origin main; }

echo "control: the pull alone breaks the link"
base="$(setup control)"
pull "$base"
check "pull replaced the link with a directory" "directory" \
    "$([[ -L "$base/site/apps/server/storage" ]] && echo link || echo directory)"

echo "restore after the pull"
base="$(setup restore)"
target="$(readlink "$base/site/apps/server/storage")"
pull "$base"
( cd "$base/site" && restore_shared_storage_link apps/server/storage "$target" >/dev/null )
check "storage is a link again" "link" \
    "$([[ -L "$base/site/apps/server/storage" ]] && echo link || echo directory)"
check "link points at the shared target" "$target" "$(readlink "$base/site/apps/server/storage")"
check "release state is reachable through it" '{"version":"1.2.0"}' \
    "$(cat "$base/site/apps/server/storage/app/release-state.json" 2>/dev/null || echo missing)"
check "the pull itself landed" "managed-upgrade.json" \
    "$(git -C "$base/site" show HEAD:apps/server/storage/framework/.gitignore | tail -1)"

echo "an untracked file in the replacement refuses and is kept"
base="$(setup refuse)"
target="$(readlink "$base/site/apps/server/storage")"
pull "$base"
printf 'keep me\n' > "$base/site/apps/server/storage/framework/stray.log"
status=0
( cd "$base/site" && restore_shared_storage_link apps/server/storage "$target" ) >/dev/null 2>&1 || status=$?
check "restore refuses" "1" "$status"
check "the untracked file survives" "keep me" \
    "$(cat "$base/site/apps/server/storage/framework/stray.log" 2>/dev/null || echo missing)"

echo "no shared link: nothing to restore"
base="$(setup plain)"
rm "$base/site/apps/server/storage"
git -C "$base/site" checkout -q -- apps/server/storage
pull "$base"
( cd "$base/site" && restore_shared_storage_link apps/server/storage "" )
check "a real storage directory is left alone" "directory" \
    "$([[ -L "$base/site/apps/server/storage" ]] && echo link || echo directory)"

echo "an untouched link is left alone"
base="$(setup untouched)"
target="$(readlink "$base/site/apps/server/storage")"
( cd "$base/site" && restore_shared_storage_link apps/server/storage "$target" )
check "link is unchanged" "$target" "$(readlink "$base/site/apps/server/storage")"

printf '\n%d passed, %d failed\n' "$pass" "$fail"
[[ "$fail" -eq 0 ]]
