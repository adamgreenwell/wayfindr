# The Wayfindr API

Status: **v1, read and narrow write.** Outbound webhooks are not shipped yet.

Wayfindr's public API exists so that integrations do not have to be built by
Wayfindr's team. It is deliberately smaller than the dashboard, and the reasons
for each limit are in [ADR 0018](../decisions/0018-public-api-and-programmatic-access.md).

## Getting a token

**Account → API tokens → Issue a token.** Admins only.

The token is shown **once**, immediately after creation. Wayfindr stores a
SHA-256 hash of it, not the token, so it cannot be recovered or resent — if you
lose it, revoke it and issue another. It is not written to the session store in
readable form on the way to that one screen either: the default session driver
is your database, and a plaintext credential sitting there would undo the
hash-only guarantee from a different direction. The list afterwards shows only the last
four characters, which is enough to match a row to the credential in your
deployment config and not enough to use it.

Two settings on the form are worth spending a moment on:

- **Expires after.** Left empty, the token never expires, which means noticing
  it becomes nobody's job. Pick a horizon you will actually revisit.
- **Restrict to sites.** Tick none and the token reaches every site you support
  **today**. A site created afterwards is not added to it — issue a new token
  when you want one to cover more. An integration that watches one site should
  not be a credential for all of them. If every site a token was restricted to
  is later purged, the token reaches **nothing** — it does not fall back to the
  whole account.

Read and write are separate abilities. Neither implies the other. A reporting
export normally needs only `read`; an integration that opens work or changes a
ticket needs `write`; a two-way integration needs both.

A token can never reach further than the person issuing it. If you do not
support every site on the account, a token you issue is pinned to the sites you
do — and ticking sites you cannot see grants nothing rather than everything.

Issuing and revoking a token are both recorded in the account audit log, naming
which token and who did it, and searchable by the token's name. Revoking keeps the row rather than deleting it: what the credential
existed for and when it was last used is the part worth keeping afterwards.

## Making a request

```bash
curl -H "Authorization: Bearer wfk_your_token_here" \
  https://support.example.com/api/v1/me
```

Errors come back as JSON whether or not you send `Accept: application/json`, so
the example above behaves the same as one that sets it.

Everything lives under `/api/v1/`. The version is in the path from the first
release, because a contract with no version is a contract that can never change.

`GET /api/v1/me` reports what the token is and what it can reach — including
which sites, after any restriction has been intersected with the account. It is
the fastest way to answer "why is this returning fewer rows than I expected".

## What you can read

| Endpoint | Returns |
| --- | --- |
| `GET /api/v1/conversations` | Conversations, newest first. Filter by `site_id` and `status`. |
| `GET /api/v1/conversations/{support_code}` | One conversation, by its support code. |
| `GET /api/v1/conversations/{support_code}/messages` | The transcript, oldest first. |
| `GET /api/v1/tickets` | Tickets, newest first. Filter by `site_id` and `status`. |
| `GET /api/v1/tickets/{id}` | One ticket. |
| `GET /api/v1/visitors` | Visitors. Filter by `external_id` or `email`, both exact. |
| `GET /api/v1/visitors/{id}` | One visitor. |

Conversations are addressed by **support code** rather than id: it is the
identifier the product already uses with people, and it does not tell a reader
how many conversations the install has.

### Pagination

Lists are **cursor paginated**, not page-numbered:

```json
{
  "data": [ ... ],
  "meta": { "per_page": 25, "next_cursor": "eyJ...", "previous_cursor": null }
}
```

Pass `?cursor=` with the value of `next_cursor` to continue, and `per_page` up
to 100. Cursors rather than page numbers because a support inbox changes while
you are reading it — with offsets, new conversations arriving between requests
silently shift rows across page boundaries, and a walk loses some with no error
to notice.

## What you can write

The write surface is deliberately smaller than the dashboard:

| Endpoint | Accepted fields | Result |
| --- | --- | --- |
| `POST /api/v1/conversations` | `site_id`, `visitor_id`, optional `subject` | Opens a conversation for a known visitor. |
| `POST /api/v1/conversations/{support_code}/messages` | `body` | Posts one customer-visible text message. |
| `POST /api/v1/tickets` | `site_id`, `subject`, optional `requester_id`, `description`, `priority` | Creates an open ticket. |
| `PATCH /api/v1/tickets/{id}` | `status`, `assignee_id`, or both | Changes status (`open`, `pending`, `closed`) and/or assignment. |

There is no delete endpoint, attachment write, cobrowse write, transcript edit,
or broad ticket edit. Ticket priority may be `low`, `normal`, `high` or
`urgent`. An assignee must be an active agent who supports that ticket's site.

### Idempotent POSTs

Every `POST` requires an `Idempotency-Key` header containing 1 to 255 visible
ASCII characters:

```bash
curl -X POST \
  -H "Authorization: Bearer wfk_your_token_here" \
  -H "Idempotency-Key: 7c8df5d6-4dcf-455a-a488-f67352f45a70" \
  -H "Content-Type: application/json" \
  --data '{"site_id":12,"visitor_id":481,"subject":"Checkout help"}' \
  https://support.example.com/api/v1/conversations
```

