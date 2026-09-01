# What Wayfindr does with a desk's worth of data in it

**Measured 1 September 2026.** Before this, Wayfindr had never been measured
under load — not badly, at all. Everything the disposable-VM matrix proves is a
correctness proof on an empty install.

This page is the first answer to the question a self-hosted `1.0` has to be able
to answer: *how much can it take?*

**The short version: the conversation and ticket queues do not paginate, and at
a year of real traffic they stop being usable.** Measuring also turned up an N+1
on the ticket queue, now fixed — 12,518 queries down to 18. The conversation
detail page is fine and stays fine. Numbers below.

## Reproducing this

Two commands. Both are shipped, so an operator can run them against their own
hardware rather than trusting these figures:

```bash
php artisan wayfindr:seed-desk --conversations=50000 --months=12 --fresh
```

```bash
php artisan wayfindr:measure-dashboard --runs=3
```

Every figure on this page was taken with `--runs=3`.

**Timings are taken with query logging OFF.** Laravel's query log allocates and
retains an entry per query, so measuring with it on charges the page for the
measuring — and that overhead grows with query count, which mattered most on the
ticket queue before its N+1 was fixed. Query counts come from a separate,
untimed request.

**Read the milliseconds as approximate and the other two as near-exact.** Query
counts are deterministic. Response sizes are stable to within a few bytes on a
hundred-kilobyte page, and the residue is worth naming: the dashboard links to
conversations and tickets by **database id**, so rebuilding the fixture advances
those sequences and widens an href by a character each time one passes a power of
ten. No seeder can hold that still, and it does not grow with the desk. What the
fixture does control — every word it writes — is stable across rebuilds, and is
asserted to be. Timings move by several per cent between runs on the same
machine, and by much more if anything else is using it, so treat them as an
order of magnitude rather than a benchmark. Nothing here turns on the
difference between 25.4 and 25.6 seconds.

"Much more" is not hypothetical: running the test suite alongside a measurement
put the 25,000-row open queue at 4,367 ms, nearly double its 2,659 ms on an idle
machine and higher than the same page at twice the data. If a figure here breaks
the pattern of the ones around it, suspect the machine before the code.

**Measurement cannot change what it measures.** Every request runs inside a
transaction that is always rolled back. The conversation detail page is not a
read — it marks the conversation read for the viewer — so without that, an
operator benchmarking their own install with `--email` would clear a real
agent's state while taking the numbers.

The seeder writes to its own account (`wayfindr-measurement-desk`) and `--fresh`
deletes exactly that account, so it is safe to run beside real data. It refuses
to run in production without `--force`.

## The hardware these numbers came from

Figures are only comparable against the machine that produced them, so:

| | |
| --- | --- |
| Machine | Apple M4 Max, 16 cores, 128 GB |
| OS | macOS 27.0 |
| PHP | 8.5.8 |
| Database | PostgreSQL 18.4, local |
| Dataset | 50,000 conversations, 300,000 messages, 12,500 tickets, 50,000 visitors, over 12 months |

**This is a fast development machine, so read these as a floor.** A modest VPS —
the thing most self-hosted installs actually run on — will be several times
slower, and the measurements exclude the network, the web server and TLS because
requests are dispatched through the HTTP kernel directly.

## The numbers

At 50,000 conversations:

| Page | ms (median) | Queries | Response |
| --- | ---: | ---: | ---: |
| Conversation queue (open) | 4,598 | 21 | 39.0 MB |
| Conversation queue (closed) | 25,108 | 15 | 192.8 MB |
| Conversation queue (search) | 628 | 21 | 3.5 MB |
| Conversation queue (mine) | 415 | 21 | 2.5 MB |
| Ticket queue (open) | 2,320 | 18 | 21.0 MB |
| Ticket queue (all) | 6,928 | 18 | 62.8 MB |
| **Conversation detail** | **11** | **26** | **148 KB** |

All seven, because the command measures seven: a table listing fewer than the
documented command prints leaves an operator with figures they cannot compare
against anything. The two filtered lanes are cheap for the reason the open lane
is — they match fewer rows, and it is the same unpaginated query with a narrower
`where`.

### How it grows

Every queue is linear in the number of rows, because every queue renders all of
them:

| Conversations | Queue (open) | Queue (closed) | Closed response | Tickets (all) | Ticket queries | Detail |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 109 ms | 456 ms | 3.9 MB | 139 ms | 18 | 11 ms / 26 q |
| 5,000 | 479 ms | 2,258 ms | 19.3 MB | 674 ms | 18 | 12 ms / 26 q |
| 25,000 | 2,324 ms | 11,811 ms | 96.3 MB | 3,402 ms | 18 | 11 ms / 26 q |
| 50,000 | 4,598 ms | 25,108 ms | 192.8 MB | 6,928 ms | 18 | 11 ms / 26 q |

