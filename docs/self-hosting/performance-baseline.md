# What Wayfindr does with a desk's worth of data in it

**Measured 1 September 2026.** Before this, Wayfindr had never been measured
under load — not badly, at all. Everything the disposable-VM matrix proves is a
correctness proof on an empty install.

This page is the first answer to the question a self-hosted `1.0` has to be able
to answer: *how much can it take?*

**The short version: the queues rendered every matching row, and at a year of
real traffic that stopped being usable.** The conversation queue is capped now —
its closed lane went from 187 MB and twenty-three seconds to 1 MB and 161 ms —
and the ticket queue still is not. Measuring also turned up an N+1 on the ticket
queue, now fixed: 12,518 queries down to 19. The conversation detail page is fine
and stays fine, and so do the report tabs. Numbers below.

## Reproducing this

Two commands. Both are shipped, so an operator can run them against their own
hardware rather than trusting these figures:

```bash
php artisan wayfindr:seed-desk --conversations=50000 --months=12 --fresh --force
```

```bash
php -d memory_limit=1G artisan wayfindr:measure-dashboard --runs=3
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
being asked to hold a second desk's worth of data and serve the ticket queue's
63 MB responses while you measure it, so **measure a staging copy if you have one**, and expect
the disk and the load to be real if you do not.

**The memory override is required, not a precaution.** The shipped image sets
`memory_limit = 256M` (`docker/self-hosting/php.ini`), and at this fixture size
the measurement dies without ever printing the table. It needs somewhere between
512M and 1G: 512M is fatal, 1G completes. The seeding command is unaffected and
runs inside 256M, because it writes in chunks.

That requirement is worth reading as a finding rather than a footnote, and it
names which page is still unbounded. It used to be 2 GB, because the conversation
queue rendered every matching row and the closed lane built a 187 MB response;
capping that queue took it to 1 GB. What is left is the **ticket** queue, which
still hydrates every matching row — the [pagination problem](#the-ticket-queue-still-renders-every-row)
showing up as a memory bill before it shows up as a number. Scale the override
with the desk: a smaller fixture needs proportionally less.

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
difference between 23.0 and 23.2 seconds.

"Much more" is not hypothetical. On one occasion, running the test suite
alongside a measurement put the 25,000-row open queue at 4,367 ms against 2,659
on an idle machine — higher than the same page at twice the data, which is what
gave it away. (Both are from that day; the table above is a later run.) If a
figure here breaks the pattern of the ones around it, suspect the machine before
the code.

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
| Conversation queue (open) | 303 | 22 | 1.0 MB |
| Conversation queue (closed) | 161 | 16 | 1.0 MB |
| Conversation queue (search) | 402 | 22 | 1.0 MB |
| Conversation queue (mine) | 298 | 22 | 1.0 MB |
| Ticket queue (open) | 2,206 | 18 | 21.0 MB |
| Ticket queue (all) | 7,052 | 19 | 62.8 MB |
| Reports (7 days) | 151 | 34 | 140 KB |
| Reports (90 days) | 613 | 80 | 223 KB |
| Reports export (90 days) | 94 | 7 | 2 KB |
| **Conversation detail** | **13** | **26** | **148 KB** |

All ten, because the command measures ten: a table listing fewer than the
documented command prints leaves an operator with figures they cannot compare
against anything. The two filtered lanes are cheap for the reason the open lane
is — they match fewer rows, and it is the same capped query with a narrower
`where`.

### How it grows

The capped queue is not, and the uncapped one still is:

| Conversations | Conversations closed (capped) | Tickets all (uncapped) | Reports (90d) | Detail |
| ---: | ---: | ---: | ---: | ---: |
| 1,000 | 121 ms / 1.0 MB | 138 ms / 1.4 MB | 26 ms | 12 ms |
| 5,000 | 126 ms / 1.0 MB | 665 ms / 6.4 MB | 69 ms | 13 ms |
| 25,000 | 127 ms / 1.0 MB | 3,382 ms / 31.4 MB | 289 ms | 14 ms |
| 50,000 | 161 ms / 1.0 MB | 7,052 ms / 62.8 MB | 613 ms | 13 ms |

**The first two columns are the argument for capping, side by side.** The
conversation queue is flat — fifty times the data, the same megabyte, and the
milliseconds move only because the count query beside it runs over a bigger
table. The ticket queue is not capped and is linear in both: fifty times the
rows, fifty times the response.

The last column is the control and always was: the same page, at fifty times the
data, costs the same. The reports grow mildly in both time and queries, the
latter because `ResolutionEpisodes::walk()` chunks the subjects it walks by 500
and asks twice per chunk.

## What that means

### The conversation queue used to render every row

It had no cap: every conversation matching the filters was queried, hydrated
and rendered into one response. At 50,000 conversations the closed lane was
**187 MB of HTML arriving after twenty-three seconds** — a response many proxies
refuse outright and no browser renders pleasantly.

Capped at 200 rows in #837, the same number and the same shape the live visitor
board already used:

| Conversation queue, 50,000 conversations | Before | After |
| --- | ---: | ---: |
| Open | 4,153 ms / 37.8 MB | **303 ms / 1.0 MB** |
| Closed | 23,007 ms / 186.7 MB | **161 ms / 1.0 MB** |
| Search | 548 ms / 3.4 MB | **402 ms / 1.0 MB** |
| Assigned to me | 374 ms / 2.4 MB | **298 ms / 1.0 MB** |

The closed lane is the one to look at: about a hundred and forty times faster
and a hundred and eighty times smaller.

**The rows are capped and the count is not.** A busy lane reads as "the 200 most
recently active of 12,431" rather than as 200 — reporting the cap as the total
is the one number an agent would have trusted. That costs one extra query, and
only when the cap is actually reached; a lane that fits already knows its own
size.

This is a cap rather than pagination on purpose. The queue is a workspace an
agent scans, filters and returns to all day, not a list they page through, and
nobody reaches row 12,000 by scrolling — they narrow it with the filters above
it. Choosing the affordance the product had already validated elsewhere seemed
better than inventing a third pattern for the page agents live in.

### The ticket queue still renders every row

It shares the same defect and does not have the same fix, because it filters
and sorts **in PHP after the query**: the external-issue and attention states are
computed from loaded relations. Capping the query would silently show fewer rows
than the cap whenever a refinement is active, and make any "of N" beside it
misleading.

Moving those filters into SQL is the real fix and a larger change than a cap.
Tracked separately rather than bundled in.

### The ticket queue used to issue one query per ticket

Fixed in #838 while this page was being written, and recorded because the size
of the difference is the argument for measuring at all:

| Ticket queue (all), 50,000 conversations | Queries | ms |
| --- | ---: | ---: |
| Before | 12,518 | 9,503 |
| After | **18** | **6,928** |

Both rows are on the fixture as it stood then, which wrote no lifecycle history
— that is what makes them comparable to each other. The current figure in the
table above is 19 queries and 7,052 ms, measured on a desk that now has a
history to hydrate; the paragraph after this measures that difference directly
rather than leaving two numbers to be reconciled by the reader.

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
fixture writes lifecycle history now — 95,604 events, of which 33,229 belong to
tickets — so those queries return rows to hydrate.

Measured as an A/B on one desk in one sitting, deleting only the ticket events
between the two runs, because comparing figures from different days would put
the answer inside the run-to-run noise this page already warns about:

| Ticket queue (all), 50,000 conversations | ms | Queries |
| --- | ---: | ---: |
| With 33,229 ticket lifecycle events | 6,917 | 19 |
| With none | 6,699 | 18 |

Both rows are from that sitting, which is what makes them comparable to each
other and slightly adrift of the 7,052 ms in the table above — that is a
different run, and the gap between them is the run-to-run variance this page
opens by warning about.

**About three per cent, and one query.** That is what the eager load costs when
it has something to load — a long way from the 9,503 ms the N+1 was costing, and
small enough that the pagination problem remains the only thing on this page
worth acting on.

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
| 7 days | 150 | 34 | 140 KB |
| 90 days | 607 | 80 | 223 KB |
| Export, 90 days | 94 | 7 | 2 KB |

The window matters — 90 days is about four times the work of 7 — but so does the
desk, and the query count grows with it:

| Conversations | Reports (90 days) |
| ---: | ---: |
| 1,000 | 30 ms / 32 q |
| 5,000 | 79 ms / 36 q |
| 25,000 | 350 ms / 57 q |
| 50,000 | 607 ms / 80 q |

Fifty times the data costs about twenty times the milliseconds, so it grows
sub-linearly rather than flatly — the window bounds how much of the desk is in
scope, and a bigger desk puts more inside the same window.

**The queries grow with the number of resolved subjects, not with the rows
read.** The streaming itself is one query per section: `SupportReport` reads with
`cursor()`, which walks a single result set rather than fetching pages. The
growth is in `ResolutionEpisodes::walk()`, which splits the subject ids it has to
walk into chunks of 500 — a bind-parameter limit, not a memory one — and issues
two queries per chunk, one for creation times and one for the lifecycle events.
More closed conversations and tickets inside the window means more chunks, and
the conversation and ticket halves each pay it.

That is a fair trade at this size and worth watching rather than fixing: 607 ms
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

13-15 ms, 26 queries and 148 KB at *every* size measured, from 20 conversations
to 50,000. The queries and the response size do not move at all; the
milliseconds vary by a millisecond or two between runs, which is the noise floor
on a page this cheap. Earlier revisions of this page reported 11-12 ms for the
same measurement — the difference is the machine on the day, not the code, and
it is a fair illustration of how much weight the milliseconds here will carry.

Its cost is bounded by one conversation's own messages rather than by the desk
around it, which is what the other pages are not.

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
