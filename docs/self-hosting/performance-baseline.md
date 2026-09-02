# What Wayfindr does with a desk's worth of data in it

**Measured 1 September 2026.** Before this, Wayfindr had never been measured
under load — not badly, at all. Everything the disposable-VM matrix proves is a
correctness proof on an empty install.

This page is the first answer to the question a self-hosted `1.0` has to be able
to answer: *how much can it take?*

**The short version: the conversation and ticket queues do not paginate, and at
a year of real traffic they stop being usable.** Measuring also turned up an N+1
on the ticket queue, now fixed — 12,518 queries down to 19. The conversation
detail page is fine and stays fine, and so do the report tabs. Numbers below.

## Reproducing this

Two commands. Both are shipped, so an operator can run them against their own
hardware rather than trusting these figures:

```bash
php artisan wayfindr:seed-desk --conversations=50000 --months=12 --fresh --force
```

```bash
php -d memory_limit=2G artisan wayfindr:measure-dashboard --runs=3
```

Every figure on this page was taken with `--runs=3`.

**`--force` is needed because the image runs as production.**
`server.Dockerfile` sets `APP_ENV=production`, and the seeder refuses to run
there without being told twice — which is correct, and makes the flag
mandatory on the one environment this page is written for. Read it as the
warning it is: this writes tens of thousands of rows.

They go to an account of the seeder's own (`wayfindr-measurement-desk`), and
`--fresh` deletes exactly that account and refuses if anything it did not
create is sitting there. Nothing else is touched. But a real install is still
being asked to hold a second desk's worth of data and serve 193 MB responses
while you measure it, so **measure a staging copy if you have one**, and expect
the disk and the load to be real if you do not.

**The memory override is required, not a precaution.** The shipped image sets
`memory_limit = 256M` (`docker/self-hosting/php.ini`), and at this fixture size
the measurement dies inside the closed queue without ever printing the table. It
needs somewhere between 1.5 GB and 2 GB: 1536M is fatal, 2G completes. The
seeding command is unaffected and runs inside 256M, because it writes in chunks.

