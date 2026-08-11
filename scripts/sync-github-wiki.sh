#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'USAGE'
Preview or publish the reviewed docs/wiki source to the GitHub Wiki.

Usage:
  scripts/sync-github-wiki.sh [options]

Actions (default: --dry-run):
      --dry-run            Show changes without modifying the Wiki checkout.
      --apply              Commit changes in the Wiki checkout without pushing.
      --push               Commit and push; requires clean main at origin/main.

Options:
  -m, --message <message>  Wiki commit message.
      --source <path>      Source directory. Defaults to docs/wiki.
      --wiki-dir <path>    Local Wiki checkout. Defaults to ../wayfindr.wiki.
      --remote <url>       Wiki Git remote URL.
  -h, --help               Show this help.
USAGE
}

die() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
source_dir="$repo_root/docs/wiki"
wiki_dir="$(dirname "$repo_root")/$(basename "$repo_root").wiki"
remote_url="https://github.com/adamgreenwell/wayfindr.wiki.git"
commit_message="Update the Wayfindr Wiki."
action="dry-run"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            action="dry-run"
            shift
            ;;
        --apply)
            action="apply"
            shift
            ;;
        --push)
            action="push"
            shift
            ;;
        -m|--message)
            [[ $# -ge 2 ]] || die "$1 requires a value."
            commit_message="$2"
            shift 2
            ;;
        --source)
            [[ $# -ge 2 ]] || die "$1 requires a value."
            source_dir="$2"
            shift 2
            ;;
        --wiki-dir)
            [[ $# -ge 2 ]] || die "$1 requires a value."
            wiki_dir="$2"
            shift 2
            ;;
        --remote)
            [[ $# -ge 2 ]] || die "$1 requires a value."
            remote_url="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            die "Unknown option: $1"
            ;;
    esac
done

require_command git
require_command rsync

[ -d "$source_dir" ] || die "Wiki source directory does not exist: $source_dir"
[ -f "$source_dir/Home.md" ] || die "Wiki source must include Home.md: $source_dir"

if [[ ! -d "$wiki_dir/.git" ]]; then
    if [[ -d "$wiki_dir" && -n "$(find "$wiki_dir" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
        die "Wiki directory exists but is not a Git checkout: $wiki_dir"
    fi

    mkdir -p "$(dirname "$wiki_dir")"
    printf 'Cloning Wiki checkout into %s\n' "$wiki_dir"
    git clone "$remote_url" "$wiki_dir" \
        || die "Could not clone the Wiki. Make sure it has an initial saved page."
fi

git -C "$wiki_dir" fetch origin

remote_head="$(git -C "$wiki_dir" symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null || true)"
if [[ "$remote_head" == origin/* ]]; then
    wiki_branch="${remote_head#origin/}"
elif git -C "$wiki_dir" show-ref --verify --quiet refs/remotes/origin/master; then
    wiki_branch="master"
else
    wiki_branch="main"
fi

if git -C "$wiki_dir" show-ref --verify --quiet "refs/heads/$wiki_branch"; then
    git -C "$wiki_dir" checkout "$wiki_branch" >/dev/null
else
    git -C "$wiki_dir" checkout -b "$wiki_branch" "origin/$wiki_branch" >/dev/null
fi

git -C "$wiki_dir" pull --ff-only origin "$wiki_branch"

[[ -z "$(git -C "$wiki_dir" status --porcelain)" ]] \
    || die "Wiki checkout has uncommitted changes: $wiki_dir"

if [[ "$action" == "dry-run" ]]; then
    printf 'Dry run: showing Wiki file changes only.\n'
    rsync -ani --delete --exclude='.git/' "$source_dir/" "$wiki_dir/"
    exit 0
fi

if [[ "$action" == "push" ]]; then
    [[ "$(git -C "$repo_root" branch --show-current)" == "main" ]] \
        || die "Publishing requires the source checkout to be on main."
    [[ -z "$(git -C "$repo_root" status --porcelain)" ]] \
        || die "Publishing requires a clean source checkout."

    git -C "$repo_root" fetch origin main
    [[ "$(git -C "$repo_root" rev-parse HEAD)" == "$(git -C "$repo_root" rev-parse origin/main)" ]] \
        || die "Publishing requires local main to match origin/main exactly."
fi

rsync -a --delete --exclude='.git/' "$source_dir/" "$wiki_dir/"

if [[ -z "$(git -C "$wiki_dir" status --porcelain)" ]]; then
    printf 'Wiki already up to date.\n'
    exit 0
fi

git -C "$wiki_dir" add -A

if ! git -C "$wiki_dir" config user.name >/dev/null; then
    git -C "$wiki_dir" config user.name "Wayfindr Wiki Sync"
fi

if ! git -C "$wiki_dir" config user.email >/dev/null; then
    git -C "$wiki_dir" config user.email "actions@users.noreply.github.com"
fi

git -C "$wiki_dir" commit -m "$commit_message"

if [[ "$action" == "push" ]]; then
    git -C "$wiki_dir" push origin "$wiki_branch"
else
    printf 'Wiki commit created locally; push skipped.\n'
fi
