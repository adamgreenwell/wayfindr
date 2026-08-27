# 0019: Presence for visitors who have not made contact

Date: 2026-08-26

Supersedes part of [ADR 0016](0016-observing-visitors.md) — see *What this
changes in 0016* below.

## Context

[ADR 0016](0016-observing-visitors.md) §4 deferred anonymous page-load presence
and named the three things that had to be answered first: **disclosure**,
**retention**, and **proportion**. It also said the deferral could not stand
indefinitely, because [proactive messaging](https://github.com/adamgreenwell/wayfindr/issues/757)
depends on the same answer.

That decision has now been taken: Wayfindr will observe visitors who have not
made contact, on the conditions below. This ADR records the conditions, because
"yes" without them is the version that quietly spends the product's position.

The thing being decided is not small. Today `visitors` means *people who opened
the chat*. After this it means *people on the site*, and that is a different
table with different growth, a different privacy weight, and a different answer
to "what does this product know about me".

## What makes this different from cobrowse, and what does not

[ADR 0005](0005-cobrowse-shared-page-state.md) and the
[cobrowse data boundaries](../privacy/cobrowse-data-boundaries.md) gate page
state on explicit consent: before consent the widget "must not send page
snapshots, mutation batches, or cobrowse telemetry".

Presence is a weaker gate than that, and the difference has to be argued rather
than assumed:

- **Cobrowse sends the page's contents** — the DOM, its mutations, what the
  visitor typed. It is the visitor's screen.
- **Presence sends the page's address** — that somebody is here, and where
  "here" is.

A URL is still page state, and on some sites it carries more than a location.
That is why the reporting rules below are specific rather than "we send the
page".

What does **not** differ: both are things the visitor did not ask for. So
presence is gated too — just at the level its disclosure warrants, rather than
with a consent dialogue nobody would grant for being counted.

## Decision

### 1. Off until an operator turns it on, per site

Presence reporting ships disabled. An operator enables it per site, the way
[pre-chat intake](../product/support-hours-and-intake.md) and the
[rating prompt](../product/satisfaction.md) already work.

A desk that has not chosen to watch does not watch. This also means the default
install has exactly the privacy posture it has today, and upgrading changes
nothing until somebody decides otherwise.

### 2. The visitor is told, in the widget, and can decline

Disclosure is not a paragraph in a privacy policy the visitor will not read. When
presence is on, the widget carries a visible statement that this site can see
which of its pages you are on while the widget is loaded, and a control to stop
reporting.

**The notice covers the record, not only the moment.** It said the site can see
which of its pages you are on *while this widget is loaded*, which describes
live visibility and stops there — while §4 keeps the visitor's record for thirty
days after their last heartbeat, specifically so a later visit can be recognised
as a return. A visitor could therefore be told the truth about what is visible
and nothing about what is kept, which is the half they would more likely object
to. The disclosure names the retention and the recognition.

**Outside the panel, beside the launcher.** The notice began inside the panel,
which was the wrong place by the same logic that justifies the whole feature:
the visitors presence exists to see are the ones who never open the widget, so a
notice that appears only when they do is an explanation offered exclusively to
the people it does not apply to. It is on the page, from the first heartbeat,
for as long as reporting continues.

That is a real cost — a small persistent element on every page of an opted-in
site, which is more of a footprint than this product usually asks for. It is the
price of §1 being an honest opt-in rather than a quiet one, and it is bounded:
the sites that pay it are the sites that chose it.

Declining is remembered per site in the same storage the anonymous id already
uses, and a declined visitor reports nothing — not a reduced payload, nothing.

**And when that storage is unavailable, presence stays off.** An embed may pass
`storage: null`; private browsing and locked-down browsers reject writes;
`storageSet()` swallows the failure and `resolveAnonymousId()` already falls back
to an in-memory id. In all of those the widget cannot remember a decline across a
navigation, so a visitor who declined would be reported again on the next page.

Fail closed, because the alternative is a control that appears to work and does
not. If we cannot keep a "no", we do not get to assume a "yes".

**The first report waits for the disclosure to be PAINTED**, not merely to
exist. Reporting before the visitor could have seen the notice is the same
defect as not having one, arriving a few hundred milliseconds earlier — and
being in the document is not being visible, because the element is inserted with
its styles unresolved and a report sent in the same turn beats the browser to
the screen.

**A decline binds the visitor, not the tab.** It is re-read from storage before
every heartbeat, and a cross-tab storage event stops the other tabs at once. The
same site is routinely open several times over, and "Stop sharing" writes a
site-wide key while only being able to stop the instance that was clicked — so
without this, a visitor watched their own decline fail in every other tab. The
re-read is the guarantee, because storage events are not delivered in every
embedding; the event is the promptness.

**Configuration reaches the widget from an endpoint that writes nothing.** It
cannot come from bootstrap, which is the natural place to put it: bootstrap runs
only when the panel is opened, and it records that opening as contact. Asking it
whether to watch people who have not made contact answers the question by
destroying it.

That read has **its own throttle**, `WAYFINDR_WIDGET_CONFIG_RATE_LIMIT`
(default 600 a minute, keyed by site and address like the rest). It shared
bootstrap's budget until presence needed it on every page load rather than every
panel opening — at which point passive browsing from one office could exhaust
the allowance that lets somebody start a conversation, and the visitor who tried
would be refused for other people's page views. The response is identical for
everyone on the site and writes nothing, so it is cheap to serve and safe to
allow generously.

**A tab that stays open picks up a revised setting**, and the channel that
matters is the heartbeat's own answer. Bootstrap carries the settings too and
updates them underneath a running reporter without restarting it — but bootstrap
only runs when somebody opens the panel, and the visitor this feature exists for
never does. Their config read happened once, at page load, and would otherwise
be the newest answer that tab ever got: an operator turning presence off would
leave precisely the targeted population reporting until they navigated away.

So **every heartbeat is answered with the settings in force**, and the refusal
answer takes the same shape as the accepted one, so a widget already reporting
learns from it that it should stop.

**The bound this achieves is one report, not zero**, and that is worth stating
plainly rather than implying otherwise. A tab learns from a response, so it
learns after sending: a change made between two heartbeats is acted on at the
next one, and the heartbeat already in flight was built under the old setting.
Reaching zero means either asking before every report — doubling the request
volume of the one endpoint every visitor hits continuously — or pushing
configuration to widgets over a socket, which is a great deal of machinery for a
window measured in seconds. One report at the previous setting, bounded by the
cadence, is the trade this takes.

Rejecting the write server-side does not substitute for this. By the time the
request arrives the address has already crossed the wire, which is the whole
reason the widget sanitises before sending rather than trusting the endpoint to
clean up afterwards.

Settings arriving this way are merged key by key. A key the answer does not
carry means *unchanged*, not *allowed* — assigning wholesale would let a partial
response put addresses back on the wire that an operator had switched off — and
a body that is not recognisable as configuration is ignored rather than read as
permission.

### 3. What is reported, named exhaustively

Sent: the site's public key, the visitor's existing anonymous id, and the
current page URL after sanitising.

**Sanitised in the widget, before the request is built** — and again on the
server. Not belt and braces: a server-side pass alone means the raw URL has
already crossed the wire, and a query string that reached us is a query string
that reached proxies, access logs, error trackers and anything else on the path.
Removing a token after transmitting it is not removing it.

The server pass stays because the endpoint is public and the client cannot be
trusted to have done it. Client-side is what keeps the secret off the wire;
server-side is what makes the stored value true regardless.

**No timestamp, and no duration.** `last_seen_at` is stamped from server receipt
time, exactly as bootstrap, conversation start, message fetch and typing already
do. A widget
timestamp would be a value the endpoint cannot verify: a browser with a skewed
clock reports presence that is wrong in both directions, and this endpoint is
public and unauthenticated beyond the site key, so a forged one could park a
row far enough in the future to sit "active" indefinitely and outrun the
thirty-day prune. Presence is a claim about when WE heard from somebody, which
is the only part of it we actually know.

**Not sent, and not to be added without another decision:** DOM or page
contents, form values, scroll or viewport geometry, referrer chains, device or
browser fingerprints, IP-derived location.

The URL is sanitised before it is stored, and this ADR specifies what that
means rather than leaving it to the implementation:

- **Only `http` and `https` survive at all.** Every other scheme is discarded,
  including ones that parse perfectly: these values are rendered as clickable
  `href`s on the agent ticket page, the widget endpoints are public, so the URL
  is attacker-controlled and an agent is the target.
  `javascript://evil.test/%0Aalert(1)` has a scheme, a host and a path and is
  indistinguishable from a page address to any check that is not an allowlist.
  Requiring a host is not a scheme rule — it rejects `javascript:alert(1)` and
  waves the version with a host straight through.
- **The host must belong to the site**, and is dropped otherwise — with the
  whole address, since a path without a trustworthy host answers nothing. The
  endpoint is public and the site key is public, so an attacker can submit
  `https://attacker.example/login`; stored page addresses are rendered as
  clickable `target="_blank"` links on the agent ticket page, which makes an
  unchecked host a phishing delivery mechanism aimed at agents. A site with no
  configured domain therefore stores no page address at all: we cannot tell its
  own pages from anybody else's, and guessing is the failure mode this rule
  exists to prevent. `SiteInstallHealth` already treats the configured domain as
  how a site is identified.
- **The port and path are kept, with opaque segments redacted.** A path is the
  answer to "which page" — but it is also where this very product puts a
  credential: `/reset-password/{token}` is a route in this repository. A segment
  that looks like an identifier rather than a word is replaced with `…`, so
  `/reset-password/9f2c…` still says which page without saying which token.

  The test is deliberately crude, and it is a heuristic rather than a proof: a
  UUID, anything 32 characters or longer, or anything at least **six**
  characters carrying a digit with no word separator.

  Six, not twenty, and the difference matters more than it looks. Twenty is
  long enough to feel safe and is not: the dangerous values are frequently
  short. `/invite/A1B2C3` and `/orders/123456` are both a credential in a path
  on real sites, and a rule waiting for twenty characters keeps them whole.

  A hyphenated slug survives because it has a separator; a word without digits
  survives at any length; a version segment survives because it is short. The
  cost is real and named: `/product/iphone15` is redacted, which loses an agent
  some context on some sites. That is the right way round for a rule whose
  failures are credentials — and it is exactly why the query string is dropped
  WHOLE rather than filtered by this same kind of guessing.

    **When it is off, the notice says so.** A site reporting without page
  addresses sends only "somebody is here", and a disclosure still claiming
  otherwise is untrue and a worse explanation than none — it describes a sharing
  the visitor cannot decline on account of it not happening.

  **And switching it off clears what was already stored.** The control exists
  for operators whose paths carry secrets, so "from now on" is the wrong scope:
  a visitor who never heartbeats again would keep the address that prompted the
  change for the rest of the retention window. Conversation history is left
  alone — that is a support record somebody wrote in about, and deleting it
  because a collection setting changed is a different decision from the one
  being taken.

**And it is not enough, which is why there is a switch.** Three rounds of
  broadening this rule taught the same thing each time: there is no shape that
  separates a short lowercase token from a short lowercase word. `/invite/abcdef`
  is indistinguishable from a page name, and a rule strict enough to catch it
  redacts the vocabulary routes are named after.

  So a site can report presence **without page addresses at all**. It is on by
  default, because which page is most of the value and a site with nothing
  sensitive in its paths should not have to opt in to the ordinary case. A site
  that puts invitation codes, order numbers or reset tokens in path segments
  turns it off and gets presence that says somebody is here and nothing more.

  That is the answer a heuristic cannot be: the operator knows what their own
  routes contain, and this decision does not.

  **The same rule runs in the widget, before the report leaves the browser.**
  Redacting on arrival is too late to be the promise it sounds like: by then the
  value has crossed every proxy in between and landed in access logs on both
  sides. The server keeps its copy of the rule because the widget is not the
  only writer, and the two must not drift — a disagreement shows up as page
  addresses that change shape depending on which path they took.
- **The query string is dropped in full.** No exceptions, and no per-site
  allowlist — see below.
- **The fragment is dropped.** It never reaches a server in an ordinary
  navigation, so a widget reporting one is reporting something the page chose to
  put in front of us. Single-page apps route with it; they also carry tokens in
  it, and there is no way to tell which one is in front of you.
- **Credentials in the authority (`user:pass@`) are dropped.** The URL is
  rebuilt from the parts named above rather than edited as a string, so anything
  not named cannot survive.
- **An unparseable URL is discarded rather than stored as-is.** An input that
  cannot be reasoned about is the one most likely to be carrying something odd.

Dropped rather than filtered by name, deliberately. Filtering means guessing
which parameter names are dangerous, and the dangerous ones are frequently the
shortest — `?t=`, `?k=`, `?c=`. A name-based rule fails exactly where it matters
most, and fails silently.

**And dropped whole rather than with an allowlist**, which is a correction to an
earlier draft of this ADR rather than the original intention. The sanitiser can
take a list of parameters to keep, and offering one per site looked like a
reasonable kindness to an operator whose URLs carry a plan or a campaign.

It cannot work alongside the guarantee that matters more. Sanitising also
happens in a model `saving` hook, so that no writer — including one holding a
value read before a cleanup — can put a query string back. That hook runs
without knowing which site a row belongs to and can only apply the strict rule,
so a kept parameter would be stripped again by the next ordinary save and the
operator would watch their configuration vanish for no visible reason.

A rule that no stored page address ever carries a query string is absolute,
assertable in one line, and cannot be got wrong by a writer that has not been
written yet. The allowlist is worth revisiting only if the hook is given a site.

**None of this existed when this ADR was first written, and saying so is the
correction that matters most in this document.** `VisitorContextSanitizer`
sanitises the host-provided `context` array; it never touched the URL.
`mergeMetadata()` assigned `last_page_url` verbatim, guarded only by Laravel's
`url` rule and a length cap — so the first draft named a safeguard the codebase
did not have, which is exactly the reassurance an ADR is supposed to stop.

It is now real: `VisitorPageUrl`, shipped in
[#804](https://github.com/adamgreenwell/wayfindr/pull/804). Presence depends on
that landing rather than restating the intention.

**The same address is stored in three places, and every one of them counts.**
Naming only the first is how the first version of that PR shipped a fix that
looked complete:

| where | what it is |
| --- | --- |
| `visitors.metadata.last_page_url` | where they are now |
| `conversations.metadata.started_page_url` | where they asked from |
| `tickets.metadata.visitor_context.{last,started}_page_url` | a snapshot, taken at ticket creation |

The entry-page copy is the likelier leak of the two live ones, because people
ask for help *from* the page that is going wrong. The ticket copy is the one
that outlives the rest: it is a point-in-time snapshot rather than a reference,
so sanitising the sources it was copied from never reaches it, and tickets
outlive the conversations that produced them.

Presence adds a fourth writer to that list. It sanitises for the same reason and
through the same class.

**Presence LABELS read the website column too, not only the visit boundary.**
Separating the columns stopped an email starting a website visit and stopped
there: `Visitor::presenceState()` and the conversation queue's presence filter
still read the cross-channel timestamp, so an email correspondent showed as
*active* on their profile and in the filter while they sat in their mail client
— and an agent would offer to cobrowse with a browser that was not open. Both
now read `last_web_seen_at`. `last_seen_at` keeps its own question, which is the
one the directory's *Last seen* column asks: when did we last hear from this
person by any means.

**Two windows on the same site are one visitor, and the last report wins.**
The anonymous id belongs to the browser, not to the tab, so a visitor with two
non-minimised windows on different pages has both reporting and alternately
overwriting the current page. The board will show them moving between two pages
they are not navigating between.

Accepted rather than solved. Giving each tab its own identity means a row per
tab, a retention story per tab, and a board that shows one person as several —
which is a worse answer to "who is on the site right now" than an occasionally
oscillating page. The page is the most recent report, and that is all it claims
to be.

### 3a. How often, and when a visit begins

Two values the payload does not carry and the feature cannot work without.

**The heartbeat reports every 45 seconds** while the page is loaded and the
visitor has not declined. The number is not free: `VisitorPresence` calls a
visitor inactive after two minutes, so any cadence at or near that cutoff makes
a continuously present visitor flicker between *active* and *quiet* for most of
every interval. Comfortably under the cutoff, with room for one lost report, is
the requirement — 45 seconds gives two chances to stay inside two minutes.

A hidden tab reports nothing. `visibilitychange` is the honest signal that
somebody is not looking, and presence decaying to *quiet* while a tab sits in
the background is correct rather than a gap to paper over.

**A visit needs its own start**, because `last_seen_at` cannot answer "how long
have they been here" — updating it destroys the very thing the question needs —
and `visitors.created_at` answers "when did we first ever see them", which is a
different question that #747 also asks under *returning or new*. So the server
keeps a current-visit start alongside `last_seen_at`, set when a report arrives
from a visitor who has **no** previous one, or whose previous one is older than
the fifteen-minute *recent* window. Left alone otherwise.

**Maintained by every writer of the website sighting, not by the heartbeat.**
Presence is not the only one: bootstrap, conversation start, message fetch and
typing all stamp it, and a returning visitor whose page reloads a stored
conversation hits bootstrap *before* their first heartbeat. A rule living in the
presence endpoint would then see a timestamp refreshed seconds ago, keep the
previous visit's start, and report a visit spanning days. The transition
therefore belongs to the model, computed from the value being replaced, so it
holds for writers nobody has written yet.

**But not every writer of `last_seen_at`, which is a different column.** That
one answers "when did we last hear from this person by any means", and inbound
mail stamps it: somebody who emails support has certainly been seen. Keying the
visit boundary off it put an email correspondent on the board as present, with a
time-on-site counting up, while they sat in their mail client — and an agent
acting on that would try to watch a browser that is not there.

Asking "have they ever used the widget" does not separate the two. The harder
case is a real visitor who browsed last week and today replies to an email
notification: they have an `anonymous_id`, and the reply would resume a visit
from days ago.

So **`last_web_seen_at` is the website fact and the visit start is maintained
from it**, while mail writes the cross-channel column directly and never reaches
the visit logic. Web writers set only the website column; the model derives the
cross-channel one from it and never moves it backwards. Converged there rather
than required of each writer, because a writer that eventually sets one and
forgets the other makes somebody look out of contact while they are on the site,
and that failure is silent.

A relationship update is not a writer the model can reach. `visitor()->update()`
is a mass update and dispatches no model events, so a writer using one is
outside this rule however well the rule is written — which is not a caveat but a
requirement on the writers: they save the model.

### 3b. What the heartbeat costs, and the throttle it needs

`docs/product/widget-api-abuse-controls.md` requires a named, tunable throttle
on every public widget route, and this one cannot borrow the shape the others
use.

Every other widget limiter is keyed by **site and source IP**, which is right
for endpoints a visitor hits occasionally. This is the one every visitor hits
*continuously*: at 45 seconds a single open tab is 1.33 requests a minute and 80
an hour. A shared per-IP bucket therefore divides the quota by the number of
people behind the address, and an office, a school or carrier NAT exhausts it at
roughly sixteen simultaneous visitors.

The symptom would not arrive as a bug report. Valid heartbeats take a 429 and
those visitors flicker to inactive on the board, which reads as the feature
being unreliable rather than as a limit being hit.

**Two limits, therefore.** `WAYFINDR_WIDGET_PRESENCE_PER_MINUTE` (default 30) is
the everyday quota and is keyed **per visitor** — about twenty tabs' worth of
one browser, since tabs share an anonymous ID.
`WAYFINDR_WIDGET_PRESENCE_PER_IP_PER_MINUTE` (default 1200) stays keyed by site
and address as the **abuse cap**, covering roughly nine hundred simultaneous
visitors behind one address at the standard cadence.

**And a third, on creating rows rather than on traffic.** The two above bound
requests, which is the cheap half. A forged client rotating anonymous IDs turns
every accepted report into a visitor that lives for the whole of §4's retention
window, so a per-address ceiling sized for a busy office is millions of durable
rows a day when it is spent on creation.
`WAYFINDR_WIDGET_PRESENCE_CREATIONS_PER_IP_PER_MINUTE` (default 30) bounds that
directly, keyed by address and site — an attacker choosing a fresh ID every time
has an unlimited supply of per-visitor buckets and exactly one address.

Refreshing somebody the site already knows is not counted: it costs nothing
durable, and throttling it would make the board wrong for precisely the visitors
it is right about. Over the limit the report is not stored and the client is not
told, because a 429 there reports how much quota is left to whoever is probing.

An office where everybody arrives at nine exceeds thirty new visitors a minute
briefly; those visitors are recorded on their next heartbeat rather than lost.

**And a daily budget, because a burst limit is not a sustained one.** Thirty a
minute held all day is 43,200 rows and roughly 1.3 million across the retention
window — so the allowance that makes an office work is, by itself, a licence to
grow the table without end. `WAYFINDR_WIDGET_PRESENCE_CREATIONS_PER_IP_PER_DAY`
(default 2000) sits far above any real site's new visitors from one address in a
day and far below what an unattended script reaches by lunchtime. It is checked
before the minute limit is spent, so an exhausted day does not silently consume
the burst counter as well.

Because §1 keeps all of this off until an operator turns it on, the surface is
only ever the sites that chose it.

The first clause is not pedantry: a presence-only visitor's opening heartbeat
has nothing to be "older than", so a rule written only around the gap never
starts a visit at all and every new visitor is left without the field the board
needs.

That reuses a cutoff the product already has rather than inventing a session
length: a gap long enough to read as *quiet* is long enough to call the next
report a new visit. Time on site is then the distance between the two fields,
and both are server-stamped.

### 4. Retention, which this product does not currently have

`visitors` has no pruning today, and the
[data inventory](../privacy/data-inventory.md) says so. A page-load heartbeat
changes row growth from "chat openers" to "every unique visitor", which makes
the absence of retention a defect rather than a gap.

So this ships with the product's **first automatic retention control**:

- A visitor **recorded by presence and only by presence** is deleted **30 days
  after their last heartbeat**, measured from `last_seen_at` rather than
  `created_at`.

  **Positive evidence, never an inference from absence.** "No conversation and
  no ticket" reads like "never made contact" and is not: `BootstrapController`
  creates a visitor the moment somebody *opens* the widget, which §1 of
  [ADR 0016](0016-observing-visitors.md) counts as contact. A pruner reasoning
  from absence would delete every one of those older than the window — on every
  install, including ones that never enabled presence, irreversibly, on its
  first scheduled run.

  So a row carries a `presence_only` flag that defaults to **false**. Every row
  written before this existed is therefore safe by construction; only the
  presence endpoint sets it, and only when creating; and **every path that
  constitutes contact clears it** — opening the widget, and starting a
  conversation, which is its own public route and does not require bootstrap to
  have run.

  The flag is one of three conditions, not the whole test. The pruner also
  requires no conversations and no tickets, in the selecting query and again
  under the row lock before it deletes. A visitor whose flag was somehow left
  set after they wrote in is still not deletable; the flag being accurate
  matters because the record should not say something untrue about them, not
  because it is the only thing standing between them and a cascade.

  **Switching presence off deletes what it collected.** Not "stops collecting
  and lets the rest age out": leaving the rows for thirty days means the visitor
  directory still listing people who never made contact, on a site whose
  operator has just revoked the setting that collected them, while every surface
  describing that list says otherwise. Only rows this feature created and nobody
  has since been in touch through — somebody who arrived as a heartbeat and
  later wrote in is a contact and stays.

  Which timestamp is the whole rule. Measured from `created_at`, somebody first
  seen 31 days ago is deleted while they are on the site heartbeating, and
  reappears seconds later as a brand-new visitor — so the board loses them
  mid-visit and *returning or new* starts lying. Measured from activity, a row
  is only ever removed once nobody has been behind it for a month. An operator may shorten that; they may
  not lengthen it, so 30 days is not merely the default but the product's
  maximum retention for presence-only rows, and the data inventory can state it
  as a fact.

  Thirty rather than a vaguer "short", because the number is the decision: it
  has to be long enough for *returning or new* to mean something on the board
  [#747](https://github.com/adamgreenwell/wayfindr/issues/747) asked for, and
  short enough that somebody who wandered past a site once is not still on
  record a quarter later. A visitor returning after 31 days reads as new, and
  that is the honest trade rather than an oversight.
- A visitor who **has** made contact is untouched by this. They are support
  history, and support history is not presence data.

Pruning is load-bearing rather than housekeeping: without it, enabling presence
grows a table forever. It ships in the same release as the collection, not after
it.

### 5. What this does not become

- **Proactive or triggered messaging** stays out of scope
  ([#757](https://github.com/adamgreenwell/wayfindr/issues/757)). This decision
  unblocks it; it does not perform it.
- **Journey history.** Presence answers where somebody is, not everywhere they
  have been. Storing the path is a second decision with its own retention
  question.
- **Geo-IP or enrichment.** Still explicitly out, as
  [#747](https://github.com/adamgreenwell/wayfindr/issues/747) said.

## What this changes in 0016

ADR 0016 §1 — "Wayfindr records a visitor when they make contact, not when they
arrive" — was written as a deliberate property. It is now **conditional**: true
for every install by default, and untrue for a site whose operator has enabled
presence and whose visitor has not declined.

**ADR 0016 §2 is amended, not preserved.** It defines the visitor index as
listing people who made contact, and this decision requires that index to
include presence-only visitors on a site that has opted in. Saying §2 stands
would leave two requirements that cannot both be met, and an implementation
could satisfy the older one by omitting exactly the visitors this exists to
show. So §2 now reads: the index lists visitors the install has a record of,
which is people who made contact plus — on an opted-in site — people presence
recorded. Its scoping rules are untouched: account and per-site assignment
decide who sees which visitors, and that is unchanged.

ADR 0016 §4 is answered rather than deferred. §3 stands: presence keeps the
two-minute and fifteen-minute cutoffs in `VisitorPresence`.

## Consequences

**The visitor index stops being partial, conditionally.** ADR 0016 made the
index honest about listing only people who made contact, and its empty state
says so. On a site with presence enabled that sentence becomes wrong, so the
copy has to know which mode it is in rather than stating one of them as a fact.

**The data inventory's retention posture is no longer accurate.** "The current
application does not yet ship automatic data retention controls" has to change,
and the visitors row has to say what is now collected and for how long.

**Presence is a new reason for a `visitors` row to exist**, so anything that
counts visitors — reporting, the directory, install health — is counting a
different population than before on sites where it is on. Every such surface has
to be read again with that in mind; a number that silently changes meaning is
worse than one that changes visibly.

**Tester visitors are excluded**, as ADR 0016 already required. A heartbeat makes
this sharper: without the `tester-site-%` exclusion an agent on the tester page
becomes a row on the live board every time they load it.

**The exposure this ADR guards against already exists, in three fields rather
than one.** Page addresses were stored whole at bootstrap, at conversation
start, and in the ticket snapshot taken from both — and shown to agents in the
visitor panel, the conversation panel and the ticket trail. On a site whose URLs
carry tokens, that data is in the database today, before any of this ships.

So the sanitiser §3 requires is not a rule for the new path. It applies to every
writer of a page address, and the historical rewrite covers every column holding
one, because a rule that protects the page a visitor is on now while the page
they opened chat from keeps its query string is not a rule. That is
[#804](https://github.com/adamgreenwell/wayfindr/pull/804), and presence becomes
the fourth writer to go through it.

**Cobrowse stores page addresses too, and this ADR does not settle those.**
`cobrowse_sessions.metadata` and the audit events taken from it hold the URL a
visitor was on when they granted, and that is a different consent context: they
agreed to share their page, which is the whole feature. Whether agreeing to
share a page also means keeping its credentials for the life of an audit record
is a real question and a separate one — it is tracked rather than answered
here.

**The honest summary for an operator** is that turning this on changes what
Wayfindr collects about people who never spoke to them, and that is why it is a
switch with a sentence next to it rather than a default.
