# Changelog

All notable changes to Wayfindr are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
as scoped by ADR 0012 (`docs/decisions/0012-platform-versioning.md`) — where a
**major** release means *the operator must do something beyond pulling the image
and restarting*, not merely that an API changed.

## Does this release need me?

Wayfindr is self-hosted, so the question every entry here has to answer is
whether an upgrade is safe to take unattended. Each release therefore opens with
one of:

- **Requires operator action** — followed by exactly what to do. Pulling and
  restarting is *not* enough; something (a process, a config key, a manual
  migration) must be done by hand.
- **No operator action required** — pull, restart, and migrations run themselves.

If you are several releases behind, read **every** release between yours and your
target, not just the newest: an action required by a release you skipped still
applies to you.

Entries are grouped with the Keep a Changelog headings — `Added`, `Changed`,
`Deprecated`, `Removed`, `Fixed`, `Security` — and anything that requires
operator action is also marked inline with **⚠ Operator action** so it cannot be
missed while skimming.

## [Unreleased]

**⚠ Requires operator action.** Running backups from the operator GUI needs a
**second queue worker** on the dedicated `backups` connection. Without it the
"Run a backup now" button queues jobs nothing will ever process, and the run sits
at *Running* indefinitely. Scheduled backups are unaffected.

```bash
# Compose stacks get the backup-queue service from the refreshed compose.yml.
# On Forge or any host-managed setup, add a second worker:
php artisan queue:work backups --queue=backups --sleep=5 --tries=1 --timeout=3600
```

### Added

- Operator settings under `/operator`: mail, attachment storage, malware
  scanning, and backups are configured in the browser, stored in the database,
  and override env without a restart (ADR 0011).
- A guided onboarding checklist as the landing page after `/setup`, replacing the
  read-only readiness screen for first-run configuration.
- Backup and restore: `wayfindr:backup` and a guarded `wayfindr:restore`, with an
  optional offsite mirror to an S3-compatible bucket and age-based retention
  (ADR 0009, ADR 0010).
- **⚠ Operator action** — Operator backups GUI: configure destination, retention,
  and per-install prefix; run a backup on demand; review run history; and perform
  a confirmed in-GUI restore. Running backups from the GUI needs the second queue
  worker described above; without it those runs never start. The in-GUI restore
  additionally requires a Redis-backed queue, cache, and maintenance state — where
  that is not met, the page explains why and points to the CLI (ADR 0011).

- Every install now carries a release identity — official images bake theirs at
  build time, source builds derive one from the `VERSION` file, and `WAYFINDR_VERSION`
  and `WAYFINDR_COMMIT` override both. Backup archives record the version and the
  commit, and a restore warns when the archive's *version* does not match the
  running install. Where a build stamps its commit into that version — official
  images and derived Forge deploys both do — two different builds are told apart.
  Two archives sharing a plain version string are not, because the separately
  recorded commit is not yet compared (ADR 0012).
- Forge deploys derive that identity from the checkout on every deploy, rather
  than reading a value typed into the Environment panel that keeps claiming a
  release after the site has moved off it. A tagged checkout reports its version,
  anything else reports a development identity, and a working tree carrying local
  edits reports one that is explicitly unverifiable rather than a commit that does
  not describe what is deployed.

### Fixed

- A restore no longer treats two unverifiable versions as a match. Installs
  without a release identity reported `unknown` on both sides, which compared
  equal and silently skipped the schema-mismatch guard; an unverifiable pair now
  keeps the site in maintenance for the operator to check.
- The Forge identity snippet no longer aborts untagged deploys, replaces the
  `.env` symlink with a detached copy, or reports every clean zero-downtime
  release as a modified tree. This only affects operators tracking `main` who
  copied the snippet before this release — no published release contained it. If
  a deploy warns that `.env` is a regular file, follow *Repairing a detached
  environment file* in the Forge guide.

---

Releases before this changelog — `v0.1.0-alpha.1` through `v0.1.0-alpha.3`
(2026-07-21 to 2026-07-22) — are not reconstructed here. They are early alpha
builds, and their history lives in the git tags and commit log. This changelog
starts fresh rather than backfilling entries after the fact; the gap is
deliberate, not an absence of changes. Everything merged *after* `alpha.3` is
recorded under **Unreleased** above, so an operator upgrading from it still gets
the full picture.
