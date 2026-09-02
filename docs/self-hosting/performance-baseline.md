# What Wayfindr does with a desk's worth of data in it

**Measured 2 September 2026.** Before this work, Wayfindr had never been measured
under load — not badly, at all. Everything the disposable-VM matrix proves is a
correctness proof on an empty install.

This page is the first answer to the question a self-hosted `1.0` has to be able
to answer: *how much can it take?*

The browser-to-Reverb path has its own
[heavy-page cobrowse baseline](cobrowse-performance-baseline.md). It uses a
populated cobrowse session rather than the empty-session detail control below.

**The short version: both queues used to render every matching row, and at a
year of real traffic that stopped being usable. Both are capped now.** The
conversation queue's closed lane went from 187 MB and twenty-three seconds to
about 1 MB and 175 ms. The ticket queue's all lane went from 62.8 MB and seven
seconds to 1.1 MB and 227 ms. Measuring also turned up an N+1 on the ticket
queue, fixed before the cap: 12,518 queries down to a bounded 20. The
conversation detail page is fine and stays fine. The report tabs grow with the
desk but sub-linearly, and hold up at this size. Numbers below.

## Reproducing this

Two commands. Both are shipped, so an operator can run them against their own
hardware rather than trusting these figures. With PHP on the host:

```bash
php artisan wayfindr:seed-desk --conversations=50000 --months=12 --fresh --force
```

```bash
php artisan wayfindr:measure-dashboard --runs=3
```

On the documented Docker installs `artisan` is inside the `web` container. The
one-line installer puts `compose.yml` and `.env` in `./wayfindr`, or wherever
`--dir` pointed if it was given one. From inside that directory, whichever it
is:

```bash
docker compose -f compose.yml --env-file .env exec web php artisan wayfindr:seed-desk --conversations=50000 --months=12 --fresh --force
```

```bash
docker compose -f compose.yml --env-file .env exec web php artisan wayfindr:measure-dashboard --runs=3
```

A by-hand Compose install runs from the checkout, with the stack files under
`docker/self-hosting`, exactly as it was brought up:

```bash
docker compose -f docker/self-hosting/compose.yml --env-file docker/self-hosting/.env exec web php artisan wayfindr:seed-desk --conversations=50000 --months=12 --fresh --force
```

```bash
docker compose -f docker/self-hosting/compose.yml --env-file docker/self-hosting/.env exec web php artisan wayfindr:measure-dashboard --runs=3
```

Every figure on this page was taken with `--runs=3`.

**`--force` is needed because the image runs as production.**
`server.Dockerfile` sets `APP_ENV=production`, and the seeder refuses to run
there without being told twice — which is correct, and makes the flag
mandatory on the one environment this page is written for. Read it as the
warning it is: this writes tens of thousands of rows.

They go to an account of the seeder's own (`wayfindr-measurement-desk`), and
`--fresh` deletes exactly that account and refuses if anything it did not
create is sitting there. Nothing else is touched.

**Do not run this on an install serving real traffic.** The load matters, but
the sharper reason is what the seeder leaves behind: an owner account
`desk-agent-0@example.test` whose password is literally `password`, committed.
Only the measurement transaction rolls back. The desk stays, login-capable,
until somebody removes it — a publicly known owner credential on an
internet-facing box.

Measure a staging copy, a restored backup, or a throwaway VM. If a desk was
seeded somewhere it should not have been, `--purge` removes it — the account,
everything under it, and the `desk-agent-` sign-ins — and writes nothing. With
PHP on the host:

```bash
php artisan wayfindr:seed-desk --purge
```

From inside the one-line installer's directory (`./wayfindr`, or the `--dir`
it was given):

```bash
docker compose -f compose.yml --env-file .env exec web php artisan wayfindr:seed-desk --purge
```

From a by-hand Compose checkout:

```bash
docker compose -f docker/self-hosting/compose.yml --env-file docker/self-hosting/.env exec web php artisan wayfindr:seed-desk --purge
```

