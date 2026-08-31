# Generic Runtime Requirements

Forge is the most complete documented deployment path today, but Wayfindr is
not Forge-only. This guide describes the runtime shape any self-hosting
platform needs to provide. Use it when translating Wayfindr to a plain VPS,
Docker host, Coolify-style application platform, Kubernetes, or another
Laravel-capable environment.

Wayfindr is still pre-alpha. Treat this as the runtime contract, not a polished
one-command installer.

## Application Shape

Wayfindr is a Laravel-first monorepo:

```text
wayfindr/
  apps/server/          Laravel application root
  apps/server/public/   public web root
```

Run Composer, Artisan, queue, scheduler, and Reverb commands from
`apps/server`. Point the web server document root at `apps/server/public`.

Minimum runtime:

- PHP 8.4 or newer, with `ext-intl`. It is declared in
  `apps/server/composer.json`, so `composer install` refuses an environment
  without it rather than letting one reach production — most distributions ship
  it as `php8.4-intl` and the official PHP images include it. Wayfindr uses it
  to compare hostnames in one representation: an operator configures the domain
  they own (`bücher.example`) and browsers report its Punycode form
  (`xn--bcher-kva.example`), and without normalisation those read as different
  sites, so every page address on such a site is silently discarded.
- Composer 2.
- A web server that can serve Laravel through PHP-FPM or an equivalent PHP
  runtime.
- Postgres.
- Redis for cache, queues, and realtime-friendly operations.
- Outbound mail when alerts, password resets, or operator notices should leave
  the application.
- Public HTTPS for production-like installs.
- A process manager for queue workers, the scheduler, and Reverb.
- Writable `apps/server/storage` and `apps/server/bootstrap/cache`
  directories for the Laravel runtime user.

## Required Processes

A healthy Wayfindr install has more than one long-running concern. Do not treat
the Laravel web request process as the whole application.

| Process | Purpose | Typical command |
| --- | --- | --- |
| Web | Serves Laravel HTTP routes and the widget script. | Web server to PHP-FPM with root `apps/server/public` |
| Queue worker | Runs queued jobs outside the request lifecycle. | `php artisan queue:work redis --sleep=3 --tries=3 --timeout=90` |
| Backup worker | Runs the operator "run a backup now" job. Separate connection so its retry window can exceed the backup timeout. | `php artisan queue:work backups --queue=backups --sleep=5 --tries=1 --timeout=3600` |
| Scheduler | Lets Laravel run scheduled work once per minute, including hourly alert digest delivery. | `* * * * * cd /path/to/apps/server && php artisan schedule:run` |
| Reverb | Serves WebSocket connections for live chat/cobrowse notices. | `php artisan reverb:start --host=127.0.0.1 --port=8080` |

Run the workers and Reverb under Supervisor, systemd, your host's process
manager, or separate containers. The scheduler can be cron, a platform
scheduled task, or a dedicated process that invokes `schedule:run` once per
minute.

The **backup worker** runs on its own queue connection (`backups`) whose
`retry_after` is deliberately larger than the backup timeout, so a backup that
takes several minutes is never re-released to a second worker and false-failed.
The default queue worker must not process the `backups` connection. If you skip
this worker, scheduled backups (via cron) still run, but the operator's
"run a backup now" button will queue jobs that are never processed.

Backups are serialized instance-wide by a short-lived lock so two never run at
once. If yours take a long time, raise `WAYFINDR_BACKUP_JOB_TIMEOUT` (seconds;
default 3600) to cover the queued job, and it also lifts the lock lifetime;
`WAYFINDR_BACKUP_LOCK_TTL` can raise the lock lifetime alone. The lock lifetime
must exceed your longest backup — including the untimed scheduled one — or a
second backup could start before the first finishes.

After the first deploy, use a few boring process checks before trusting the
install with visitor traffic:

```bash
cd apps/server

# Queue smoke: there should be no failed jobs after a visitor/agent smoke test.
php artisan queue:failed

# Scheduler shape: configure this once per minute through cron or your host.
* * * * * cd /path/to/apps/server && php artisan schedule:run

# Scheduled task inventory: alert digest delivery should be listed.
php artisan schedule:list

# Reverb shape: keep this under a process manager when realtime is enabled.
php artisan reverb:start --host=127.0.0.1 --port=8080

# Cobrowse transport shape: aggregate-only, safe to run on production-like installs.
php artisan wayfindr:cobrowse-transport-smoke
```

If the queue is `sync` or `null`, switch to `database` or `redis` before real
traffic. If agents choose digest email cadence, confirm
`php artisan wayfindr:send-alert-digests` appears in `php artisan schedule:list`;
Laravel will run it hourly once the one-minute scheduler is active. If
`schedule:list` cannot render, check the configured cache/Redis connection too;
Laravel inspects scheduler mutex state while building the list. If Reverb is
enabled, keep `php artisan reverb:restart` in the deploy script so long-running
WebSocket workers refresh after releases.

