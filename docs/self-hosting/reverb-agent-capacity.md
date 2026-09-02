# Reverb concurrent-agent capacity

**Measured 2 September 2026.** A single local Reverb process held 200 distinct,
signed-in agent sessions for 90 seconds at the test ceiling. Every client
authenticated its real private conversation channel, every one of the 2,355
expected realtime deliveries arrived, and no client disconnected, reconnected,
or reported a WebSocket error.

This establishes **at least 200 concurrent active agent sessions** for the
topology below. It does not establish Reverb's failure point: 200 was the test
ceiling, not the first failed stage.

For an equivalently configured single Reverb process on comparable hardware,
use **100 concurrent active agents per process as the initial planning
envelope**. That retains two-times headroom below the clean test ceiling. It is
an operating recommendation, not a hard `max_connections` value and not a
substitute for measuring the actual host, proxy, and worker layout before a
large launch.

## What ran

| | |
| --- | --- |
| Revision | `24086d33ca5cf622681d60773ce39d20bd168b01` |
| Machine | Apple M4 Max, 16 logical CPUs, 128 GB |
| OS | macOS 27.0 (Darwin 27.0.0), arm64 |
| Client runtime | Node.js 24.20.0 built-in `fetch` and `WebSocket` |
| Application | PHP 8.5.8, Laravel 13.26.1, one development-server worker |
| Realtime | Laravel Reverb 1.11.1, one process, scaling disabled |
| Reverb app limits | `max_connections` unset, 60-second ping interval, 30-second activity timeout |
| Data services | PostgreSQL 17.10 and Redis 7.4.9 in local Docker containers |
| Queue workers | None; the representative event implements `ShouldBroadcastNow` |
| Reverse proxy / TLS | None; direct loopback HTTP and WebSocket |
| Fixture | One disposable measurement account, 200 unique agent accounts, 100 conversations |

The machine was not isolated from ordinary development activity. Timing and
sampled CPU figures are therefore approximate. Counts, subscriptions,
deliveries, disconnects, and protocol errors are the useful pass/fail evidence.

## Workload

The harness does more than open raw sockets. Each simulated agent:

1. gets the Laravel login page and CSRF token;
2. signs in as its own seeded `desk-agent-N@example.test` account;
3. opens the seeded conversation detail page;
4. obtains a real `/broadcasting/auth` signature for that conversation's
   private channel;
5. completes the Pusher-compatible WebSocket handshake and subscription; and
6. sends application pings on half the server-declared activity timeout, just
   like a live client keeping an otherwise quiet connection healthy.

The ramp used 10-way connection concurrency and stopped at 10, 25, 50, 100,
and 200 simultaneous agents. At every stage it toggled the authenticated agent
typing endpoint three times and required every subscriber to receive every
`conversation.typing.updated` event. The highest stage then stayed connected
for 90 seconds, with another event every 15 seconds.

All 200 clients shared one conversation channel. That intentionally exercises
200-recipient fan-out for each event. It does not model high message throughput,
many simultaneous conversations per agent, browser rendering, a reverse
proxy, TLS, or a multi-process scaled Reverb cluster.

## Ramp results

The login and ready figures describe only the new cohort added at each stage.
`Ready` includes the WebSocket handshake plus the private-channel authorization
HTTP request. The one PHP worker serializes parts of those 10-way bursts.

| Active agents | New sign-ins / subscriptions | Login p95 | Private channel ready p95 | Delivery p95 | Delivered | Reverb CPU max | Reverb RSS max |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 10 | 10 / 10 | 2,519 ms | 428 ms | 26.6 ms | 30 / 30 | 0.7% | 65.9 MiB |
| 25 | 15 / 15 | 2,744 ms | 419 ms | 25.2 ms | 75 / 75 | 0.6% | 65.9 MiB |
| 50 | 25 / 25 | 2,735 ms | 413 ms | 27.5 ms | 150 / 150 | 0.5% | 65.9 MiB |
| 100 | 50 / 50 | 2,756 ms | 433 ms | 24.1 ms | 300 / 300 | 1.0% | 65.9 MiB |
| 200 | 100 / 100 | 2,747 ms | 428 ms | 26.7 ms | 600 / 600 | 1.2% | 66.0 MiB |

Every stage had zero login, connection, subscription, and delivery failures;
zero disconnects and reconnect attempts; and zero WebSocket errors.

## The 200-agent hold

| Signal | Result |
| --- | ---: |
| Duration | 90.0 seconds |
| Realtime events | 6 |
| Deliveries | 1,200 / 1,200 |
| Delivery latency | 63.9 ms median / 74.6 ms p95 / 75.2 ms max |
| Disconnects / reconnect attempts / WebSocket errors | 0 / 0 / 0 |
| Subscribed at end | 200 / 200 |
| Application pings sent during hold | 1,200 |
| Pongs received during hold | 1,201 |
| Reverb CPU | 1.0% median / 2.2% p95 / 2.5% max |
| Reverb RSS | 66.0 MiB median / 66.0 MiB max |
| Load-client RSS | 275.1 MiB median / 276.4 MiB max |

The extra pong is an interval-boundary artifact: a ping sent just before the
hold produced its reply just after the hold counters were captured. Pusher
pings do not carry an identifier the harness could use to assign that reply
back to the preceding interval. All 200 connections exercised multiple
application keepalives, and none timed out.

## First observed constraint

