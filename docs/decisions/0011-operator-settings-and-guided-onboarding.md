# 0011: Operator Settings and Guided Onboarding

Date: 2026-07-24

## Context

Self-hosting Wayfindr has two distinct phases with very different ergonomics.
The **install** (one-line script, Compose, or Forge) and the **first-run
`/setup`** — creating the first account owner and site — are already smooth:
`/setup` is a clean browser form that even recovers an interrupted bootstrap.

The **configure** phase between them is a wall. Every runtime knob an operator
needs — mail, attachment storage (S3/R2), malware scanning, backups — is
hand-edited in `.env`, followed by a restart or `config:cache`. The readiness
screens (`/dashboard/readiness`, `/operator`) are read-only: they *report*
status and hand the operator a *CLI command to copy*, but configure nothing.
Mail is the sharpest edge — it is the first gate before the install is usable,
yet there is no in-app way to enter SMTP settings and not even a "send test
email" button (only `wayfindr:mail-test` on the CLI).

The goal is to lower the technical cost of entry: make setup as GUI-driven as
reasonable. Not zero-knowledge — installing containers and pointing DNS is
inherently infrastructure — but an operator should configure *how Wayfindr
runs* in the browser, not a text editor and a restart loop.

## Decision

Introduce a **DB-backed operator-settings layer** that overrides env for the
operationally-configurable areas, and evolve the readiness surface into a
**guided onboarding** experience with inline configuration.

### Settings live in the database, override env

A single-row-per-key `operator_settings` store holds operator-set configuration.
At boot, a `SettingsServiceProvider` reads it and applies overrides onto Laravel
config for the managed keys, so **all existing `config()` / `Mail` / `Storage`
code keeps working unchanged** — it simply sees the operator's values. This is
decisively better than writing `.env` in a container stack: a DB setting is
instantly live across the web, queue, scheduler, and reverb containers (they
share the database) with **no restart and no file access**, where an `.env` edit
needs both, on every container.

Precedence and safety:

- **DB value wins when set; env is the default and the seed.** A form is
  pre-filled from the current effective value (env or a prior DB value), so an
  operator who already configured via env loses nothing, and the UI always shows
  the effective value and its source.
- **Boot-critical config stays env-only:** `APP_KEY`, the database connection,
  Redis, and the Reverb *transport* secrets. These are needed to reach the
  database before any DB setting can be read (chicken-and-egg), and are
  infrastructure secrets, not day-two operator choices. The provider is guarded:
  if the database is unreachable or the table is not migrated yet (a fresh
  boot), it silently applies nothing and env defaults stand.
- **Secrets are encrypted at rest** (mail password, S3 secret) via encrypted
  casts, never returned to the browser in the clear (write-only fields that show
  "set / not set").
- **Operator-gated and audited.** Editing operator settings is a platform
  operator action (ADR [0008](0008-platform-operator-break-glass.md)), lives
  behind `/operator`, and records an `AuditEvent` — the settings are instance
  infrastructure, not per-account configuration.
- Overrides are **cached** so the settings table is not read on every request;
  the cache is busted on write.

### Readiness becomes guided onboarding

The readiness checks already compute per-item status and a single "next step".
Instead of only reporting status and a CLI command, each item that has a GUI
config form gains an inline **Configure** action and, where a command already
exists, a **test/run** button (send test email, run a backup now). After
`/setup`, the operator lands on a guided checklist that walks the essential
steps to green — mail first — rather than a diagnostic wall. Items a request
cannot prove (cron, backups) keep their existing "mark confirmed" evidence flow.

## What stays out of scope

- **Not a zero-CLI install.** Bringing up containers, DNS, and TLS is still
  infrastructure. This is about the *configure* phase, not the install.
- **Not per-account infra config.** These settings are instance-level operator
  configuration, not tenant settings.
- **Boot-critical infra (`APP_KEY`, DB, Redis, Reverb transport) is not moved to
  the database.** Env remains its home.

## Delivery slices

1. **Foundation + Mail + guided onboarding.** The `operator_settings` store, the
   boot-time override provider (guarded, cached), the encrypted-secret pattern,
   and operator gating/audit. First managed area: **mail** — an SMTP settings
   form under `/operator` with a "send test email" button (reusing the
   `wayfindr:mail-test` logic), taking effect with no restart. Turn the
   post-`/setup` landing into a guided checklist that drives mail to green.
2. **Attachment storage + scanning.** GUI for local vs S3/R2 (bucket, key,
   secret, endpoint, region, ACL) with a **test-connection** button, and the
   malware-scanner toggle + ClamAV socket with a reachability test.
3. **Backups.** GUI to configure the backup disk, retention, and prefix; a "run
   a backup now" button; a backup history/status view; and a guarded restore
   entry point.

## Consequences

- Operators configure how the instance runs in the browser, with changes live
  immediately — the single biggest cut to the technical cost of entry.
- A new settings layer is a real thing to maintain: config now has two sources
  (env default, DB override) with a documented precedence, and a boot provider
  that must fail safe when the database is not yet reachable.
- Secrets in the database raise the bar on encryption, operator gating, and
  audit — handled the same way ADR 0008 held platform actions.
- The readiness/onboarding surface shifts from a diagnostic to an actionable
  setup tool, which is where a new operator most needs help.

Related: ADR [0008](0008-platform-operator-break-glass.md) (the operator
boundary this configures behind), and the self-hosting install work whose
"configure" gap this closes.
