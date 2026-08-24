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

Nothing yet.

## [0.7.0] - 2026-08-24

**No operator action required.** Pull, restart, and migrations run themselves.

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

  **Two of these numbers are older than the others, and the page says so.**
  Conversations opened and first-response times are recoverable from data
  Wayfindr has always kept. Closes, resolution times and reopens are read from a
  lifecycle log that only started being written in this release — before that,
  the previous answer was destroyed on every reopen and cannot be backfilled. A
  flat line before that date is an absence of records, not an absence of work,
  and only the recording date the page names can tell the two apart.

- **Satisfaction ratings.** Every other figure reports how *fast* the desk moved.
  A desk can improve volume, first-response and resolution time all at once while
  getting worse at helping people. Visitors can now be asked whether it helped,
  and the percentage is never reported over people who said nothing.

- **A help centre.** Articles written in the dashboard, published deliberately,
  and searchable from inside the widget — so a visitor can find the answer before
  they open a conversation.

- **Email as a conversation channel.** Every ticket used to have to begin as a
  widget chat, and a customer replying to a Wayfindr notification was replying
  into nothing. Mail can now open and continue conversations.

  **This is off until you switch it on, and it needs more than a restart.** The
  inbound endpoint refuses every request until `WAYFINDR_INBOUND_MAIL_SECRET` is
  set — an open endpoint that writes conversations is worse than one an operator
  has to enable. Once set, point your mail provider's inbound webhook at
  `POST /mail/inbound` and have it sign each delivery with an `X-Wayfindr-Signature`
  HMAC-SHA256 of the raw body using that secret. Without the secret the endpoint
  answers `404`; with a bad signature, `401`.

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
  language under **Profile**, and the queues, the ticket list, the app shell and
  the profile page follow it. **The conversation detail page does not yet** — it
  stays English until 0.7.1, and pages that have not been translated render in
  English rather than showing a half-translated screen. This is deliberate and
  visible: you will see German in some places and English in others.

- **A visitor directory.** The desk can now list the visitors it has heard from,
  with a path into each profile and any live conversation. Live presence — who is
  on the site *right now* — is not part of this and is recorded in ADR 0016,
  because it is the first surface that makes Wayfindr's visitor data feel like
  surveillance and needs a retention decision before it is built.

- **A read-only public API.** Every route was previously the widget talking to its
  own backend. There is now an authenticated public surface with a decided
  isolation model, documented in ADR 0018. Writes are deliberately not included
  yet.

### Changed

- **Conversation closes and reopens are recorded from this release forward.**
  `conversations.closed_at` was a current-state column, nulled on every reopen,
  so the previous answer was destroyed each time. There is now a lifecycle log.
  Nothing you do changes because of this, but reporting can only measure from the
  date your install began keeping it, and the Reports page names that date.

- **The test suite runs against PostgreSQL as well as SQLite.** Every documented
  install runs PostgreSQL and CI ran neither, so a query valid on only one engine
  could ship green. Both engines now run on every change. This is invisible in
  the product and is the reason several of the above features are trustworthy.

### Fixed

- The installer's closing message pointed at a readiness page that had moved,
  so the last thing a fresh install printed was a link to nothing.

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
