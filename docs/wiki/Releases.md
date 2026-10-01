# Releases

[Back to Home](Home)

Read each release as an operator change and validate it on a disposable VM
before upgrading a persistent installation. From `v1.0.0` the version number
says whether a release needs you: only a new major can ask for anything beyond
pulling and restarting, a minor adds features and self-migrating schema, and a
patch only fixes. The release notes and manifest remain the authoritative
detail.

The latest published release is
[`v1.1.0`](https://github.com/adamgreenwell/wayfindr/releases/tag/v1.1.0).
Its [release workflow](https://github.com/adamgreenwell/wayfindr/actions/runs/36875096725)
verified the tagged commit, manifest, multi-architecture image, GitHub Release,
and stable image aliases on October 1, 2026. Its protected tag resolves to
`9b634b23`, its image resolves to
`sha256:ef0f50c987aa56a796e627c93197c3bb5a8588fbb6f1fc43056c598681d05905`, and
the `1.1` and `latest` image aliases point at that image. It needs no operator
action; five migrations run themselves. It adds erasing a contact on request
and exporting everything held about one
([ADR 0026](https://github.com/adamgreenwell/wayfindr/blob/main/docs/decisions/0026-erasing-and-exporting-a-visitor.md)).
Each erasure is also recorded in `storage/app/erasure-ledger/` on the storage
volume, which backups do not carry, so a restore erases again anyone the
archive brings back. Keep that directory alongside your backups. If you are
several releases behind, read each release in between: `v0.11.0` carries a
session-secret security fix. Separate public-artifact install and upgrade runs
are recorded below.

## Where to Look

- [GitHub Releases](https://github.com/adamgreenwell/wayfindr/releases) for
  published artifacts and operator-facing notes.
- [`CHANGELOG.md`](https://github.com/adamgreenwell/wayfindr/blob/main/CHANGELOG.md)
  for the cumulative change history.
- The [attached `v1.1.0` release manifest](https://github.com/adamgreenwell/wayfindr/releases/download/v1.1.0/release-manifest.json)
  for the required actions and advisory notices in that published artifact;
  [tagged `release.json`](https://github.com/adamgreenwell/wayfindr/blob/v1.1.0/release.json)
  is its source declaration.
- [Tagged `releases/history.json`](https://github.com/adamgreenwell/wayfindr/blob/v1.1.0/releases/history.json)
  for the skipped-release history carried by `v1.1.0`, including the
  `v0.9.0` host-managed PHP runtime action for an install upgrading from
  before it.
- [Current `release.json`](https://github.com/adamgreenwell/wayfindr/blob/main/release.json)
  for the next development line. Its cleared actions do not replace the
  published `v1.1.0` manifest.

Official images carry their release and commit identity. Source builds identify
their development lineage, and only a clean build supplied with its commit can
be compared precisely.

Before adopting a release, follow [Upgrading](Upgrading), take a backup, keep a
rollback target, and record clean-install or upgrade evidence from the same
artifact you intend to run.

## Current Release Evidence

Evidence below is recorded per artifact and is not superseded by a later
release: each entry states what was proved, for which version, on which date.

The October 1, 2026 `v1.1.0` public-artifact runs cover two hosted paths:

- A [clean install](https://github.com/adamgreenwell/wayfindr/actions/runs/36881545824)
  on a fresh Ubuntu 24.04 runner, which passed on a re-run after GitHub's
  releases API refused the first attempt with HTTP 403 before anything was
  installed.
- A [`v0.2.0 → v1.1.0` upgrade with a custom backup queue](https://github.com/adamgreenwell/wayfindr/actions/runs/36881550403)
  on a separate fresh Ubuntu 24.04 runner.

Both completed the synthetic support loop, backup/restore, and stack restart,
and read the authenticated `/operator` console's `Wayfindr version: v1.1.0`
after the install or upgrade and after restore. The upgrade resolved the
published image to
`sha256:ef0f50c987aa56a796e627c93197c3bb5a8588fbb6f1fc43056c598681d05905`, and
its `1.1.0/backups-queue-consumer` notice retired itself once the custom-queue
worker was observed. The same boundaries as below apply: loopback HTTP on
hosted runners, not bare metal, DNS/TLS, real mail, offsite backups, or
production restore.

The September 30, 2026 `v1.0.0` public-artifact runs cover two hosted paths:

- A [clean install](https://github.com/adamgreenwell/wayfindr/actions/runs/36723360923)
  on a fresh Ubuntu 24.04 runner.
- A [`v0.2.0 → v1.0.0` upgrade with a custom backup queue](https://github.com/adamgreenwell/wayfindr/actions/runs/36723365996)
  on a separate fresh Ubuntu 24.04 runner.

Both resolved the published image to
`sha256:47b697666e9bd7380cbbf39157d54a5a89b5e7071b0503cc22bf8c3aa27e222d`,
completed the synthetic support loop, backup/restore, and stack restart, and
read the authenticated `/operator` console's `Wayfindr version: v1.0.0` after
install and after restore. In the upgrade, the `1.0.0/backups-queue-consumer`
notice retired itself once the custom-queue worker was observed. The same
boundaries as below apply: loopback HTTP on hosted runners, not bare metal,
DNS/TLS, real mail, offsite backups, or production restore.

The September 29, 2026 `v0.11.0` public-artifact runs cover two hosted paths:

- A [clean install](https://github.com/adamgreenwell/wayfindr/actions/runs/36613505205)
  on a fresh Ubuntu 24.04 runner.
- A [`v0.2.0 → v0.11.0` upgrade with a custom backup queue](https://github.com/adamgreenwell/wayfindr/actions/runs/36613509014)
  on a separate fresh Ubuntu 24.04 runner.

Both resolved the published image to
`sha256:6b19e4c2529c86b291b25c09dbfe579c12df167e70a2473ce2f4a8b6da9b7ff5`,
completed the synthetic support loop, backup/restore, and stack restart, and
read the authenticated `/operator` console's `Wayfindr version: v0.11.0` after
install and after restore. In the upgrade, the `0.11.0/backups-queue-consumer`
notice retired itself once the custom-queue worker was observed. The same
boundaries as below apply: loopback HTTP on hosted runners, not bare metal,
DNS/TLS, real mail, offsite backups, production restore, or
[#797](https://github.com/adamgreenwell/wayfindr/issues/797).

The September 28, 2026 `v0.10.0` public-artifact runs cover two hosted paths:

- A [clean install](https://github.com/adamgreenwell/wayfindr/actions/runs/36463449869)
  on a fresh Ubuntu 24.04 runner.
- A [`v0.2.0 → v0.10.0` upgrade with a custom backup queue](https://github.com/adamgreenwell/wayfindr/actions/runs/36463452977)
  on a separate fresh Ubuntu 24.04 runner.

Both resolved the published image to
`sha256:fa8c0449d516f6fade3eff556c3887111e202e09165ea4959b8d34d49741affe`,
completed the synthetic support loop, backup/restore, and stack restart, and
read the authenticated `/operator` console's `Wayfindr version: v0.10.0` after
install and after restore. In the upgrade, the `0.10.0/backups-queue-consumer`
notice retired itself once the custom-queue worker was observed. The same
boundaries as below apply: loopback HTTP on hosted runners, not bare metal,
DNS/TLS, real mail, offsite backups, production restore, or
[#797](https://github.com/adamgreenwell/wayfindr/issues/797).

The September 24, 2026 `v0.9.0` public-artifact runs cover two hosted paths:

- A [clean install](https://github.com/adamgreenwell/wayfindr/actions/runs/36008767661)
  on a fresh Ubuntu 24.04 runner.
- A [`v0.2.0 → v0.9.0` upgrade with a custom backup queue](https://github.com/adamgreenwell/wayfindr/actions/runs/36008785768)
  on a separate fresh Ubuntu 24.04 runner.

Both resolved the published image to
`sha256:5799f89e3561c0e8b8a0e2f2e9933cc1edf0292604ff36cc127608090093138a`
and completed the synthetic support loop, backup/restore, and stack restart.
A separate [fresh-install operator probe](https://github.com/adamgreenwell/wayfindr/actions/runs/36013000938)
used that same published image digest and read the rendered authenticated
`/operator` console's `Wayfindr version: v0.9.0` after install and after
restore. These runs prove those public-artifact paths over loopback HTTP. They
do not establish bare-metal reboot, operator-managed DNS/TLS, real mail, offsite
backups, production restore, or the human non-author acceptance in
[#797](https://github.com/adamgreenwell/wayfindr/issues/797). The
[local `v0.9.0` candidate rehearsal](https://github.com/adamgreenwell/wayfindr/blob/main/docs/development/release-0.8.0-rehearsal.md)
also exercised a `v0.7.0` upgrade before publication, but its locally built
image does not add another public-artifact upgrade path.

Earlier evidence remains tied to `v0.3.2`:

- `v0.3.2` has passing hosted public-artifact clean-install evidence from
  August 11, 2026:
  [run 31535388323](https://github.com/adamgreenwell/wayfindr/actions/runs/31535388323).
  The resolved container image digest was
  `sha256:3fe112ca3d3f83efb1f4d00c401b8bf43cc706ec5bfddb05244be01b2fd8e660`.
- Published upgrade paths also have passing hosted evidence:
  [`v0.2.0 → v0.3.2` with custom `BACKUP_QUEUE`](https://github.com/adamgreenwell/wayfindr/actions/runs/31535924025),
  [`v0.1.0 → v0.3.2`](https://github.com/adamgreenwell/wayfindr/actions/runs/31536143352),
  and
  [`v0.1.0 → v0.2.0 → v0.3.2`](https://github.com/adamgreenwell/wayfindr/actions/runs/31536145475).
- On August 12, 2026, `v0.3.2` also passed two owner-operated fresh Ubuntu
  24.04.4 Hyper-V clean installs, exact database-plus-attachment restore, full
  service restart, and a real guest reboot/reverify. Public `v0.2.0 → v0.3.2`
  with a custom backup queue passed on a separate guest, including advisory
  retirement, exact restore markers, and reboot/reverify. A forced release-
  discovery failure refused before mutation and left the previous release live.
  See [Disposable VM Evidence](Disposable-VM-Evidence) for the sanitized detail.
- Treat those `v0.3.2` guest checks as strong evidence for the tested local-only
  Compose paths, not a substitute for your own DNS/TLS, real mail,
  offsite-backup, destructive-schema rollback, or production restore checks.

The versioning and enforcement contract is documented in the
[platform versioning ADR](https://github.com/adamgreenwell/wayfindr/blob/main/docs/decisions/0012-platform-versioning.md)
and [release manifest guide](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/release-manifest.md).
