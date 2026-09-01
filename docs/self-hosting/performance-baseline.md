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
| Conversation queue (open) | 4,843 | 21 | 38.9 MB |
| Conversation queue (closed) | 25,542 | 16 | 192.1 MB |
| Ticket queue (open) | 3,141 | 4,187 | 20.9 MB |
| Ticket queue (all) | 9,573 | 12,518 | 62.5 MB |
| **Conversation detail** | **12** | **26** | **149 KB** |

### How it grows

Every queue is linear in the number of rows, because every queue renders all of
them:

| Conversations | Queue (open) | Queue (closed) | Closed response | Tickets (all) | Ticket queries | Detail |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 112 ms | 473 ms | 3.9 MB | 184 ms | 268 | 11 ms / 26 q |
| 5,000 | 479 ms | 2,355 ms | 19.2 MB | 958 ms | 1,268 | 12 ms / 26 q |
| 25,000 | 2,356 ms | 12,058 ms | 96.0 MB | 4,793 ms | 6,268 | 14 ms / 25 q |
| 50,000 | 4,843 ms | 25,542 ms | 192.1 MB | 9,573 ms | 12,518 | 12 ms / 26 q |

The last column is the control, and it is the point: the same page, at fifty
times the data, costs the same.

## What that means

### Neither queue paginates

`AgentConversationController` and `AgentTicketController` contain no
`paginate()`. Every conversation and every ticket matching the current filters
is queried, hydrated and rendered into one response.

At a thousand conversations that is invisible. At fifty thousand the closed lane
is **192 MB of HTML** — a response no browser will render pleasantly and many
proxies will refuse outright, arriving after twenty-six seconds. The open lane
is better only because a desk that is keeping up has fewer open rows; it is the
same query with a narrower `where`.

Response size is the number to watch here rather than milliseconds. The server
builds 192 MB in twenty-six seconds; the browser then has to parse it.

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

### The conversation detail page is fine

12 ms, 26 queries and 149 KB at *every* size measured, from 20 conversations to
50,000. Its cost is bounded by one conversation's own messages rather than by
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
- **Attachments and the retention sweep.** No large object count has been run
  through either.
- **Cobrowse mutation batches** on a heavy page.
- **Concurrency of any kind.** Every figure here is one request at a time on an
  otherwise idle machine. Real contention will be worse.

## What this does not say

It does not say Wayfindr is slow. It says two specific pages are unbounded, one
of them additionally holds an N+1, and one page that could have been either is
neither.

Fixing any of that is deliberately not part of taking this measurement. The
point of a baseline is to exist before the change, so the next set of numbers
can be compared rather than guessed at.
