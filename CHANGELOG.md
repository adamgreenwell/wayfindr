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

## [0.8.0] - 2026-09-10

**Requires operator action when upgrading a host-managed PHP install, including
Laravel Forge, from an earlier Wayfindr release.**
The official Wayfindr image and images built locally with Wayfindr's Dockerfile
include them and need no action; their normal pull, restart, and automatic
migration path is unchanged. A known-fresh host install has the same baseline
runtime prerequisites before its first deploy, but it has no upgrade action to
acknowledge.

1. **⚠ Operator action, host-managed PHP upgrades only.** Before updating an
   existing checkout, confirm the supported PHP binaries used by
   Composer, the web process, and workers
   have `curl`, `gd`, and `intl`, and that cURL uses libcurl 7.59.0 or newer:

   ```bash
   php -r '$problems = []; if (PHP_VERSION_ID < 80401) { $problems[] = "PHP 8.4.1+"; } foreach (["curl", "gd", "intl"] as $extension) { if (! extension_loaded($extension)) { $problems[] = "ext-{$extension}"; } } if (extension_loaded("curl") && (! is_string($version = curl_version()["version"] ?? null) || version_compare($version, "7.59.0", "<"))) { $problems[] = "libcurl 7.59.0+"; } if ($problems !== []) { fwrite(STDERR, "Missing runtime requirements: ".implode(", ", $problems).PHP_EOL); exit(78); } echo "This PHP runtime is ready.".PHP_EOL;'
   ```

   On Debian or Ubuntu with PHP 8.4 the packages are normally `php8.4-curl`,
   `php8.4-gd`, and `php8.4-intl`. Enable the equivalents for the PHP binary
   Composer and the application actually use, reload PHP-FPM, restart queue and
   Reverb processes, and verify the scheduler's PHP command before deploying.
   Composer now declares all three
   extensions and the libcurl minimum, and stops before migration if any are
   missing. If the host already complies, verify Composer, PHP-FPM, queues, the
   scheduler, and Reverb; otherwise remediate first and then verify every one of
   those runtimes. Finally, add `0.8.0/php-runtime-extensions` to
   `WAYFINDR_ACKNOWLEDGED_ACTIONS` before deploying. A passing CLI check cannot
   prove those other runtimes, so the release guard deliberately requires that
   attestation; a failed machine check cannot be acknowledged away. The release
   manifest scopes this work to host-managed PHP, so image installs do not ask
   an operator to acknowledge modules already baked in. Release state now binds
   its clean marker to that installation profile, so moving persisted storage
   between an image and host PHP cannot inherit the other path's exemption.
   Forge also asks for the key when missing state cannot prove a site is truly
   fresh; that conservative preflight protects restored and legacy installs and
   does not redefine a demonstrably fresh install as an upgrade.

**Migration footprint.** This candidate contains 37 migration files beyond
v0.7.0. They stay on the normal automatic migration path, but they create the
tables, columns, and indexes behind the features below, so allow a realistic
upgrade window for a busy database. The stored-page query-string rewrite under
*Security* is the one deliberately irreversible data change.

The nginx timeout and page-address cleanup notes below repair pre-existing host
gaps. Neither leaves a conforming install worse after an unattended upgrade, so
they are not release actions.

**Two things you will notice within a minute of upgrading, neither of them
broken:**

- **The operator console flips from Ready to "Needs attention."** A new
  readiness check asks whether anybody has confirmed the install's language and
  clock, and it gates on a stored value rather than on your environment — so an
  install already configured correctly through `APP_LOCALE` and
  `WAYFINDR_DASHBOARD_TIMEZONE` still flips. Visit **Operator console →
  Language and region** and save once; confirming that English and UTC were
  right all along is a valid answer and clears it permanently.
- **Every agent's profile gains a third language.** Italian ships in this
  release. See the caveat under *Added* before you promise it to anyone.

**And one change to your traffic.** The widget now makes one additional
unauthenticated `GET /api/widget/appearance` per page view, on every install,
whether or not you turn presence on. It writes nothing and returns the same
bytes to every visitor on a site, so it is cacheable — but it is a request per
page view that was not there before.

### Added

- **A per-agent timezone, and a dashboard that renders on it.** An agent picks a
  timezone on their profile beside their language, and times, dates and report
  day boundaries follow it. Everything is still stored in UTC — a single seam
  converts on the way to a screen and nowhere else — so this changes what is
  shown and never what is written, and changing it re-reads existing history
  rather than rewriting it. It also fixes a real miscount: activity in the
  offset band (00:30 in Berlin) was being filed under the previous day.

  A site's **support hours** are deliberately excluded and stay in the site's
  own zone, because "visitors are told support is back at 09:00" would be untrue
  read on an agent's clock.

  New optional key `WAYFINDR_DASHBOARD_TIMEZONE`, defaulting to `UTC`, which is
  what every existing install already renders. **Not `APP_TIMEZONE`** — that is
  read nowhere in Wayfindr, and the storage clock it would set is hardcoded to
  `UTC` on purpose, because Laravel writes `created_at` through it into columns
  that carry no offset.

- **Language and region as an operator setting**, under **Operator console →
  Language and region**, DB-backed and overriding the environment the same way
  mail, storage, scanning and backups already do. `APP_LOCALE` and
  `WAYFINDR_DASHBOARD_TIMEZONE` now seed a new install and pre-fill that form;
  once you save there, the console value is what the dashboard uses.

  There is deliberately **no "go back to the environment" control**. It would
  let you un-confirm the setup step while the checklist still read ready.

- **Italian, and a much wider translated dashboard.** English, German, and
  Italian now cover conversation detail and cobrowse, visitors, reply templates,
  labels, articles, API tokens, live visitors, the account audit, operator
  access, integrations, account/team management, the principal operator
  console workflows, ticket detail, alerts, reports, sites, the hosted tester,
  and site settings. The remaining ordinary dashboard pages intentionally left
  in English are the dashboard home, readiness, and support-code lookup. `DashboardLanguage::EXTRACTED_ROUTES` remains the executable authority
  on that boundary.

  Both packs have now been through the review the translation policy defines:
  every catalogue in both trees has been read against the glossary, the rendered
  surface, and the register rules. No catalogue still opens by declaring itself
  unreviewed, in English or in its own language — three headers keep a narrower
  note saying only that no native speaker has read them, which is true of all of
  them and is the next paragraph's point.

  **That is a reader, not a speaker, and the difference is the point.** Section 9
  of [the translation policy](docs/product/translation-policy.md) sets the bar
  deliberately at somebody who reads the target language without speaking it,
  because that is the competence the pipeline optimises for — a reviewable diff
  rather than a better first draft. It catches a wrong glossary term, a string
  that overflows its button, and `Sie` where it does not belong. It does not
  establish that a sentence is good German or good Italian. **Neither pack has
  been read by a qualified speaker**, so do not promise either language to a
  customer until one has read the rendered screens.

  Content the *account* wrote — an article's title and body, a token's name, a
  site's name, a visitor's name and the page they are on — now carries `lang=""`
  on the surfaces listed above **and on the conversation queue and detail
  page**, where several visitor-derived values had been inheriting the agent's
  language since those pages were extracted. Assistive technology stops
  pronouncing a visitor's English words with German phonetics there. Surfaces
  that are still English are unaffected: the whole document is English, so
  nothing is being announced as the wrong language.

