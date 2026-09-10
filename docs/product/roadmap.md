# Roadmap

This roadmap is directional and should not include private business strategy.

The latest public release is `v0.7.0` (August 25, 2026), and the current
unreleased development line is `0.8.0`. Under the project's pre-1.0 versioning
contract, features, additive schema, or operator action advance the minor slot;
`0.8.0` is a development identity, not a claim that the release is published.

`1.0.0` is scoped to finishing the core support product and proving it, rather
than to feature parity. The two gaps that framed it are closed: live visitor
monitoring is implemented, and localization landed as a platform property —
timezone, region, and where language is configured — rather than as a
translation pass. Performance baselines have been measured instead of assumed.

**One acceptance criterion is still open, and it is deliberately the one the
author cannot satisfy alone:**
[#797](https://github.com/adamgreenwell/wayfindr/issues/797) — somebody who is
not the author installs Wayfindr from a published artifact and it works. The
repaired baseline for that run does not exist publicly yet:
[#932](https://github.com/adamgreenwell/wayfindr/issues/932) and
[PR #934](https://github.com/adamgreenwell/wayfindr/pull/934) prepared and
reviewed the `v0.8.0` candidate on current `main`. The stop-before-tag gates in
[RELEASING.md](../../RELEASING.md) still have to clear, the owner must separately
authorize the repository-settings and publication changes, and the exact
artifact must be verified before #797's brief is refreshed for a human tester.

Tier 2 parity work is no longer post-1.0 either; it is implemented on current
`main`. The AI tier has one assistive half implemented and one autonomous half
deliberately deferred, which is recorded under *Deferred On Purpose* below
rather than left implied.

## Implemented on Current Main

This section describes the unreleased `0.8.0` development tree. Some foundation
also exists in public `v0.7.0`, but none of the post-`v0.7.0` additions below
should be read as a public-artifact claim until a stable release containing
them is published and verified.

The product has moved past a spine. Both competitive-gap tiers behind it —
[#741](https://github.com/adamgreenwell/wayfindr/issues/741) (Tier 1) and
[#751](https://github.com/adamgreenwell/wayfindr/issues/751) (Tier 2) — are
closed. It now includes:

- Email as a second conversation channel. Mailgun and Postmark inbound webhooks
  can now be pointed straight at Wayfindr
  ([#799](https://github.com/adamgreenwell/wayfindr/issues/799) is closed; the
  re-signing intermediary 0.7.0 required is no longer one), and Wayfindr's own
  signature scheme still verifies for anyone who built it. See
  [Inbound Mail](../self-hosting/inbound-mail.md). Alongside it: a searchable
  help centre inside the widget, per-site support hours with an away state and
  offline capture, and a configurable pre-chat form.
- Reporting over conversations and tickets, plus visitor satisfaction ratings.
- Per-site widget appearance, a widget language catalogue in English and German,
  and an agent-selectable dashboard language in English, German and Italian.
  Coverage is now most of the product rather than a handful of pages; what is
  and is not extracted is described immediately below.

  **The operator console is now fully extracted**, and so is the great majority
  of the dashboard — Alerts, Reports, ticket detail, sites and site settings,
  the account audit, break-glass, and the security, SLA, automation and webhook
  pages, including Integrations. The ordinary pages that still render English
  are the agent home page, custom roles, the readiness page, and support-code
  lookup. The CSV exports keep stable English headers deliberately, because a
  localized cell is reparsed by whatever spreadsheet opens it.

  `DashboardLanguage::EXTRACTED_ROUTES` is the list that decides which **pages**
  are translated, and it is the only place worth reading: a page missing from it
  renders English by design rather than by accident. Rather than keep a second
  list here in prose and let the two drift, treat that constant as the answer —
  what is written above is a summary of it on the day this was edited.

  **Write endpoints are the exception, and it is deliberate.** A form submitted
  from a translated page answers in that page's language even when its own route
  is unlisted, because `DashboardLanguage::forRequest()` resolves from the
  surface the response renders back to. The same action reached from a page that
  is still English answers in English. The language belongs to the page the agent
  is looking at, not to the endpoint.

  A German agent can now cross most of the product without the language
  changing under them. The agent home page is the most frequently visited of
  the surfaces that still do.

  The widget and the dashboard also carry different language sets on purpose
  rather than by omission: Italian is agent-facing, and adding it to the widget
  is a separate catalogue for a separate audience.
- A visitor directory and profile with account-defined typed attributes over
  existing safe host context, exact-value filtering, private person-level
  contact notes, explicit same-site identity merge with durable browser-ID
  aliases, and a contacts-only custom role boundary; agent-initiated password
  recovery; and a public API with a decided isolation model, scoped reads, and
  a narrow write surface.
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

  The board that reads it is implemented too, so an operator who turns presence
  on gets the live view as well as the visitor directory.

- Throughput tooling for a desk with more than one agent:
  [SLA policies](sla-policies.md) with breach warnings,
  [automatic assignment and routing](automatic-assignment-and-routing.md), a
  typed support lifecycle underneath condition-to-action automation rules and
  macros, bulk actions across the conversation and ticket queues, and a command
  palette with global keyboard shortcuts and a reference sheet.
- Agent alerting that finally uses the socket already open: background alerts,
  web push, quiet hours, and cross-channel de-duplication so one event does not
  arrive three times.
- [Proactive and triggered messages](proactive-messaging.md), defined as rules
  and delivered to the visitors who match them — the first thing the widget does
  without being spoken to first.
- Outbound webhooks (ADR 0020) beside the read and write API surfaces, so an
  integration no longer has to be built inside Wayfindr to exist.
- An agent copilot inside ADR 0004's assistive boundary: on-demand conversation
  summaries, editable reply drafts, suggested ticket details at conversion, and
  suggested knowledge snippets. Every one is a suggestion an agent reviews;
  each is optional, labelled, scrubbed and bounded at one seam before the
  provider, and absent rather than broken when no provider is configured. The
  operator chooses the driver, model, credential and endpoint — see
  [Agent Copilot Providers](../self-hosting/agent-copilot-providers.md).
- A provider-free evaluation harness for that copilot, with a confidence and
  refusal policy and an opt-in private capture path. Versioned provider captures
  bind to exact suite and prompt identities, and equivalent runs can be compared
  offline without exposing support text. It exists to decide what AI may do next
  on evidence rather than on enthusiasm; comparison capability is not itself
  drift or runtime evidence.
- Measured performance baselines rather than assumed ones: Reverb concurrent
  agent capacity, heavy-page cobrowse transport, attachment retention at a large
  object count, and the dashboard and report tabs under a desk's worth of data.

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

## Before 1.0.0

The feature gaps that defined the pre-1.0 work are closed, so what is left is
proof rather than scope. Polish stays demand-gated.

- **Hold the reviewed release candidate at its publication gates:**
  [#932](https://github.com/adamgreenwell/wayfindr/issues/932) completed through
  [PR #934](https://github.com/adamgreenwell/wayfindr/pull/934) on the unreleased
  `0.8.0` tree. Before any tag, wait for every eligible pre-guard release run to
  leave its rerun window or, with separate owner authorization, delete named
  runs after preserving their evidence. Creating the required active `v*` tag
  ruleset is a separately authorized settings change. Neither the merged
  candidate nor this gate inventory authorizes a repository-settings change,
  tag, release, registry push, or deployment.
- **Publish only after separate owner authorization, then verify the exact
  artifact:** record the tag, commit, image digest, release metadata, and
  relevant clean-install/upgrade evidence before treating it as #797's new
  baseline.
- **Run the sole open `1.0.0` milestone criterion:** refresh
  [#797](https://github.com/adamgreenwell/wayfindr/issues/797) with that verified
  artifact and hand it to a human who is not the author. A cold, no-context
  Claude sandbox run against `v0.7.0` was valuable synthetic evidence: it
  matched the release, commit, and image digest, found the widget bootstrap and
  installer discovery defects later fixed by PRs #929 and #931, and completed a
  synthetic support loop after workarounds. It did not exercise a real VM,
  public TLS/origin, local-CA trust, or an unwarmed image pull, and it is not the
  human acceptance required by #797.
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
- Ticket workflow comfort: bulk actions, the command palette and global
  shortcuts took the throughput half of this. What remains is context — smoother
  transitions between conversation, ticket, visitor, and support-code, and
  clearer “what needs attention” cues.
- Alert calm: SLA policies and automation rules now supply the urgency rules the
  digest foundation was waiting for. Keep both observable and metadata-safe under
  real traffic before widening them. See
  [Account Escalation Policies](account-escalation-policies.md).
- Operator hardening: clearer setup/recovery guidance, safer instance activity,
  process-health affordances, platform-action audit inventory, and continued
  checks that break-glass support does not erode tenant boundaries.
- Privacy and retention controls: transcript/message retention visibility,
  operator-owned defaults, deletion/export planning, and warnings that help
  self-hosters understand their responsibility.

## Deferred On Purpose

Not everything absent from the product is a gap waiting for time. One thing is
absent because the decision went the other way, and it belongs on a roadmap so
nobody plans around its arrival.

**A visitor-facing answer agent** — AI replying to a customer without an agent
in the loop — is deferred by
[ADR 0004](../decisions/0004-ai-as-assistive-product-and-development-layer.md)
and tracked as the unchecked half of
[#762](https://github.com/adamgreenwell/wayfindr/issues/762). The evaluation
foundation behind it is implemented, not skipped: a provider-free harness, a
confidence and refusal policy, suite- and prompt-identified private captures,
offline comparison for equivalent provider runs, and a rerun against a deployed
model that passed 9 of 9 cases with no unsafe answer. That evidence was judged
useful and insufficient, and the ADR was reaffirmed rather than relaxed.

One narrow, wholly synthetic run through a single provider route establishes
nothing about model or prompt drift, adversarial and multilingual coverage,
stale or conflicting knowledge, or representative self-hosting failure modes.
The comparison tooling now prevents a changed suite, policy, or Wayfindr prompt
request from masquerading as provider/model drift. It does not fingerprint every
provider-side transformation, and its existence is not a second run or a drift
result.
Reconsideration has a stated sequence — broaden the evaluation set, record drift
across more than one run and model revision, revisit the ADR with that evidence,
and only then define a visitor-facing runtime with grounding, low-confidence
handoff, per-site opt-in and disclosure, and reply audit.

Until that happens, the reply a customer reads belongs to a human.

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
- SPA route tracking and richer host-app SDKs.
- WordPress, Laravel, Next.js, React, and plain JavaScript integration polish.
- Automation surfaces beyond the implemented rules, macros and outbound
  webhooks —
  widened only where real accounts hit the edges of what those already do.