That requirement is worth reading as a finding rather than a footnote. The
measurement needs eight times the image's limit because the page it measures
builds a 193 MB response with every matching row hydrated at once — the
[pagination problem](#neither-queue-paginates) showing up as a memory bill
before it shows up as a number. Scale the override with the desk: a smaller
fixture needs proportionally less.

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
| Dataset | 50,000 conversations, 300,000 messages, 12,500 tickets, 50,000 visitors, 95,604 lifecycle events, 21,082 ratings, over 12 months |

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
| Ticket queue (open) | 2,407 | 18 | 21.0 MB |
| Ticket queue (all) | 7,379 | 19 | 62.8 MB |
| Reports (7 days) | 156 | 34 | 140 KB |
| Reports (90 days) | 659 | 81 | 223 KB |
| Reports export (90 days) | 97 | 7 | 2 KB |
| **Conversation detail** | **16** | **26** | **148 KB** |

All ten, because the command measures ten: a table listing fewer than the
documented command prints leaves an operator with figures they cannot compare
against anything. The two filtered lanes are cheap for the reason the open lane
is — they match fewer rows, and it is the same unpaginated query with a narrower
`where`.

### How it grows

Every queue is linear in the number of rows, because every queue renders all of
them:

| Conversations | Queue (open) | Queue (closed) | Closed response | Tickets (all) | Ticket queries | Reports (90d) | Detail |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 117 ms | 462 ms | 3.9 MB | 148 ms | 19 | 30 ms / 32 q | 14 ms / 26 q |
| 5,000 | 494 ms | 2,353 ms | 19.3 MB | 714 ms | 19 | 79 ms / 36 q | 15 ms / 26 q |
| 25,000 | 2,353 ms | 12,216 ms | 96.3 MB | 3,604 ms | 19 | 350 ms / 57 q | 15 ms / 26 q |
| 50,000 | 4,839 ms | 25,691 ms | 192.8 MB | 7,379 ms | 19 | 659 ms / 81 q | 16 ms / 26 q |

Two columns do not move. The last one is the control and always was: the same
page, at fifty times the data, costs the same. **Ticket queries** joined it when
the N+1 was fixed — that column read 268, 1,268, 6,268, 12,518 before, one query
per ticket, and it is now flat at 19 while the milliseconds beside it still climb
with the rows the page renders.

The reports column grows in both, and mildly: twenty-two times the milliseconds
for fifty times the data, with the query count rising because the rows are
streamed in chunks.

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

Both rows are on the fixture as it stood then, which wrote no lifecycle history
— that is what makes them comparable to each other. The current figure in the
table above is 19 queries and 7,379 ms, measured on a desk that now has a
history to hydrate; the paragraph after this explains that difference rather
than leaving two numbers to be reconciled by the reader.

The queue eagerly loaded each ticket's lifecycle events and then threw that work
away: the helper reading them went to the relation rather than to what had
already been loaded, so every ticket paid for its own query.

```sql
select * from "audit_events"
where "subject_type" = ? and "subject_id" = ? and "action" in (...)
```

Two things are worth carrying forward from it. **The 12,499 wasted queries each
returned nothing**, because the fixture wrote no audit events at the time — they
cost a round trip and an index probe and no hydration, so the defect was costing
a real desk more than those figures showed. And **the fix was one missing
condition**: of three helpers reading that relation, two asked whether it was
already loaded and one did not.

That caveat has since been settled by measurement rather than left standing. The
fixture writes lifecycle history now — 95,604 events at this size — so those
queries return rows to hydrate. The all-tickets queue moved from 6,928 ms and 18
queries on the empty fixture to **7,379 ms and 19** with a real history behind
it: about six per cent, which is what the eager load costs when it has something
to load, and a long way from the 9,503 ms the N+1 was costing.

What remains is the pagination problem, which the ticket queue shares with the
conversation queue: 19 queries is the right number, and it still renders every
matching row into one response.

### The reports hold up

They stream rows and bucket them in PHP, deliberately and for portability
(`docs/product/reporting.md`), and that decision had never been measured against
a busy desk. At 50,000 conversations with 95,604 lifecycle events and 21,082
ratings behind them:

| Window | ms | Queries | Response |
| --- | ---: | ---: | ---: |
| 7 days | 156 | 34 | 140 KB |
| 90 days | 659 | 81 | 223 KB |
| Export, 90 days | 97 | 7 | 2 KB |

The window matters — 90 days is about four times the work of 7 — but so does the
desk, and the query count grows with it:

| Conversations | Reports (90 days) |
| ---: | ---: |
| 1,000 | 30 ms / 32 q |
| 5,000 | 79 ms / 36 q |
| 25,000 | 350 ms / 57 q |
| 50,000 | 659 ms / 81 q |

Fifty times the data costs about twenty-two times the milliseconds, so it grows
sub-linearly rather than flatly — the window bounds how much of the desk is in
scope, and a bigger desk puts more inside the same window. **The queries grow
because the rows are streamed in chunks**, which is the bucketing decision
showing up as query count rather than as memory: more rows in the window means
more chunks to fetch.

That is a fair trade at this size and worth watching rather than fixing: 659 ms
for the busiest window on a year of a fifty-thousand-conversation desk is not
where this product's problem is. **The queues are**, and they are on the same
page as this one.

The export is the cheapest path here and the one whose cost is not bounded by
what a screen can show — worth re-measuring if the window choices ever grow past
90 days.

**These figures needed a fixture before they meant anything.** Everything here is
inserted directly rather than driven through the application, so until #839 the
reports had no closes, reopens, ticket lifecycle or ratings to read: measuring
them would have timed queries over empty tables and called the pages fast. What
is measured above is a desk with a history a real install could have produced.

### The conversation detail page is fine

14-16 ms, 26 queries and 148 KB at *every* size measured, from 20 conversations
to 50,000. The queries and the response size do not move at all; the
milliseconds vary by a millisecond or two between runs, which is the noise floor
on a page this cheap. Earlier revisions of this page reported 11-12 ms for the
same measurement — the difference is the machine on the day, not the code, and
it is a fair illustration of how much weight the milliseconds here will carry. Its cost is bounded by one conversation's own messages
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