- **Live visitor presence.** You can see who is on the site right now, with the
  privacy question settled first (ADR 0019) rather than after: a visitor is told
  before anything is reported, can decline, and what is kept is bounded and
  pruned rather than held forever.

- **The conversation detail page speaks the agent's language**, including its
  cobrowse panel — the half that 0.7.0 said would arrive in the next release.

- **`wayfindr:translate-catalogue`**, the command that drafted the Italian pack.
  It reads a new `MURF_API_KEY` and **sends catalogue strings to an outside
  service**. Nothing calls it on your behalf; it exists for whoever maintains
  the packs. If that is not you, leave the key unset and the command unused.

- **A narrow public API write surface and durable outbound webhooks.** Tokens can
  independently receive write abilities for creating conversations and tickets,
  replying, and updating the supported ticket fields. Writes require 24-hour
  idempotency keys and preserve the integration—not the person who created its
  token—as the actor. Account-managed webhook endpoints can receive four signed
  support events through an ordered, retryable outbox with delivery history,
  manual retry, one-time secret display, and SSRF-resistant destinations.

  API-created email replies are also committed to a durable outbox and recovered
  when the queue handoff or worker fails. Email remains at-least-once because a
  generic SMTP server cannot atomically confirm mailbox delivery. Ticket notes
  relayed to GitHub, GitLab, or Jira use a separate at-most-once outbox: after a
  provider call begins, an uncertain result is held for reconciliation rather
  than risking a duplicate public comment.

- **TOTP, OpenID Connect, and account-owned roles.** Accounts can require
  encrypted TOTP with replay-safe challenges and one-time recovery codes, link
  existing verified agents to one account OIDC provider, and define custom roles
  from deny-by-default permissions. Owners may also opt into exact OIDC claim
  mappings that create eligible agents on first federated sign-in and resync only
  JIT-managed roles; Owner authority, local role assignments, raw claims, and
  provider tokens stay outside federation control.

- **Business-hours SLAs and automatic routing.** First-response and resolution
  targets now pause with site support hours and surface approaching, breached,
  met, and missed states in queues, work details, alerts, mail, and reports.
  Sites may opt into round-robin assignment using explicit agent online/away
  state and account conversation capacity. Conversation status, ticket status,
  and priority now share typed write boundaries so API, dashboard, routing, and
  automation cannot quietly invent different lifecycle values.

- **Rules, macros, and durable automation history.** Account-scoped rules can
  react to bounded ticket, conversation, and visitor-message events with six
  internal actions: assignment, labels, priority, status, notifications, and
  private notes. Rules and macros start as disabled drafts, support
  side-effect-free previews, execute transactionally in stable order, isolate a
  failed rule from the support write and later rules, and retain readable
  per-action results. Visitor-message sending is deliberately not an automation
  action. Authorized agents can run the same vocabulary as one-click macros.

- **Bulk queue work and an agent command layer.** Ticket and conversation queues
  support accessible multi-selection, one-use review, assignment and lifecycle
  updates, and conflict-aware undo that refuses to overwrite newer work. Global
  guarded shortcuts cover queue navigation and common actions; `Alt+P` opens an
  accessible, permission-filtered command palette, and `?` opens the live
  page-aware shortcut reference.

- **Background, sound, and Web Push agent alerts.** Durable dashboard alerts now
  broadcast on private agent channels, reconcile socket gaps, badge background
  tabs, optionally play a local sound, and can reach opted-in browsers through
  operator-managed VAPID Web Push. Per-agent quiet hours pause interruptive
  channels while keeping the alert center current. A delivery ledger coordinates
  visible-dashboard, push, immediate mail, unattended mail, and digest fallbacks
  so one alert version is not intentionally sent through every channel.

- **A richer, deliberately bounded visitor contact record.** Accounts can define
  typed attributes over already-sanitized visitor context, keep private durable
  contact notes, and explicitly merge same-site duplicates while preserving old
  browser/session lineage and support history. Authorized exports reapply the
  visible filters, cap at 500 recent contacts, neutralize spreadsheet formulas,
  and exclude page URLs, raw context, notes, support history, and alias lineage.

- **Opt-in proactive messages.** Disabled-by-default site rules can match URL,
  referrer, time on page, visit count, agent availability, frequency, and prior
  dismissal. Eligible visitors see a capped, dismissible plain-text invitation;
  accepting it enters the ordinary conversation transcript. Ninety-day shown,
  engaged, and dismissed evidence is reported and pruned. This is deterministic
  rule delivery, not an autonomous AI reply.

- **An optional, agent-controlled copilot.** An operator can configure Anthropic,
  Gemini, OpenAI, OpenRouter, local Ollama, or an OpenAI-compatible endpoint for
  four on-demand suggestions: conversation summaries, editable reply drafts,
  ticket details, and locally resolved knowledge snippets. Requests use bounded,
  scrubbed text with no attachment or cobrowse path; keys are encrypted and
  write-only. OpenRouter requests pin one named upstream, require zero data
  retention, and disable fallback routing. Every result becomes stale on new
  conversation activity and requires an explicit agent decision before it can
  fill—but never submit—a support control.

  A provider-free evaluation harness now scores versioned synthetic cases for
  grounded accuracy, confidence, refusal reasons, citations, coverage, unsafe
  answers, and overconfident errors; private provider capture is separately
  opt-in. Fixture schemas v3 and v4 require trusted synthetic `current` or
  `stale` article metadata, while fixture v2 remains accepted and normalizes
  every article to `current`. Version 4 also pins an offline
  German-versus-all-profiles language classifier, whole-answer margin, and a
  bounded English marker-window check into the suite identity for its selected German
  answer case. The public corpus now has sixteen cases—eight
  answerable and eight refusal—including current-over-stale resolution,
  stale-only and conflicting-current handoff, indirect article-body export
  injection and citation poisoning, an overlapping secret/action jailbreak,
  and grounded German answer/refusal cases. Answers are instructed to use the
  question's language while the structured JSON keys and enum values remain
  stable. Its bundled 16/16 responses are a curated check of evaluator
  coherence, not a new provider run, broad adversarial or multilingual
  evidence, or runtime freshness/language detection.

  Response schema v3 binds each capture to deterministic SHA-256 identities for
  its exact fixture/policy suite and prompt contract. An offline comparison
  command accepts two to twenty identified provider runs under the same
  contract, then reports chronological metric deltas and content-free case
  transitions; legacy response-schema-v2 files remain individually scoreable
  but cannot be compared. The corpus and prompt expansions intentionally change
  both identities, so the historical nine-case provider capture cannot be
  compared with the sixteen-case contract. Four later private captures shared
  the new identities. Two GPT-5.2/Azure runs produced a 15/16
  contradictory-confidence failure followed by a 16/16 recovery 38 minutes 11
  seconds later. Claude Sonnet 5/Bedrock then failed 15/16 on one unexpected
  refusal. Gemini 3.8 Flash/Vertex scored 13/16 because three semantically
  plausible paraphrases missed the frozen lexical fact alternatives; those
  machine failures remain failures pending human adjudication. All four were
  recorded within about 78 minutes, and the cross-model samples also changed
  upstream route. They demonstrate point-in-time variability, not long-term
  drift resistance, provider approval, or runtime evidence. This infrastructure
  did **not** ship autonomous visitor replies. ADR 0004 remains unchanged.

### Changed

