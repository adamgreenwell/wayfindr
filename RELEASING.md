# Releasing Wayfindr

Wayfindr is self-hosted: a release is something other people have to *operate*.
The version number, changelog entry, and `release.json` declaration are the
signals an operator and the upgrade guard get about whether an upgrade is safe
to take unattended, so all three are decided deliberately, not derived from the
diff. The contract is ADR 0012
(`docs/decisions/0012-platform-versioning.md`).

## 1. Decide the number

Ask the question that defines a major release:

> If an operator pulls this and restarts, and does nothing else, is anything
> worse than before?

- **Yes** → the release **requires operator action**. Pre-1.0 that means bumping
  the **minor** (`0.1.0 → 0.2.0`), since the major slot is reserved until 1.0;
  after 1.0 it means bumping the **major**.
- **No, and it adds features or schema** → minor.
- **No, fixes only** → patch.

Things that count as operator action: a new process or service to run (the
`backups` queue worker was one), a config key that must be set, a manual data
migration, a dropped dependency version, or a breaking widget/public-API change.
Additive schema that migrates itself does not.

The guarded publisher currently ships stable `x.y.z` releases only. A
dash-suffixed tag is a valid SemVer identity, but it is not a supported public
release path: the release declaration/history contract does not yet define how
an operator action moves from a prerelease to the eventual stable release
without disappearing or being demanded twice. `VERSION` and the release tag
must therefore both be the same plain stable version. Historical alpha releases
predate this guard and do not establish a current prerelease procedure.

An action may be limited to an installation profile when the packaging itself
proves the other path already satisfies it. Use `image` for a container built
from Wayfindr's Dockerfile and `host` for Forge or another host-managed PHP
deployment. This is about who owns the runtime, not where the source came from:
a locally built Wayfindr image is still `image`. Omitting
`installation_profiles` applies the action everywhere. A scoped action still
makes the release require operator action globally and therefore still drives
the version decision above. Release state binds its clean marker to the profile
that assessed it; moving an install between host PHP and an image reopens scoped
history rather than inheriting the other path's exemptions.

## 2. Write the changelog entry

Move `## [Unreleased]` content in [CHANGELOG.md](CHANGELOG.md) into a new
`## [x.y.z] - YYYY-MM-DD` section. Open it with either **Requires operator
action** (followed by exactly what to do) or **No operator action required**, and
mark the individual entries that need hands with **⚠ Operator action**.

**If that section already exists, set its date to the day you are tagging.**
Notes are often moved out of Unreleased days or weeks before the cut — the
section is frozen from that moment, and its date silently ages with it. That
date is the release date every reader sees. The publishing preflight in step 3
refuses a section dated more than a day before the commit it would tag — a day
of slack absorbs timezone skew between whoever wrote the heading and the
committer, so it is a backstop against a stale date, not against an
off-by-one one. Setting it here is cheaper than discovering it there, and it is
the only place that gets the date exactly right.

Write it for someone several releases behind who has never read the PR.

Every human action in the changelog needs a matching action in `release.json`.
For a profile-scoped action, keep the global **Requires operator action** verdict
and name both the affected profile and the exempt profile in its first paragraph.
Then verify that the version and both declarations agree:

```bash
make release-contract-test
```

## 3. Land the release commit, then tag it

`VERSION` holds the version under development, and it is what source builds
report as `<VERSION>-dev` — the derivation lives in `server.Dockerfile` for image
builds and in `ReleaseIdentity` for host builds. **It must match the tag you are
about to push.** If they drift, every source build between this release and the
next claims the wrong lineage — a build after `v0.2.0` would still report
`0.1.0-dev`.

### Retire pre-guard workflow runs before the first guarded release

GitHub permits a workflow run to be rerun for 30 days after its initial run,
using the workflow file and commit from that original run. That means an
eligible **pre-guard** `Release image` run can still execute its old publisher
and move `latest` backward even after the guarded workflow lands; repository
changes cannot revoke a stored run.

Before the first release using the guarded publisher, list the release runs and
inspect every run still inside that 30-day window:

```bash
gh run list \
  --workflow "Release image" \
  --limit 100 \
  --json databaseId,createdAt,headSha,displayTitle,status,conclusion,url
```

