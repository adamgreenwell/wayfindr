# Asking whether it helped

Status: shipped. Off by default, per site.

Every other figure Wayfindr reports says how *fast* the desk moved. A desk can
improve volume, first-response time and resolution time all at once while
getting worse at helping people. This is the only measure in the product of
whether support **worked**.

## How it is asked

When a conversation closes, a site with the prompt enabled shows the visitor
three choices — **good**, **ok**, **bad** — and an optional comment box.

Three points rather than five. The useful signal is "did this go badly", and a
finer scale adds noise and translation problems without adding meaning: nobody
can say what the difference between a 3 and a 4 is, and two people will not
agree.

The prompt is **off unless an operator turns it on**, under **Sites → the site →
Asking how it went**. A question nobody chose to ask is an interruption, and the
answer to an unasked question is worth nothing anyway.

The intro line is operator-authored, so a German desk asks in German. The three
labels and the comment prompt come from the widget's own catalogue
([ADR 0017](../decisions/0017-speaking-the-visitors-language.md)).

## What is stored, and why it is a row

A rating is a row in `conversation_ratings`, not a column on the conversation.

**Absence of a row is what "unrated" means.** That is the property everything
else depends on: a column would need a null, and a null in a numeric column is
one careless `AVG()` away from being read as a zero. There is no schema shape
here that can be accidentally averaged over people who said nothing.

**A conversation closed twice can be rated twice.** The second answer is a
second data point, not a correction — the same conversation going well and later
badly is exactly the signal worth keeping. Within one closed episode, though, a
visitor changing their mind *replaces* what they said: one answer per close.

That bound matters more than it looks. Response rates for this kind of question
are low, so the denominator is small, and a small denominator is cheap to swamp
— a script holding a visitor token posting the same score two hundred times must
not move the aggregate two hundred times.

## Never a percentage nobody answered for

The reports page has a **Satisfaction** tab. Its rules:

- **Every percentage is a share of the people who answered**, never of the
  closes. People who said nothing are not counted as satisfied.
- **Nobody answering is not a score.** With no answers the tab says so in words.
  A `0%` sitting where a score goes reads to anyone skimming as "everybody said
  it went badly", which is the opposite of the truth.
- **A thin sample says it is thin.** Below ten answers the tab says to read the
  figure as a direction rather than a measurement, because one more answer would
  move it visibly.

## The comments are the point

A score tells you something went wrong. Only the comment says what.

**What people said** lists the most recent comments with their score and the
conversation's support code, so an admin can click through to the conversation
behind a complaint. Collecting a comment and never showing it would be worse
than not asking for one — the visitor spent effort answering a question nobody
reads.

Comments are visitor-authored text and are treated as such: escaped on render,
never used as a link label, and the conversation is referenced by **support
code** rather than subject line — the same rule the audit page follows, because
a subject is visitor-authored and a support code is a reference by construction.

## Scope and retention

Ratings are scoped like every other reporting figure: account pinned, sites
restricted to the agent's visibility allowlist, and a site id in the query
string can only narrow.

`conversation_ratings` cascades on both `conversation_id` and `site_id`, so
purging a site takes its ratings with it. That is deliberate and matches the
promise purge makes elsewhere in the product.

Recorded in the [data inventory](../privacy/data-inventory.md): a comment is
free text a visitor typed, and it can contain anything they chose to put there.
