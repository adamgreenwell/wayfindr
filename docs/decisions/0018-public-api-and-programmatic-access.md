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

Abilities are coarse and deny-by-default: `read` grants the read surface,
individual write abilities are named, and anything not granted is refused.

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

### Read first, and less than the dashboard can do

The first surface is read-only: conversations, messages, tickets, visitors. The
write surface follows separately and stays deliberately narrower than the
dashboard — create a conversation, post a message, create or transition a
ticket.

Explicitly out: cobrowse, in any form. The consent model is the product's
strongest privacy claim, and a token reaching it before that has been thought
through properly would undo the claim rather than extend it.

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