Preserve the run IDs, dates, SHAs, and URLs as release evidence. If any eligible
run predates the guarded publisher, **stop before tagging**. Either wait for its
rerun window to expire or, with explicit repository-owner approval, delete that
specific workflow run after preserving the evidence. Repeat the audit
immediately before pushing the first guarded tag. A passing new workflow cannot
neutralize an independently rerunnable old one.

### Protect release tags before publication

The workflow checks the remote tag before each visibility-changing hand-off and
again after publication, but it cannot stop a privileged actor from moving or
deleting that tag later. Before pushing any guarded release tag, the repository
must have an active ruleset covering `v*` tags that restricts creation and blocks
updates and deletions, with bypass access restricted to the repository owner.
Without the creation rule, a new tag on an older commit can still invoke that
commit's unguarded publisher. Inspect the live rulesets and preserve the response
with the release evidence:

```bash
gh api repos/adamgreenwell/wayfindr/rulesets

gh api repos/adamgreenwell/wayfindr/rulesets --jq '.[].id' |
  while read -r ruleset_id; do
    gh api "repos/adamgreenwell/wayfindr/rulesets/$ruleset_id"
  done
```

An empty response, an inactive rule, a pattern that misses the proposed tag, or
a broad bypass is a **stop-before-tagging** result. The September 9, 2026 audit
returned no repository rulesets, so this gate is currently unmet. Creating the
rule is a separate repository-settings change and requires explicit owner
authorization; merging release code does not silently authorize it.

```bash
# VERSION and the tag must agree; the tag carries the conventional "v".
# Stage both explicitly — `commit -a` would skip VERSION the first time,
# because a file git has never seen is untracked, not modified.
# Release from main. The commit goes up WITHOUT its tag first, because the full
# main-branch CI run for this exact SHA is the authorization gate for tagging.
[ "$(git rev-parse --abbrev-ref HEAD)" = "main" ] || { echo 'Release from main.'; exit 1; }

printf '0.2.0\n' > VERSION

# Append this release's declaration to the published record. The image bakes this
# file, and it is how a later release knows what an intermediate one required —
# a v1 -> v3 upgrade has to learn v2's requirements from somewhere (ADR 0013).
# No --commit: the release commit does not exist yet, and recording its parent
# would be wrong. The image build stamps the real identity into the copy it bakes;
# what this file records is the DECLARATION, which is what a later release needs.
# Field reference: docs/self-hosting/release-manifest.md
#
# NOT --reset-declaration here. The tagged tree is what the release workflow and
# the image build read, and both regenerate this release's manifest from
# release.json. Emptying it before the tag would publish an asset and bake a
# history entry declaring that no operator action is required - overwriting the
# action-bearing entry this command just recorded. The reset belongs after the
# tag, with the other next-cycle housekeeping below.
php scripts/release/build-manifest.php \
  --version=0.2.0 \
  --history=releases/history.json

git add VERSION CHANGELOG.md release.json releases/history.json
git commit -m "Release 0.2.0"

# AFTER the commit, and before the tag. The publishing contract compares the
# changelog's date against the commit being released, so running it beforehand
# reads the PARENT: on a main that has been quiet for a few days, a stale date
# sits close enough to that parent to pass, and the same check then rejects the
# release at tag time -- once the protected tag is already pushed. Here a
# failure costs `git commit --amend` and nothing else.
make release-publish-contract-test

release_sha="$(git rev-parse HEAD)"
git push origin main

# Find and watch the full main CI run for exactly that commit. GitHub can take a
# moment to index a new run, so this waits for at most one minute for it to show.
release_ci_run=""
for attempt in {1..12}; do
    release_ci_run="$(gh run list \
      --workflow "Pull request CI" \
      --commit "$release_sha" \
      --event push \
      --limit 1 \
      --json databaseId \
      --jq '.[0].databaseId // empty')"
    [ -n "$release_ci_run" ] && break
    sleep 5
done
[ -n "$release_ci_run" ] || { echo 'No exact-SHA main CI run appeared.'; exit 1; }
gh run watch "$release_ci_run" --exit-status

# Only now create and push the ONE tag by name. `--tags` pushes every local tag,
# and any v* tag triggers publication, so a stray experimental tag could ship.
git tag v0.2.0 "$release_sha"
git push origin v0.2.0

# Do not start next-cycle housekeeping yet. Wait for the release workflow to
# publish and verify the draft assets, exact image, GitHub Release, and aliases.
release_run=""
for attempt in {1..12}; do
    release_run="$(gh run list \
      --workflow "Release image" \
      --commit "$release_sha" \
      --event push \
      --limit 1 \
      --json databaseId \
      --jq '.[0].databaseId // empty')"
    [ -n "$release_run" ] && break
    sleep 5
done
[ -n "$release_run" ] || { echo 'No release workflow run appeared.'; exit 1; }
gh run watch "$release_run" --exit-status
#
# If either watch fails, stop here. Do not move VERSION, clear release.json, or
# pretend the release is complete while its public artifacts are partial.
```

