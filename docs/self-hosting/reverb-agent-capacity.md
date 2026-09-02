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
| Revision | `72c27f6c8eb12275d0196173e0a4e213fee74e1f` |
| Working tree | Clean |
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
CPU percent is derived from the Reverb process's cumulative CPU-time delta over
each sampling interval (Linux `/proc` ticks or cumulative process time on
Darwin), not the lifetime average reported by `ps %cpu`. The harness takes and
excludes a new baseline at every ramp and hold boundary.

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
| 10 | 10 / 10 | 2,432 ms | 366 ms | 32.2 ms | 30 / 30 | 1.4% | 62.4 MiB |
| 25 | 15 / 15 | 2,697 ms | 419 ms | 27.1 ms | 75 / 75 | 1.2% | 62.4 MiB |
| 50 | 25 / 25 | 2,690 ms | 424 ms | 24.6 ms | 150 / 150 | 1.0% | 62.8 MiB |
| 100 | 50 / 50 | 2,770 ms | 438 ms | 23.6 ms | 300 / 300 | 2.8% | 63.8 MiB |
| 200 | 100 / 100 | 2,828 ms | 446 ms | 26.0 ms | 600 / 600 | 2.1% | 65.8 MiB |

Every stage had zero login, connection, subscription, and delivery failures;
zero disconnects and reconnect attempts; and zero WebSocket errors.

## The 200-agent hold

| Signal | Result |
| --- | ---: |
| Duration | 90.0 seconds |
| Realtime events | 6 |
| Deliveries | 1,200 / 1,200 |
| Delivery latency | 51.5 ms median / 73.6 ms p95 / 74.1 ms max |
| Disconnects / reconnect attempts / WebSocket errors | 0 / 0 / 0 |
| Subscribed at end | 200 / 200 |
| Application pings sent during hold | 1,200 |
| Matched pongs received during hold | 1,200 |
| Clients sending pings / receiving matched pongs | 200 / 200 |
| Pong latency | 0.7 ms median / 2.4 ms p95 / 7.8 ms max |
| Keepalive timeouts | 0 |
| Unacknowledged pings after deadline drain | 0 |
| Reverb CPU | 1.0% median / 2.0% p95 / 2.0% max |
| Reverb RSS | 65.8 MiB median / 65.8 MiB max |
| Load-client RSS | 233.7 MiB median / 233.7 MiB max |

The harness permits only one outstanding application ping per connection and
gives each ping a 10-second response deadline. At the exact end of the hold it
stops scheduling new pings and waits for every in-hold ping to receive its pong
or reach that deadline. A missed deadline records a WebSocket error and closes
the connection so the disconnect and reconnect path is measured too. Every
client completed six independent ping-to-pong exchanges during this hold; all
final pongs had already arrived, so the deadline drain added 0 ms.

## First observed constraint

Reverb was not the first constraint in this run. Its memory grew by only 3.4
MiB from the 10-agent stage and its sampled CPU remained low through 200
connections. The visible
pressure was the **single PHP development worker used for login, conversation
loading, channel authorization, and event-trigger HTTP requests**. A new
agent's login reached 2.83 seconds p95 in the 10-way onboarding bursts, while
the already-connected 200-recipient event stayed below 27 ms p95 during the
ramp.

During the hold, the typing POST and its 200 deliveries stayed below 75 ms.
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
The harness checks the Git worktree both before and after the measurement and
refuses to publish a report if either check is dirty, so edits made during a run
cannot be mislabeled as the recorded revision. `WAYFINDR_CAPACITY_ALLOW_DIRTY=1`
is available only for development shakedowns; those reports record start, end,
and combined cleanliness explicitly.

Stop both application processes, then remove the login-capable fixture:

```bash
cd apps/server
php artisan wayfindr:seed-desk --purge
```
