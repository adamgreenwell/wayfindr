# Project Status

[Back to Home](Home)

Wayfindr is pre-1.0. As of August 25, 2026, the latest public release is
`v0.7.0`, and the next development line is `0.7.1`. The product has moved from
"the core support loop exists" to a support desk reachable by widget, email and
help centre, with a measurement surface of its own. "Reachable by email" carries
one qualification: no provider can post to the inbound endpoint directly,
because Wayfindr verifies a signature scheme none of them emit, so that channel
needs an intermediary the project does not ship ([#799](https://github.com/adamgreenwell/wayfindr/issues/799)).

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
  customer replying to a notification is no longer replying into nothing —
  once you have put something in front of the inbound endpoint to re-sign for
  it, which the project does not yet ship
  ([#799](https://github.com/adamgreenwell/wayfindr/issues/799)).
- **A help centre**: articles written in the dashboard and searchable from
  inside the widget, so a visitor can find the answer before asking.
- **Support hours, away state and offline capture**, per site and in the site's
  own timezone, with a pre-chat form for sites that need to know who is asking.
- **Reporting**: conversation and ticket volume, first-response and resolution
  times, reopen rates, per-agent workload, and visitor satisfaction ratings.
  Resolution and reopen figures are read from lifecycle logs, and the two
  halves have different memories: **conversation** closes began being recorded
  in this release, while **ticket** closes have been audited since well before
  it — so an upgraded desk can describe a quarter of ticket work while its
  conversation figures are still accumulating. The page states each boundary
  separately. Volume, first-response times and agent replies come from data the
  product always kept and reach back as far as the install does.
- **Per-site widget appearance**, and a widget that speaks the visitor's
  language — **English and German**.
- **A dashboard an agent can read in their own language** — **English, German
  and Italian** — on the surfaces extracted so far: the profile pages, the
  conversation queue, the ticket list, the conversation detail page with its
  cobrowse panel, the live-visitors board, and four account pages — reply
  templates, ticket labels, articles and API tokens. An agent who has chosen
  nothing reads the install's language, which the operator sets in the browser
  under **Language and region**; `APP_LOCALE` seeds a new install and is the
  fallback until somebody saves one.

  **Much of the dashboard is still English** — the home page, Alerts, Reports,
  Visitors, site settings, ticket detail, and the rest of account management:
  Account, Integrations, Operator access, Audit. The **operator console** is the
  largest untouched surface and has never been extracted at all. A German or
  Italian agent moving from the queue to Reports changes language mid-session.

  `DashboardLanguage::EXTRACTED_ROUTES` is the list that decides which **pages**
  are translated, and it is the authority rather than this paragraph: a page
  missing from it renders English by design rather than by accident. A prose
  list will drift from the constant, so read the constant.
  **Write endpoints are the deliberate exception.** A form submitted from a
  translated page answers in that page's language even when its own route is
  absent from the constant, because `DashboardLanguage::forRequest()` resolves
  from the surface the response renders back to. Closing a ticket from the
  conversation panel produces German validation; the same action from the
  untranslated ticket page produces English. The language belongs to the page
  the agent is looking at rather than to the endpoint.

  **The two lists are not the same, and the difference matters.** Italian is
  agent-facing only: an Italian-speaking desk reads its own dashboard in
  Italian, and its visitors still get English or German. Adding a language to
  the widget is a separate catalogue with a separate audience, and reading
  "Italian" as covering both is the wrong conclusion to draw.

  **Neither pack has been read by a qualified speaker, and they are unreviewed
  in different ways.** German was drafted during development: written by hand,
  in context, by somebody who is not a professional translator. Italian is
  mostly machine output. Thirteen of its fourteen catalogues came out of a
  pipeline with a glossary, a protection scheme for placeholders and a policy
  scorer; each opens with `NOT YET REVIEWED` and describes its own values as
  proposals. The fourteenth, `validation.php`, is not pipeline output at all —
  the pipeline only translates files it finds in `lang/en`, and there is no
  English validation catalogue — so it was written by hand against the German
  one, covering the rules the dashboard actually validates with and falling back
  to Laravel's own English for any rule it does not name.

  The count grows with every extracted surface, so treat it as of this writing
  rather than as a fixed figure: `ls apps/server/lang/it` is the answer, and
  `grep -l 'NOT YET REVIEWED'` over it is the unreviewed share.

  Those checks establish mechanical consistency: the same term rendered the same
  way everywhere, no placeholder lost in translation, the right register
  attempted. They establish nothing about whether a sentence is good Italian,
  and the translation policy says so directly. Do not promise either language to
  a customer until somebody who speaks it has read the rendered screens.
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
- **A visitor directory**, and a read-only public API with a decided isolation
  model (ADR 0018).
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

  Proactive messaging — the feature presence exists to unblock — is deliberately
  not part of this and remains Tier 2.
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