Two columns do not move. The last one is the control and always was: the same
page, at fifty times the data, costs the same. **Ticket queries** joined it when
the N+1 was fixed — that column read 268, 1,268, 6,268, 12,518 before, one query
per ticket, and it is now flat at 18 while the milliseconds beside it still climb
with the rows the page renders.

## What that means

### Neither queue paginates

`AgentConversationQueueController` and `AgentTicketQueueController` — the
single-action controllers behind `/dashboard/conversations` and
`/dashboard/tickets` — contain no `paginate()`. Every conversation and every
ticket matching the current filters is queried, hydrated and rendered into one
response.

At a thousand conversations that is invisible. At fifty thousand the closed lane
is **193 MB of HTML** — a response no browser will render pleasantly and many
proxies will refuse outright, arriving after twenty-five seconds. The open lane
is better only because a desk that is keeping up has fewer open rows; it is the
same query with a narrower `where`.

Response size is the number to watch here rather than milliseconds. The server
builds 193 MB in twenty-five seconds; the browser then has to parse it.

### The ticket queue used to issue one query per ticket

Fixed in #838 while this page was being written, and recorded because the size
of the difference is the argument for measuring at all:

| Ticket queue (all), 50,000 conversations | Queries | ms |
| --- | ---: | ---: |
| Before | 12,518 | 9,503 |
| After | **18** | **6,928** |

The queue eagerly loaded each ticket's lifecycle events and then threw that work
away: the helper reading them went to the relation rather than to what had
already been loaded, so every ticket paid for its own query.

```sql
select * from "audit_events"
where "subject_type" = ? and "subject_id" = ? and "action" in (...)
```

Two things are worth carrying forward from it. **The 12,499 wasted queries each
returned nothing**, because this fixture writes no audit events — they cost a
round trip and an index probe and no hydration, so on an install with real
lifecycle history the same defect was costing more than the numbers above show.
And **the fix was one missing condition**: of three helpers reading that
relation, two asked whether it was already loaded and one did not.

What remains is the pagination problem, which the ticket queue shares with the
conversation queue: 18 queries is the right number, and it still renders every
matching row into one response.

### The conversation detail page is fine

11-12 ms, 26 queries and 148 KB at *every* size measured, from 20 conversations
to 50,000. The queries and the response size do not move at all; the
milliseconds vary by a millisecond or two between runs, which is the noise floor
on a page this cheap. Its cost is bounded by one conversation's own messages
rather than by the desk around it, which is what the other pages are not.

This is worth stating as plainly as the problems: the page an agent spends most
of their day inside does not degrade, and the guard in
`MeasureDashboardCommandTest` asserts that it stays that way.

## What has NOT been measured

Stated because a baseline with silent gaps is worse than one with named ones:

- **Reverb, and concurrent agent count.** #796 asks for a figure an operator can
  size against. Producing one needs many real WebSocket clients against a
  running Reverb, which is a different kind of harness from this one. Nothing
  here establishes it.
- **Reporting.** The report tabs stream rows and bucket them in PHP, deliberately
  and for portability (`docs/product/reporting.md`), and that decision has never
  been measured against a busy desk. The seeder produces data spread across
  twelve months specifically so this can be measured next; it has not been.

  **And the fixture is not ready for it yet.** Everything is inserted directly
  rather than driven through the application, so what the reports read is not
  there: `audit_events` holds neither `conversation.closed` nor
  `conversation.reopened` rows, nor any of the ticket lifecycle actions
  `TicketReport` walks separately, and `conversation_ratings` has nothing behind
  the satisfaction figures. Measuring the report tabs against this data would
  time queries over empty tables and report them as fast. Seeding that history
  is the first piece of the reporting measurement, not a detail of it — tracked
  as #839.
- **Attachments and the retention sweep.** No large object count has been run
  through either.
- **Cobrowse mutation batches** on a heavy page.
- **The conversation detail page's cobrowse panel.** The seeder creates no
  cobrowse sessions, so the panel — a substantial part of that page, and the
  only path on it that touches the cache — never renders in these figures. The
  figure above is a detail page without it.
- **Concurrency of any kind.** Every figure here is one request at a time on an
  otherwise idle machine. Real contention will be worse.

## What this does not say

It does not say Wayfindr is slow. It says two specific pages are unbounded, one
of them held an N+1 that has since been fixed, and one page that could have been
either is neither.

Fixing it was not the point of taking the measurement — but the ticket queue's
N+1 was fixed before this page was published, and the figures here were then
taken again against the same fixture. That is the baseline doing its job in
miniature: 12,518 queries became 18, and the claim is a subtraction rather than
an impression. The pagination problem is left standing on purpose, so the next
set of numbers has something to be compared against.