The cobrowse transport smoke command prints the same aggregate transport status
used by operator readiness. It does not print support codes, visitor
identifiers, account or site names, page URLs, snapshots, transcripts, or
mutation payloads. A no-data result is successful but means no active consented
cobrowse session has reported telemetry yet. A needs-attention result exits
non-zero so deployment or operational checks can flag stale, reconnecting, or
degraded active sessions. If the command cannot inspect the cobrowse session
table, it reports a manual check so the operator can fix database connectivity
or pending migrations before trusting cobrowse diagnostics. Add `--json` when a
deploy script or external monitor needs machine-readable aggregate status and
the same static cobrowse payload budget defaults.

## Environment

Manage secrets in the host platform, not in Git. The important production-like
shape is:

```dotenv
APP_NAME=Wayfindr
APP_ENV=production
APP_KEY=base64:replace-with-generated-key
APP_DEBUG=false
APP_URL=https://replace-with-public-host
WAYFINDR_VERSION=
WAYFINDR_COMMIT=

DB_CONNECTION=pgsql
DB_HOST=replace-with-postgres-host
DB_PORT=5432
DB_DATABASE=replace-with-database-name
DB_USERNAME=replace-with-database-user
DB_PASSWORD=replace-with-database-password

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=replace-with-redis-host
REDIS_PASSWORD=null
REDIS_PORT=6379

BROADCAST_CONNECTION=log
REVERB_APP_ID=wayfindr-production
REVERB_APP_KEY=replace-with-public-reverb-key
REVERB_APP_SECRET=replace-with-private-reverb-secret
REVERB_HOST=replace-with-public-websocket-host
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_SERVER_PATH=

MAIL_MAILER=smtp
MAIL_HOST=replace-with-mail-host
MAIL_PORT=587
MAIL_USERNAME=replace-with-mail-user
MAIL_PASSWORD=replace-with-mail-password
MAIL_SCHEME=null
MAIL_FROM_ADDRESS=support@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

For SMTP on STARTTLS ports such as `587` or `2587`, leave `MAIL_SCHEME` unset
or `null`; Laravel's SMTP transport does not accept `tls` as a scheme. Use
`MAIL_SCHEME=smtps` only for implicit TLS providers on port `465`. For a worked
provider example — Google Workspace SMTP relay — plus SPF/DKIM/DMARC
deliverability and troubleshooting, see
[email-delivery.md](email-delivery.md).

After configuring outbound mail, run a real smoke test from the Laravel
application directory:

```bash
cd apps/server
php artisan wayfindr:mail-test --to="verified-recipient@example.com"
```

The command prints the mailer, SMTP host, sender, and recipient without
printing SMTP credentials. If the provider is still sandboxed, send to a
verified recipient until the sending domain is approved for normal delivery.

Generate the app key from the Laravel application directory:

```bash
cd apps/server
php artisan key:generate --show
```

Keep `BROADCAST_CONNECTION=log` until Reverb is running and WebSocket traffic
is routed through HTTPS. Switch to `reverb` when live message delivery should
be active. The Reverb app key is public browser configuration; keep
`REVERB_APP_SECRET` private.

Set `WAYFINDR_VERSION` and `WAYFINDR_COMMIT` from the deploy pipeline. The
official image bakes both, and a deploy that builds from source falls back to
`<VERSION>-dev` (from the repository's `VERSION` file), so an install always
reports *something* — but a bare `-dev` names the lineage, not the build. Setting
them pins the exact build, which is what lets version checks actually verify:
without it, a **restore cannot confirm an archive matches this install** and
keeps the site in maintenance for you to check (ADR 0012). They also make
`/operator` far more useful when someone needs to
confirm what is running.

## Deploy Flow

For a simple non-zero-downtime deployment, the app server should do roughly
this from the monorepo root:

```bash
cd apps/server
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan reverb:restart
```

Zero-downtime platforms should run the install/build/cache steps inside a new
release directory, then activate the release only after those steps pass. Make
`apps/server/storage` persistent across releases.

## WebSockets

Reverb can listen privately on `127.0.0.1:8080` while the public site serves
TLS on `https://example.com`. Proxy Reverb's Pusher-compatible paths to that
private port:

```nginx
location /app {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";

    # Without these the WebSocket inherits nginx's 60-second default and is
    # torn down whenever nothing crosses it for that long -- see the note
    # below for which connections that actually reaches.
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;

    proxy_pass http://127.0.0.1:8080;
}

location /apps {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";

    # Without these the WebSocket inherits nginx's 60-second default and is
    # torn down whenever nothing crosses it for that long -- see the note
    # below for which connections that actually reaches.
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;

    proxy_pass http://127.0.0.1:8080;
}
```