- **The nginx sample now keeps proxied WebSockets open for an hour.** Add
  `proxy_read_timeout 3600s;` and `proxy_send_timeout 3600s;` to both the `/app`
  and `/apps` location blocks and reload nginx for the same coverage. The sample
  through 0.7.0 inherited nginx's 60-second upstream-read timeout; existing
  installs that do not update it retain that pre-existing behavior rather than
  becoming worse during this upgrade. Visible Wayfindr tabs now exchange a
  15-second ping/pong, which keeps ordinary quiet sessions inside the old limit;
  the proxy setting additionally covers delayed keepalives and non-Wayfindr
  clients. A browser-frozen tab still reconnects when it wakes. The Docker
  Compose stack proxies through Caddy and needs no change.

- **Email can be switched on by pasting a webhook URL.** 0.7.0 told you that no
  provider's inbound webhook could be pointed at Wayfindr and that you needed a
  re-signing proxy in front that Wayfindr does not ship. That was true when it
  was written and is no longer. Mailgun and Postmark can now be pointed straight
  at `POST /api/mail/inbound`; set `WAYFINDR_INBOUND_MAIL_PROVIDER` to name the
  scheme. **If you built that proxy, you can retire it** — the original
  `X-Wayfindr-Signature` scheme still verifies, so nothing breaks if you keep
  it. If you gave up on email in 0.7.0, this is the release to try again.
  `docs/self-hosting/inbound-mail.md` is new and covers all three.

- **The shipped image now sets PHP's upload limits** (`upload_max_filesize`,
  `post_max_size`, `memory_limit`) to match what Wayfindr itself accepts. They
  were the compiled-in 2M/8M defaults, below the application's own 10 MB limit,
  so a visitor attaching a 4 MB screenshot was refused by PHP before any
  Wayfindr code ran. If you run your own PHP rather than the image, raise them
  yourself — `post_max_size` bounds the whole request, not one file.

- **Numbers and dates follow the reading agent's language**, not the server's
  and not the browser's. Nothing changes for an English reader's *numbers*.
  Some English *dates* do shift, cosmetically: report and backup-history dates
  lose the weekday (`Mon, Aug 24, 2026` → `Aug 24, 2026`), and break-glass
  stamps move to a 12-hour clock with the year (`Aug 24, 15:05` →
  `Aug 24, 2026 3:05 PM`). The account audit list and its CSV export
  deliberately keep a sortable `Y-m-d H:i:s`, because a localized cell is
  reparsed by whatever spreadsheet opens it.

- **Busy queues now have a deliberate display boundary.** Conversation and
  ticket queues render at most 200 ordered rows while keeping uncapped lane and
  filter totals visible. Ticket attention/external-issue filtering moved into
  portable SQL before that cap, and deterministic tie-breakers keep adjacent
  pages and measurements stable instead of hydrating an entire account to sort
  it in PHP.

- **Performance claims now come with reproducible measurements.** Production-
  guarded harnesses and dated baselines cover a 50,000-conversation support desk,
  report tabs and exports, heavy-page cobrowse transport, Reverb delivery through
  200 concurrent authenticated agents, and attachment retention at a large
  object count. The published conservative Reverb operating envelope remains
  100 concurrent agents; these are bounded baselines, not universal capacity
  promises for every host.

- **Abandoned cobrowse sessions stop looking active.** Active-session queries
  now share one idle cutoff, the attention calculation hydrates recent candidates
  in bounded keyset chunks, and the scheduled expiry path uses the same boundary.
  Old retained sessions remain part of history without inflating active queue
  badges or loading every candidate into memory.


- **The widget script is served as a public asset, and is now genuinely
  cacheable.** It had been declared in `routes/web.php` since the first commit
  that served it from Laravel, which put it in the `web` middleware group. The
  response therefore carried `Set-Cookie`. A visitor's own browser cache stores
  such a response perfectly well, so the declared `max-age=60` did apply there.
  What it could not survive was the layer an operator actually puts in front of
  an asset every visitor fetches: shared caches and CDNs commonly decline to
  store a response carrying `Set-Cookie`. Removing the cookie makes the lifetime
  dependable at that layer rather than only in each visitor's own browser. It
  now carries `max-age=300` — chosen deliberately, because the URL has no
  version in it, so that number is also how long a visitor can keep running the
  previous release's widget after an upgrade — plus an `ETag`, so a revalidation
  that matches costs a bare `304` instead of roughly 97 KB gzipped. Two size
  budgets now exist where there had been none anywhere in the repository: the
  widget source is budgeted by `make self-host-test`, and the served response —
  which is not the source files concatenated, because the realtime client is
  wrapped before it is joined — is budgeted by the server test suite, which is
  the only place the controller's actual output can be measured.

- **Custom-role management now renders in German and Italian.** The catalogues
  had been complete for some time, but none of the `dashboard.account.roles.*`
  routes was listed in `DashboardLanguage::EXTRACTED_ROUTES`, so the locale
  never resolved to anything but English on that surface and none of that work
  could reach a screen. Three documents describing it as intentionally English
  have been corrected to match.


- **The retention panel now names every class of data Wayfindr deletes on its
  own.** It said automatic deletion covered "cobrowse content only", and that
  everything else stayed until an operator removed it. Two scheduled commands
  said otherwise: visitor records that never produced a conversation are pruned
  after 30 days, and the record of which proactive message reached which visitor
  after 90. Both now have their own row, in all three languages, naming the
  command and the window.

  On a privacy surface, understating what the software deletes is as wrong as
  understating what it keeps — an operator answering a subject-access request
  from that panel would have got it wrong in a way they could not detect. The
  presence row also states that its 30-day window is a ceiling rather than a
  default, because presence collects people who never asked for anything (ADR
  0019).

- **Three more copilot surfaces render in German and Italian.** The summary
  fragment was listed in `DashboardLanguage::EXTRACTED_ROUTES`; the reply draft,
  knowledge suggestion and ticket suggestion were not. All four render back into
  the conversation page, which *is* extracted, so those three resolved to English
  and injected it into a German document — 51 translated strings that could never
  reach a screen. The render audit could not have caught it: it matches
  parameterised routes by static prefix, so each fragment passed on the
  conversation index's coverage without ever being rendered.

### Fixed

- **AI evaluation now fails closed on contradictory confidence.** A provider
  response that chooses refusal while reporting confidence at or above the
  answer threshold can no longer leave the overall evaluation green merely
  because its aggregate Brier score remains within tolerance. The refusal stays
  visitor-safe, but the response-contract contradiction is now a hard failure.

- **Generated widget snippets now initialize automatically.** The bundle deferred its
  automatic setup and only then read `document.currentScript`, which is no
  longer the executing script by that point. The script loaded successfully,
  defined `window.Wayfindr`, made no API request, rendered nothing, and reported
  no error. It now captures the embedding script synchronously and carries that
  exact element into deferred initialization.

- **The installer distinguishes release-discovery failure from an absent
  release.** HTTP errors, rate limits, transport failures, malformed responses,
  and an authoritatively empty release list now produce different diagnostics.
  A fresh install whose GitHub API lookup is unavailable is directed to retry
  with an explicit `--ref <tag>`, and the public Quick Start now documents that
  escape hatch.

- **Host-source upgrades now carry the same release declaration as the official
  image.** The generic deploy flow generates and validates the target manifest,
  binds it to a truthful clean-checkout identity, caches that identity, and only
  then migrates. Forge delegates to the same writer. A dirty same-commit checkout
  is treated as a changed build instead of inheriting clean state over newly
  authored actions.

