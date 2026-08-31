# 0016: Observing visitors

Date: 2026-08-22

> **§1, §2 and §4 are amended by
> [ADR 0019](0019-presence-for-visitors-who-have-not-made-contact.md)
> (2026-08-26).** The deferral in §4 has been answered: Wayfindr will observe
> visitors who have not made contact, on a per-site operator switch, with
> visitor-facing disclosure and bounded retention. So §1's "loading a page does
> not record a visitor" is now the default rather than an invariant.
>
> **§2 is amended with it.** Read as written — the index lists people who made
> contact — it contradicts ADR 0019, which requires the index to include
> presence-only visitors on an opted-in site. An implementer working from this
> page alone could satisfy §2 by omitting exactly the visitors presence exists
> to show, and be correct by one document and wrong by the other. §2 now reads:
> the index lists visitors the install has a record of, which is people who made
> contact plus, on an opted-in site, people presence recorded. **Its scoping
> rules are untouched** — account and per-site assignment still decide who sees
> which visitors.
>
> §3 stands unchanged.

## Context

[#747](https://github.com/adamgreenwell/wayfindr/issues/747) asks for live visitor
monitoring: who is on the site right now, what page they are on, how long they have
been there. It reads as assembling data Wayfindr already holds, and describes the
visitor *show* route having no index as the gap.

Investigating first changed the shape of the work.

### The data does not exist

`visitors.last_seen_at` is written in exactly four places, all widget-facing:
bootstrap, conversation creation, message fetch or send, and typing. In the widget,
`client.bootstrap()` is called from three places — opening the panel, the first send,
and resuming a stored conversation — and **never on script load**.

A visitor who loads a page and never touches the launcher produces **no `visitors`
row at all**. Today the table means *people who opened the chat*, not *people on the
site*.

`metadata.last_page_url` is written only at bootstrap and conversation start, so it
is the page where chat was opened rather than where somebody is now. Time on site
has no backing field whatsoever.

So the feature as described needs a **page-load heartbeat**: new collection about
people who never made contact.

### That crosses a line this product already drew

[ADR 0005](0005-cobrowse-shared-page-state.md) and
[cobrowse data boundaries](../privacy/cobrowse-data-boundaries.md) state that before
consent the widget "must not send page snapshots, mutation batches, or cobrowse
telemetry". Page state is gated on the visitor agreeing to share it.

A board showing a visitor's current page and time on site, for somebody who never
made contact, is page-state reporting with that gate removed.

Wayfindr's stated advantage over the one competitor shipping anything comparable is
that its version is consent-based rather than remote control. Watching everybody by
default spends exactly that.

Nothing in `docs/decisions`, `docs/privacy` or `docs/product` states a position on
observing visitors who never made contact. It is undecided in the strong sense: the
one adjacent statement points the other way.

## Decision

### 1. Wayfindr records a visitor when they make contact, not when they arrive

Opening the widget, starting a conversation, sending or fetching a message, and
typing all record a visitor. **Loading a page does not**, and no page-load heartbeat
is added.

This is now a deliberate property rather than an implementation detail nobody had
examined.

### 2. The visitor index lists people who made contact

An index over the visitors we already hold, scoped the way every other list is:
account, then per-site agent assignment, with any site filter validated against what
the agent may already see.

It answers "who has been in touch", which is the question the missing index actually
left unanswerable. It does not claim to answer "who is on the site".

The empty state says so in as many words, because a list that silently omits most
visitors is worse than one that explains what it counts.

### 3. Presence keeps its existing meaning, in one place

Active is two minutes, recent is fifteen — the cutoffs the conversation queue and
the visitor profile already use, now in `VisitorPresence` rather than written out
three times. `SiteInstallHealth`'s thirty minutes stays separate: it answers whether
the widget is installed and reporting at all.

The list adds no new signal. A visitor last seen twenty minutes ago reads as quiet,
which is honest, because Wayfindr genuinely does not know whether they are still
reading.

### 4. Anonymous page-load presence is deferred to its own decision

If Wayfindr is to observe visitors who never made contact, that needs answering
first:

- **Disclosure.** Does the visitor know? The cobrowse precedent is explicit consent,
  not a privacy policy paragraph.
- **Retention.** `visitors` has no pruning today. A page-load heartbeat changes row
  growth from "chat openers" to "every unique visitor", and the data inventory
  already records that automatic retention does not ship yet.
- **Proportion.** Is it worth what it costs the product's position?

Proactive messaging ([#757](https://github.com/adamgreenwell/wayfindr/issues/757))
depends on the answer, so this cannot stay open indefinitely — but it is a product
decision, not a slice of work.

## Consequences

**The index is honest about being partial**, and that is a permanent property until
the decision above is taken. An operator comparing it to a competitor's live board
will find fewer people on it. The empty state and documentation say why.

**A `(site_id, last_seen_at)` index is added.** Every existing index on `visitors` is
a point lookup — two unique keys and one on email — so a list filtering and ordering
on `last_seen_at` scanned every visitor ever recorded for the site.

**Tester visitors are excluded** by the `tester-site-%` prefix, the way
`Site::latestVisitor()` already excludes them. Without it an agent watches themselves
browse.

**Presence has one definition and two names.** `Visitor::presenceState()` still
returns `unknown` for a visitor never seen, while the filter vocabulary calls it
`not_reported`. The name is in the realtime presence payload and the views reading
it, so it is translated at that boundary rather than changed underneath them. Worth
unifying; not worth breaking a broadcast contract in passing.
