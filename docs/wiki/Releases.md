# Releases

[Back to Home](Home)

Wayfindr is pre-1.0. Read each release as an operator change and validate it on
a disposable VM before upgrading a persistent installation.

The latest published release is
[`v0.9.0`](https://github.com/adamgreenwell/wayfindr/releases/tag/v0.9.0).
Its [release workflow](https://github.com/adamgreenwell/wayfindr/actions/runs/36002701167)
verified the tagged commit, manifest, multi-architecture image, GitHub Release,
and stable image aliases on September 24, 2026. Separate public-artifact install
and upgrade runs are recorded below.

## Where to Look

- [GitHub Releases](https://github.com/adamgreenwell/wayfindr/releases) for
  published artifacts and operator-facing notes.
- [`CHANGELOG.md`](https://github.com/adamgreenwell/wayfindr/blob/main/CHANGELOG.md)
  for the cumulative change history.
- The [attached `v0.9.0` release manifest](https://github.com/adamgreenwell/wayfindr/releases/download/v0.9.0/release-manifest.json)
  for the required actions and advisory notices in that published artifact;
  [tagged `release.json`](https://github.com/adamgreenwell/wayfindr/blob/v0.9.0/release.json)
  is its source declaration.
- [Tagged `releases/history.json`](https://github.com/adamgreenwell/wayfindr/blob/v0.9.0/releases/history.json)
  for the skipped-release history carried by `v0.9.0`.
- [Current `release.json`](https://github.com/adamgreenwell/wayfindr/blob/main/release.json)
  for the next development line. Its cleared actions do not replace the
  published `v0.9.0` manifest.

Official images carry their release and commit identity. Source builds identify
their development lineage, and only a clean build supplied with its commit can
be compared precisely.

Before adopting a release, follow [Upgrading](Upgrading), take a backup, keep a
rollback target, and record clean-install or upgrade evidence from the same
artifact you intend to run.

## Current Release Evidence

Evidence below is recorded per artifact and is not superseded by a later
release: each entry states what was proved, for which version, on which date.

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