- **Release publication now fails closed at every hand-off.** A tag must point
  at frozen notes and matching committed history and have a successful full CI
  run for its exact main-branch commit. The publisher stages verified draft
  assets, verifies the exact multi-architecture image directly from GHCR, then
  publishes and re-verifies the release before moving floating image aliases.
  Publication is serialized, old tags rerun through the guarded workflow cannot
  roll aliases backward, and every external action in the write-capable
  workflow is pinned by commit. Pre-guard workflow runs remain a release blocker
  until their 30-day rerun window expires or the repository owner explicitly
  approves their removal after evidence is preserved. Publication also requires
  an active immutable `v*` tag ruleset; the September 9 audit found none, and
  changing repository settings remains a separately authorized operation. The
  guarded publisher is intentionally stable-only until a prerelease-to-stable
  operator-action contract is designed; historical dash-tag alpha support is
  not silently inherited by this stricter pipeline.

- **The unattended-alert digest was mailing a raw UTC timestamp** —
  `2026-08-24T15:05:00.000000Z`, mid-sentence — instead of a readable time on
  the recipient's own clock.

- **Blade directives were reaching the browser as text** on one page, where a
  directive written flush against a word character was never compiled.

- **The agent conversation socket** now reconnects with the same discipline the
  visitor board already had, rather than going quiet after a drop.

- **Widget rejection messages reach the visitor in their own language.** The
  widget was being handed an English sentence to display; it is now handed the
  key and says it itself, with the server sentence kept as the fallback for the
  cases a key cannot reach.

- **The cobrowse pressure indicator** no longer derives its state by comparing
  the rendered sentence against English literals — a comparison that would have
  pinned the indicator on permanently for German and Italian agents the moment
  that surface was translated.


- **The widget script no longer starts a session or sets a cookie.** Because it
  sat in the `web` middleware group, every request that actually reached it --
  each first load, and each one after the previous response's short cache
  lifetime expired -- wrote a row to the `sessions` table on installs using the
  default database session driver, and returned `wayfindr-session` and
  `XSRF-TOKEN` to a visitor who had not interacted with anything. That is a
  consent surface in a product whose visitor presence collection is deliberately
  opt-in with an explicit decline path (ADR 0019), and it applied to visitors
  who never opened the widget at all. Nothing for an operator to do; the URL is
  unchanged and existing visitor cookies simply stop being renewed.

- **Four interface defects, each of which the test suite could not see.** The
  visitors list rendered a colourless site dot, because it passed the site's
  colour as an attribute that matches no rule anywhere while the conversation
  and ticket queues supply the hue inline; it also announced as an empty element
  for want of `aria-hidden`. The custom-roles list offered an enabled delete
  button for a role a single sign-on claim mapping still names, which the server
  always refused — the list never counted mappings, so the view could not see
  that condition. Two tables lost their horizontal-overflow container by using
  class names that have no styles anywhere, and one of them, the live visitor
  board, would scroll the whole page sideways on a single long page URL.

- **A form's validation errors no longer render inside a collapsed disclosure.**
  The site-details form sits inside a `<details>` that is closed by default, so
  a rejected domain or colour told the agent nothing at all: the page reloaded,
  the change had not saved, and the explanation was hidden. The disclosure now
  opens itself when that form has errors.

- **An alert is no longer recorded as accepted before it started.** The digest
  and unattended-conversation jobs captured the delivery timestamp before
  sending and reused it as the acceptance time, so a listener stamping the start
  mid-send could produce a record accepted earlier than it began.

### Security

- **Query strings are no longer stored with the page addresses Wayfindr keeps**,
  and the ones already stored have been rewritten. A visitor carrying a password
  reset token or a session id in a URL had it kept whole and shown to an agent
  on the visitor profile, the conversation, the ticket snapshot, and — longest
  of all — the cobrowse session, which keeps addresses by design after pruning
  strips everything else.

  **The rewrite is intentionally irreversible**, which is the point: the query
  strings are gone.

  It runs automatically, and it needs two passes rather than one. On a
  zero-downtime deploy the migration runs while the *previous* release is still
  serving, so rows written after the sweep passed them keep their query strings
  and the migration reports success anyway. The Forge deploy script runs
  `wayfindr:sanitise-page-urls` after activation to catch exactly those, and the
  scheduler runs it daily besides — which is what covers Docker and Compose
  installs, since they never run that script. A working one-minute scheduler is
  already part of Wayfindr's runtime contract. On a host where that pre-existing
  requirement is missing, the migration still removes the query strings it can
  see; after repairing the scheduler, run `wayfindr:sanitise-page-urls` once to
  close the narrow old-request race immediately. The command is idempotent and
  reports nothing on every run after the first.

  `audit_events` is deliberately **not** rewritten. An audit trail you rewrite
  is not an audit trail; the protection there is that the account audit screen
  and its export carry only time, action, actor, subject and site, and never raw
  event metadata.

- **Inbound mail deliveries are bound to the message they carry.** Mailgun signs
  a timestamp and a token and not the body, so a captured signature would
  otherwise authenticate any payload for as long as it stayed fresh. A token is
  now good for one message however many times the provider redelivers it, and a
  delivery that differs in sender, recipients, subject, body, threading headers
  or attachments is refused.

- **Signing in is rate limited, and only failures count.** It was the one
  unauthenticated credential endpoint with no limit at all, so an attacker could
  grind a known agent's address at whatever rate the host allowed. Ten failures
  against the same address from the same source now buy a fifteen-minute wait,
  and a correct password clears the count.

  Counting *failures* rather than requests is the part that matters for a
  support desk. The `throttle` middleware counts every request, so ten agents
  arriving at shift start behind one office NAT would spend a shared budget
  between them and the eleventh would be refused while typing the right
  password — a lockout wearing a rate limit's clothes. There is deliberately no
  per-source-only bucket for the same reason, and the key is hashed because
  `cache.key` is a 255-character column and a valid-but-long address composed
  raw into a key would fail the insert and return a 500 instead of a login.

## [0.7.0] - 2026-08-25

**No operator action required.** Pull, restart, and migrations run themselves.

**One note if your desk is busy.** This release adds ten migrations. All are
additive and none needs a decision from you, but five of them build indexes —
on `visitors`, `conversations`, `conversation_messages`, `tickets` and
`audit_events`. PostgreSQL blocks writes to a table while a non-concurrent index
builds, so on an install with a large message or audit history the migration
step may pause the desk for longer than you are used to. On a small install it
is instant. We have not measured where the line is; take the upgrade at a quiet
hour if that matters to you.

0.6.0 changed how Wayfindr looks. **0.7.0 changes what it can do.** It is the
largest functional release since the product went public: a support desk that
could only be reached through a chat widget can now be reached by email, answers
questions before they are asked, tells visitors when nobody is home, and — for
the first time — can tell you whether any of it is working.

Seven of the nine gaps that separated Wayfindr from an established support
product are closed in this release. The two that remain are live visitor
monitoring, which needs a privacy decision before it is built, and the second
half of interface language support.

### Added

- **Reporting.** Wayfindr had no measurement surface at all. It now has one, at
  **Reports**, admin and owner only: conversation and ticket volume, first
  response and resolution times as median and 90th percentile, how often
  resolutions did not hold, who is carrying the queue, and what visitors said
  when asked whether it helped.

  **Some of these numbers are older than the others, and the page says so.**
  Conversations opened, first-response times and agent replies are recoverable
  from data Wayfindr has always kept.

  **Conversation** closes, resolution times and reopens are not: they are read
  from a lifecycle log that starts being written in this release, because before
  it the previous answer was destroyed on every reopen and cannot be backfilled.
  **Ticket** closes and reopens have been audited for far longer, so an upgraded
  desk can describe months of ticket work while its conversation figures are
  still accumulating. The page states each boundary separately, and a flat line
  before one of them is an absence of records rather than an absence of work.

