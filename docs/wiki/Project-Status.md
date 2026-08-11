# Project Status

[Back to Home](Home)

Wayfindr is pre-alpha. As of August 11, 2026, the latest public release is
`v0.3.2`, and the next development line is `0.4.0`. The core support loop
exists, and the current cycle is about proving that self-hosting and upgrades
are repeatable from public artifacts.

## Shipped Spine

- Widget install, visitor identity, live chat, agent replies, and durable
  tickets.
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

The active `0.4.0` proof work is not broad feature expansion. It is clean
evidence for:

- disposable-VM clean installation;
- supported upgrade and advisory behavior;
- backup, restore, rollback, and reboot recovery;
- deployment-fork synchronization and release-readiness audit.

Use [Disposable VM Evidence](Disposable-VM-Evidence) when recording those runs.
Treat dated stage or fork observations as context, not current runtime proof.

## Current Evidence Snapshot

As of August 11, 2026, the hosted public-artifact workflow has a passing
`clean-install-latest` run for `v0.3.2`:
[run 31535388323](https://github.com/adamgreenwell/wayfindr/actions/runs/31535388323).
That run proves a fresh GitHub-hosted Ubuntu runner can install the public
release artifact, boot the Compose stack, run migrations, complete the support
loop, take and restore a backup, repeat the support loop after restore, and
restart the stack.

It remains partial evidence. It does not close the bare-metal disposable VM
reboot, rollback, real DNS/TLS, mail delivery, offsite backup, or production
restore posture checks.

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
