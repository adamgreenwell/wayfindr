#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WIKI_DIR="$ROOT_DIR/docs/wiki"
HOME_PAGE="$WIKI_DIR/Home.md"

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

printf '%s\n' "Wiki documentation is navigable and links back to repository authority."