**Do not leave the two timeouts out.** nginx's default `proxy_read_timeout` is
60 seconds, and it is measured between successive reads **from Reverb** — not
from traffic in either direction. When nothing arrives from upstream for that
long the socket is torn down with no close frame, the browser reports an
abnormal close, and the page reconnects, losing whatever was published in the
gap until the next resync.

That the timer watches the *upstream* is why a keepalive works: Wayfindr's
pages send `pusher:ping` every 15 seconds and Reverb answers `pusher:pong`, so
something arrives from upstream well inside the window. A client that sent
frames Reverb does not answer would still time out, however chatty it was.

So a quiet conversation is **not** by itself an idle connection — a visible tab
carries traffic whether or not anybody is typing.

What the setting protects is every connection whose keepalive is delayed past
60 seconds — a tab the browser has throttled, any other client speaking to this
Reverb, and anything at all if that keepalive stops.

It does not rescue a tab the browser has **suspended**, and no proxy setting
can. A frozen page cannot send the keepalive and cannot answer Reverb's own
`pusher:ping` either, so Reverb closes the connection after its
`activity_timeout` regardless of what nginx permits. Such a tab reconnects when
it wakes, which is the designed path.

The overlap is what makes this easy to misdiagnose: Reverb's `ping_interval`
also defaults to 60 seconds, so on a default nginx the proxy closes the
connection at the same moment the keepalive that would have held it open was
due.

**Where to look.** Nothing surfaces in the application or the browser — no
error, no failed request, just a page that quietly reconnects. nginx does
record it, though, and its error log is the most direct evidence you will get:

```
upstream timed out (110: Connection timed out) while reading upstream
```

entries against the `/app` or `/apps` location are this exact problem.

They appear only for the sockets actually affected, roughly once a minute
each. A healthy Wayfindr page produces none, so a quiet log does not mean the
setting is unnecessary — it means the clients you happen to be serving are the
ones that keep themselves alive.

Other reverse proxies can use the same idea: public HTTPS outside, private
Reverb port inside, WebSocket upgrade headers preserved.

## Docker, Coolify, And Similar Hosts

On application platforms, model Wayfindr as a Laravel monorepo with several
processes:

- one web process serving `apps/server/public`;
- one queue worker process;
- one backup worker process on the `backups` connection (only needed if
  operators run backups from the GUI; scheduled backups run under the scheduler);
- one scheduler job or scheduler process;
- one Reverb process when realtime is enabled;
- Postgres and Redis services;
- persistent storage mounted at `apps/server/storage`.

Set the build and run working directory to `apps/server` whenever the platform
lets you. If it only works from the repository root, make the commands `cd
apps/server` first.

Do not expose the private Reverb port directly to browsers unless the platform
terminates TLS and routes WebSocket traffic correctly. For public installs, the
widget should connect to a secure `wss` endpoint through the same host or a
dedicated TLS WebSocket host.

This guide deliberately avoids a host-specific one-liner. A future Docker or
Coolify template can make setup smoother, but the template still needs to
provide the services and process shape above.

## Backups And Retention

Wayfindr cannot prove infrastructure backups from inside a Laravel request.
Before real visitor traffic, operators should confirm:

- Postgres backups are scheduled, retained, monitored, and restorable.
- `apps/server/storage` is backed up or intentionally disposable.
- Logs rotate and do not retain secrets longer than expected.
- Deleted application data is not kept forever in backups by accident.
- At least one restore drill has been performed.

See [Data Responsibility](../privacy/data-responsibility.md) and the
[Data Inventory](../privacy/data-inventory.md) before using Wayfindr with real
visitors.

## First-Run Smoke Path

After deploy:

1. Visit `/setup` and create the first account owner and install site.
2. Sign in and open the generated site install snippet.
3. Review `/operator`.
4. Resolve any app key, database, queue, mail, Reverb, storage, scheduler, or
   backup warnings.
5. Send a real mail smoke test with `php artisan wayfindr:mail-test
   --to="verified-recipient@example.com"`.
6. Confirm `php artisan queue:work`, the one-minute scheduler, and Reverb are
   managed by the host or process manager; then confirm
   `php artisan wayfindr:send-alert-digests` appears in
   `php artisan schedule:list`.
7. Send a test visitor message through the widget or smoke script.
8. Reply from the agent dashboard and confirm the visitor can see the reply.

The smoke path is intentionally boring. It should catch wiring mistakes before
real support traffic starts flowing through the instance.
