# Releases

[Back to Home](Home)

Wayfindr is pre-1.0. Read each release as an operator change and validate it on
a disposable VM before upgrading a persistent installation.

The latest published release is `v0.7.0`.

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

Evidence below is recorded per artifact and is not superseded by a later
release: each entry states what was proved, for which version, on which date.

**None of it covers `v0.7.0`.** That release adds schema migrations — articles,
inbound mail, ratings, lifecycle recording boundaries, API tokens, reporting
indexes and per-user locales — so an older artifact's clean-install and upgrade
runs prove nothing about this one's install path. Record fresh evidence from the
`v0.7.0` artifact before adopting it on anything that matters, exactly as the
rule above says.

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
- Treat that as strong evidence for the tested local-only Compose paths, not a
  substitute for your own DNS/TLS, real mail, offsite-backup, destructive-schema
  rollback, or production restore checks.

The versioning and enforcement contract is documented in the
[platform versioning ADR](https://github.com/adamgreenwell/wayfindr/blob/main/docs/decisions/0012-platform-versioning.md)
and [release manifest guide](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/release-manifest.md).
