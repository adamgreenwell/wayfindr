# What Wayfindr does with a desk's worth of data in it

**Measured 1 September 2026.** Before this, Wayfindr had never been measured
under load — not badly, at all. Everything the disposable-VM matrix proves is a
correctness proof on an empty install.

This page is the first answer to the question a self-hosted `1.0` has to be able
to answer: *how much can it take?*

**The short version: the conversation and ticket queues do not paginate, and at
a year of real traffic they stop being usable.** The conversation detail page is
fine and stays fine. Numbers below.

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
measuring — and that overhead grows with query count, which is exactly the axis
the ticket queue's N+1 sits on. Query counts come from a separate, untimed
request.

**Read the milliseconds as approximate and the other two as exact.** Query
counts and response sizes are deterministic — the same fixture produces the same
figures every run. Timings move by several per cent between runs on the same
machine, and by much more if anything else is using it, so treat them as an
order of magnitude rather than a benchmark. Nothing here turns on the
difference between 22.1 and 22.3 seconds.

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
| Conversation queue (open) | 4,642 | 21 | 37.7 MB |
| Conversation queue (closed) | 25,477 | 15 | 186.0 MB |
| Ticket queue (open) | 3,187 | 4,185 | 20.9 MB |
| Ticket queue (all) | 9,503 | 12,518 | 62.5 MB |
| **Conversation detail** | **12** | **26** | **148 KB** |

### How it grows

Every queue is linear in the number of rows, because every queue renders all of
them:

| Conversations | Queue (open) | Queue (closed) | Closed response | Tickets (all) | Ticket queries | Detail |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 136 ms | 499 ms | 3.9 MB | 215 ms | 268 | 14 ms / 26 q |
| 5,000 | 545 ms | 2,472 ms | 19.3 MB | 950 ms | 1,268 | 13 ms / 26 q |
| 25,000 | 2,659 ms | 13,269 ms | 96.0 MB | 5,108 ms | 6,268 | 14 ms / 26 q |
| 50,000 | 4,642 ms | 25,477 ms | 186.0 MB | 9,503 ms | 12,518 | 12 ms / 26 q |

The last column is the control, and it is the point: the same page, at fifty
times the data, costs the same.

## What that means

### Neither queue paginates

`AgentConversationQueueController` and `AgentTicketQueueController` — the
single-action controllers behind `/dashboard/conversations` and
`/dashboard/tickets` — contain no `paginate()`. Every conversation and every ticket matching the current filters
is queried, hydrated and rendered into one response.

At a thousand conversations that is invisible. At fifty thousand the closed lane
is **186 MB of HTML** — a response no browser will render pleasantly and many
proxies will refuse outright, arriving after twenty-five seconds. The open lane
is better only because a desk that is keeping up has fewer open rows; it is the
same query with a narrower `where`.

Response size is the number to watch here rather than milliseconds. The server
builds 186 MB in twenty-five seconds; the browser then has to parse it.

### The ticket queue issues one query per ticket

12,518 queries to render the ticket list, of which **12,499 are the same query**
— one per ticket:

```sql
select * from "audit_events"
where "subject_type" = ? and "subject_id" = ? and "action" in (...)
```

One per ticket, lazily loaded while rendering. This is an N+1, and it is the
reason the ticket queue is slower than the conversation queue despite holding a
quarter as many rows. It would be invisible on a demo install and severe on a
busy one — and unlike the pagination issue, it does not need a large desk to be
worth fixing, only a large enough one to notice.

**The figure above is a floor, not a typical value.** The fixture writes no
audit events, so all 12,499 of those queries return nothing: they cost a round
trip and an index probe and no more. On an install with real lifecycle history
each one returns rows to hydrate into models, so the same page is more expensive
there than it is here. The query *count* is exact and the milliseconds are the
best case.

### The conversation detail page is fine

12-14 ms, 26 queries and 148 KB at *every* size measured, from 20 conversations
to 50,000. The queries and the response size do not move at all; the
milliseconds vary by a millisecond or two between runs, which is the noise floor
on a page this cheap. Its cost is bounded by one conversation's own messages rather than by
the desk around it, which is what the other pages are not.

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
  rather than driven through the application, so three tables the reports read
  are empty: `audit_events` has no `conversation.closed` or `conversation.
  reopened` rows, none of the ticket lifecycle actions `TicketReport` walks, and
  `conversation_ratings` has nothing for the satisfaction figures. Measuring the
  report tabs against this data would time queries over empty tables and report
  them as fast. Seeding that history is the first piece of the reporting
  measurement, not a detail of it — tracked as #839.
- **Attachments and the retention sweep.** No large object count has been run
  through either.
- **Cobrowse mutation batches** on a heavy page.

- **The conversation detail page's cobrowse panel.** The seeder creates no
  cobrowse sessions, so the panel — a substantial part of that page, and the
  only path on it that touches the cache — never renders in these figures. The
  15 ms above is a detail page without it.
- **Concurrency of any kind.** Every figure here is one request at a time on an
  otherwise idle machine. Real contention will be worse.

## What this does not say

It does not say Wayfindr is slow. It says two specific pages are unbounded, one
of them additionally holds an N+1, and one page that could have been either is
neither.

Fixing any of that is deliberately not part of taking this measurement. The
point of a baseline is to exist before the change, so the next set of numbers
can be compared rather than guessed at.
