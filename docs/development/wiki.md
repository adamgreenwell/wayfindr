# GitHub Wiki Workflow

The Markdown pages in `docs/wiki/` are the reviewable source for Wayfindr's
GitHub Wiki. The Wiki is a curated front door for evaluators and self-hosters;
detailed technical and operating contracts stay in the main repository docs.

## Update and Review

1. Edit `docs/wiki/*.md` in a normal feature branch.
2. Keep every page reachable from `docs/wiki/Home.md` and `_Sidebar.md`.
3. Link detailed contracts to their authoritative `main` repository URL rather
   than copying them into the Wiki.
4. Run `make wiki-test` and include the Wiki changes in a pull request.
5. Merge the reviewed source to `main` before publishing it.

The GitHub Wiki web editor is useful for an initial bootstrap page, but it is
not the durable authoring path. A later source sync intentionally replaces
Wiki-only edits.

## Preview a Sync

From a clean checkout:

```bash
scripts/sync-github-wiki.sh --dry-run
```

This clones or reuses a sibling `wayfindr.wiki` checkout, fast-forwards its Wiki
branch, and prints the file changes without modifying or publishing them. The
script defaults to this preview mode when no action flag is supplied.

## Publish Reviewed Source

After the pull request has merged:

```bash
git switch main
git pull --ff-only
scripts/sync-github-wiki.sh --push --message "Publish reviewed Wiki updates"
```

`--push` only works when the source checkout is clean, on `main`, and exactly
matches `origin/main`. It copies `docs/wiki/`, commits the Wiki checkout only
when content changed, and pushes its current branch. Use `--apply` to create the
Wiki commit locally without pushing it.

The first source sync may replace GitHub's temporary `Home.md`. Review the dry
run first. If a published Wiki change needs to be backed out, revert it in the
main repository, merge that review, and sync again; the Wiki repository's own
Git history remains an additional recovery path.