`--fresh` is not that: it deletes and then seeds again, so it replaces the
credential rather than removing it. `--purge` also sweeps up seeded sign-ins an
older `--fresh` left behind without an account. It refuses if the account at
the seeder's slug holds a site or a user the seeder did not create, and does
not ask for `--force` — it is the remedy, and a remedy that asks to be told
twice is one an operator postpones.

**No memory override is required now.** The full 50,000-conversation measurement
completed at the shipped image's `memory_limit = 256M`
(`docker/self-hosting/php.ini`). Before both row caps, it needed 2 GB; after the
conversation cap alone it still needed 1 GB because the ticket queue hydrated
12,500 rows. The seeding command also stays inside 256M because it writes in
chunks.

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
deletes exactly that account, so nothing else in the database is touched. That
is a property of the delete, not permission to run it beside real data — the
account it leaves behind is the problem, as the warning above says. It refuses
to run in production without `--force`.

## The hardware these numbers came from

Figures are only comparable against the machine that produced them, so:

| | |
| --- | --- |
| Machine | Apple M4 Max, 16 cores, 128 GB |
| OS | macOS 27.0 |
| PHP | 8.5.8 |
| Database | PostgreSQL 17, local Docker |
| Dataset | 50,000 conversations, 300,000 messages, 12,500 tickets, 50,000 visitors, 95,604 lifecycle events, 21,082 ratings, over 12 months |

**This is a fast development machine, so read these as a floor.** A modest VPS —
the thing most self-hosted installs actually run on — will be several times
slower, and the measurements exclude the network, the web server and TLS because
requests are dispatched through the HTTP kernel directly.

## The numbers

These are the three figures recorded by the original dashboard run. The command
now also prints peak allocated PHP memory; that fourth field was added for the
[populated cobrowse measurement](cobrowse-performance-baseline.md) and was not
reconstructed for this historical table.

At 50,000 conversations:

| Page | ms (median) | Queries | Response |
| --- | ---: | ---: | ---: |
| Conversation queue (open) | 360 | 22 | 1.0 MB |
| Conversation queue (closed) | 175 | 16 | 1.0 MB |
| Conversation queue (search) | 530 | 22 | 1.0 MB |
| Conversation queue (mine) | 365 | 22 | 1.0 MB |
| Ticket queue (open) | 172 | 19 | 1.0 MB |
| Ticket queue (all) | 227 | 20 | 1.1 MB |
| Reports (7 days) | 196 | 34 | 140 KB |
| Reports (90 days) | 752 | 80 | 223 KB |
| Reports export (90 days) | 102 | 7 | 2 KB |
| **Conversation detail** | **23** | **26** | **148 KB** |

All ten, because the command measures ten: a table listing fewer than the
documented command prints leaves an operator with figures they cannot compare
against anything. The two filtered lanes are cheap for the reason the open lane
is — they match fewer rows, and it is the same capped query with a narrower
`where`.

### How the ticket queue grows after the cap

Each point below was rebuilt and measured independently with three runs. The
response stays at roughly one megabyte because every desk has enough tickets to
fill the same 200-row window; the remaining growth is the uncapped count query.

| Conversations | Tickets | Ticket queue (all) |
| ---: | ---: | ---: |
| 1,000 | 250 | 147 ms / 1.2 MB |
| 5,000 | 1,250 | 143 ms / 1.1 MB |
| 25,000 | 6,250 | 219 ms / 1.1 MB |
| 50,000 | 12,500 | 227 ms / 1.1 MB |

Before the cap those same response sizes grew from 1.4 MB to 62.8 MB and the
50,000-conversation point took 7,052 ms. The new curve is not perfectly flat —
the count still has to walk a larger indexed set — but row hydration and HTML
no longer grow with the desk.

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

