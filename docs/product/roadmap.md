# Roadmap

This roadmap is directional and should not include private business strategy.

As of August 25, 2026, the latest public release is `v0.7.0`, and the next
development line is `0.7.1`.

`1.0.0` is scoped to finishing the core support product and proving it, rather
than to feature parity: the remaining Tier 1 gaps (live visitor monitoring, and
the dashboard surfaces still rendering English), first-class localization
including timezone and regional settings, and hardening — upgrade paths,
performance, and third-party install validation. Tier 2 parity work and the AI
tier are explicitly post-1.0.

## Shipped

The product has moved past a spine. It now includes:

- Email as a second conversation channel — inbound still needs an intermediary
  in front of it ([#799](https://github.com/adamgreenwell/wayfindr/issues/799)) — a searchable help centre inside the
  widget, per-site support hours with an away state and offline capture, and a
  configurable pre-chat form.
- Reporting over conversations and tickets, plus visitor satisfaction ratings.
- Per-site widget appearance, a widget language catalogue in English and German,
  and an agent-selectable dashboard language in English, German and Italian on
  the surfaces extracted so far: the profile pages, conversation queue and
  detail, ticket queue, live-visitors board, visitor directory and profile,
  reply templates, ticket labels, articles, API tokens, and outbound webhooks.

  **Much of the rest is still English**: the home page, Alerts, Reports, site
  settings, ticket detail, the account overview, Integrations, Operator access,
  Audit, and the operator console.

  `DashboardLanguage::EXTRACTED_ROUTES` is the list that decides which **pages**
  are translated, and it is the only place worth reading: a page missing from it
  renders English by design rather than by accident. Rather than keep a second
  list here in prose and let the two drift, treat that constant as the answer —
  what is written above is a summary of it on the day this was edited.

  **Write endpoints are the exception, and it is deliberate.** A form submitted
  from a translated page answers in that page's language even when its own route
  is unlisted, because `DashboardLanguage::forRequest()` resolves from the
  surface the response renders back to. Closing a ticket from the conversation
  panel produces German validation; the same action from the untranslated ticket
  page produces English. The language belongs to the page the agent is looking
  at, not to the endpoint.

  A German agent moving from the queue to Reports changes language mid-session,
  and finishing that is the remaining half.

  The widget and the dashboard also carry different language sets on purpose
  rather than by omission: Italian is agent-facing, and adding it to the widget
  is a separate catalogue for a separate audience.
- A visitor directory and profile with account-defined typed attributes over
  existing safe host context, exact-value filtering, and a contacts-only custom
  role boundary; agent-initiated password recovery; and a public API with a
  decided isolation model, scoped reads, and a narrow write surface.
- TOTP two-factor authentication with one-time recovery codes, replay-safe
  challenges, and an admin-controlled account requirement; OIDC federation;
  account-owned custom roles; and owner-controlled, deny-by-default JIT role
  mapping. SAML remains demand-gated and SCIM remains a separate lifecycle
  decision.
- Visitor presence collection (ADR 0019) — heartbeat, disclosure, decline, and
  the product's first automatic retention window: thirty days by default, and at
  most, with operators free to shorten it. The maximum belongs to the product
  rather than the install, so a configured value longer than thirty days is
  clamped rather than honoured.

  **Off until an operator turns it on.** The switch lives on the site page under
  *Live visitor presence*, behind the same permission as the masking rules, and
  a default install reports nothing and shows no visitor any notice. Turning it
  off again deletes the visitors it collected who never made contact.

  The board that reads it is still in review, so an operator who enables
  presence today is collecting for the visitor directory rather than for a live
  view.

Underneath that, the original foundation:

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

These are the nearest slices because the product spine exists, the repeatable
self-hosting evidence gate has passed, and polish should stay demand-gated.

- Keep reliability evidence repeatable: use the
  [disposable VM evidence contract](../self-hosting/disposable-vm-evidence.md)
  for future release candidates. The August 12 matrix proved clean install,
  supported upgrade/advisory behavior, backup/restore, narrow rollback/retry,
  reboot recovery, and deployment-fork readiness; do not stretch that dated
  evidence into proof for a later artifact or production restore posture.
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
