# Roadmap

This roadmap is directional and should not include private business strategy.

As of August 11, 2026, the latest public release is `v0.3.1`, and the next
development line is `0.4.0`.

## Current Alpha Spine

The current pre-alpha app has moved beyond the original technical spike. The
foundation now includes:

- Laravel core app shell, authentication, account roles, site access, and
  platform operator authority.
- Browser and CLI first-run setup, operator readiness diagnostics, database-
  backed operator settings, guided onboarding, Forge-first docs, generic runtime
  docs, mail smoke testing, and recovery for incomplete bootstrap records.
- A script-tag widget, visitor identity, conversation creation, two-way
  messaging, Reverb delivery, and manual refresh fallbacks.
- Private conversation-message attachments, visitor/agent upload UI, retention
  sweep, pluggable malware scanning, and S3-compatible storage routing.
- Support-code lookup, visitor profiles, safe visitor context, and support
  reference trails across conversations and tickets.
- Consent-based cobrowse observe-mode foundations: request/consent lifecycle,
  telemetry, page state, sanitized snapshots, bounded mutation diagnostics, and
  an inert replay preview.
- Ticket workflow foundations: assignment, statuses, priorities, categories,
  labels, notes, replies, queue filters, handoff notes, reference panels, and
  next-action guidance.
- Alert preferences, dashboard notifications, queued email delivery, welcome
  emails, mail readiness warnings, and documented alert digest/escalation
  guardrails.
- Operator backup configuration, run-on-demand backup history, restore
  preflights, and self-hosting release manifests with upgrade guards and
  non-blocking advisory notices.
- Scoped break-glass grants for platform-operator support: reasoned, time-bound,
  read-only, account-visible, audited, and transparent in the dashboard while
  live.
- Provider-neutral external issue connections, site project mappings, external
  ticket links, GitHub/GitLab/Jira outbound issue creation, reflected inbound
  state, bidirectional comment relay, and local sync-health visibility.
- Repository maintenance foundations: PHP 8.4, pull-request CI, branch
  protection, private vulnerability reporting, Dependabot, and a repo-authored
  GitHub Wiki.

## Next Alpha Focus

These are the nearest slices because the product spine exists; the project now
needs repeatable self-hosting evidence and deliberately demand-gated polish.

- `0.4.0` reliability evidence cycle: use the
  [disposable VM evidence contract](../self-hosting/disposable-vm-evidence.md)
  to prove clean install, supported upgrade/advisory behavior, backup/restore,
  rollback, reboot recovery, and deployment-fork readiness without treating
  local or dated stage evidence as current release proof.
- MVP dogfood operation: the Forge stage has been the owner-approved initial
  dogfood instance. Keep any runtime claim dated, use
  [MVP Dogfood Readiness](mvp-dogfood-readiness.md) after deploys, and let real
  conversations select the next narrow product slice.
- External integration polish: live GitHub issue creation, inbound state,
  comment relay, and echo suppression are proven. Richer labels, assignee,
  priority mapping, inbound-comment presentation, and assigned-agent
  notifications are parked in
  [External Ticket Integrations](external-ticket-integrations.md) until real
  traffic proves a specific need.
- Ticket workflow comfort: smoother transitions between conversation, ticket,
  visitor, and support-code context; clearer “what needs attention” cues; and
  less page-hopping for common agent moves.
- Alert calm: keep the implemented digest/manual-escalation foundation stable,
  observable, and metadata-safe before adding automatic urgency rules. See
  [Account Escalation Policies](account-escalation-policies.md).
- Operator hardening: clearer setup/recovery guidance, safer instance activity,
  process-health affordances, platform-action audit inventory, and continued
  checks that break-glass support does not erode tenant boundaries.
- Privacy and retention controls: transcript/message retention visibility,
  operator-owned defaults, deletion/export planning, and warnings that help
  self-hosters understand their responsibility.

## Later Expansion

These remain valid but should wait until the support loop and operator loop feel
stable.

- Richer external field mapping only after real provider traffic establishes
  which fields, directions, and conflict rules are useful. Native Bitbucket
  Issues remain deferred to demonstrated operator demand. Track this in the
  product docs, not as an evergreen open issue, until a real operator need
  appears.
- Richer inbound-comment presentation and assigned-agent notifications only if
  continued live use demonstrates that the base internal-note relay is too
  quiet or too plain.
- Literal incremental DOM patching inside the cobrowse replay iframe should not
  proceed while the preview depends on a bare sandbox, no scripts, and server-
  sanitized `srcdoc` swaps. Revisit only if dogfood telemetry shows full-preview
  swaps are a real performance problem and an ADR accepts the security tradeoff.
- Direct ticket attachments, internal-note attachments, office-document opt-ins,
  pre-signed attachment URLs, and broader attachment workflows only if
  conversation-message attachments prove the demand and operator controls.
- Agent-assisted summaries, reply drafts, and ticket suggestions when they
  improve concrete workflows without becoming AI decoration.
- SPA route tracking and richer host-app SDKs.
- WordPress, Laravel, Next.js, React, and plain JavaScript integration polish.
- Webhooks and broader automation surfaces.
