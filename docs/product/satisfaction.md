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

Each row records which close it answers: `episode_event_id`, the id of the
`conversation.closed` audit event, alongside `episode_closed_at` for reporting.
The table is **unique on `(conversation_id, episode_event_id)`**, and neither
column is nullable.

The identity is the event, not the time. A timestamp has second resolution, so a
conversation closed, reopened and closed again inside one second would give two
genuinely different episodes the same key — the second answer silently
overwriting the first while reporting counted both closes.

Not nullable matters twice over: reporting filters on `episode_closed_at`, so a
null-episode row would sit in the table and appear in no report ever — data that
is silently invisible rather than absent — and PostgreSQL treats nulls as
distinct in a unique index, so the bound below would not hold for exactly those
rows. The bound is a database
rule rather than a check the endpoint performs, because a check that reads and
then writes loses the race: two concurrent requests both see no row and both
insert.

That bound matters more than it looks. Response rates for this kind of question
are low, so the denominator is small, and a small denominator is cheap to swamp
— a script holding a visitor token posting the same score two hundred times must
not move the aggregate two hundred times.

The close must be one the reports can **count**: a recorded
`conversation.closed` event, never the `closed_at` column. On an upgraded
install a conversation closed before lifecycle recording began still has that
column set, and an answer about it would be counted with no close to count
against it — the same *1 of 0 closes answered* the cohort rule exists to
prevent, arriving through a different door.

**A late response speaks only for the close it was about.** If the conversation
is reopened and closed again while an answer is on its way, the response that
comes back neither hides the new prompt nor acknowledges it — no thanks, no
error. Both would describe a question the visitor has not answered yet.

**An answer is bound to the close it was shown for.** The widget sends the
episode it is displaying, and the server refuses an answer whose close is no
longer the current one. Otherwise a conversation reopened and closed again
between the prompt appearing and the answer arriving would have that answer
attributed to the *new* close — and that new prompt marked answered, so nobody
would ever be asked about it.

**A rating nobody asked for is refused.** The endpoint is reachable without the
widget, so it checks rather than assumes: an answer for a site whose operator
switched collection off is rejected, and so is an answer about a conversation
that has never closed. Otherwise the promise this page makes — that ratings are
written only after an enabled prompt, about a finished stretch of work — would
hold for the widget and not for the API.

## Asking about the agent too

A site can choose the **starter template** instead of the single question. It
asks two separate things, and keeps them separate everywhere afterwards:

| Question | Answers |
| --- | --- |
| How was your overall support experience? | good / ok / bad |
| How was the help you received from *agent name*? | good / ok / bad / not applicable |
| Anything you would like us to know? | optional comment |

The overall question is the same question the single-question template asks, so
its answers count towards the same figure. The agent question is a different
question and is reported on its own: an agent score is never folded into
overall satisfaction, and an overall score is never read as a verdict on an
agent.

### Which agent, if any

The agent question names **the one person who helped in the closed stretch of
work being rated**, or it is not asked at all.

- **The episode** runs from the conversation's previous `conversation.closed`
  event — or its creation if there is none — up to and including the close
  being rated. It is bounded by the previous close rather than the reopen,
  because a reply that reopens a conversation is written before the reopen is
  recorded, and it belongs to the episode it started. Both bounds are
  inclusive: a message in the same second as a close is counted on both sides,
  so the uncertainty can only produce "more than one person", which asks
  nothing, and never leaves somebody named for help they did not give alone.
- **A qualifying agent** is a human dashboard user who sent a message to the
  visitor inside that episode. Conversations carry no private notes, so every
  message a user sends is one the visitor saw. Messages from integrations, API
  tokens and proactive rules are not a person helping.
- **Exactly one qualifying agent** → the question names them.
- **None, or more than one** → no agent question. With two people in the
  episode — a handoff, a colleague stepping in — there is no honest single
  answer to "how was the help from X", and picking one would attribute a score
  to someone the visitor may never have meant. Overall feedback is still
  collected.
- **Who is assigned, and who clicked Close, settle nothing.** Assignment is
  current state that changes after the fact, and closing is housekeeping that a
  colleague or the visitor may do. Neither says who helped.

### What a response keeps

The agent and the template are **snapshotted onto the response** when it is
written: the agent's user id and the display name the visitor was shown, and
the template key and version. Reassigning the conversation, renaming the agent,
or switching the site back to the single question later moves none of it. If the
agent's account is deleted, the id is cleared and the name stays.

The name recorded is **the name the visitor was shown**, not whatever the
account is called when the answer arrives. The prompt carries a handle the
server signed over the close, the agent and that name, and the answer is
accepted only with the name it was issued with. So an agent renamed — or deleted
— between the prompt and the answer is still recorded under the name the
visitor read, and a forged handle or name is refused.

The widget shows the template and agent it received with the prompt and sends
them back with the answer. The server recomputes the qualifying agent for that
close and refuses an agent answer that names anyone else, so the answer is
attached to the person the visitor actually saw named. Changing the site's
template while a prompt is open does not change what that open prompt meant: the
answer is stored against the template it was shown under.

### Skipping is not answering

- The visitor can **skip** the survey. Skipping hides that episode's prompt in
  that browser — for the open widget even where storage is unavailable — and
  records nothing: an unanswered question stays absent rather than becoming a
  row.
- The agent question is **optional**. Overall feedback can be sent without it.
- **Not applicable** is an answer that the question did not fit, not a score.

None of the three contribute to an agent figure. An agent result counts only
good, ok and bad answers; *applicable* is how many responses were asked the
question, and *answered* is how many gave a score. Ratings collected before the
starter template existed stay overall feedback: no agent score is inferred for
them.

## A site's own template

An account admin can write the site's own questions under **Sites → the site →
Asking how it went → Edit your own template** (#1157).

- **Draft, then publish.** The draft is a work in progress that no visitor ever
  sees. Publishing turns the saved draft into the next numbered **version**, and
  a version is never edited afterwards: a change in meaning is a new version. A
  response records the exact version it was answered under, so a prompt that was
  open while a new version was published is stored against the one the visitor
  actually read.
- **Publishing is not choosing.** The site's rating settings decide which
  template is asked: one of the built-ins or the newest published version. A site
  can switch back, or stop asking, without losing a version or an answer.
- **What can change:** the wording of the overall, agent and comment questions in
  English, German and Italian; the answer labels; whether the agent and comment
  questions are asked; and the scale — three answers or five stars. English is
  required for everything that is asked, and every other language falls back to
  it. The agent question must contain `{name}`, and follows the same one-agent
  rule as the starter template.
- **What cannot:** which answers count as positive. It is fixed by the scale —
  *good* on three answers, four and five stars on five — so a positive share means
  the same thing on every site and in every version.
- **Never combined.** A custom version is a different question from the built-in
  one, so its answers never count towards the built-in figure or the per-agent
  table. The Satisfaction tab reports each version on its own: its wording, scale,
  distribution, positive share and, on five stars, its average. A five-star
  average is never placed in the same figure as a good/ok/bad share.
- **Text is plain text.** Questions and labels are stored as typed and escaped
  wherever they are shown, in the editor, the report and the widget.

## Never a percentage nobody answered for

The reports page has a **Satisfaction** tab. Its rules:

- **Every percentage is a share of the people who answered**, never of the
  closes. People who said nothing are not counted as satisfied.
- **An answer belongs to the period its close belongs to**, not to the moment it
  arrived. Filtering answers by arrival time while counting closes by close time
  puts them in different cohorts, and a visitor answering shortly after a
  boundary then produces output like *1 of 0 closes answered*.
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