The cobrowse-attention lane needs a PHP transport-state check before it knows
which rows belong. It still reports the exact total, but it evaluates recent
active sessions in chunks of 200, records matching integer IDs, then hydrates
only the 200 matches the queue can render after one current database ordering.
Keeping the cheap ID list until that final ordering lets a conversation move
back into the rendered window if new activity arrives during the scan; keeping
only 200 full models would lose an already-evicted match. The scan and final
ordering share one repeatable-read snapshot, so session membership and
telemetry do not change underneath later chunks. Sessions outside the configured
idle window (15 minutes by default) do not enter that scan. This bounds
expensive model hydration without hiding an older degraded session behind a
page of healthy ones. The fixture below creates no cobrowse sessions, so the
timings in this document do not quantify that path.

This is a cap rather than pagination on purpose. The queue is a workspace an
agent scans, filters and returns to all day, not a list they page through, and
nobody reaches row 12,000 by scrolling — they narrow it with the filters above
it. Choosing the affordance the product had already validated elsewhere seemed
better than inventing a third pattern for the page agents live in.

### The ticket queue is capped too

The ticket queue needed a prerequisite the conversation queue did not. Its
attention and external-issue states were derived in PHP after every matching
ticket had already been loaded. Applying a SQL limit there would have selected
an arbitrary window first and then filtered it down, producing a short or empty
lane while older matching tickets existed.

The attention state and its ordering moved into one SQL expression first. The
external-issue state followed as correlated predicates that preserve the audit
pairing rules: later successes resolve failures, and later removals cancel
creations only when they name the same link. Ticket-by-ticket parity tests run
against SQLite and PostgreSQL so those SQL rules cannot quietly drift from the
PHP presentation rule.

Only then was the same 200-row display cap applied:

| Ticket queue, 50,000 conversations | Before | After |
| --- | ---: | ---: |
| Open | 2,206 ms / 21.0 MB | **172 ms / 1.0 MB** |
| All | 7,052 ms / 62.8 MB | **227 ms / 1.1 MB** |

The count remains uncapped. A desk with 12,500 tickets says how many are in the
selected lane while rendering only the first 200 in the established attention
order. Every filter is applied before that window, and an id tie-break keeps the
boundary stable when bulk-created tickets share timestamps.

### The ticket queue used to issue one query per ticket

Fixed in #838 while this page was being written, and recorded because the size
of the difference is the argument for measuring at all:

| Ticket queue (all), 50,000 conversations | Queries | ms |
| --- | ---: | ---: |
| Before | 12,518 | 9,503 |
| After | **18** | **6,928** |

Both rows are on the fixture as it stood then, which wrote no lifecycle history
— that is what makes them comparable to each other. They predate the row cap;
the current figure in the table above is 20 bounded queries and 227 ms on a desk
that does carry lifecycle history.

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
other. They are historical uncapped figures; comparing either directly to the
current 227 ms result would mix the lifecycle experiment with the much larger
effect of rendering only 200 rows.

**About three per cent, and one query.** That was what the eager load cost when
it had something to load — a long way from the 9,503 ms the N+1 was costing.
The row cap now bounds that eager load too, which is why the current response is
roughly one megabyte rather than sixty-three.

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

A separate run from the table at the top of the page, which has 151 and 613 ms
for the same two windows. That spread — a millisecond on one, six on the other,
under one percent — is what three-run timings on this machine look like, and
the query counts and sizes agree exactly.

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

It does not say Wayfindr is universally fast. It says two specific queues were
unbounded, one of them also held an N+1, and those measured defects are now
bounded. The named gaps above — Reverb concurrency, attachments, heavy cobrowse
mutation batches and real contention — remain gaps.

Fixing it was not the point of taking the measurement, but measuring again is
what makes the result more than an impression. The ticket queue moved from
12,518 queries to 20, from 62.8 MB to 1.1 MB, and from about seven seconds to
227 ms on the same shaped 50,000-conversation fixture. Those are separate
query-count and row-cap improvements, recorded separately above so one does not
borrow the other's credit.
