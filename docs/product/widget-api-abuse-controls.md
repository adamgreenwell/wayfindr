# Widget API Abuse Controls

Wayfindr's widget API is public by design: host pages need to bootstrap a
visitor, start conversations, exchange messages, authenticate realtime
subscriptions, and send consented cobrowse state without an agent session. The
MVP posture is to keep that public surface bounded, observable, and tunable
without pretending these controls replace network-level protection.

## Default Rate Limits

The Laravel server applies named throttles to every public widget API route.
Most defaults are counted per minute using the request client IP and
`site_public_key`. **Presence and proactive authorization also count per
visitor, within the address the request came from**; see below.

| Area | Routes | Keyed by | Default |
| --- | --- | --- | --- |
| Widget bootstrap | `POST /api/widget/bootstrap` | IP + site | 120 |
| Site configuration | `GET /api/widget/appearance` | IP + site | 3000 |
| Presence heartbeats | `POST /api/widget/presence` | IP + origin + anonymous ID + site | 30 |
| Presence heartbeats, ceiling | `POST /api/widget/presence` | IP + site | 1200 |
| Proactive authorization and outcomes | `POST /api/widget/proactive-messages/{id}/authorize`, `POST /api/widget/proactive-messages/{id}/outcomes` | IP + origin + anonymous ID + site | 120 |
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

So the per-minute limit is keyed by the visitor's own anonymous ID **within the
address the request arrived from**, which bounds a single misbehaving tab, and a
much larger **per-IP ceiling** sits behind it to bound an address as a whole. A
request with no anonymous ID falls back to the IP-keyed bucket, so an omitted
field cannot buy an unlimited number of empty buckets.

**Why the address is part of that key.** The anonymous ID is not a secret:
Wayfindr shows it on `/dashboard/visitors/{id}` for every visitor, and the
widget puts it in query strings that a normal access log records. These
throttles are applied by route middleware, which runs before anything about a
request is verified, so a budget keyed on the ID alone is a budget anyone who
has read that ID can spend — thirty forged heartbeats a minute and the real
visitor's own heartbeats start taking 429s, drop off *active* after two minutes
and leave the board entirely after fifteen. Including the address does not
authenticate anybody; it partitions, so a stranger elsewhere spends their own
budget instead of the visitor's. Visitors behind one office address each keep
their own budget, because they each have their own anonymous ID.

Two limits of that, worth knowing rather than discovering:

- **Someone sharing the visitor's address can still spend their budget** — a
  colleague on the same office network, or another customer behind the same
  carrier NAT. The precondition rises from "has read the ID" to "has read the ID
  and shares the address", which is a real reduction and not a closure.
- **If Wayfindr cannot see the real client address, this protection is lost.**
  An install terminating TLS at a proxy or load balancer without setting
  `TRUSTED_PROXIES` sees every visitor as the proxy, which collapses the
  partition and returns the behaviour above. Wayfindr's own installer sets
  `TRUSTED_PROXIES="*"` when you answer yes to running behind a proxy; a
  hand-built deployment has to set it.

  This degrades quietly — nothing errors, and **no Wayfindr screen shows you
  whether it is happening**: no client address is stored on a visitor or
  rendered anywhere in the dashboard.

  You can measure it directly, though, because the throttle reports its own
  state. Every widget response carries `X-RateLimit-Remaining` for whichever
  bucket is the most constrained — for presence that is the per-visitor one.
  Send the same `anonymous_id` from two different networks and watch what that
  number does:

  ```bash
  # Run this from two genuinely different addresses -- an office machine and a
  # phone on mobile data is enough. Use the same made-up anonymous_id for both.
  curl -si https://support.example.com/api/widget/presence \
    -H 'Content-Type: application/json' \
    -d '{"site_public_key":"YOUR_SITE_KEY","anonymous_id":"partition-probe"}' \
    | grep -i x-ratelimit-remaining
  ```

  Run it twice from the first address, then once from the second.

  - **Partition intact:** the second address starts its own countdown, at or
    near the full limit.
  - **Partition collapsed:** the second address continues the first's
    countdown. Wayfindr is resolving both to one address, and this protection
    is not in effect.

  If it has collapsed, confirm your proxy sends `X-Forwarded-For` and that
  `TRUSTED_PROXIES` names that proxy (or `*`) in the environment the
  application actually booted with — a worker or FPM pool started before the
  variable was set still holds the old value.

- **A page the attacker controls, loaded in the visitor's own browser**, posts
  from the visitor's address, so the address does not separate it. The request's
  `Origin` is part of the key for that reason — a browser sets it and cannot be
  scripted into lying about it, so the attacker's page spends its own budget.
  A client that forges `Origin` is not a browser, and so is not borrowing the
  visitor's address in the first place.

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
means a visitor session token never expires. Setting it does TWO things:
bootstrap and refresh begin advertising a lifetime, so the widget rotates its
token ahead of that deadline, AND the server refuses a token past it.

The ordering that used to be built into this setting is now yours to keep.
`widget.js` is cached for five minutes and carries no version, so the widgets
holding tokens right now are the ones that have to survive the change, and they
only begin rotating once a browser has re-fetched the asset. Wait out that cache
before you set a lifetime. A rule enforced before its clients rotate strands
open conversations, and it does so quietly, because a refused refresh is
indistinguishable to the widget from a declined one.

A visitor whose widget has not started rotating recovers on their next page
load, when bootstrap mints a fresh token. One sitting in an open panel
mid-conversation does not, until they reload.

Raise or lower it freely afterwards: the widget reads the deadline from each
response rather than caching a policy, and it will not schedule a refresh past
an expiry it has been told about.

**One gap to know about before you set this.** Rotation lives in
`Wayfindr.init()`, the embedded widget. A host integrating through
`Wayfindr.createClient()` directly -- a documented path in the widget package's
README -- gets the token but no timer, and nothing in that API tells the
integrator to drive one.

The server now refuses a token past its lifetime, so setting a value breaks
those sessions rather than merely warning them: a `createClient` integration
holds a token nothing is rotating, and it stops working one lifetime after it
was issued. Finish that integration before you set this.

The same applies to the embedded widget for a short while after an upgrade.
`widget.js` is cached for five minutes and carries no version, so a browser that
loaded it before you upgraded is holding a token it is not refreshing. Those
visitors recover on their next page load; one sitting in an open panel
mid-conversation does not, until they reload. Waiting out the asset cache before
setting a lifetime is the whole precaution.

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
