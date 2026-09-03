# 0020: Outbound webhook contract

Date: 2026-09-03

Status: Accepted

## Context

The public API lets an integration ask what happened, but polling either lags
or hammers the install. Outbound webhooks make that API reactive. They also
create a more dangerous publication path than a response: support data leaves
asynchronously for a URL an administrator typed, when nobody is watching the
request.

This decision resolves the payload, ordering, delivery, destination-security
and site-scope questions in [#785](https://github.com/adamgreenwell/wayfindr/issues/785).

## Decision

### Events are thin notifications

An endpoint may subscribe to:

- `conversation.opened`;
- `conversation.message.created`;
- `ticket.created`; and
- `ticket.closed`.

Every payload contains only a stable delivery UUID, event name, monotonically
increasing sequence for that endpoint, occurrence time, site id, resource type,
and the resource identifier needed to read it through API v1. A conversation is
identified by support code; a message also names its conversation support code;
a ticket is identified by id.

It does **not** contain conversation subjects, message bodies, ticket subjects
or descriptions, transcripts, `metadata`, `anonymous_id`, visitor identity, or
cobrowse data. The subscriber reads content through a separately issued API
token. That credential has an independent site scope and can be revoked without
changing the endpoint.

Payload construction remains in `App\Support\Api\V1\Payload`. This is a second
publication path through the existing allowlist boundary, not a parallel set of
model serializers. The architecture suite prevents the publisher from falling
back to whole-model serialization.

### An endpoint has its own immutable ordering stream

Sequence numbers are allocated under a lock on the endpoint row. New events
always advance; retries reuse the original sequence and delivery UUID. HTTP
requests may arrive out of order when an older event is retrying, but the
subscriber can reconstruct creation order and de-duplicate attempts.

The delivery guarantee is **at least once**, not exactly once. There is no
atomic commit shared by the subscriber and Wayfindr's database. If a subscriber
accepts the POST and the worker exits before `delivered_at` is committed, the
same delivery may be sent again. Subscribers should de-duplicate by the stable
delivery UUID.

### The database outbox is the acceptance boundary

Model observers cover writes made through the widget, agent dashboard, inbound
mail and public API. A matching delivery row is written in the resource's
transaction. Queue handoff waits for commit. If Redis is unavailable after the
commit, the core write remains accepted and a minutely scheduler pass queues the
same pending row later.

The queue retries with bounded backoff. After five worker attempts the delivery
is marked failed rather than retried forever. The account page shows the exact
thin payload, attempt count, latest HTTP status and a bounded encrypted response
sample. The response is capped while the transport receives it, before it can
consume unbounded worker memory or disk. An administrator may explicitly retry
a terminal failure. Disabling an endpoint cancels pending rows and prevents new
ones while retaining its history.

An attempt takes a shared lock on its site and an exclusive lock on its delivery
row, rechecks the endpoint, and holds those lifecycle guards until the HTTP
request finishes. Endpoint disable cancels pending delivery rows under its short
endpoint mutation; site purge's delete conflicts with the shared site guard. If
either mutation commits first, the worker sends nothing; if a request has
already started, the mutation waits for it to finish before returning. Normal
support creation uses the same shared site guard, so it remains concurrent with
delivery, as do separate deliveries for one site. The endpoint row is also not
held during subscriber I/O because foreground publishing uses it to allocate
sequence numbers. A completed disable or purge can never be followed by a
request authorized from stale state, and a slow subscriber cannot block
creation of new support work.

### Signing covers the exact bytes sent

Each POST carries:

- `X-Wayfindr-Event`;
- `X-Wayfindr-Delivery`; and
- `X-Wayfindr-Signature: sha256=<hex digest>`.

The digest is `hash_hmac('sha256', $exactRequestBody, $endpointSecret)`. The
generated secret is shown once. Wayfindr must retain it to sign future events,
so it is encrypted behind the application key rather than hashed. Subscriber
implementations compare the supplied signature in constant time.

### Destinations cannot be an SSRF tunnel

Endpoints require HTTPS and reject credentials and fragments. The host must
resolve, and **every** address in its answer set must be public. This is checked
when an endpoint is saved and again before every attempt. Explicit special-use
coverage includes deprecated-but-routable ranges such as IPv6 site-local
`fec0::/10`, not only the ranges PHP's public-address flags happen to reject.

The delivery check pins cURL to the complete verified address set while
retaining the hostname for TLS SNI and certificate validation. cURL may fall
back among those addresses but cannot perform another lookup. Redirects and
environment proxies are disabled. Re-resolving only at validation time would
leave a DNS rebinding window; checking without pinning would leave a second
lookup between the decision and the connection.

### Site scope follows the API-token ceiling

Every endpoint is pinned to the sites its creating administrator may see at
creation. Selecting sites can narrow that list; it cannot widen it. A site
created later is not added automatically, and an endpoint whose named sites are
all purged reaches nothing. No role implicitly creates an account-wide future
grant. Purging a site also deletes its delivery rows, including pending work and
bounded response samples, before the recovery scheduler can queue them.

The delivery log follows the viewing administrator's current site scope. A row
for a site they no longer support is omitted, and guessing its numeric database
key cannot trigger a manual retry.

## Consequences

- Integrations can react promptly without receiving support content in an
  unsolicited POST.
- A subscriber normally needs two independently revocable objects: a webhook
  endpoint for notification and an API token for reading.
- Operators need a working default queue worker, one-minute scheduler and HTTPS
  egress to subscriber destinations.
- Endpoints cannot target private networks, localhost development receivers or
  webhook relays reached only through an environment proxy.
- Historical events are not replayed. An endpoint receives events created after
  it is registered.
