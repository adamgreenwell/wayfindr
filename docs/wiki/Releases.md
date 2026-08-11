# Releases

[Back to Home](Home)

Wayfindr is pre-alpha. Read each release as an operator change and validate it
on a disposable VM before upgrading a persistent installation.

## Where to Look

- [GitHub Releases](https://github.com/adamgreenwell/wayfindr/releases) for
  published artifacts and operator-facing notes.
- [`CHANGELOG.md`](https://github.com/adamgreenwell/wayfindr/blob/main/CHANGELOG.md)
  for the cumulative change history.
- [`release.json`](https://github.com/adamgreenwell/wayfindr/blob/main/release.json)
  for machine-enforced required actions and advisory notices.
- [`releases/history.json`](https://github.com/adamgreenwell/wayfindr/blob/main/releases/history.json)
  for the history an artifact uses to evaluate skipped releases.

Official images carry their release and commit identity. Source builds identify
their development lineage, and only a clean build supplied with its commit can
be compared precisely.

Before adopting a release, follow [Upgrading](Upgrading), take a backup, keep a
rollback target, and record clean-install or upgrade evidence from the same
artifact you intend to run.

## Current Release Evidence

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
- Treat that as partial operational evidence. Before adopting it on a persistent
  host, still run your own disposable VM reboot, upgrade, rollback, DNS/TLS,
  mail, offsite-backup, and restore checks.

The versioning and enforcement contract is documented in the
[platform versioning ADR](https://github.com/adamgreenwell/wayfindr/blob/main/docs/decisions/0012-platform-versioning.md)
and [release manifest guide](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/release-manifest.md).
