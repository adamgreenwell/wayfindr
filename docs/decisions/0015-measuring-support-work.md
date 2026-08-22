# 0015: Measuring support work

Date: 2026-08-22

## Context

Wayfindr has no reporting surface. Zero of 39 controllers is one, and
`routes/web.php` carries no reporting route. [#742](https://github.com/adamgreenwell/wayfindr/issues/742)
raises that, and [#741](https://github.com/adamgreenwell/wayfindr/issues/741)
argues it should come first in its tier, because nothing else can be prioritised
honestly without measurement — including whether that list is right.

Before designing a surface, we established what the data can currently answer.
It is less than expected, and **asymmetric between tickets and conversations**.

### Tickets already carry their history

Ticket lifecycle transitions are audited. `AgentTicketController::recordActivity()`
writes `ticket.closed`, `ticket.reopened` and `ticket.pending`, and
`ReopenPendingTicketsForVisitorReply` writes `ticket.visitor_replied` carrying
`previous_status`. `audit_events.occurred_at` is indexed, and
`Ticket::latestLifecycleEvent()` already reads it with `ofMany`.

So ticket resolution time and reopen rate are computable retrospectively, with
one gap: `ticket.created` is written only when a ticket is created from a
conversation, so other tickets fall back to `tickets.created_at`.

### Conversations carry none of it

Conversation lifecycle is **not audited at all**. `Conversation::auditEvents()`
exists, but the only writers are attachment events. There is no
`conversation.closed`, no `conversation.reopened`, nothing.

What exists instead is `conversations.closed_at`, a single nullable timestamp
that is **nulled on reopen**. It is a current-state field wearing a history
field's name: it answers "when did the close I am presently in begin", and is
`NULL` for anything not closed right now.

Worse, three paths reopen a conversation *silently*:

- an agent reply force-fills `status: open, closed_at: null`,
- a **visitor** message does the same, and
- neither writes anything.

A visitor replying to a closed conversation is a reopen — arguably the most
interesting one, because it means the resolution did not hold — and it leaves no
trace distinguishable from any other message.

**Conversation resolution time and reopen rate are therefore not computable at
all, retrospectively or otherwise.**

### The part that cannot wait for a reporting surface

History cannot be backfilled. Every day the product runs without recording
conversation lifecycle is a day of measurement permanently lost, whatever we
build later. That makes the recording decision urgent in a way the reporting
screen is not.

## Decision

### 1. Conversation lifecycle is recorded as audit events, not as columns

Mirroring what tickets already do, rather than adding `reopened_at` /
`reopen_count` columns.

Columns would summarise; events record the sequence. "Closed twice, reopened by
the visitor both times, then closed a third time" is a different story from
`reopen_count = 2`, and it is the story a support lead needs. The
`audit_events` table already exists, is indexed on `occurred_at`, and has the
`ofMany` idiom for reading the latest per subject.

Actions: `conversation.closed`, `conversation.reopened`. Both carry
`previous_status` in metadata, following `ticket.visitor_replied`.

### 2. A silent reopen is recorded as a reopen

The agent-reply and visitor-message paths that flip a closed conversation back
to open write `conversation.reopened` with the actor that caused it — including
when that actor is a `Visitor`. `audit_events.actor` is already a nullable morph
and `ticket.visitor_replied` already records a visitor as an actor, so this
needs no new shape.

A reply to an already-open conversation writes nothing. Only a transition is an
event.

### 3. `closed_at` stays as it is

It is load-bearing for current behaviour and reads correctly as "currently
closed since". Renaming or repurposing it would be churn; the history now lives
where history belongs.

### 4. Reporting reads the audit trail, and is account-scoped like the audit page

When the surface is built it reads `audit_events`, and it copies the scoping of
`AgentAccountAuditController`: `account_id` pinned, `site_id` restricted to a
pre-resolved allowlist of visible sites, and any user-supplied site filter
validated against that allowlist before use. A user-supplied id must never widen
scope.

### 5. Indexes come with the reporting surface, not before it

Every composite index on `conversations` and `tickets` is `(scope, status)` —
right for the queue, useless for "everything in this account in Q3". Neither
table has an index on `created_at`, and `conversations` has no `account_id`
column at all, so account-scoped time queries must join through `sites`.

Adding indexes now would be guessing at query shapes that do not exist yet. They
are part of the reporting slice, chosen against its real queries.

## Consequences

**Measurement starts accumulating immediately**, before any screen exists. This
is the point: the screen can be built whenever, but the history it will read has
to start now.

**Reporting will be able to answer more about tickets than conversations for a
while.** Tickets have retrospective history; conversations only have it from this
release forward. Any surface must say which is which rather than presenting a
short conversation series as though it were complete.

**Purging a site removes its history.** `audit_events.account_id` and `site_id`
are `cascadeOnDelete`, and `SitePurge` deletes site data. That is correct for a
product that promises purge means purge, and it means reporting totals can
legitimately decrease. Reporting must not treat that as corruption.

**Conversation lifecycle writes add rows to a table the account audit page
already reads.** Those pages will show conversation closes and reopens, which is
new but consistent — the page exists to show account activity, and this is
account activity that was previously invisible.

**Two pre-existing scope discrepancies are now worth naming**, since a reporting
surface would link into detail pages: `ConversationPolicy::view` and
`TicketPolicy::view` do not check `archived_at` while the query scope does, so a
detail page shows an archived site's records the queue hides; and
`ConversationPolicy::view` checks the account only transitively through
`Site::supportsAgent()`, where `TicketPolicy` checks it directly. Neither is
exploitable today. Both are recorded here rather than fixed in passing.
