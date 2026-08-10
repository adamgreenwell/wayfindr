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

**No operator action required.** Pull, restart, and migrations run themselves.

### Added

- The backups page now reports whether a queue worker has actually been seen on
  the `backups` queue, instead of leaving you to find out when a run sits at
  *Running* forever. It names the missing worker and prints the exact command for
  your install's configured queue and timeout. Where the platform genuinely
  cannot tell — a cache driver that cannot carry a sighting between processes —
  it says so rather than blaming the configuration.

### Fixed

- The upgrade guard and the installer preflight can no longer disagree about what
  an upgrade owes you. The two are separate implementations in different
  languages (the preflight has to answer *before* the image is pulled, so it
  cannot ask the artifact), and they had drifted repeatedly — the preflight
  reporting "clear", the pull going ahead, and the new release then refusing to
  start. They now share one classification, and a test fails if the two ever
  answer differently.
- Upgrade refusal messages no longer contradict themselves. The report command
  and the migration refusal render one shared set of instructions, so an action
  you can still clear by acknowledging it is never also described as one that
  needs a rollback.

## [0.1.0] - 2026-08-05

The first stable release. `v0.1.0-alpha.1` through `v0.1.0-alpha.3` were
prereleases of this same version; everything below landed after `alpha.3`.

**⚠ Requires operator action.** Running backups from the operator GUI needs a
**second queue worker** on the dedicated `backups` connection. Without it the
"Run a backup now" button queues jobs nothing will ever process, and the run sits
at *Running* indefinitely. Scheduled backups are unaffected.

```bash
# Compose stacks get the backup-queue service from the refreshed compose.yml.
# On Forge or any host-managed setup, add a second worker:
php artisan queue:work backups --queue=backups --sleep=5 --tries=1 --timeout=3600
```

One thing to expect if you check both: this release's *machine-readable* manifest
declares no required actions, so the upgrade guard below will not stop you and
the installer will not warn you. That is deliberate rather than a contradiction.
The guard can only enforce what it can evaluate, and a "does the backups queue
have a consumer" check does not exist yet. Declaring it as an operator
attestation instead would make every operator acknowledge it by hand — a weaker
signal than this paragraph, since an attestation proves only that someone typed
something. The requirement is real; this release states it rather than enforces
it. It is also why the first enforcing release deliberately requires nothing of
its own: a release that refused traffic the moment it landed would punish
installs that were already doing the right thing.

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
- Releases can now declare what they require of an operator, and the install
  enforces the declaration (ADR 0013). A release publishes a manifest naming each
  required action, when it has to happen relative to the upgrade, and whether the
  platform can verify it or the operator has to attest to it.
- **An install with unmet requirements refuses to migrate.** `migrate` stops
  before touching the schema, names the release, the action, and the exact
  command, and exits non-zero — so the previous image is still runnable and the
  recovery is to complete the action or restart the old tag. Requirements that
  can only be carried out once the new code is live gate **serving** instead: the
  app starts, keeps answering `/up` so health checks and load balancers behave,
  and refuses other traffic until they are met. Blocking migration on those would
  withhold the very state they need and could never be satisfied.
- Enforcement lives in the artifact, not the installer. An operator upgrading
  from an older release runs *their* installer, which has no preflight and cannot
  be given one — so a guarantee that lived there would not bind the upgrades that
  need it most.
- `install.sh` additionally refuses **before pulling** when it can read the target
  release's manifest. Some actions have to be performed while the old release is
  still live, and that is the only moment they can still be honoured; afterwards
  the artifact can report the problem but not give the moment back.
- A release can declare the oldest version it will upgrade from, and refuse a
  jump that skips a release whose required action cannot be performed after the
  fact.
- Attested actions are acknowledged one at a time, naming the release and the
  action — `WAYFINDR_ACKNOWLEDGED_ACTIONS=0.2.0/backups-worker` — so an
  acknowledgement can never become a blanket opt-out. It lives in the environment
  because the guard runs before migration, when the schema is still the old
  release's.
- Versions now compare in order rather than only for equality, so guidance that
  depends on which install is newer — restore's schema-skew warning most of all —
  can say which way the mismatch runs instead of hedging both directions
  (ADR 0012).
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
  release as a modified tree. It also now *adds* the identity keys when `.env`
  lacks them, rather than substituting into lines that are not there and silently
  leaving the identity on its fallback; and it stays ASCII, because the snippet is
  pasted into a browser editor and a mangled character inside a quoted string
  ends the string early and fails the deploy somewhere else entirely. This only
  affects operators tracking `main` who copied the snippet before this release —
  no published release contained it. If a deploy warns that `.env` is a regular
  file, follow *Repairing a detached environment file* in the Forge guide.

---

Releases before this changelog — `v0.1.0-alpha.1` through `v0.1.0-alpha.3`
(2026-07-21 to 2026-07-22) — are not reconstructed here. They are early alpha
builds, and their history lives in the git tags and commit log. This changelog
starts fresh rather than backfilling entries after the fact; the gap is
deliberate, not an absence of changes. Everything merged *after* `alpha.3` is
recorded under **0.1.0** above, so an operator upgrading from it still gets the
full picture.
