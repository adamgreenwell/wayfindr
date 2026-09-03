# 0018: Public API and programmatic access

Date: 2026-08-23

## Context

`routes/api.php` is seventy-nine lines, and every one of them is either the
widget talking to its own backend or an inbound webhook from GitHub, GitLab or
Jira. There is no authenticated public surface, no token issuance, and nothing
that fires outward when a conversation opens or a ticket closes.

[#752](https://github.com/adamgreenwell/wayfindr/issues/752) argues the
consequence is structural rather than a missing feature: **every integration
anyone will ever want, we have to build.** The competitive read that produced
that issue put it first among all recommendations, and it is the only item on
that list that reduces future work rather than adding to it.

It also gets more expensive every release. Each surface shipped before the API
exists is another shape to retrofit into a public contract, and a public
contract is far harder to change once people depend on it.

### This is the most sensitive surface in the product

Support transcripts are the most sensitive data Wayfindr holds, and this is the
first thing that would grant programmatic access to them. Cobrowse aside, every
existing access path has a human at one end: an agent signed into a dashboard,
a visitor in their own conversation, an operator at a console. A token has
nobody at either end and no session to expire.

So the isolation model has to be settled before the first endpoint exists,
not discovered afterwards.

### What the codebase already does

Worth stating plainly, because the decisions below mostly follow from it rather
than from preference:

- **`composer.json` requires six packages.** `php`, `laravel/framework`,
  `laravel/reverb`, `laravel/tinker`, `league/flysystem-aws-s3-v3`,
  `predis/predis`. No Sanctum, no Passport, no Socialite. Token authentication
  would be the first auth dependency the project takes.
- **Bearer-shaped credentials are already hand-rolled.** `VisitorSessionToken`
  issues an encrypted JSON payload and checks it with `hash_equals`. Break-glass
  grants are a model with statuses, scopes and expiry. Neither reached for a
  package.
- **Webhook signing already has a house style.** GitHub, GitLab, Jira and
  inbound mail all verify `sha256=` + `hash_hmac('sha256', $body, $secret)`
  through `hash_equals`, and an architecture test fails the build if a signature
  is compared any other way.
- **Site visibility is already the unit of scope.** `site_user` decides which
  sites an agent supports, and every reporting figure derives its scope from it
  rather than from the account alone.

## Decision

### Tokens are hand-rolled, not Sanctum

A `api_tokens` table holding a **SHA-256 hash** of the token, never the token
itself; the plaintext is shown once at creation and is unrecoverable after.

"Unrecoverable" is a claim about every store, not only the tokens table. The
plaintext must not be written anywhere in readable form on its way to that one
screen either — the default session driver is the operator's database, so
flashing it across a redirect put a working credential in the `sessions` table
and undid the guarantee from a direction the sentence above does not cover. It
is encrypted before it goes near the session, behind the same app key that
external provider credentials sit behind.

The constraint this sets for later work: **no surface may show an existing
token again.** Re-displaying one would require storing something reversible,
and there is no version of that which keeps this promise.
Alongside it: an owning account, a name, an abilities list, `expires_at`,
`last_used_at`, and `revoked_at`.

Sanctum was considered and declined. Its personal-access-token half is the same
`hash('sha256', $plain)`-and-lookup we would write, so the audit benefit is
smaller than it appears; its distinctive value is the SPA cookie mode, which
this project does not use and which carries the bulk of its footguns. Taking a
dependency to avoid writing a hash lookup, and inheriting a session-auth mode
we would have to actively keep switched off, is a worse trade than the fifty
lines it saves.

This is a narrow exception to "don't roll your own auth", and it is worth being
explicit about why it is narrow: **no cryptography is being invented here.**
Password hashing, session handling and CSRF remain Laravel's. What is
hand-rolled is a hashed lookup and an expiry check.

### A token belongs to an account, and can see less than one

Every token is owned by an account, and account isolation is enforced in the
query rather than checked afterwards — the rule the reporting scope already
follows. There is no cross-account token and no operator-wide token.

A token may also be **restricted to specific sites**, mirroring `site_user`. A
token with no site restriction sees the account's sites; a restricted token sees
the intersection, and the intersection can only narrow. An integration that
watches one site should not be a credential for all of them.

The ceiling on all of it is the issuer: **a token can never reach further than
the person who created it.** Site access binds an admin as much as an agent —
`rbac-waypoints` is explicit that no role bypasses it because a controller
checked only `account_id`, and that elevated cross-site views must be an
explicit decision. Issuing a token is not that decision. So an admin who does
not support every site gets a token pinned to the sites they do, and asking for
sites they cannot see grants nothing rather than everything.

**Every token is pinned to a list**, including one issued by somebody who
supports every site that currently exists. Seeing all of today's sites is not
account-wide authority, and there is no such authority here: visibility is per
site, so a site created later and assigned to its creator is invisible to
everyone else — and an open-ended token would have read it anyway, outliving the
ceiling it was issued under. The cost is that a new site does not join existing
tokens, which is the right way round: a credential that quietly widens as the
account grows is worse than one that has to be reissued on purpose.

Whether a token is restricted is **stored on the token**, not inferred from
whether any site rows remain attached to it. Sites can be archived and purged,
and the join rows go with them — so a token restricted to one site would
otherwise inherit the entire account the moment that site was deleted. That is a
privilege escalation performed by an admin doing something else entirely, with
nothing in the token's own record to show it happened. A restricted token whose
sites are all gone reaches nothing.

Abilities are coarse and deny-by-default: `read` grants the read surface,
individual write abilities are named, and anything not granted is refused.

**Archived sites remain readable**, matching `ReportingScope` rather than the
widget and inbound-mail paths, which refuse them. The difference is what the
surface is for: archiving takes a site out of *service*, and a read surface is
not service. Dropping archived sites here would make a year of transcripts
vanish from an integration the day somebody tidied up, while the dashboard
carried on showing them. Purge is the operation that removes data.

The write surface has the opposite rule, and this is the reason to state the
read rule explicitly rather than let it be inferred: an archived site stops
accepting new work. Creating a conversation or ticket for one is refused, as is
posting to or transitioning a record already on one. An idempotent replay of a
write accepted before archive still returns its original receipt; it does not
perform the write again.

### Break-glass grants do not extend to tokens

A break-glass grant widens what a *person* can see, under an approval trail,
for a bounded window, read-only ([ADR 0008](0008-platform-operator-break-glass.md)). It is
built around somebody being accountable for having looked.

A token has no person at the other end. So a grant never widens a token's reach
and a token never satisfies a grant: the two systems do not compose. Stated here
because the alternative is attractive and wrong — "the grant already permits
this read" would quietly turn a bounded human exception into a standing
programmatic one.

### Versioned from the first response

Routes live under `/api/v1/`. A public contract with no version is a contract
that can never change, and the cost of adding the segment now is one path
component.

The existing widget and inbound-webhook routes are **not** part of this contract
and are not versioned by it. They are internal surfaces that happen to be
reachable over HTTP, and freezing them by accident is the thing versioning is
supposed to prevent.

### Rate limited per token, by name

A named throttle per token id, alongside the existing named limiters rather than
inside them. The widget's limits protect a visitor's browser from a mistake; a
token's limits protect an account's data from a script, which is a different
question with a different answer.

Two details that are forced rather than chosen. The authentication middleware
implements `AuthenticatesRequests` — an empty marker interface whose only effect
is Laravel's middleware priority, where it sits immediately before
`ThrottleRequests`. Without it, the route's throttle sorts *ahead* of
authentication and the per-token limiter keys on a token that has not been
resolved yet, so every token behind one address silently shares one bucket.

For the same reason, **failed authentication is bounded inside that middleware**
rather than by a throttle placed before it: a throttle placed before it does not
stay before it. Only failures spend that budget, so a working integration never
touches it however much traffic it sends.

**Exhausting that budget locks out invalid credentials, not the address.** An IP
is not a tenant. Self-hosted installs sit behind office NAT, CI egress and cloud
gateways, so the address that burned the budget with a stale token in a retry
loop is also the address every other integration in the building calls from.
Refusing before the lookup meant one broken script took all of them down while
they were holding correct credentials — denial of service by a co-tenant, which
is a worse failure than the enumeration the budget exists to bound.

So the lookup no longer sits behind the budget, which means it has to be cheap
to reach. It is gated on **shape** instead: every issued token is the prefix
plus 40 base62 characters, so anything else cannot be in the table and is
refused on a regex. A flood of malformed guesses never reaches the database.
A well-formed guess still costs one indexed read, locked out or not, and that
is the price of not locking a building out of its own integrations.

### Read first, then less than the dashboard can do

The first surface shipped read-only: conversations, messages, tickets, visitors.
The write surface followed separately and stays deliberately narrower than the
dashboard:

- open a conversation for a visitor the token can already identify;
- post a text message to a conversation;
- create a ticket; and
- change a ticket's status or assignment.

There is no delete, no transcript mutation, no ticket subject/description edit,
no attachment path and no way to reach cobrowse. `write` is a separate ability:
it does not imply `read`, and `read` does not imply it. Write responses are
receipts containing the caller's own input plus generated identity, not the
ordinary read payload; otherwise a write-only token could turn a PATCH into a
read of the ticket it guessed.

Explicitly out: cobrowse, in any form. The consent model is the product's
strongest privacy claim, and a token reaching it before that has been thought
through properly would undo the claim rather than extend it.

### A token authors its own messages

An API-posted message is stored with the `ApiToken` as its polymorphic sender.
It is not attributed to the user who issued that credential: doing so would put
a machine's words in a person's mouth and make every agent figure keyed on
`sender_type = User` claim work that person did not do.

Visitor surfaces present it on the support side, because it is a message the
visitor is meant to receive. Reporting and alerting do **not** count it as a
human agent reply: it does not satisfy first-response time, add agent activity,
or silence the unattended-conversation alert. The human queue therefore keeps
calling the conversation unanswered until a person replies.

The ordinary lifecycle still applies. Posting to a closed conversation reopens
it and records `conversation.reopened`, with the token — and `integration` — as
the actor. Ticket creation, status changes and assignment changes are likewise
audited against the token rather than its issuer. Assigning a ticket still
alerts the assigned agent; the notification names the integration that did it.

### Every POST carries a short-lived idempotency key

`POST` writes require an `Idempotency-Key` header. The key is scoped to the API
token, valid for 24 hours and hashed before storage. A retry with the same key,
path and validated input returns the original resource receipt without another
insert, broadcast, email or lifecycle event. Reusing the key for any different
request returns `409`.

Receipts store only the resource type and id, not a second copy of a transcript
or ticket response. They are pruned hourly after the retry window. Writes for
one token briefly serialize on that token's row so every supported database has
the same check-and-insert behavior under concurrent retries; unrelated tokens
do not wait on each other. Revocation takes the same lock, which leaves a clean
ordering: either the write commits before revocation, or the revoked token is
refused before it changes anything.

## Consequences

**A token is a standing credential for support transcripts, and the product now
has to treat it as one.** Tokens carry `last_used_at` so an operator can tell a
live token from a forgotten one, expiry so an unused token stops working on its
own, and revocation that takes effect immediately. Issuance and revocation are
audited like any other account event.

**Reads through a token are not attributable to a person**, and no amount of
logging changes that. This is the honest cost of the feature: a transcript read
by a token cannot answer "who read it" the way a dashboard read can. It is the
reason the account/site scoping is enforced in the query and the reason grants
do not compose with tokens — the containment has to come from what the
credential can reach, not from who is holding it.

**We own the token code.** Nobody else patches it. The surface is small enough
that this is a fair trade, but it is a real one, and it means the token path
gets the same mutation-tested treatment the signature verification already has.

**Six dependencies stay six.**
