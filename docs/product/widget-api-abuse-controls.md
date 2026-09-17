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
| Proactive authorization and outcomes | `POST /api/widget/proactive-messages/{id}/authorize`, `POST /api/widget/proactive-messages/{id}/outcomes` | anonymous ID + site | 120 |
| Proactive authorization and outcomes, ceiling | Same routes | IP + site | 1200 |
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
WAYFINDR_WIDGET_PROACTIVE_RATE_LIMIT=120
WAYFINDR_WIDGET_PROACTIVE_PER_IP_RATE_LIMIT=1200
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

# Visitor session refresh. The first is per SESSION -- one widget's run of
# rotations, not one visitor -- and is charged only after the request proves it
# holds a token from that session; the second is the ceiling for one address,
# and is what bounds unauthenticated attempts. A widget trades its token in
# ahead of expiry, so an established tab spends this rarely -- once per token
# lifetime, plus a retry.
WAYFINDR_WIDGET_SESSION_REFRESH_PER_MINUTE=30
WAYFINDR_WIDGET_SESSION_REFRESH_PER_IP_PER_MINUTE=600

# How long a visitor session token stays usable, in minutes. Zero is no expiry,
# and is the shipped default. See "Turning on a token lifetime" below.
WAYFINDR_VISITOR_SESSION_TTL_MINUTES=0
```

Use lower values for tightly controlled demos or test installs. Use higher
values when many real visitors share one client IP, such as office networks,
VPNs, or proxy-heavy host environments.

`POST /api/widget/session` is the refresh route, and its failure is quiet: the
widget reduces a refused refresh to the same outcome as a declined one, so a
visitor whose token cannot be renewed simply stops being able to. If refresh
429s are suspected, raise `WAYFINDR_WIDGET_SESSION_REFRESH_PER_IP_PER_MINUTE`
rather than the per-visitor budget, for the same reason as presence below.

That budget is spent inside the controller rather than by route middleware, and
is keyed on the session rather than the visitor. Both are deliberate.

Middleware runs before the token is checked, so the only thing it could key on
is the caller-supplied `anonymous_id` -- a value the dashboard displays and
access logs record. Charged there, anyone who could read it could exhaust a
stranger's allowance with junk tokens.

Verifying the token is not by itself enough either, because widget bootstrap
mints a working token for whoever presents a site's public key and an anonymous
id. A budget keyed on the visitor could therefore be spent with genuinely valid
credentials by someone who bootstrapped once. Refreshing carries a session's
start forward and bootstrapping begins a new one, so keying on the session puts
those requests in the caller's own bucket.

## Turning on a token lifetime

`WAYFINDR_VISITOR_SESSION_TTL_MINUTES` is zero on every install today, and zero
means a visitor session token never expires. Setting it does ONE thing: bootstrap
and refresh begin advertising a lifetime, and the widget rotates its token ahead
of that deadline. The server still accepts an older token.

That ordering matters and is worth not reversing. `widget.js` is cached for five
minutes and carries no version, so the widgets holding tokens right now are the
ones that have to survive the change. Advertise the lifetime first, let the
installed widgets rotate against it, and only then consider refusing an expired
token. A rule enforced before its clients can rotate strands every open
conversation at once, and it does so quietly, because a refused refresh is
indistinguishable to the widget from a declined one.

Raise or lower it freely afterwards: the widget reads the deadline from each
response rather than caching a policy, and it will not schedule a refresh past
an expiry it has been told about.

**One gap to know about before you set this.** Rotation lives in
`Wayfindr.init()`, the embedded widget. A host integrating through
`Wayfindr.createClient()` directly -- a documented path in the widget package's
README -- gets the token but no timer, and nothing in that API tells the
integrator to drive one.

Setting a lifetime does not break those sessions today -- nothing refuses an
expired token yet, which is the whole point of advertising first. They are the
sessions that break at the LATER step, when the server starts refusing. So a
`createClient` integration is work to finish before that step, not a reason to
leave this at 0: leaving it at 0 declines the advertise-first move that makes
enforcement safe, which is the opposite of what you want.

The residual: two sessions begun for the same visitor in the same microsecond
share a budget. The value is inside the encrypted token, so it cannot be read
and aimed at -- but it is a timestamp rather than a secret, and the real remedy
is for bootstrap to stop minting on a published identifier at all.

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