- **Satisfaction ratings.** Every other figure reports how *fast* the desk moved.
  A desk can improve volume, first-response and resolution time all at once while
  getting worse at helping people. Visitors can now be asked whether it helped,
  and the percentage is never reported over people who said nothing.

- **A help centre.** Articles written in the dashboard, published deliberately,
  and searchable from inside the widget — so a visitor can find the answer before
  they open a conversation.

- **Email as a conversation channel — with a caveat worth reading first.** Every
  ticket used to have to begin as a widget chat, and a customer replying to a
  Wayfindr notification was replying into nothing. Mail can now open and continue
  conversations.

  **You cannot point a mail provider straight at it yet.** Wayfindr accepts one
  signature scheme, its own, and no provider emits it — so using this today means
  running a small intermediary that verifies your provider and re-signs, and
  Wayfindr does not ship one. The mechanism is real and tested; the last mile to
  your provider is not built. It is tracked as
  [#799](https://github.com/adamgreenwell/wayfindr/issues/799) and is a 1.0.0
  blocker rather than a nice-to-have.

  If you have that intermediary, or you are posting to Wayfindr from your own
  code, here is the contract. **Three things, and missing the third fails
  silently:**

  1. Set `WAYFINDR_INBOUND_MAIL_SECRET`. Until you do, the endpoint answers `404`
     to everything — an open endpoint that writes conversations is worse than one
     an operator has to enable.
  2. Point your provider's inbound webhook at `POST /api/mail/inbound`, and have
     it send an `X-Wayfindr-Signature` header of exactly
     `sha256=<hex HMAC-SHA256 of the raw body, keyed with that secret>`. **The
     `sha256=` prefix is part of the value**, not a description of it — a bare
     hex digest is rejected. A bad or unprefixed signature answers `401`.

     The payload is read flexibly rather than in one fixed shape: sender from
     `from` / `From` / `sender`, recipient from `to` / `To` / `recipient` /
     `OriginalRecipient` (and the `Cc` equivalents), subject from `subject` /
     `Subject`, body from `body` / `TextBody` / `body-plain` / `stripped-text`,
     threading from `in_reply_to` / `In-Reply-To` and `references` /
     `References`, and **the delivery's own id from `message_id` / `MessageID` /
     `Message-Id` / `message-id`**. Mailgun's and Postmark's field *names* are
     both recognised.

     **Their signatures are not.** `X-Wayfindr-Signature` is Wayfindr's own
     scheme, and no provider emits it: Mailgun signs a timestamp and token with
     its own key, Postmark does not HMAC the body with your secret at all. So a
     provider's inbound webhook cannot be pointed straight at this endpoint —
     every delivery answers `401`. Something has to sit in front that verifies
     the provider's own signature and re-signs the body as `sha256=<hex>`. That
     is a small function or worker, and Wayfindr does not ship one yet.

     **Send the message id even though nothing rejects you for omitting it.** It
     is what makes a redelivery safe: a provider that retries after a timeout or
     a lost response is recognised and ignored. Without it, the retry is treated
     as a new email — a second conversation, or a threaded reply inserted twice.

     Only the **sender** and **recipient** fields decide whether a delivery is
     usable at all. The rest have defaults, which is a separate problem
     described in step 3.
  3. **Give each site the address mail arrives at**, under **Sites → the site →
     Email to this site**. A delivery whose recipient matches no site's address
     is answered `200 Ignored` — deliberately, so a provider does not retry
     forever — which means a correctly signed webhook can look perfectly healthy
     while creating nothing at all.

     **Three things can go wrong after a delivery is signed correctly, and none
     of them says so in the response.**

     - *Nothing appears, response says `200 Ignored`.* Either the sender address
       is unusable, or the recipient matches no site. Nothing was created.
     - *A conversation appears but is empty or misthreaded, response says
       `200 Accepted`.* The sender and recipient mapped; the **body**, **subject**
       or **threading** fields did not, so the delivery was accepted with
       defaults. A conversation reading `(no message text)`, or a reply that
       opened its own conversation instead of joining the original.
     - *Duplicates appear on a retry.* The message id was not sent — see step 2.

     A `404` or a `401` means the secret or the signature, and both say so.

  Outbound replies use your existing mail configuration.

- **Support hours, an away state, and offline capture.** The widget behaved
  identically at 3pm Tuesday and 3am Sunday. A site can now say when it is open,
  in **its own timezone**, show the words you choose when it is closed, and still
  take the question. Somebody can also close the desk early and have it mean
  something.

- **A pre-chat form.** Sites that need to know who is asking can ask before the
  conversation reaches the queue, with the fields they choose.

- **Password recovery.** There was no forgot-password route. Recovery meant an
  operator with production shell access running a command. Agents can now
  recover their own password.

  **Configure and test outbound mail before you rely on this.** Laravel's default
  mailer writes to the log rather than sending, and the reset form tells the
  agent a link is on its way either way — so an install without working mail
  gives a locked-out agent a dead end that looks like success. The operator
  console's mail settings include a send test; use it.

- **Per-site widget appearance.** A site's widget can wear its own colour, sit in
  the corner it chooses, and speak the operator's own words.

- **The widget speaks the visitor's language.** Every word in the chat box was
  English, hardcoded. A visitor did not choose Wayfindr and cannot be asked to
  read a language they do not speak — they arrived on somebody's website with a
  question.

  The widget now carries a language catalogue, and **German ships complete**. It
  picks a language before it draws anything: your own page's choice first (add
  `data-wayfindr-locale="de"` to the install snippet if your app knows what the
  visitor reads), then the visitor's own browser, then the site default under
  **Sites → the site → What language the widget speaks**, then English.

  The browser deliberately outranks the site default. The default is your guess
  at who visits; the browser is the visitor answering for themselves.

  **Nothing changes unless you want it to.** With no default configured the
  widget follows each visitor's browser and falls back to English, exactly as
  before. Your own words — the away message, the intake introduction, the
  cobrowse notice — are shown as you wrote them, in whatever language you wrote
  them.

- **The dashboard speaks German, on some surfaces.** An agent can choose their
  language under **Profile**, and four surfaces follow it: the profile page
  itself, the conversation queue, the ticket list, and the app shell around
  them — the rail, the topbar and the search.

  **The conversation detail page does not yet.** It was planned for the next
  release after 0.7.0. A page
  that has not been translated renders in English rather than showing a
  half-translated screen, and the shell renders in whatever language the page
  it is framing does. So an agent who chooses German sees German on the queue
  and English the moment they open a conversation. That is deliberate and it is
  visible; it is not a bug you need to report.

- **A visitor directory.** The desk can now list the visitors it has heard from,
  with a path into each profile and any live conversation. Live presence — who is
  on the site *right now* — is not part of this and is recorded in ADR 0016,
  because it is the first surface that makes Wayfindr's visitor data feel like
  surveillance and needs a retention decision before it is built.

