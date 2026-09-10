# Project Status

[Back to Home](Home)

Wayfindr is pre-1.0. The latest public release is `v0.7.0` (August 25, 2026),
and the current unreleased development line is `0.8.0`. Current `main` has moved
from "the core support loop exists" to a support desk reachable by widget,
email, and help centre, with a measurement surface of its own. Mailgun and
Postmark can post directly to `POST /api/mail/inbound` when their matching
verification is configured; the original Wayfindr-signed proxy format remains
compatible. Public `v0.7.0` predates that direct-provider support. See the
[repository inbound-mail guide](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/inbound-mail.md).

Self-hosting and upgrades from public artifacts have been proved repeatable on
hosted runners and disposable bare-metal guests — **for the artifacts that were
tested, the most recent being `v0.3.2`**. `v0.7.0` adds ten migrations and has
not been through that matrix; see [Releases](Releases).

The Tier 1 and Tier 2 feature epics are closed on current `main`. The sole open
`1.0.0` milestone criterion is
[#797](https://github.com/adamgreenwell/wayfindr/issues/797), a successful
published-artifact install by somebody who is not the author. Its prerequisite
release sequence is recorded under *Current Release and Acceptance Gates*.

## Current Development Tree

This section describes unreleased `0.8.0` source. Some foundation also exists
in public `v0.7.0`, but post-`v0.7.0` work listed here is not publicly available
until a stable artifact containing it is published and verified.

- Widget install, visitor identity, live chat, agent replies, and durable
  tickets.
- **Email as a second channel**: mail opens and continues conversations, so a
  customer replying to a notification is no longer replying into nothing.
  Current `main` verifies Mailgun and Postmark directly and retains the original
  Wayfindr-signed proxy contract for existing integrations.
- **A help centre**: articles written in the dashboard and searchable from
  inside the widget, so a visitor can find the answer before asking.
- **A public API and outbound webhooks**: scoped tokens provide read and narrow
  write access, while signed thin events announce new conversations, messages
  and ticket lifecycle changes without pushing support content to the configured
  destination. Deliveries are durable, ordered per endpoint, retried with
  backoff, and visible to admins.
- **Account authentication and authorization**: TOTP with recovery codes and
  an account requirement; OIDC federation; account-owned custom roles; and
  owner-controlled JIT provisioning with deny-by-default claim mapping. SAML
  remains demand-gated and SCIM remains a separate lifecycle decision in
  [#761](https://github.com/adamgreenwell/wayfindr/issues/761).
- **Support hours, away state and offline capture**, per site and in the site's
  own timezone, with a pre-chat form for sites that need to know who is asking.
- **Reporting**: conversation and ticket volume, first-response and resolution
  times, reopen rates, per-agent workload, and visitor satisfaction ratings.
  Resolution and reopen figures are read from lifecycle logs, and the two
  halves have different memories: **conversation** closes began being recorded
  in v0.7.0, while **ticket** closes have been audited since well before it — so
  an upgraded desk can describe a quarter of ticket work while its
  conversation figures are still accumulating. The page states each boundary
  separately. Volume, first-response times and agent replies come from data the
  product always kept and reach back as far as the install does.
- **Per-site widget appearance**, and a widget that speaks the visitor's
  language — **English and German**.
- **A dashboard an agent can read in their own language** — **English, German,
  and Italian** — across the operator console and most dashboard workflows:
  profile, alerts, reports, conversations, tickets, sites, visitors, account
  security, SLA, automation, articles, API/webhooks, audit, operator access,
  Integrations, and the account overview. An agent who has chosen nothing reads
  the install's language, which the operator sets in the browser under
  **Language and region**; `APP_LOCALE` seeds a new install and is the fallback
  until somebody saves one.

  The ordinary dashboard pages still intentionally rendered in English are the
  agent home page, custom-role management, readiness, and support-code lookup.
  `DashboardLanguage::EXTRACTED_ROUTES` is the executable authority; it also
  includes writes and partials whose validation or refreshed content must match
  the page that invoked them. A write shared by translated and untranslated
  pages resolves from the surface it renders back to, so the language belongs
  to the page the agent is looking at rather than to the endpoint.

  **The visitor and agent catalogues are deliberately different.** Italian is
  agent-facing only: an Italian-speaking desk reads its dashboard in Italian,
  while visitors still receive English or German. Adding a widget language is a
  separate catalogue for a separate audience.

  The catalogue files remain the authority for review state too. German was
  drafted by hand in context by somebody who is not a professional translator,
  and much of the Italian catalogue is machine-assisted. Both trees have since
  been through the review the translation policy defines, and no catalogue in
  either still carries `NOT YET REVIEWED`.

  That review is a reader rather than a speaker — the bar the policy sets on
  purpose, because it catches wrong terms, overflowing strings and misplaced
  register, which is what a reviewable diff is good for. Mechanical checks
  protect keys, terminology and placeholders. Neither establishes natural
  language quality, so do not promise either language to a customer until a
  qualified speaker has read the rendered screens.
- **A dashboard an agent can read on their own clock.** An agent picks a
  timezone on their profile beside their language; everyone who has not picked
  one follows the install's. The operator sets that in the browser, under
  **Language and region**, and it is authoritative —
  `WAYFINDR_DASHBOARD_TIMEZONE` seeds a new install and is the fallback until
  somebody saves one. It changes what is **shown**, never what is stored —
  every record stays in UTC — so changing it re-reads existing history rather
  than rewriting it, and it applies to report day boundaries as well as
  timestamps.

  A site's **support hours** are the deliberate exception: they belong to the
  site and stay in the site's own zone, because "visitors are told support is
  back at 09:00" would become untrue read on an agent's clock.

  **`app.timezone` is the storage clock, and it is hardcoded to `UTC`** in
  `config/app.php` — deliberately, and with no environment variable for it.
  Laravel writes `created_at` through that value into columns that carry no
  offset, so pointing it anywhere else would record local wall-clock time where
  every reader, and every report query, expects UTC. The display clock is a
  separate setting for exactly that reason.
- **Numbers grouped the way the reading agent groups them** — `4.213` for a
  German agent, `4,213` for an English one — on the same extracted pages, and
  in live updates as well as the first render. Values that something reads back
  are deliberately left alone: chart bar widths, data attributes, CSV cells,
  and anything on a broadcast.
- **A visitor directory and contact workspace** with account-defined typed
  attributes, exact-value filtering, private person-level notes, explicit
  same-site identity merge, and a contacts-only custom-role boundary; plus a
  public API with a decided isolation model, scoped reads, and a narrow write
  surface (ADR 0018).
- **Live visitor presence** ([#747](https://github.com/adamgreenwell/wayfindr/issues/747)):
  who is on the site right now, on what page, for how long, and whether the desk
  has ever heard from them. It updates over the Reverb connection the agent
  pages already use, and resyncs on subscribe and on a timer so a missed frame
  costs a minute rather than the session.

  The decision that made it possible is
  [ADR 0019](https://github.com/adamgreenwell/wayfindr/blob/main/docs/decisions/0019-presence-for-visitors-who-have-not-made-contact.md):
  Wayfindr may observe visitors who never made contact, on a per-site operator
  switch, with a visitor-facing disclosure, a decline the widget honours, and
  the product's first automatic retention control.

  **Off on every install until somebody turns it on.** The switch is on the site
  page under *Live visitor presence*, behind the same permission as the masking
  rules, and a default install reports nothing and shows no visitor a notice.
  Turning it off again deletes the visitors it collected who never made contact,
  and a second switch decides whether reports may name the page at all — for
  sites whose paths carry invitation codes or reset tokens.

  Presence-only visitors are deleted 30 days after they were last seen, or
  sooner if the operator shortens the window; the maximum is the product's, not
  the operator's, and a longer value is clamped rather than honoured.

  Presence also feeds operator-configured proactive-message rules. Presence is
  still off by default, proactive messages are separately enabled, and the
  visitor-facing disclosure and decline remain in force.
- **Team-scale support workflow**: SLA policies and breach warnings, automatic
  assignment and routing, typed lifecycle conditions and actions, automation
  rules, macros, bulk actions, and a command palette with global shortcuts.
- **Quieter, more useful alerting**: background dashboard alerts, Web Push,
  quiet hours, and cross-channel de-duplication.
- **An optional agent copilot inside the assistive boundary**: on-demand
  summaries, editable reply drafts, ticket-conversion suggestions, and proposed
  knowledge snippets. Every result is reviewed by an agent; no provider is
  required, and Wayfindr does not autonomously answer visitors.
- **Measured performance baselines** for concurrent Reverb agents, heavy
  cobrowse transport, large attachment-retention sets, and data-heavy dashboard
  and report pages.
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

## Historical `0.4.0` Reliability Cycle

The `0.4.0` proof cycle was not broad feature expansion. It collected clean
evidence for:

- disposable-VM clean installation;
- supported upgrade and advisory behavior;
- backup, restore, rollback, and reboot recovery;
- deployment-fork synchronization and release-readiness audit.

Use [Disposable VM Evidence](Disposable-VM-Evidence) when recording those runs.
Treat dated stage or fork observations as context, not current runtime proof.

## Last Full Public-Artifact Evidence Snapshot

As of August 12, 2026, the public-artifact matrix had passing hosted runs for
the then-current clean-install, published-upgrade, warning/recovery, and
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
hosted proof for the then-current restore warning path and for a narrow
schema-compatible image rollback from `0.3.2` to `0.3.1`, followed by retrying
the v0.3.2 image.

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

## Current Release and Acceptance Gates

A cold, no-context Claude agent tested public `v0.7.0` in a cloud sandbox. It
matched the release, commit, and image digest and completed a synthetic
visitor-to-agent support loop after working around two defects. The run found
that the script-tag widget did not auto-initialize and that an unreachable
GitHub release API was misdiagnosed as "no release." Those defects were fixed on
current `main` by
[#929](https://github.com/adamgreenwell/wayfindr/pull/929) and
[#931](https://github.com/adamgreenwell/wayfindr/pull/931).

That was valuable cold-start evidence, not #797 acceptance. It ran in a cloud
sandbox rather than a real VM, warmed the image cache before timing, used
localhost over HTTP, skipped public-origin and TLS/local-CA paths, and was
performed by an AI agent rather than a human non-author. The fixes also remain
unreleased while `0.8.0` is only a development identity.

The next sequence is intentionally gated:

1. [#932](https://github.com/adamgreenwell/wayfindr/issues/932) and
   [PR #934](https://github.com/adamgreenwell/wayfindr/pull/934) have prepared
   and reviewed the `v0.8.0` candidate on current `main`.
2. Before any tag, retire every eligible pre-guard release run and install the
   required active `v*` tag ruleset. Creating the ruleset — or deleting a run
   instead of waiting for its rerun window to expire — requires separate owner
   authorization.
3. After separate publication authorization, publish and verify the exact
   stable tag, commit, image digest, release metadata, and relevant
   install/upgrade paths.
4. Refresh [#797](https://github.com/adamgreenwell/wayfindr/issues/797) to name
   that verified artifact, then hand the brief to a human who is not the author.

Candidate readiness, publication, artifact verification, and human acceptance
are four different claims. None should be collapsed into the next one.

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
- Automation beyond the current rules, macros, bulk actions, and webhooks waits
  for real accounts to expose a specific edge. Host SDK polish remains
  demand-gated.
- A visitor-facing autonomous answer agent remains deferred by ADR 0004 and
  [#762](https://github.com/adamgreenwell/wayfindr/issues/762). The implemented
  copilot is assistive: a human reviews every suggestion before a visitor sees
  it. Four private captures under the same sixteen-case suite and prompt across
  three model/upstream routes produced one 16/16 pass and three machine-scored
  failures. Gemini's three lexical fact misses await human adjudication. All
  four were recorded within about 78 minutes, so this is point-in-time
  variability, not long-term drift resistance, model-revision evidence,
  provider approval, or visitor-runtime safety; #762 and the ADR boundary remain
  open and unchanged.

The repository remains authoritative. See the
[README](https://github.com/adamgreenwell/wayfindr#status),
[Roadmap](https://github.com/adamgreenwell/wayfindr/blob/main/docs/product/roadmap.md),
and current
[GitHub issues](https://github.com/adamgreenwell/wayfindr/issues).
