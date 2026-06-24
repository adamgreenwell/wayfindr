# Widget API Abuse Controls

Wayfindr's widget API is public by design: host pages need to bootstrap a
visitor, start conversations, exchange messages, authenticate realtime
subscriptions, and send consented cobrowse state without an agent session. The
MVP posture is to keep that public surface bounded, observable, and tunable
without pretending these controls replace network-level protection.

## Default Rate Limits

The Laravel server applies named throttles to every public widget API route.
Each default is counted per minute using the request client IP and
`site_public_key`.

| Area | Routes | Default |
| --- | --- | --- |
| Widget bootstrap | `POST /api/widget/bootstrap` | 120 |
| Realtime auth | `POST /api/widget/broadcasting/auth` | 120 |
| Conversation starts | `POST /api/conversations` | 30 |
| Messages, polling, typing, read receipts | `GET/POST /api/conversations/{supportCode}/messages`, `POST /api/conversations/{supportCode}/typing` | 240 |
| Cobrowse status, consent, telemetry, page state, snapshots, mutations | `/api/conversations/{supportCode}/cobrowse*` | 1200 |

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
WAYFINDR_WIDGET_BROADCAST_AUTH_RATE_LIMIT=120
WAYFINDR_WIDGET_CONVERSATION_RATE_LIMIT=30
WAYFINDR_WIDGET_MESSAGE_RATE_LIMIT=240
WAYFINDR_WIDGET_COBROWSE_RATE_LIMIT=1200
```

Use lower values for tightly controlled demos or test installs. Use higher
values when many real visitors share one client IP, such as office networks,
VPNs, or proxy-heavy host environments.

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