### Then advance `VERSION` for the next cycle

Only after the release workflow succeeds, move `VERSION` on to the *next*
development version and commit that separately:

```bash
printf '0.3.0\n' > VERSION

# Clear the actions now that the release carrying them is published and its
# artifacts are verified. Without this the next release rebuilds the same
# actions under its own version, and an operator who already acknowledged
# 0.2.0/thing is asked again for 0.3.0/thing - work they have demonstrably done.
#
# NOTICES ARE DELIBERATELY NOT CLEARED. An action is one-time upgrade work, so
# repeating it is wrong. A notice is standing advice about how the release wants
# to be RUN - "have a backups worker" is as true next release as it is this one -
# so clearing it would make real advice vanish silently the moment a release
# shipped without re-adding it. Removing a notice is a deliberate edit.
#
# The cost is that a surviving notice is re-stamped with the new version, so its
# acknowledgement key changes (0.3.0/x becomes 0.4.0/x). That only reaches an
# operator who acknowledged INSTEAD of doing the work: a `check`-verified notice
# retires itself, so anyone who actually did it is never asked again. Being
# reminded once per release of a requirement you chose to skip is the intended
# outcome, not a bug to design around.
php scripts/release/build-manifest.php --version=0.3.0 --reset-declaration >/dev/null

git add VERSION release.json
git commit -m "Begin 0.3.0 development"
git push origin main
```

Leaving it at the version you just released would stamp every subsequent source
build as `0.2.0-dev` — code that is *newer* than `0.2.0`, wearing a prerelease
label that SemVer orders *below* it. A comparator would then read the newer
checkout as older and could give backwards restore guidance. (Precedence for
development builds is treated as indeterminate for exactly this reason — see
ADR 0012 — but there is no sense in publishing an identity that is wrong on its
face.)

Pushing a `v*` tag is what triggers
[release-image.yml](.github/workflows/release-image.yml) to build and publish the
multi-arch image to GHCR, baking the tag and commit in as the release identity.
Before authenticating to the registry or pushing anything, that workflow checks
that the tag equals `v$(cat VERSION)`, that the tagged commit belongs to `main`,
that the publishing-mode release contract passes, that full **Pull request CI**
succeeded for the exact release SHA, and that the manifest can be built. It then
stages a draft with the manifest, pushes only the exact version image, records
its digest, publishes and verifies the GitHub Release, and promotes the minor
and `latest` image aliases last. Publication is serialized across tags, and an
older tag rerun through **this guarded workflow** cannot move those aliases
backward. The release workflow is the only artifact publisher; main CI
authorizes it but publishes nothing.

## 4. Check what an upgrader actually sees

After the image publishes, confirm `/operator` on a fresh install reports the new
version, and that the changelog entry answers "does this need me?" without the
reader having to open a single PR.

For a profile-scoped action, inspect the generated manifest and exercise both
paths before tagging: it must remain globally action-bearing, the exempt profile
must filter the action, and the affected profile must report it when unmet. A
fresh install is exempt from upgrade actions, but must still satisfy its ordinary
runtime prerequisites.

## 5. Update the public site

[wayfindr.cc](https://github.com/adamgreenwell/wayfindr-site) restates this
release's facts in several places, and tagging makes them false at the same
moment. It is a separate repository, so nothing in this checklist fails when it
is skipped — the site just goes quietly stale, which is what happened between
0.6.0 and 0.7.0.

Four things move:

- a new entry at the top of the release timeline, linked to the tag;
- the status copy's release count, the version it says you can install today,
  and the ordinal it gives the next release in preparation;
- every feature chip this release ships, from **In development** to
  **Shipped**;
- the roadmap section, wherever this release closes something it describes as
  still coming.

The site's own suite (`npm test`) asserts those claims agree with each other, so
a half-finished update fails there rather than reaching wayfindr.cc. It cannot
tell that the site is *behind* — nothing offline can — so noticing that the
update is due is this step's job, not the test's.
