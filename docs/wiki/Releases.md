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

The versioning and enforcement contract is documented in the
[platform versioning ADR](https://github.com/adamgreenwell/wayfindr/blob/main/docs/decisions/0012-platform-versioning.md)
and [release manifest guide](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/release-manifest.md).
