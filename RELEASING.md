# Releasing Wayfindr

Wayfindr is self-hosted: a release is something other people have to *operate*.
The version number and the changelog entry are the only signals an operator gets
about whether an upgrade is safe to take unattended, so both are decided
deliberately, not derived from the diff. The contract is ADR
0012 (`docs/decisions/0012-platform-versioning.md`).

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

## 2. Write the changelog entry

Move `## [Unreleased]` content in [CHANGELOG.md](CHANGELOG.md) into a new
`## [x.y.z] - YYYY-MM-DD` section. Open it with either **Requires operator
action** (followed by exactly what to do) or **No operator action required**, and
mark the individual entries that need hands with **⚠ Operator action**.

Write it for someone several releases behind who has never read the PR.

## 3. Update `VERSION`, then tag to match

`VERSION` holds the version under development, and it is what source builds
report as `<VERSION>-dev` — the derivation lives in `server.Dockerfile` for image
builds and in `ReleaseIdentity` for host builds. **It must match the tag you are
about to push.** If they drift, every source build between this release and the
next claims the wrong lineage — a build after `v0.2.0` would still report
`0.1.0-dev`.

```bash
# VERSION and the tag must agree; the tag carries the conventional "v".
# Stage both explicitly — `commit -a` would skip VERSION the first time,
# because a file git has never seen is untracked, not modified.
# Release from main. --atomic makes the branch and tag updates transactional,
# but it does not check that the branch you push actually contains the tagged
# commit — tag a release branch while pushing an unchanged `main` and the tag
# lands on a commit unreachable from remote main, which still publishes.
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
git add VERSION CHANGELOG.md releases/history.json
git commit -m "Release 0.2.0"
git tag v0.2.0

# Push the ONE tag by name. `--tags` pushes every local tag, and any v* tag
# triggers a build-and-publish — so a stray experimental tag would ship an
# unintended image and GitHub release.
#
# --atomic so the branch and tag succeed or fail together. Without it, if origin
# advanced since you prepared the release, `main` is rejected while the tag is
# still accepted — and the tag alone publishes a release from a commit that
# never reached the branch.
git push --atomic origin main v0.2.0
```

### Then advance `VERSION` for the next cycle

Immediately after tagging, move `VERSION` on to the *next* development version
and commit that separately:

```bash
printf '0.3.0\n' > VERSION

# Clear the actions now that the release carrying them is tagged and its
# artifacts are built from it. Without this the next release rebuilds the same
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
Nothing else publishes; there is no other CI.

## 4. Check what an upgrader actually sees

After the image publishes, confirm `/operator` on a fresh install reports the new
version, and that the changelog entry answers "does this need me?" without the
reader having to open a single PR.
