# Project Status

[Back to Home](Home)

Wayfindr is pre-1.0. As of August 25, 2026, the latest public release is
`v0.7.0`, and the next development line is `0.7.1`. The product has moved from
"the core support loop exists" to a support desk reachable by widget, email and
help centre, with a measurement surface of its own.

Self-hosting and upgrades from public artifacts have been proved repeatable on
hosted runners and disposable bare-metal guests — **for the artifacts that were
tested, the most recent being `v0.3.2`**. `v0.7.0` adds ten migrations and has
not been through that matrix; see [Releases](Releases).

`1.0.0` is scoped: the remaining Tier 1 gaps plus hardening, not feature
parity with every competitor. See [the 1.0.0 milestone](https://github.com/adamgreenwell/wayfindr/milestone/1).

## Shipped Spine

- Widget install, visitor identity, live chat, agent replies, and durable
  tickets.
- **Email as a second channel**: mail opens and continues conversations, so a
  customer replying to a notification is no longer replying into nothing.
- **A help centre**: articles written in the dashboard and searchable from
  inside the widget, so a visitor can find the answer before asking.
- **Support hours, away state and offline capture**, per site and in the site's
  own timezone, with a pre-chat form for sites that need to know who is asking.
- **Reporting**: conversation and ticket volume, first-response and resolution
  times, reopen rates, per-agent workload, and visitor satisfaction ratings.
  **Resolution times and reopen rates** are read from a lifecycle log that
  starts when an install upgrades, and the page states that date; volume,
  first-response times and agent replies come from data the product always
  kept, so they reach back as far as the install does.
- **Per-site widget appearance**, and a widget that speaks the visitor's
  language (German ships complete).
- **A dashboard an agent can read in their own language** on the queues, the
  ticket list, the app shell and the profile page. The conversation detail page
  follows in `0.7.1`.
- **A visitor directory**, and a read-only public API with a decided isolation
  model (ADR 0018).
- **Agent-initiated password recovery.**
- Consent-based cobrowse observe mode with sanitized snapshots, bounded
  mutations, telemetry, and an inert replay preview.
- Private conversation-message attachments with visitor and agent UI, retention
  sweep, malware-scanner hook, and S3-compatible storage routing.
- Operator readiness, database-backed operator settings, guided onboarding,
  backup/restore surfaces, and release upgrade guidance.
- Scoped read-only break-glass grants for platform-operator support.
- GitHub/GitLab/Jira issue creation, state reflection, and comment relay
  foundations.
- Pull-request CI, branch protection, Dependabot, private vulnerability
  reporting, and this repo-authored Wiki.

## Current Reliability Cycle

The `0.4.0` proof cycle was not broad feature expansion. It collected clean
evidence for:

- disposable-VM clean installation;
- supported upgrade and advisory behavior;
- backup, restore, rollback, and reboot recovery;
- deployment-fork synchronization and release-readiness audit.

Use [Disposable VM Evidence](Disposable-VM-Evidence) when recording those runs.
Treat dated stage or fork observations as context, not current runtime proof.

## Current Evidence Snapshot

As of August 12, 2026, the public-artifact matrix has passing hosted runs for
the current clean-install, published-upgrade, warning/recovery, and
schema-compatible image rollback/retry scenarios:

- [`clean-install-latest`](https://github.com/adamgreenwell/wayfindr/actions/runs/31535388323)
  for `v0.3.2`;
- [`upgrade-v0.2.0-latest-custom-backup-queue`](https://github.com/adamgreenwell/wayfindr/actions/runs/31535924025);
- [`upgrade-v0.1.0-latest`](https://github.com/adamgreenwell/wayfindr/actions/runs/31536143352);
- [`upgrade-v0.1.0-v0.2.0-latest`](https://github.com/adamgreenwell/wayfindr/actions/runs/31536145475);
- [`recovery-latest-synthetic-skew-restore`](https://github.com/adamgreenwell/wayfindr/actions/runs/31537984956);
- [`recovery-latest-v0.3.1-image-rollback-retry`](https://github.com/adamgreenwell/wayfindr/actions/runs/31539581605).

Together, those hosted runs prove fresh GitHub-hosted Ubuntu runners can install
published artifacts, upgrade through the supported public-release paths, boot
the Compose stack, run migrations, complete the support loop, take and restore
a backup, repeat the support loop after restore, and restart the stack. The
custom backup queue run also proves the backups-queue advisory appears during
upgrade guidance and retires once the worker is observed. The recovery runs add
hosted proof for the current restore warning path and for a narrow
schema-compatible image rollback from `0.3.2` to `0.3.1`, followed by retrying
the current image.

The owner-operated bare-metal repeat adds two fresh Ubuntu 24.04.4 clean guests,
a public `v0.2.0` to `v0.3.2` upgrade guest, database and exact attachment-byte
restore, pre-mutation refusal with the previous release kept live, and real
guest reboot/reverify passes. Both clean attempts found actionable Docker-only
host gaps; PRs
[#707](https://github.com/adamgreenwell/wayfindr/pull/707) and
[#708](https://github.com/adamgreenwell/wayfindr/pull/708) fixed them before the
final repeat. See [Disposable VM Evidence](Disposable-VM-Evidence) for the
sanitized matrix and its limits.

The deployment fork matched source at `b8be095` after PR #708, with passing
[fork CI](https://github.com/northcoastmedia/wayfindr/actions/runs/31630708915)
and an observed successful Forge deployment. The authenticated operator surface
reported the same revision, PHP 8.4, a ready `13 / 0 / 2` installation posture,
configured SMTP, Redis queueing, Reverb, writable storage, S3-compatible private
attachment storage, and reachable ClamAV. Forge showed queue, backup queue,
Reverb, and per-minute scheduler processes plus three-region HTTP 200 health
checks. The operator's scheduler confirmation was stale and its backup/restore
confirmation missing; those manual proof notes remain operational follow-up,
not evidence of a failed process or restore.

The combined evidence still does not claim destructive-schema downgrade safety,
real DNS/TLS configuration, real mail delivery, offsite-backup durability, or a
production restore.

*(Historical: this snapshot was recorded during the `0.4.0` evidence cycle, when
`v0.3.2` was the public artifact. Releases through `v0.7.0` have been cut since.
The evidence above stands as a record of what was proved then; it is not a
statement about the current release.)*

## Parked or Demand-Gated

- External tracker labels, assignees, priorities, richer inbound comments, and
  assigned-agent notifications are documented parking-lot items, not active
  open issues. Reopen a narrow issue only when real support traffic identifies
  the provider, field direction, conflict policy, and operator pain.
- Direct ticket attachments, internal-note attachments, office-document opt-ins,
  and pre-signed attachment URLs wait for message attachments to prove the next
  shape.
- Live cobrowse replay is already handled through server-sanitized preview
  swaps. Literal incremental DOM patching inside the iframe should not proceed
  while it weakens the bare-sandbox, no-script, observe-only boundary; revisit
  only with measured dogfood pressure and a new architecture decision.
- Broader automation, host SDK polish, and AI-assistive features come after the
  operator loop is boring.

The repository remains authoritative. See the
[README](https://github.com/adamgreenwell/wayfindr#status),
[Roadmap](https://github.com/adamgreenwell/wayfindr/blob/main/docs/product/roadmap.md),
and current
[GitHub issues](https://github.com/adamgreenwell/wayfindr/issues).