- **A read-only public API.** The API surface was previously the widget talking
  to its own backend, plus inbound webhooks from GitHub, GitLab and Jira — there
  was nothing an account could call on its own behalf. There is now an
  authenticated public surface with a decided isolation model, documented in
  ADR 0018. Writes are deliberately not included yet.

### Changed

- **Conversation closes and reopens are recorded from this release forward.**
  `conversations.closed_at` was a current-state column, nulled on every reopen,
  so the previous answer was destroyed each time. There is now a lifecycle log.
  Nothing you do changes because of this, but reporting can only measure from the
  date your install began keeping it, and the Reports page names that date.

- **The test suite runs against PostgreSQL as well as SQLite.** CI ran SQLite
  only, while every documented install runs PostgreSQL — so a query valid on
  SQLite and invalid on Postgres could ship green. Both engines now run on every change. This is invisible in
  the product and is the reason several of the above features are trustworthy.

### Fixed

- The installer's closing message pointed at the readiness page's old address.
  It still redirects, so nobody was stranded — but the last thing a fresh
  install printed was a detour rather than the page that owns readiness now.

## [0.6.0] - 2026-08-21

**No operator action required.** Pull, restart, and migrations run themselves.

The interface renovation described in ADR 0014 lands in this release. **The
dashboard looks substantially different** — a permanent sidebar instead of a row
of tabs, a new typeface, denser queues, a colour per site, and a dark mode. Your
data, URLs, and the install snippet on your pages are untouched; this is the same
application wearing a different face. Screenshots of the queue, a conversation,
dark mode, and the operator console are on the
[0.6.0 release page](https://github.com/adamgreenwell/wayfindr/releases/tag/v0.6.0).

The operator side got the same treatment, plus a pass over its wording: pages
are named for what they do rather than for the mechanism behind them, and the
console is grouped into tabs instead of one long scroll. **One access change is
worth reading before you upgrade** — the readiness report is now operator-only.
It is the first item under *Changed*.

### Added

- **Every site now has a colour.** Wayfindr is built for one desk covering many
  sites, so the question an agent asks all day is *whose visitor is this?* A
  colour answers it faster than a repeated site name. Pick one per site under
  **Sites → Edit name and domain**; it appears wherever that site does, and on
  the widget its visitors see.

  Existing sites are assigned a colour automatically when you upgrade, in
  creation order, so each of your sites starts out distinct from the others.
  Nothing to configure unless you want to change them.

- **Move through the queue without going back to it.** Opening a conversation
  used to be a round trip: read it, return to the queue, find your place, open
  the next one. A conversation now carries the queue it came from — previous and
  next, your position in it, and a menu of the others — so a run of work is one
  pass instead of many. It follows the queue you actually used, including its
  filters and search, and it stays out of the way when you arrive from a
  notification or a support-code lookup, where there is no queue to speak of.

### Changed

- **The readiness report is now for operators only.** `/dashboard/readiness`
  reported on the installation itself — mail, queues, storage, attachment
  scanning, debug mode, the commands to run — and any account administrator
  could open it, while every settings page that could act on what it said
  refused them. It also let them record readiness confirmations, attesting on an
  operator's behalf that something about the install had been checked.

  It now lives only in the operator console. The old address still works and
  takes you there, so existing links are not dead ends; anyone who is not a
  platform operator is refused, the same as anywhere else under `/operator`.
  Nothing is lost — the console shows everything that page did.

  ⚠ **Operator action** if someone who is *not* a platform operator was using
  that page: give them the platform operator role, or have an operator take over
  readiness checks. On an install set up the documented way the first account
  owner is already the initial platform operator, so most installs are
  unaffected.

- **The operator console is grouped into tabs.** It had grown to sixteen
  sections on one page. They are now five, named for the question being asked —
  *Overview*, *Health*, *Go live*, *Data* and *Access* — and the tabs carrying
  something that needs attention say so with a count. Overview opens first and
  carries the summary and the single recommended next step, so the most
  important thing is still the first thing.

- **Operator pages say what they do.** Several were named after the machinery
  behind them rather than the job in front of you. *Break-glass* — a security
  term for the emergency envelope on the wall — is now **Operator access**, and
  the screen opens by telling you what it guarantees: an operator cannot see any
  account's conversations or tickets, and asks for read-only, expiring access
  that the account approves, watches and can end.

  Elsewhere *Dogfood readiness* is now **Before real support traffic**,
  *Post-install smoke path* is **Prove the install works**, *Readiness proof
  coverage* is **What you have confirmed**, and *Retention posture* is **How
  long data is kept**. Status wording is consistent across every readiness
  surface. Nothing changed about what these pages check or record — only what
  they are called, including in your account's audit log, where operator access
  events used to read as "Break Glass Resource Viewed".

- **The operator console stopped describing a feature it already has.** It still
  said customer-data access was "Not enabled" and would require scope, expiry,
  approval and audit "before it exists". That shipped a release ago with exactly
  those four properties.

- **The operator console is part of the application now.** Every operator page —
  the console, the setup checklist, mail, storage, scanning, backups and
  operator access — used to render outside the dashboard entirely, with a single text
  link at the top of each page to get back out. They now sit in the same shell as
  everything else, with their own list of sections down the side, so moving
  between them no longer means going back to the console first.

- **A conversation looks like a conversation.** The chat widget has always shown
  replies as a back-and-forth. The agent's own view of the same exchange stacked
  every message full width in one column, so the two halves of a conversation
  looked like different things and only the agent got the log. Visitor messages
  now sit on the left carrying their site's colour, replies sit on the right, and
  delivery state sits on the message it belongs to.

  A message with no text and no attachment now says so, instead of rendering as an
  empty box with a timestamp in it.

- **The widget follows the visitor's colour scheme.** A visitor whose device is
  set to dark mode now sees a dark chat panel, and it wears its site's colour on
  its top edge. It takes its colours from the same source the dashboard does, so
  the two cannot drift apart again.

- **Every queue is readable again.** Conversations, tickets, alerts and sites all
  used to stack bands of controls above the first row and give each row about
  130 pixels. Lanes now carry their own counts, filters sit on one line, and rows
  are less than half the height &mdash; the same screen shows roughly four times as
  much without dropping anything it used to tell you.

  Colour now means something everywhere. A calm row is no longer marked in amber:
  red appears where somebody is waiting, and each row carries its site's colour as
  a stripe so you can tell whose work it is at a glance.

- **The dashboard's typeface now ships with the application.** IBM Plex is
  served from your own install rather than fetched from a font CDN, so an
  install on `localhost`, on a bare IP, or behind a firewall renders the same as
  one on a public domain. Previously a font host was configured but never
  actually used, so every install already fell back to the system stack.

### Fixed

- **Long commands stay inside their card.** A setup command wide enough to
  exceed the page — the support-loop script, with a real host page URL in it —
  stretched its whole list past the edge of the card and made the page scroll
  sideways. Commands now scroll within their own box.

- **The dashboard no longer calls an install ready while something is
  unconfirmed.** The support checklist reported "Ready for visitors" whenever
  nothing had outright failed, even though the scheduler check can only ever be
  confirmed by a person and so was always outstanding. It now says *Nearly
  ready* until the last item is confirmed.

- **Smaller console corrections.** The recommended next step printed its
  explanation twice when that step came from the setup path; a summary line
  reported counts using different words than the labels directly above it; and
  a link to a specific tab scrolled it underneath the header.

### Removed

- **The unused frontend build.** Wayfindr shipped a Vite and Tailwind pipeline
  that no page ever loaded: it was built on every release and referenced by no
  template. Removing it takes an entire Node stage out of the released image.

  If you deploy manually rather than from the published image, the `npm ci` and
  `npm run build` steps in the deploy flow are gone — see
  `docs/self-hosting/runtime-requirements.md`. They were already doing nothing;
  removing them is safe on any install. Nothing else about deploying changes.

## [0.5.0] - 2026-08-18

**No operator action required.** Pull, restart, and migrations run themselves.

Nothing changes for a site you leave alone, and the install snippet on your
pages is untouched. Everything below is new capability on the site settings
screen.

### Added

- **A site can be renamed, and its domain corrected.** Both were fixed at
  creation and could never be changed afterwards, so a typo in a site's name was
  permanent. Neither field identifies the site — the widget works from the site's
  public key — so renaming cannot interrupt a live install, and the snippet
  already on your pages keeps working untouched.

- **A site can be archived when you stop supporting it.** Archiving takes the
  site out of service: the widget stops answering for it everywhere and
  immediately, including for visitors who already have it open. The site also
  leaves the working site list, the conversation and ticket queues, and the
  dashboard, so retired work stops competing for attention with live work.

  Nothing is deleted. Every conversation, ticket, visitor and audit record stays
  exactly where it was, and restoring the site puts it straight back into
  service. Archived sites stay reachable under the site list's **Archived**
  filter, and stay selectable in the audit log, because their history outlives
  their being in service.

- **A site can be permanently deleted.** This destroys the site and everything
  recorded beneath it — conversations, messages, attachments, tickets, visitors,
  cobrowse history, and the site's own audit trail. It cannot be undone; the only
  way back is a backup taken beforehand.

  It is deliberately awkward to do by accident. The site must be archived first,
  so a deletion can never cut off a visitor mid-conversation; only an account
  owner can do it, where archiving needs only an admin; and the site's name has
  to be typed exactly to confirm, against a summary of what will be destroyed. A
  record of the deletion — who did it, which site, and how much it contained — is
  kept against the account afterwards, outliving the site it describes.

The self-hosting installation and troubleshooting guides now also cover
installing at a local, private, or IP address, which 0.4.0 introduced.

## [0.4.4] - 2026-08-14

**No operator action required.** Pull, restart, and migrations run themselves.

Your install snippet changes, but the one already on your pages keeps working.
Copy the new one from the site settings screen when convenient — see below.

### Changed

- **The widget no longer loads anything from a third party.** Its realtime
  library came from a public CDN (`js.pusher.com`), which meant an install
  without outbound internet lost live updates with nothing to explain why, a
  host page with a strict content-security policy could not load it, and every
  visitor's browser contacted an external service to use support chat. The
  library now ships inside `widget.js`, so a self-hosted install serves every
  byte it runs and only your own host needs allowing through.

  The install snippet on the site settings screen is now a single `<script>`
  tag. Existing pages carrying the older two-tag snippet continue to work —
  the extra tag is simply redundant — so replace it whenever it suits you.

  The bytes are not new: pages with realtime already downloaded this same
  library, from further away, in an extra request. Installs without realtime
  configured carry nothing extra.

## [0.4.3] - 2026-08-14

**No operator action required.** Pull, restart, and migrations run themselves.

### Fixed

- **The widget's live updates now connect.** They never did: the realtime
  client requires a `cluster` setting even when the server address is given
  outright, and without one it failed while being created — on every install,
  whatever the address. Visitors fell back to polling for new messages, so
  replies arrived within a few seconds rather than instantly, and typing
  indicators never appeared at all. Nothing reported it, because the failure
  was swallowed silently until 0.4.2 began logging it.

## [0.4.2] - 2026-08-14

**No operator action required.** Pull, restart, and migrations run themselves.

### Fixed

- **A visitor's message is no longer reported as failed after it was
  delivered.** If the widget's realtime connection threw while starting up,
  the composer reported "Message could not be sent" for a message the server
  had already accepted and stored. Visitors retried, sending a duplicate that
  also succeeded and also reported failure. Only a genuinely rejected send is
  reported as one now.
- **An agent's reply reaches the widget even when realtime is unavailable.**
  The message poll that exists as the fallback for realtime was scheduled
  after the realtime connection in the same unguarded sequence, so a fault
  there removed the fallback along with it — leaving a visitor no route at
  all for a reply. Realtime setup is now isolated and the poll always runs, so
  a reply arrives within a few seconds regardless.
- **Faults inside the widget are written to the browser console.** They were
  caught deliberately, so a visitor never sees a stack trace over a chat box,
  but they were caught *silently* — leaving nothing to diagnose from. They now
  log what failed while the visitor-facing behaviour stays unchanged.

## [0.4.1] - 2026-08-14

**No operator action required.** Pull, restart, and migrations run themselves.

If you installed 0.4.0 at an **IP address** and could not reach it, this is
the fix. `./wayfindr/install.sh --upgrade` also repairs the existing
environment file for you.

### Fixed

- **An install whose address is an IP now completes a TLS handshake.** 0.4.0
  obtained a certificate for such hosts and then refused every connection —
  browsers reported `ERR_SSL_PROTOCOL_ERROR` — because a client connecting to
  an IP address sends no SNI, leaving Caddy with no name to select a
  certificate by. The certificate was issued correctly and never served,
  which is why the logs looked healthy and the installer reported success.
  A default SNI is now configured for every locally-issued certificate.
- **Upgrading repairs an environment file generated by 0.4.0.** The file is
  preserved across upgrades because it holds your secrets, so the new setting
  would otherwise be missing and the install would restart, pass its health
  check, and stay unreachable. ⚠ **Operator action** if you run the Compose
  stack by hand rather than through `install.sh`: add
  `CADDY_GLOBAL_OPTIONS_EXTRA=default_sni <your host>` to your env file, using
  the same value as `SERVER_NAME` (without brackets, for an IPv6 address).
- **The certificate-export command can be copied and pasted.** It was printed
  across two lines joined by a trailing backslash, which only continues a line
  when a newline follows immediately — pasted as one line it escaped a space
  instead and failed with `unknown docker command`. It is the first command a
  local-certificate operator runs. Corrected in the installer output and in
  both self-hosting guides.

## [0.4.0] - 2026-08-14

**No operator action required.** Pull, restart, and migrations run themselves.

Existing installs are untouched. The environment file is preserved whenever
the installer re-runs, so an install that is already serving keeps the URL,
the published ports, and the certificate arrangement it was set up with.
Everything below applies to installs created from here on.

### Added

- **Local and internal hostnames are supported install targets.** Hosts that
  no public certificate authority can issue for now receive a certificate
  from Caddy's own CA instead of attempting an ACME challenge that could only
  fail: `localhost`, IP addresses, the reserved suffixes (`.localhost`,
  `.local`, `.internal`, `.home.arpa`, `.test`, `.example`, `.invalid`,
  `.onion`, `.alt`), and any single-label name. Because no challenge is
  involved these are **not** restricted to port 443 the way a publicly-issued
  certificate is, so `https://localhost:2345` is a valid install URL.

  Browsers warn until that CA root is trusted. The installer prints the
  command that exports it when it applies, and
  [docs/self-hosting/install.md](docs/self-hosting/install.md) covers adding
  it to a trust store. The root lives in a Docker volume, so it survives
  upgrades and only needs trusting once per client machine.

- **`--app-url` accepts a bare host** and infers the scheme: loopback hosts
  become `http://`, everything else `https://`. The URL it settled on is
  printed rather than assumed silently, so `--app-url localhost` works and
  says what it did.

### Fixed

- **The URL given to `--app-url` is now the URL the stack serves**, including
  its port. Plain-HTTP installs previously published on a hardcoded
  `127.0.0.1:18080` whatever the URL said, so `--app-url http://localhost`
  reported a successful install and printed a `/setup` link on a port nothing
  was listening on. The URL's port now drives the published port, and its
  host decides the interface: loopback publishes on loopback only, any other
  host on all interfaces, as its URL implies.
- **`--app-url localhost` is accepted.** It was previously rejected outright
  for having no scheme.
- **`--behind-proxy` no longer claims the port it tells you your proxy owns.**
  With a custom public port matching one of the stack's internal loopback
  ports, the stack published that port itself, so a proxy binding all
  interfaces could not take it. ⚠ If you run behind your own proxy, point it
  at the value of **`WAYFINDR_LOCAL_BIND`** in your environment file rather
  than assuming `127.0.0.1:8000` — it is usually that, but it moves when your
  own public port would collide with it, and the installer now prints the
  address to use. Existing installs keep whatever value they already have.
- **The installer reports the URL recorded in the environment file** rather
  than the one passed on the command line. Re-running an existing install
  with a different `--app-url` previously printed the new URL while the stack
  carried on serving the original one.
- **Malformed URLs are refused instead of producing a broken install.**
  Addresses that no client will open (`1.2.3.256`, `[1::2::3]`,
  `http://localhost:80:90`), hosts containing characters a hostname may not
  contain, names with labels beyond the DNS length limits, embedded
  credentials, and query strings or fragments were all previously accepted:
  the stack started, the health check passed, and the operator was handed a
  setup URL that could not be opened.

## [0.3.2] - 2026-08-11

**No operator action required.** Pull, restart, and migrations run themselves.

### Fixed

- Empty self-hosting storage volumes are now initialized by a one-shot root
  helper before the non-root app services start. This preserves Docker `nocopy`
  protection against first-boot copy races while keeping Laravel's writable
  storage tree owned by the `wayfindr` user.

## [0.3.1] - 2026-08-11

**No operator action required.** Pull, restart, and migrations run themselves.

### Changed

- PHP 8.4 is now the declared minimum everywhere, matching the dependency lock
  and the official self-hosting image. This corrects the previous documented
  floor; it does not introduce a new runtime requirement beyond what the
  published dependency set already enforced.
- The self-hosting Compose stack now mounts the shared application storage
  volume with Docker `nocopy`, avoiding a first-boot race when app services are
  created in parallel and letting the entrypoint create Laravel's storage tree.

### Security

- Refreshed the locked PHP dependency set to clear current Composer advisories
  in Guzzle, Laravel, League CommonMark, and Symfony. Source-based self-hosters
  should install from the reviewed lock file after updating; official images
  receive the refreshed dependencies through the normal release artifact.
- Composer now resolves dependency updates against PHP 8.4.1 so automation sees
  the patched PHP 8.4 floor required by the refreshed Symfony packages.

## [0.3.0] - 2026-08-11

**No operator action required.** Pull, restart, and migrations run themselves.

### Added

- Releases can now carry **advisory notices** — things worth telling you about
  that are not worth stopping your upgrade over. They appear on the operator
  console, in `wayfindr:upgrade-guard`, and in the installer's upgrade output,
  and they block nothing. Where a notice can be checked automatically it
  disappears on its own once the thing is done.
- The first one: **run a queue worker on the `backups` connection**. Without it,
  "Run a backup now" queues a job nothing will process and the run sits at
  *Running* indefinitely. Scheduled backups are unaffected. Compose stacks run
  this worker themselves; host-managed installs (Forge and similar) need one.

  **Get the command from `/operator/settings/backups`** rather than copying it
  from here. That page fills in *your* configured queue name and timeout, and
  `BACKUP_QUEUE` can move the backups queue off its default — a copied
  `--queue=backups` would then start a worker draining a queue nothing dispatches
  to, leaving backups queued forever after you did exactly as you were told. With
  stock settings it is:

  ```bash
  php artisan queue:work backups --queue=backups --sleep=5 --tries=1 --timeout=3600
  ```

  This is the same requirement 0.1.0 described in prose. It is now checked
  against a real worker heartbeat, and the check has **three** answers rather
  than two:

  - **A worker has been seen** — nothing is reported. If you already run one you
    will not hear about this at all.
  - **No worker has been seen** — the advice appears, which is the case it exists
    for.
  - **This install cannot tell** — the advice appears *and says so*. A worker
    records its heartbeat in the cache, so an `array` or `null` cache driver
    cannot carry that sighting from the worker process to the web process. Rather
    than guess, it reports that it could not check; you may well already have a
    worker running.

  That third answer is deliberate. Reporting "no worker" because of a cache
  driver would blame you for a configuration that is none of this check's
  business, and treating "cannot tell" as a pass would hide a genuinely missing
  worker.

  The installer is the exception, and deliberately so: it prints this advice
  while upgrading whether or not you need it, because it runs *before* the
  release is installed and cannot evaluate the check. Treat what it prints as
  "this release advises", not as a finding about your install — that is how it is
  worded. The running install is what tells you whether it actually applies.

  To silence it without running a worker, add
  `<release>/backups-queue-consumer` to `WAYFINDR_ACKNOWLEDGED_ACTIONS`; that is
  honoured everywhere, including the installer.

  Worth being explicit about why this is advisory rather than enforced: the only
  way to enforce it would have been to refuse traffic, and taking a whole support
  platform down because a *backup* worker was missing is not a proportionate
  answer. A release that can only shout or stay silent will eventually shout at
  the wrong time.

### Fixed

- **The upgrade-floor refusal no longer tells you how to defeat it.** When an
  install has no recorded release, that refusal offers `WAYFINDR_UPGRADE_FROM` so
  you can state where you are — and it used to suggest setting it to the floor
  version itself. Since that value is trusted as your stated origin, following
  the suggestion cleared the very check being explained: an install genuinely too
  old to upgrade directly would migrate on a path whose migrations no longer
  ship. It now asks for the release you upgraded *from*, and says plainly that
  stating a version below the floor is still refused.
- **A current install is no longer told it is too old.** `minimum_upgrade_from`
  produces two different refusals — *you are demonstrably below the floor* and
  *nothing records where you are, so it cannot be checked* — and the refusal you
  meet during a migration printed the first for both. An install that was
  perfectly up to date but had lost its release-state file was told it was "older
  than this release allows" and sent off to reinstall an ancient version, with no
  mention of the override that would have cleared it in a line. The two are now
  distinguished wherever they are reported.
- The Compose stack's backup worker now follows `BACKUP_QUEUE`. It ran with a
  hard-coded `--queue=backups` while the application dispatched to whatever
  `BACKUP_QUEUE` named, so an operator who changed it had a worker draining a
  queue nothing was sent to — every GUI-triggered backup stuck at *Running*,
  with no error anywhere. Only installs that set `BACKUP_QUEUE` were affected.

## [0.2.0] - 2026-08-10

**No operator action required.** Pull, restart, and migrations run themselves.

This is the first release you can upgrade *to* across a release boundary with a
published manifest on both sides, so it is also the first real exercise of the
upgrade guard added in 0.1.0. It deliberately declares nothing: the point is to
prove the mechanism on a release where being wrong costs nothing.

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
