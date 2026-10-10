# Releases

[Back to Home](Home)

Read each release as an operator change and validate it on a disposable VM
before upgrading a persistent installation. From `v1.0.0` the version number
says whether a release needs you: only a new major can ask for anything beyond
pulling and restarting, a minor adds features and self-migrating schema, and a
patch only fixes. The release notes and manifest remain the authoritative
detail.

The latest public release is
[`v1.3.0`](https://github.com/adamgreenwell/wayfindr/releases/tag/v1.3.0), at
`1bae329c4e349017716827dd0ce26b9ba7f9f2ac`.
[Exact-main CI 38056674067](https://github.com/adamgreenwell/wayfindr/actions/runs/38056674067)
and [guarded publisher 38057466650](https://github.com/adamgreenwell/wayfindr/actions/runs/38057466650)
passed. Independent public readback verified Release assets, manifest, OCI
index, both amd64/arm64 platform/configuration chains, `1.3.0`, `1.3` and
`latest` aliases, and GitHub latest. The index is
`sha256:f7b5361f32c4b81010d74da37d310aec048f7101d8ea3ec4a99cea9f90df8dd0`;
the manifest SHA256 is
`61ab5296646fea9f4d756b2075eba03fcc167f07302887a40a921a5df3c48c58`.
No operator action or migration is required; standing backup-queue guidance
remains. The [publication receipt](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/evidence/managed-update/2026-10-10-v1.3.0-publication.json)
records these public identities.

This release publishes the Docker 29 compatibility correction, helper `0.5.0`
and the separate supported helper upgrade CLI. An already-enrolled 1.2.0
installation must upgrade its host helper separately; an application image
pull does not replace that code. The genuine 1.2.0 → 1.3.0 minor-release pair
is now available for disposable-VM testing. Publication does not establish
managed protection/apply, recovery, native AMD64 behavior or the full matrix.
U8/#1115, U9/#1116, #1131 and epic #1107 remain open with zero qualified
managed scenarios.

Development `main` is now `1.4.0-dev` after
[PR #1136](https://github.com/adamgreenwell/wayfindr/pull/1136), merge
`a61445dcf5d67713f61d082cd389876b1d9f2e46`; all five
[exact-main CI jobs](https://github.com/adamgreenwell/wayfindr/actions/runs/38059773227)
passed. This version change did not publish another release.

The previous public release is
[`v1.2.0`](https://github.com/adamgreenwell/wayfindr/releases/tag/v1.2.0), at
`56374e9574ed84616aae430d06589cfe2f0b33a0`.
[Exact-main CI 38006329309](https://github.com/adamgreenwell/wayfindr/actions/runs/38006329309)
passed; [guarded publisher 38007100329](https://github.com/adamgreenwell/wayfindr/actions/runs/38007100329)
passed. Independent readback verified the public Release, 1,111-byte manifest,
OCI index, both amd64/arm64 platform/configuration chains and the `1.2` and `latest` aliases at that publication. The index is
`sha256:052a2897b503ebfdec4cfba232d2893cd48f3cdadeb3478c4161627d38e01683`.
The manifest SHA256 is
`2f07858a050a4c602500247d9d27ac7ad01b09e612a9425d5148ae4442aa92f4`.
Publication was October 10 at 00:26 UTC, October 9 at 20:26 EDT; the release
notes retain their October 9 date. No operator action is required; one additive
audit-event deduplication migration runs automatically. The source adds
Operator → Updates release review, CLI hardening and optional managed execution
still under disposable-VM qualification. Ordinary installs retain their update
path; optional enrollment is separate from taking the release.

ARM64 never-started baked version/commit/manifest/history verification passed.
Actual ARM64 official installation/enrollment, idle helper restart and autonomous
idle guest reboot also passed, with runtime/private nonce/API checks repeated
after reboot. Ordinary archive/restore on a separate clean, unenrolled VM
also passed without force, using the retained effective key and real
post-archive erasure ledger, with exact replay/survivor/decryption/sequence
checks. These ARM64/private HTTP results do not establish TLS, user-session
broadcasting authorization or managed protective custody.
At this earlier observation, managed protection/apply lacked a compatible
newer target and the [Docker 29 image-identity fix #1131](https://github.com/adamgreenwell/wayfindr/issues/1131).
Those artifacts are now published in 1.3.0; native qualification remains separate.
An already-enrolled 1.2.0 host also needs a supported helper upgrade path or a
new published source enrolled with the fixed helper; an application image
update alone does not replace host helper code. U8/#1115 and U9/#1116 remain
open, with zero qualified scenarios.
The historical `1.3.0-dev` reset followed the separate
`VERSION` change at `bcb5474a`; required actions are empty and standing notices
are preserved.

The verified baked-file/source VM and ordinary-restore observations cover ARM64
only. Public
amd64 manifest/configuration verification is a separate artifact check, not an
amd64 installation, reboot or restore result.

An earlier public release is
[`v1.1.1`](https://github.com/adamgreenwell/wayfindr/releases/tag/v1.1.1).
Its [release workflow](https://github.com/adamgreenwell/wayfindr/actions/runs/36966810612)
verified the tagged commit, manifest, multi-architecture image, GitHub Release,
and stable image aliases on October 2, 2026. Its protected tag resolves to
`648caa1b`, its image resolves to
`sha256:c816a46187549ea35ab04e7cae5fcbb96c3d80b1c06842951228a772ddc956a9`, and
at that publication the `1.1` and `latest` image aliases pointed at that image. It needs no operator action and has no
migrations. This patch fixes dashboard typography, form spacing, page ordering,
reports, and authentication feedback; makes mobile widget text and controls
easier to use; preserves keyboard tab selection and focus after attachment
removal; and identifies the CI run that prevents a release from publishing.

The previous `v1.1.0`
[release workflow](https://github.com/adamgreenwell/wayfindr/actions/runs/36875096725)
verified that release's tagged commit, manifest, multi-architecture image,
GitHub Release, and stable image aliases on October 1, 2026. Its protected tag
resolves to `9b634b23`, and its image resolves to
`sha256:ef0f50c987aa56a796e627c93197c3bb5a8588fbb6f1fc43056c598681d05905`.
At that publication, the `1.1` and `latest` image aliases pointed at that image.
It needs no operator action; five migrations run themselves. It adds erasing a
contact on request and exporting everything held about one
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
- The [attached `v1.3.0` manifest](https://github.com/adamgreenwell/wayfindr/releases/download/v1.3.0/release-manifest.json),
  [tagged declaration](https://github.com/adamgreenwell/wayfindr/blob/v1.3.0/release.json)
  and [retained history](https://github.com/adamgreenwell/wayfindr/blob/v1.3.0/releases/history.json)
  describe the current public artifact. Prior release actions still apply to
  installations that skipped them.
- The [attached `v1.2.0` manifest](https://github.com/adamgreenwell/wayfindr/releases/download/v1.2.0/release-manifest.json),
  [tagged declaration](https://github.com/adamgreenwell/wayfindr/blob/v1.2.0/release.json)
  and [retained history](https://github.com/adamgreenwell/wayfindr/blob/v1.2.0/releases/history.json)
  agree that this release has no new required action. Prior release actions
  remain applicable to installs that skipped them.
- The [attached `v1.1.1` release manifest](https://github.com/adamgreenwell/wayfindr/releases/download/v1.1.1/release-manifest.json)
  for the required actions and advisory notices in that published artifact;
  [tagged `release.json`](https://github.com/adamgreenwell/wayfindr/blob/v1.1.1/release.json)
  is its source declaration.
- [Tagged `releases/history.json`](https://github.com/adamgreenwell/wayfindr/blob/v1.1.1/releases/history.json)
  for the skipped-release history carried by `v1.1.1`, including the
  `v0.9.0` host-managed PHP runtime action for an install upgrading from
  before it.
- [Current `release.json`](https://github.com/adamgreenwell/wayfindr/blob/main/release.json)
  is separate from the immutable published manifest. Development `main` is
  `1.4.0-dev`; empty current actions do not replace the history carried by
  `v1.3.0`.

Official images carry their release and commit identity. Source builds identify
their development lineage, and only a clean build supplied with its commit can
be compared precisely.

Before adopting a release, follow [Upgrading](Upgrading), take a backup, keep a
rollback target, and record clean-install or upgrade evidence from the same
artifact you intend to run.

## Current Release Evidence

Evidence below is recorded per artifact and is not superseded by a later
release: each entry states what was proved, for which version, on which date.

The 1.2.0 ARM64 baked identity, official source installation/enrollment, idle
helper restart and autonomous idle guest reboot passed. All five application
roles verified the official image/UID-GID/runtime, PostgreSQL, production Redis,
80 migrations and zero failed jobs. The same eight container IDs and
credential/config/source fingerprints survived reboot; private nonce and
authenticated API/operator checks passed again with a new helper generation and
no operations. These private-HTTP source-only results do not establish TLS,
user-session broadcasting authorization, managed apply or active-upgrade reboot.
The [sanitized receipt](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/evidence/managed-update/2026-10-10-published-source-and-ordinary-restore.json)
records exact public image/source identities without private VM IDs or secrets.
Ordinary archive/restore on a separate clean, unenrolled guest also passed
without force, using the fixed pre-erasure archive and latest real nonempty
ledger. Exact returned-receipt/site/lineage/SYSTEM replay linkage and positive
counts were checked; survivor rows/local binary/settings/decryption, scrubbed
tickets and sequence/new API contact checks passed. One current key and no
previous keys were retained, so rotation was not exercised. Erasure used the
production service, not dashboard confirmation. This ordinary recovery does
not qualify managed protective custody or active-update recovery.

The October 2, 2026 `v1.1.1` public-artifact runs cover two hosted paths:

- A [clean install](https://github.com/adamgreenwell/wayfindr/actions/runs/36969131572)
  on a fresh Ubuntu 24.04 runner.
- A [`v0.2.0 → v1.1.1` upgrade with a custom backup queue](https://github.com/adamgreenwell/wayfindr/actions/runs/36969207425)
  on a separate fresh Ubuntu 24.04 runner; its second attempt passed after
  the first stopped during Docker volume creation for the old `v0.2.0` baseline.

Both resolved the published image to
`sha256:c816a46187549ea35ab04e7cae5fcbb96c3d80b1c06842951228a772ddc956a9`,
completed the synthetic support loop, backup/restore and stack restart, and
read authenticated `/operator`'s `Wayfindr version: v1.1.1` after install or
upgrade and after restore. The upgrade's `1.1.1/backups-queue-consumer` notice
retired itself once the custom-queue worker was observed.

The initial tagged harness stopped on the removed "Ticket brief" heading after
successfully reaching `v1.1.1`. These passing runs use the corrected evidence
harness at `0ea53947`, which checks the stable work-state heading instead.
The public image was not rebuilt. These runs prove loopback HTTP on hosted
runners, not bare metal, DNS/TLS, real mail, offsite backups or production
restore.

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
