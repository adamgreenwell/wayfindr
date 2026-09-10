#!/usr/bin/env bash
#
# Generate this checkout's release manifest before a host deployment migrates.
#
# Run from anywhere inside the checkout. The committed release history supplies
# skipped-release declarations; this generated root file supplies the target
# release declaration that UpgradeGuard reads on hosts without /etc/wayfindr.
set -euo pipefail

root=$(git rev-parse --show-toplevel)
php_binary=${WAYFINDR_PHP:-php}
version=$(tr -d '[:space:]' < "$root/VERSION")

# A commit identifies the declaration only while the checkout actually matches
# it. Forge replaces apps/server/storage with a shared symlink, so exclude that
# one platform-owned path exactly as its identity writer does; every other
# tracked, staged, or untracked change makes the manifest commit unknown. An
# empty commit deliberately makes UpgradeGuard treat the target as a changed
# build instead of inheriting a same-HEAD clean marker over edited actions.
shared_paths=':!apps/server/storage'
if git -C "$root" diff --quiet -- . "$shared_paths" \
   && git -C "$root" diff --cached --quiet -- . "$shared_paths" \
   && [ -z "$(git -C "$root" ls-files --others --exclude-standard -- . "$shared_paths")" ]; then
    commit=$(git -C "$root" rev-parse HEAD)
else
    commit=
    printf '%s\n' 'WARNING: working tree is not clean; release manifest commit is unknown.' >&2

    if [ "${WAYFINDR_REQUIRE_CLEAN:-0}" = "1" ]; then
        printf '%s\n' 'Refusing to generate a deploy identity for a dirty checkout.' >&2
        exit 1
    fi
fi

"$php_binary" "$root/scripts/release/build-manifest.php" \
    --version="$version" \
    --commit="$commit" \
    --out="$root/release-manifest.json"

[ -s "$root/release-manifest.json" ] || {
    printf '%s\n' 'Wayfindr release manifest was not written; refusing before migration.' >&2
    exit 1
}
