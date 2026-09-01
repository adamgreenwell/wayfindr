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
| Conversation queue (open) | 4,384 | 21 | 37.7 MB |
| Conversation queue (closed) | 23,697 | 15 | 186.1 MB |
| Ticket queue (all) | 10,373 | 12,518 | 62.5 MB |
| **Conversation detail** | **13** | **25** | **149 KB** |

### How it grows

Every queue is linear in the number of rows, because every queue renders all of
them:

| Conversations | Queue (open) | Queue (closed) | Closed response | Tickets (all) | Ticket queries | Detail |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 112 ms | 432 ms | 3.8 MB | 205 ms | 268 | 14 ms / 25 q |
| 5,000 | 444 ms | 2,188 ms | 18.6 MB | 1,016 ms | 1,268 | 14 ms / 25 q |
| 25,000 | 2,192 ms | 11,378 ms | 93.0 MB | 5,108 ms | 6,268 | 15 ms / 24 q |
| 50,000 | 4,384 ms | 23,697 ms | 186.1 MB | 10,373 ms | 12,518 | 13 ms / 25 q |

The last column is the control, and it is the point: the same page, at fifty
times the data, costs the same.

## What that means

### Neither queue paginates

`AgentConversationController` and `AgentTicketController` contain no
`paginate()`. Every conversation and every ticket matching the current filters
is queried, hydrated and rendered into one response.

At a thousand conversations that is invisible. At fifty thousand the closed lane
is **186 MB of HTML** — a response no browser will render pleasantly and many
proxies will refuse outright, arriving after twenty-two seconds. The open lane
is better only because a desk that is keeping up has fewer open rows; it is the
same query with a narrower `where`.

Response size is the number to watch here rather than milliseconds. The server
builds 186 MB in twenty-two seconds; the browser then has to parse it.

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

13 ms, 25 queries and 149 KB at *every* size measured, from 20 conversations to
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
