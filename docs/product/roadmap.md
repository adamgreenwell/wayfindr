# Roadmap

This roadmap is directional and should not include private business strategy.

## Current Alpha Spine

The current pre-alpha app has moved beyond the original technical spike. The
foundation now includes:

- Laravel core app shell, authentication, account roles, site access, and
  platform operator authority.
- Browser and CLI first-run setup, operator readiness diagnostics, Forge-first
  docs, generic runtime docs, mail smoke testing, and recovery for incomplete
  bootstrap records.
- A script-tag widget, visitor identity, conversation creation, two-way
  messaging, Reverb delivery, and manual refresh fallbacks.
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
- Provider-neutral external issue connections, site project mappings, external
  ticket links, GitHub/GitLab/Jira outbound issue creation, reflected inbound
  state, bidirectional comment relay, and local sync-health visibility.

## Next Alpha Focus

These are the nearest product slices because they improve the daily support
experience without opening broad integration or platform-service work too soon.

- MVP dogfood launch: refresh the deployed revision and
  [MVP Dogfood Readiness](mvp-dogfood-readiness.md), then route real support
  through Wayfindr before broadening self-hosted expectations.
- External integration validation: exercise issue creation, inbound state, and
  comment relay against a live provider; use that traffic to decide whether
  labels, assignee, or priority mapping is worth its conflict-handling cost.
- Ticket workflow comfort: smoother transitions between conversation, ticket,
  visitor, and support-code context; clearer “what needs attention” cues; and
  less page-hopping for common agent moves.
- Alert calm: keep the implemented digest/manual-escalation foundation stable,
  observable, and metadata-safe before adding automatic urgency rules. See
  [Account Escalation Policies](account-escalation-policies.md).
- Operator hardening: clearer setup/recovery guidance, safer instance activity,
  process-health affordances, platform-action auditing, and a separately
  designed break-glass access path that does not erode tenant boundaries.
- Privacy and retention controls: transcript/message retention visibility,
  operator-owned defaults, deletion/export planning, and warnings that help
  self-hosters understand their responsibility.

## Later Expansion

These remain valid but should wait until the support loop and operator loop feel
stable.

- Richer external field mapping only after real provider traffic establishes
  which fields, directions, and conflict rules are useful. Native Bitbucket
  Issues remain deferred to demonstrated operator demand.
- Richer inbound-comment presentation and assigned-agent notifications after
  the base comment relay is validated live.
- Agent-assisted summaries, reply drafts, and ticket suggestions when they
  improve concrete workflows without becoming AI decoration.
- SPA route tracking and richer host-app SDKs.
- WordPress, Laravel, Next.js, React, and plain JavaScript integration polish.
- Webhooks and broader automation surfaces.
