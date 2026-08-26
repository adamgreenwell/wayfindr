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

Declining is remembered per site in the same storage the anonymous id already
uses, and a declined visitor reports nothing — not a reduced payload, nothing.

**The first report waits for the disclosure to exist on the page.** Reporting
before the visitor could have seen the notice is the same defect as not having
one, arriving a few hundred milliseconds earlier.

### 3. What is reported, named exhaustively

Sent: the site's public key, the visitor's existing anonymous id, and the
current page URL after sanitising.

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
keeps a current-visit start alongside `last_seen_at`, set when a heartbeat
arrives from a visitor whose last one is older than the fifteen-minute *recent*
window, and left alone otherwise.

That reuses a cutoff the product already has rather than inventing a session
length: a gap long enough to read as *quiet* is long enough to call the next
report a new visit. Time on site is then the distance between the two fields,
and both are server-stamped.

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
- **The host, port and path are kept.** They are the answer to "which page",
  which is the only question this field exists for.
- **The query string is dropped in full**, unless the site has named specific
  parameters to keep — and then only those, and only when their value is a
  scalar.
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

### 4. Retention, which this product does not currently have

`visitors` has no pruning today, and the
[data inventory](../privacy/data-inventory.md) says so. A page-load heartbeat
changes row growth from "chat openers" to "every unique visitor", which makes
the absence of retention a defect rather than a gap.

So this ships with the product's **first automatic retention control**:

- A visitor who has **never made contact** — no conversation, no ticket, no
  message — is deleted after **30 days**. An operator may shorten that; they may
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

ADR 0016 §4 is answered rather than deferred. §2 and §3 stand: the index still
scopes by account and per-site assignment, and presence keeps the two-minute and
fifteen-minute cutoffs in `VisitorPresence`.

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
