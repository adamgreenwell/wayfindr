#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WIKI_DIR="$ROOT_DIR/docs/wiki"
HOME_PAGE="$WIKI_DIR/Home.md"
INSTALL_GUIDE="$ROOT_DIR/docs/self-hosting/install.md"

required_pages=(
    Home
    Quick-Start
    Installation
    Upgrading
    Runtime-and-Operations
    Backup-Restore-and-Rollback
    Security-and-Privacy
    Operator-Runbook
    Troubleshooting
    Releases
    Contributing
)

fail() {
    printf '%s\n' "Wiki documentation test failed: $1" >&2
    exit 1
}

[ -f "$HOME_PAGE" ] || fail "Home.md is missing."
[ -f "$WIKI_DIR/_Sidebar.md" ] || fail "_Sidebar.md is missing."

for slug in "${required_pages[@]}"; do
    page="$WIKI_DIR/$slug.md"
    [ -f "$page" ] || fail "$slug.md is missing."

    if [ "$slug" != "Home" ]; then
        grep -F "]($slug)" "$HOME_PAGE" >/dev/null \
            || fail "Home.md does not link to $slug."
        grep -F "]($slug)" "$WIKI_DIR/_Sidebar.md" >/dev/null \
            || fail "_Sidebar.md does not link to $slug."
        grep -F '](Home)' "$page" >/dev/null \
            || fail "$slug.md does not link back to Home."
    fi

    grep -F 'https://github.com/adamgreenwell/wayfindr/' "$page" >/dev/null \
        || fail "$slug.md does not link to an authoritative repository resource."
done

while IFS= read -r page; do
    slug="$(basename "$page" .md)"
    case "$slug" in
        _*) continue ;;
    esac

    grep -F "]($slug)" "$HOME_PAGE" >/dev/null \
        || [ "$slug" = "Home" ] \
        || fail "$slug.md is orphaned from Home.md."
done < <(find "$WIKI_DIR" -maxdepth 1 -type f -name '*.md' | sort)

while IFS= read -r link; do
    target="${link#](}"
    target="${target%)}"

    case "$target" in
        http://*|https://*|mailto:*|'#'*) continue ;;
    esac

    target="${target%%#*}"
    target="${target%.md}"
    [ -f "$WIKI_DIR/$target.md" ] \
        || fail "internal target '$target' does not have a Wiki page."
done < <(grep -rhoE '\]\([^)]*\)' "$WIKI_DIR" --include='*.md' | sort -u)

while IFS= read -r url; do
    repo_path="${url#*wayfindr/blob/main/}"
    repo_path="${repo_path#*wayfindr/tree/main/}"
    repo_path="${repo_path%%#*}"
    [ -e "$ROOT_DIR/$repo_path" ] \
        || fail "repository link points at missing path '$repo_path'."
done < <(
    grep -rhoE 'https://github\.com/adamgreenwell/wayfindr/(blob|tree)/main/[^) ]+' \
        "$WIKI_DIR" --include='*.md' | sort -u
)

# A synthetic cold-reader rehearsal for #797 found three documentation exits
# that are easy to reintroduce while every link still resolves: no prerequisite
# handoff, ambiguous bootstrap/ref identity, and no external-widget failure path.
grep -F 'https://docs.docker.com/engine/install/' "$WIKI_DIR/Quick-Start.md" >/dev/null \
    || fail 'Quick Start does not link the Docker Engine prerequisite.'
grep -F 'https://docs.docker.com/compose/install/linux/' "$WIKI_DIR/Quick-Start.md" >/dev/null \
    || fail 'Quick Start does not link the Compose plugin prerequisite.'
grep -F 'bootstrap installer' "$WIKI_DIR/Quick-Start.md" >/dev/null \
    || fail 'Quick Start does not distinguish the bootstrap installer from the pinned release.'
grep -F -A 1 '/wayfindr/vX.Y.Z/scripts/self-host/install.sh' "$WIKI_DIR/Quick-Start.md" \
    | grep -F -- '--ref vX.Y.Z' >/dev/null \
    || fail 'Quick Start does not use the same release tag for the installer and selected artifact.'
grep -F '## Widget Does Not Appear' "$WIKI_DIR/Troubleshooting.md" >/dev/null \
    || fail 'Troubleshooting does not cover an absent external widget.'
grep -F '/api/widget/appearance?site_public_key=...' "$WIKI_DIR/Troubleshooting.md" >/dev/null \
    || fail 'Widget troubleshooting does not name the first configuration request.'
grep -F 'docs/self-hosting/install.md#widget-does-not-appear' "$WIKI_DIR/Troubleshooting.md" >/dev/null \
    || fail 'Widget troubleshooting does not return to the authoritative install guide.'
grep -F '## Widget does not appear' "$INSTALL_GUIDE" >/dev/null \
    || fail 'The authoritative install guide has lost the widget troubleshooting target.'
grep -F -A 1 '/wayfindr/vX.Y.Z/scripts/self-host/install.sh' "$INSTALL_GUIDE" \
    | grep -F -- '--ref vX.Y.Z' >/dev/null \
    || fail 'The authoritative install guide does not use one release tag for both inputs.'

printf '%s\n' "Wiki documentation is navigable and links back to repository authority."
