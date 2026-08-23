# Reporting

Status: shipped. `/dashboard/reports`, admin and owner only.

Wayfindr's answer to "are we getting faster, and who is carrying the queue?".
The measurement decisions behind it are recorded in
[ADR 0015](../decisions/0015-measuring-support-work.md); this document describes
what the surface shows and, more usefully, what it cannot.

## What it reports

Five tabs, over a selectable range of 7, 30 or 90 days, filterable to a single
site.

**Volume.** Conversations opened and closed per day, and the number open right
now. Every day in the range is drawn whether or not anything happened on it — a
chart that omits quiet days compresses the timeline and makes a weekend look
like an afternoon.

**Speed.** First-response and resolution time as median and 90th percentile,
plus how often resolutions did not hold.

**Agents.** Replies sent and conversations closed per agent.

**Tickets.** The same three questions asked of the ticket half of the desk:
volume, resolution time and reopens, and who carried the work. It has its own
tab rather than doubling the conversation figures, because the two halves have
different histories — see below.

**Satisfaction.** Whether it helped, from the visitors who answered — and what
they wrote. Every other tab reports how fast the desk moved; a desk can improve
all of those while getting worse at helping people. See
[asking whether it helped](satisfaction.md) for the rules that keep a percentage
from being reported over people who said nothing.

Both the daily series and the agent table export as CSV, following the account
audit page's export conventions.

## Two halves with different memories

Conversation lifecycle events began on 22 August; ticket closes and reopens have
been audited since 24 May. So on most installs the ticket tab can describe a
full quarter while the conversation tabs are still accumulating, and reading
them as one dataset would be wrong.

Each half therefore states **its own recording boundary**, stamped at migration
time, and reports closes it cannot measure as *counted but not measured* rather
than folding an unknowable duration into a median. The ticket boundary is
usually much older than the conversation one. Neither is infinite: an install
upgraded from before ticket auditing existed has tickets whose earlier closes
were never written down.

## Only a transition counts

Both halves read their lifecycle log as a state machine rather than a list of
actions, because the log is not a clean alternation of close and reopen.

A close submitted twice — a double-click, a retry, a stale page — is one
resolution, not two. And a reopen that reopens nothing is not a resolution that
failed: the ticket interface offers the same **Reopen** control for a closed
ticket and a pending one, so taking a ticket off hold used to be recorded as a
reopen, which both claimed a failed resolution and restarted the clock at the
un-hold. Off-hold is now its own event, and history already recorded is read
correctly rather than rewritten.

## Medians, not averages

Support work is long-tailed: most replies are quick and a few take a day. An
average sits in the empty space between the two and describes neither. The
median says what a typical visitor experienced; the 90th percentile says what
the unlucky tenth did, which is the number worth acting on.

Percentiles are interpolated between the two observations that bracket them.
Nearest-rank was tried first and rejected because it biases small samples low —
it reports the faster of two durations as their median, flattering a desk
exactly when the sample is thin and someone is trying to decide whether there is
a problem yet.

## Two of these numbers are older than the others

This is the part worth reading before trusting a chart.

**Conversations opened** and **first-response times** are recoverable from data
the product has always kept. `conversation_messages` records every message with
a sender morph, so the first message from a `User` *is* the first response,
retrospectively, for the whole life of the install.

**Closes, resolution times and reopens** are read from conversation lifecycle
audit events, which only began being written in the release that added them.
Before that, `conversations.closed_at` was a current-state column that was nulled
on every reopen, so the previous answer was destroyed each time. None of it can
be backfilled.

The page says so directly, and names the date this install began keeping them.
A flat line before that date is an absence of records, not an absence of work,
and only that date can tell the two apart.

### The date is stamped, not inferred

A migration writes it once, into `operator_settings` under
`reporting.lifecycle_recording_began_at` — an install fact rather than anything
an operator configures.

It cannot be derived. The obvious proxy, the earliest lifecycle event on record,
is circular: that event belongs to a conversation created *before* it, so the
first close on any install could never be measured, and the boundary would move
every time old history was purged.

### Closes that are counted but not measured

A conversation created before that date may have been closed and reopened
several times while nothing was writing it down. Measuring such a close from the
conversation's creation would charge it with stretches of work that were already
finished — silently inflating the median and the p90 while the page presented
the figure as measured.

Those closes are counted as volume and set aside from the durations, and the
Resolution table names how many. A smaller sample the page admits to is better
than a larger one that is wrong. A reopen recorded after the boundary makes the
conversation measurable again, however old it is, because the stretch ending in
that close is fully on the record even when the conversation is not.

## What it deliberately does not do

**Reuse the unattended-alert definition historically.** #742 asked for the
queue-health trend to reuse `UnattendedConversationAlertCollector` rather than
inventing a second definition that disagrees with the alerts. That is not
possible: the collector reads *unread notification state*, and reading a
notification destroys the evidence, so it cannot answer "how many were unattended
last month". So the waiting figure on the Volume tab is a live count,
labelled as one, and the alert threshold appears beside it only as context.

A "first responses slower than the alert threshold" row was built and then
removed. The alert threshold asks whether anyone has *looked* at a conversation
in five minutes, which almost every first reply misses, so the count read
"nearly all of them" regardless of how the desk was performing. A number that is
always alarming trains people to ignore it — the resting-state amber pill
ADR 0014 removed. The median and the 90th percentile answer how fast without
borrowing a number that means something else.

**Group or truncate dates in SQL.** There is no portable spelling: `date_trunc`
is PostgreSQL, `strftime` is SQLite, and the test suite runs on the second while
every documented install runs on the first. A driver-specific grouping
expression would be green in CI and broken in production. Rows are streamed and
bucketed in PHP instead. See [testing](../development/testing.md#database-drivers-in-tests).

**Chart in a library.** The bars are `div`s sized as percentages, which is the
plainest thing that answers the question and adds no third-party request to a
product that serves every byte it renders (ADR 0014). Opened is filled and
closed is outlined, so the pair survives monochrome printing and readers who do
not separate the two hues.

## Scope and visibility

Admin and owner only, matching the account audit page whose records it reads.
Within that, the same three rules:

- the account is pinned;
- sites are restricted to the agent's own visibility allowlist;
- a site id in the query string is validated against that allowlist before use,
  so it can only ever narrow the answer. An unrecognised id is ignored and the
  report falls back to all visible sites, which the site selector then shows.

**Archived sites are counted.** Archiving takes a site out of service without
destroying anything, so excluding it would mean tidying up silently rewrote last
quarter's numbers. Purging is the operation that removes history, and a total
that falls after a purge is correct rather than corrupt.

## Agent activity is a workload figure

The Agents tab exists so an imbalance is visible while it is still a scheduling
problem. Two things follow from that intent.

Deactivated agents stay listed. They did the work, and a total that changes when
someone leaves is not a total.

Reply and close counts are not a performance ranking. A high close count can mean
an agent is taking the easy tickets; a low one can mean they are carrying the
hard ones. The numbers answer "where is the work going", not "who is best".
