# Working in this repository

Wayfindr: an open-source, self-hostable customer-support platform — live chat,
cobrowsing, ticketing. Laravel-first monorepo; almost everything lives in
`apps/server`.

**Read [`docs/development/handoff.md`](docs/development/handoff.md) before your
first change.** §5 is the working conventions, §7 is the accumulated gotchas, and
the dated snapshots at the end are the current situational state. This file is a
pointer, not a copy — when the two disagree, `handoff.md` is right and this file
is stale.

## Toolchain

**Server commands run from `apps/server`; widget commands from
`packages/widget-js`.** Every path below is relative to the directory its block
names. Getting this wrong is not loud — see the widget bullet.

**PHP must be 8.4 or newer.** The vendored dependencies fail Composer's platform
check before your code runs. Which binary that is depends on the machine, so
check rather than assume:

```bash
php -v                      # >= 8.4? use plain `php` for everything below
```

On Linux, in a container, and in CI the `php` on `PATH` is normally new enough.
On the maintainer's Mac it is 8.3, and the 8.5 Homebrew build
(`/opt/homebrew/opt/php/bin/php`) is the one to use — a host-specific fallback,
not a requirement of the repo.

```bash
# from apps/server, substituting whichever binary `php -v` proved is >= 8.4
php -d memory_limit=1G vendor/bin/pest
php vendor/bin/pint <files>
```

- `-d memory_limit=1G` is required for the full suite; without it you get
  "Allowed memory size exhausted" inside a compiled Blade view, which reads like
  a bug in the view.
- **A fresh `git worktree` has no `.env`**, and without it three
  `SiteConnectionStatusTest` tests fail for reasons that look like your change.
  From `apps/server`, `cp .env.example .env` immediately, as CI does. More
  generally: when a fresh checkout fails, run the same tests on unmodified
  `main` *in that checkout* before believing the failure is yours.
- **Widget tests run from `packages/widget-js`**, not `apps/server`:

  ```bash
  cd packages/widget-js && node --test
  ```

  Run from anywhere else, `node --test` discovers no test files, prints
  `tests 0`, and **exits 0** — a green run that silently skipped all 22 widget
  test files and 327 assertions. `npm test` in that package is the same command.
- For inline Blade `<script>` edits, extract the block and `node --check` it.
- **Run the whole suite, not the file you touched**, before pushing anything
  that touches a shared helper or a Blade view. A view-level parse error only
  surfaces when something renders that view, so `--filter` will not see it.

## Things that are not yours to do

- **Releases, tags, deploys and registry pushes need explicit authorization**
  each time. Merging a clean PR does not.
- **Never create or modify GitHub repository rulesets.** Owner action only.
- **Never sync the `northcoastmedia/wayfindr` fork.** Stage deploys from it and
  the owner controls when.
- **Never delete workflow runs.** Retiring pre-guard release runs is an owner
  decision with evidence preserved first (see #970).

## Commits and PRs

- Commit under the owner's credentials only. **No `Co-Authored-By` trailers and
  no "Generated with Claude Code" footers**, in commits or PR bodies.
- **Stage named paths — never `git add -A`.** It has committed the wrong
  contents more than once.
- Branch → PR → post `@codex review` **after every push** (auto-review is
  unreliable, and silence means nothing) → address, reply, resolve threads →
  merge when green. Codex delivers findings as *inline* comments; check the
  review's commit against the branch tip before trusting a clean verdict.
- Avoid `closes #NN` in PR text unless you mean it — it closes the issue on
  merge, and it has closed epics by accident.

## How work is verified here

- **A test that has never failed has not been tested.** After writing a test for
  a fix, reintroduce the bug and confirm the test fails *on the assertion that
  names it*. "It failed" is not enough — a parse error is not a caught mutation.
- **Mutate from a copy**, not `git checkout <file>` — that discards the
  uncommitted fix along with the mutation.
- **A sweep that returns zero is a claim about its pattern.** Before reporting
  something clean, check the pattern still catches a known instance — ideally
  one from a commit where the defect existed (`git show HEAD~1:path | <sweep>`).
  Searching for the *shape* you last saw a bug written in will miss the same bug
  written another way.

## Where the rest lives

- `docs/decisions/` — 24 ADRs. ADR 0004 (AI boundary), 0012/0013 (versioning and
  upgrade guards) and 0014 (design system) come up most often.
- **Shipped docs are under test.** Pest asserts the literal, case-sensitive
  content of 11 documents across `docs/privacy/`, `docs/product/` and
  `docs/self-hosting/` — `PlatformOperatorBoundaryTest` alone covers six. Grep
  the tests before editing any document under those three trees, and when a test
  and the product disagree, consider that the *test* may be the stale one.
- `docs/self-hosting/` is also **interface**: operators copy its shell snippets
  verbatim, so they need code-level review. Test the everyday case against a
  deployed environment, not the committed repo.
- `RELEASING.md` — the cut procedure, step by step. Read it rather than
  reconstructing it.
- Public docs describe the **released tag**, not `main`. Check `git tag` against
  `VERSION` before calling one stale.