Keys are scoped to the token and retained for 24 hours. Repeating the same key,
path and validated input returns the same created resource with
`Idempotent-Replayed: true`; it does not insert, broadcast, email or record the
lifecycle action again. Reusing a key for different input or another path
returns `409`. Only a SHA-256 hash of the key is stored, and expired receipts are
pruned hourly.

### Who authored an API message

The API token did. Wayfindr stores that token as the message sender and shows
the message on the support side to the visitor, but it never attributes the
machine's words to the person who issued the credential.

That distinction is functional, not cosmetic. An integration message does not
count as a human first response, agent activity, or a reason to silence the
unattended-conversation alert. A person still owes the visitor a reply. Posting
to a closed conversation does follow the ordinary lifecycle and reopens it,
with the integration recorded as the actor.

Ticket writes are audited against the token for the same reason. Assigning a
ticket still alerts the assigned agent, naming the integration that assigned
it.

### Write-only does not become read-by-response

Create responses contain the input the caller supplied plus the generated id or
support code. A ticket `PATCH` returns only its id and the fields that the request
asked to change. Those deliberately are not the full read payload: otherwise a
token granted `write` but not `read` could use mutation responses to read
support records anyway.

## What you cannot read, and why

**`metadata` is never returned.** On conversations and visitors it is a
free-form column written by the widget, the SDK and the host page, so its
contents are whatever somebody's website put there. Publishing it would export
data the operator never chose to expose.

**`anonymous_id` is never returned.** It is the widget's browser-session handle
rather than an identifier for a person. Use `external_id`, which is your own
identifier and the one you can actually join on.

**Cobrowse is not reachable at all**, in any form. The consent model is the
product's strongest privacy claim, and a token reaching it before that has been
thought through properly would undo the claim rather than extend it.

## Scope and isolation

A token belongs to **one account** and can never see another. Account and site
filters are applied in the query rather than checked afterwards, so an endpoint
cannot forget them.

A `site_id` filter can only **narrow**. Asking for a site the token cannot reach
returns nothing rather than everything — "unknown filter is ignored" would be a
silent scope escalation.

A conversation or ticket outside the token's reach returns **404, not 403**.
Telling a caller that a support code exists but is not theirs confirms it
exists, and support codes are short.

**Archived sites are still readable.** Archiving takes a site out of service —
its widget stops serving and it stops accepting inbound mail — but it does not
delete what happened on it, and the dashboard still shows that history. An API
that dropped archived sites would make a year of transcripts vanish from an
integration the day somebody tidied up. **Purging** is the operation that
removes data, and it removes it from here too.

Archived sites are **not writable**. New conversations and tickets, messages on
existing conversations, and ticket transitions are all refused after archive.
An idempotent replay of a write accepted before archive still returns its
receipt because it performs no new write.

**Operator access grants do not extend to tokens.** A break-glass grant widens
what a *person* can see, under an approval trail, for a bounded window
([ADR 0008](../decisions/0008-platform-operator-break-glass.md)). A token has no
person behind it, so the two do not compose in either direction.

## Rate limits

120 requests per minute per token by default, configurable with
`WAYFINDR_API_RATE_LIMIT`. Keyed on the token rather than the IP: an integration
runs from one host, so an IP limit would make two tokens on the same server
throttle each other.

Separately, **failed** authentication attempts are limited to 60 per minute per
address (`WAYFINDR_API_FAILED_AUTH_PER_MINUTE`). Only failures count, so a
working integration never encounters it however much traffic it sends.

Going over it locks out credentials that do not authenticate, **not the
address**. Your token keeps working even when something else behind the same
office NAT or CI runner has been failing all morning.

A token that authenticates but lacks an ability does not spend *that* budget —
it is a misconfigured integration, not somebody hunting for a credential that
works — but it is still bounded, per token, on the same per-minute budget a
working token gets. Otherwise a token with no abilities, which is a supported
thing to create, would be an authenticated request path with no limit at all.

Over either limit returns `429`.

## Errors

| Status | Means |
| --- | --- |
| `401` | No token, or a token that is unknown, revoked or expired. All four say the same thing on purpose. |
| `403` | The token authenticated but lacks the ability for this endpoint. |
| `404` | No such record **within this token's reach**. |
| `409` | An idempotency key was reused for a different request, or its original resource was purged. |
| `422` | A malformed filter or pagination parameter. For a cursor that means one that does not decode, is missing its direction marker or an ordering column, or carries a value the column cannot be compared with — including a timestamp that is well formed but names no real moment, and an id that is not an id. Anything less than a cursor this API itself issued is refused rather than treated as no cursor, which would hand you page one again and have you reprocess rows you have already seen. |
| `429` | Rate limited. |

## The honest limitation

A read made with a token cannot answer *who* read it. There is no person at the
other end and no session to attribute it to, and no amount of logging changes
that. A write can answer which token performed it, but still not which person or
process held that token at the time. It is why a token is bounded by what it can
reach rather than by who holds it, and why `last_used_at` is on the token list —
so an operator can tell a live credential from a forgotten one and revoke what
nobody is using.
