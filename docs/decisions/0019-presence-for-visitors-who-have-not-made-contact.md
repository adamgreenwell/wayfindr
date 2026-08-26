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

Sent: the site's public key, the visitor's existing anonymous id, a timestamp,
and the current page URL after sanitising.

**Not sent, and not to be added without another decision:** DOM or page
contents, form values, scroll or viewport geometry, referrer chains, device or
browser fingerprints, IP-derived location.

The URL goes through the same sanitiser that already handles
`metadata.last_page_url`, because a query string is where a host site puts
password-reset tokens, invitation codes and email addresses. A feature that
answers "which page" must not accidentally answer "with what credentials".

### 4. Retention, which this product does not currently have

`visitors` has no pruning today, and the
[data inventory](../privacy/data-inventory.md) says so. A page-load heartbeat
changes row growth from "chat openers" to "every unique visitor", which makes
the absence of retention a defect rather than a gap.

So this ships with the product's **first automatic retention control**:

- A visitor who has **never made contact** — no conversation, no ticket, no
  message — is deleted after a bounded window. The default is short, and the
  operator can shorten it.
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

**The honest summary for an operator** is that turning this on changes what
Wayfindr collects about people who never spoke to them, and that is why it is a
switch with a sentence next to it rather than a default.