Reverb was not the first constraint in this run. Its memory stayed effectively
flat and its sampled CPU remained low through 200 connections. The visible
pressure was the **single PHP development worker used for login, conversation
loading, channel authorization, and event-trigger HTTP requests**. A new
agent's login reached 2.75 seconds p95 in the 10-way onboarding bursts, while
the already-connected 200-recipient event stayed below 27 ms p95 during the
ramp.

During the hold, the typing POST and its 200 deliveries reached 75 ms maximum.
That is still healthy, but it includes both Laravel broadcast creation and
Reverb delivery, so this run cannot attribute all of the latency to Reverb.

Consequently:

- do not advertise 200 as a universal or maximum capacity;
- plan around 100 active agents per Reverb process until the deployment's real
  reverse proxy, TLS path, PHP worker pool, Redis placement, and hardware have
  passed the same test;
- rerun above the expected peak, not merely at it; and
- measure event throughput separately if the workload sends many concurrent
  messages rather than occasional human-speed conversation signals.

The separate staged proxy-path problem remains tracked by
[#814](https://github.com/adamgreenwell/wayfindr/issues/814). This loopback
capacity result is not evidence that a particular nginx/Caddy/Forge route keeps
WebSockets alive.

## Reproducing it

Use a throwaway install. The harness refuses non-loopback HTTP and WebSocket
origins and requires the explicit `WAYFINDR_CAPACITY_DISPOSABLE=YES` guard. It
then requires every agent to land in the seeded **Measurement Desk** before it
opens load. Do not proxy a production installation onto loopback to defeat
those guards.

Node.js 22 or newer is required. Start from the repository root, verify the
service versions you are about to record, and seed the disposable desk:

```bash
docker compose up -d postgres redis
docker compose exec -T postgres postgres --version
docker compose exec -T redis redis-server --version

cd apps/server
php artisan migrate --force
php artisan wayfindr:seed-desk \
    --conversations=100 --messages=2 --agents=200 --sites=1 --fresh
```

The seeder commits 200 login-capable accounts whose shared password is the
documented value `password`. Never run it on an internet-facing or real-data
installation.

In the first `apps/server` terminal, record the exact PHP executable and Reverb
PID so the report describes and samples the processes that actually ran:

```bash
php_binary="$(command -v php)"
printf '%s\n' "$php_binary" >/tmp/wayfindr-capacity-php

APP_URL=http://127.0.0.1:8000 \
BROADCAST_CONNECTION=reverb \
REVERB_APP_ID=wayfindr-capacity \
REVERB_APP_KEY=wayfindr-capacity-key \
REVERB_APP_SECRET=wayfindr-capacity-secret \
REVERB_HOST=127.0.0.1 REVERB_PORT=8080 REVERB_SCHEME=http \
"$php_binary" artisan reverb:start --host=127.0.0.1 --port=8080 &
reverb_pid=$!
printf '%s\n' "$reverb_pid" >/tmp/wayfindr-capacity-reverb-pid
wait "$reverb_pid"
```

In a second `apps/server` terminal, use the same PHP and Reverb app settings:

```bash
php_binary="$(cat /tmp/wayfindr-capacity-php)"

APP_URL=http://127.0.0.1:8000 \
BROADCAST_CONNECTION=reverb \
REVERB_APP_ID=wayfindr-capacity \
REVERB_APP_KEY=wayfindr-capacity-key \
REVERB_APP_SECRET=wayfindr-capacity-secret \
REVERB_HOST=127.0.0.1 REVERB_PORT=8080 REVERB_SCHEME=http \
"$php_binary" artisan serve --host=127.0.0.1 --port=8000
```

From the repository root in a third terminal, substitute the versions and
placement that the first commands actually printed:

```bash
WAYFINDR_BASE_URL=http://127.0.0.1:8000 \
WAYFINDR_REVERB_URL=ws://127.0.0.1:8080 \
WAYFINDR_REVERB_APP_KEY=wayfindr-capacity-key \
WAYFINDR_REVERB_PID="$(cat /tmp/wayfindr-capacity-reverb-pid)" \
WAYFINDR_CAPACITY_PHP_BINARY="$(cat /tmp/wayfindr-capacity-php)" \
WAYFINDR_CAPACITY_DISPOSABLE=YES \
WAYFINDR_CAPACITY_DATABASE_PLACEMENT='PostgreSQL 17.10, local Docker' \
WAYFINDR_CAPACITY_REDIS_PLACEMENT='Redis 7.4.9, local Docker' \
WAYFINDR_CAPACITY_REVERSE_PROXY='none; direct loopback' \
WAYFINDR_CAPACITY_PHP_WORKERS=1 \
WAYFINDR_CAPACITY_QUEUE_WORKERS=0 \
WAYFINDR_CAPACITY_REVERB_PROCESSES=1 \
WAYFINDR_CAPACITY_REVERB_CONFIGURATION='scaling disabled; max_connections unset; ping_interval 60s; activity_timeout 30s' \
WAYFINDR_CAPACITY_REVERB_PING_INTERVAL_SECONDS=60 \
WAYFINDR_CAPACITY_OUTPUT=/tmp/wayfindr-reverb-capacity.json \
scripts/smoke/reverb-agent-capacity.sh
```

The defaults reproduce the 10, 25, 50, 100, and 200 ramp, three events per
stage, 10-way connection concurrency, a 90-second top-stage hold, and an event
every 15 seconds. The JSON output contains environment metadata, aggregate
durations, counts, and process samples; it does not contain the app key,
password, cookies, CSRF values, channel name, support code, or event payloads.

Stop both application processes, then remove the login-capable fixture:

```bash
cd apps/server
php artisan wayfindr:seed-desk --purge
```
