# Widget API Abuse Controls

Wayfindr's widget API is public by design: host pages need to bootstrap a
visitor, start conversations, exchange messages, authenticate realtime
subscriptions, and send consented cobrowse state without an agent session. The
MVP posture is to keep that public surface bounded, observable, and tunable
without pretending these controls replace network-level protection.

## Default Rate Limits

The Laravel server applies named throttles to every public widget API route.
Most defaults are counted per minute using the request client IP and
`site_public_key`. **Presence is the exception and is keyed per visitor**; see
below.

| Area | Routes | Keyed by | Default |
| --- | --- | --- | --- |
| Widget bootstrap | `POST /api/widget/bootstrap` | IP + site | 120 |
| Site configuration | `GET /api/widget/appearance` | IP + site | 3000 |
| Presence heartbeats | `POST /api/widget/presence` | anonymous ID + site | 30 |
| Presence heartbeats, ceiling | `POST /api/widget/presence` | IP + site | 1200 |
| Realtime auth | `POST /api/widget/broadcasting/auth` | IP + site | 120 |
| Conversation starts | `POST /api/conversations` | IP + site | 30 |
| Messages, polling, typing, read receipts | `GET/POST /api/conversations/{supportCode}/messages`, `POST /api/conversations/{supportCode}/typing` | IP + site | 240 |
| Cobrowse status, consent, telemetry, page state, snapshots, mutations | `/api/conversations/{supportCode}/cobrowse*` | IP + site | 1200 |

### Why presence is keyed differently

A heartbeat is sent by every visitor on an opted-in site, roughly every 45
seconds, whether or not they ever open the widget. Keyed by IP alone, one
office or one carrier-grade NAT would exhaust the budget and stop presence for
everybody behind that address — and the visitors it stopped would be told
nothing, because a throttled heartbeat looks exactly like a quiet site.

So the per-minute limit is keyed by the visitor's own anonymous ID, which
bounds a single misbehaving tab, and a much larger **per-IP ceiling** sits
behind it to bound an address as a whole. A request with no anonymous ID falls
back to the IP-keyed bucket, so an omitted field cannot buy an unlimited number
of empty buckets.

Site configuration is separated from bootstrap for the same reason: it is read
once per **page load** rather than once per panel opening, so passive browsing
from a shared address must not be able to spend the budget that lets somebody
start a conversation. Its ceiling is high because the response is identical for
every visitor on the site and writes nothing.

### Durable-row creation is budgeted separately

Rate limits bound requests. Presence also creates **rows** for visitors who
never made contact, so the number of rows one address may cause is budgeted on
its own, per minute and per day. Exceeding it does not fail the request — the
heartbeat is accepted and simply does not create a visitor — because the
alternative is telling an abusive client exactly where the boundary is.

These are the limits to raise if a large shared address legitimately produces
many first-time visitors, and to lower on a demo or test install.

Normal stock-widget traffic should stay below these values. Message and
cobrowse status polling default to every 5 seconds, typing hints are throttled
to every 5 seconds, and the higher cobrowse ceiling leaves room for the
mutation stream's short flush interval. See
[Cobrowse Data Boundaries](../privacy/cobrowse-data-boundaries.md) for the
cobrowse payload-size and batching contract.

## Environment Overrides

Operators can tune the defaults per install:

```dotenv
WAYFINDR_WIDGET_BOOTSTRAP_RATE_LIMIT=120
WAYFINDR_WIDGET_CONFIG_RATE_LIMIT=3000
WAYFINDR_WIDGET_BROADCAST_AUTH_RATE_LIMIT=120
WAYFINDR_WIDGET_CONVERSATION_RATE_LIMIT=30
WAYFINDR_WIDGET_MESSAGE_RATE_LIMIT=240
WAYFINDR_WIDGET_COBROWSE_RATE_LIMIT=1200

# Presence. The first is per visitor; the second is the ceiling for one
# address. The last two bound how many visitor ROWS one address may create.
WAYFINDR_WIDGET_PRESENCE_PER_MINUTE=30
WAYFINDR_WIDGET_PRESENCE_PER_IP_PER_MINUTE=1200
WAYFINDR_WIDGET_PRESENCE_CREATIONS_PER_IP_PER_MINUTE=30
WAYFINDR_WIDGET_PRESENCE_CREATIONS_PER_IP_PER_DAY=20000
```

Use lower values for tightly controlled demos or test installs. Use higher
values when many real visitors share one client IP, such as office networks,
VPNs, or proxy-heavy host environments.

For a shared address specifically, `WAYFINDR_WIDGET_PRESENCE_PER_MINUTE` is
usually the wrong one to raise: it is already per visitor, so a busy office
does not consume it faster than one person does. Raise
`WAYFINDR_WIDGET_PRESENCE_PER_IP_PER_MINUTE` and the creation budgets instead.

## Scope And Limitations

These limits are application-level guardrails. They help contain accidental
runaway widgets, noisy pages, broken integrations, and basic request floods
against a single site/client pair. They do not replace:

- HTTPS termination and correct proxy IP handling;
- web server request-size limits;
- firewall, CDN, or WAF rules for broad volumetric abuse;
- signed visitor tokens on conversation, message, and cobrowse routes;
- server-side validation and payload budgets.

When a request exceeds a limit, Laravel returns `429 Too Many Requests` with
standard retry headers. The widget keeps manual refresh and retry paths so a
temporary throttle does not silently erase visitor-entered text.
