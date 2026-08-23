# The Wayfindr API

Status: **v1, read-only.** Writes and outbound webhooks are not shipped yet.

Wayfindr's public API exists so that integrations do not have to be built by
Wayfindr's team. It is deliberately smaller than the dashboard, and the reasons
for each limit are in [ADR 0018](../decisions/0018-public-api-and-programmatic-access.md).

## Getting a token

**Account → API tokens → Issue a token.** Admins only.

The token is shown **once**, immediately after creation. Wayfindr stores a
SHA-256 hash of it, not the token, so it cannot be recovered or resent — if you
lose it, revoke it and issue another. The list afterwards shows only the last
four characters, which is enough to match a row to the credential in your
deployment config and not enough to use it.

Two settings on the form are worth spending a moment on:

- **Expires after.** Left empty, the token never expires, which means noticing
  it becomes nobody's job. Pick a horizon you will actually revisit.
- **Restrict to sites.** Tick none and the token reaches every site on the
  account. An integration that watches one site should not be a credential for
  all of them.

## Making a request

```bash
curl -H "Authorization: Bearer wfk_your_token_here" \
  https://support.example.com/api/v1/me
```

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

**Operator access grants do not extend to tokens.** A break-glass grant widens
what a *person* can see, under an approval trail, for a bounded window
([ADR 0008](../decisions/0008-platform-operator-break-glass.md)). A token has no
person behind it, so the two do not compose in either direction.

## Rate limits

120 requests per minute per token by default, configurable with
`WAYFINDR_API_RATE_LIMIT`. Keyed on the token rather than the IP: an integration
runs from one host, so an IP limit would make two tokens on the same server
throttle each other.

Over the limit returns `429`.

## Errors

| Status | Means |
| --- | --- |
| `401` | No token, or a token that is unknown, revoked or expired. All four say the same thing on purpose. |
| `403` | The token authenticated but lacks the ability for this endpoint. |
| `404` | No such record **within this token's reach**. |
| `422` | A malformed filter or pagination parameter. |
| `429` | Rate limited. |

## The honest limitation

A read made with a token cannot answer *who* read it. There is no person at the
other end and no session to attribute it to, and no amount of logging changes
that. It is why a token is bounded by what it can reach rather than by who holds
it, and why `last_used_at` is on the token list — so an operator can tell a live
credential from a forgotten one and revoke what nobody is using.
