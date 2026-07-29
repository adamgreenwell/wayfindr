# Releasing Wayfindr

Wayfindr is self-hosted: a release is something other people have to *operate*.
The version number and the changelog entry are the only signals an operator gets
about whether an upgrade is safe to take unattended, so both are decided
deliberately, not derived from the diff. The contract is ADR
[0012](docs/decisions/0012-platform-versioning.md).

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
report as `<VERSION>-dev`. **It must match the tag you are about to push.** If
they drift, every source build between this release and the next claims the wrong
lineage — a build after `v0.2.0` would still report `0.1.0-dev`.

```bash
# VERSION and the tag must agree; the tag carries the conventional "v".
# Stage both explicitly — `commit -a` would skip VERSION the first time,
# because a file git has never seen is untracked, not modified.
printf '0.2.0\n' > VERSION
git add VERSION CHANGELOG.md
git commit -m "Release 0.2.0"
git tag v0.2.0
git push origin main --tags
```

### Then advance `VERSION` for the next cycle

Immediately after tagging, move `VERSION` on to the *next* development version
and commit that separately:

```bash
printf '0.3.0\n' > VERSION
git add VERSION
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
