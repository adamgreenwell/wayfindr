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

- PHP 8.3 or newer.
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
| Scheduler | Lets Laravel run scheduled work once per minute. | `* * * * * cd /path/to/apps/server && php artisan schedule:run` |
| Reverb | Serves WebSocket connections for live chat/cobrowse notices. | `php artisan reverb:start --host=127.0.0.1 --port=8080` |

Run the worker and Reverb under Supervisor, systemd, your host's process
manager, or separate containers. The scheduler can be cron, a platform
scheduled task, or a dedicated process that invokes `schedule:run` once per
minute.

After the first deploy, use a few boring process checks before trusting the
install with visitor traffic:

```bash
cd apps/server

# Queue smoke: there should be no failed jobs after a visitor/agent smoke test.
php artisan queue:failed

# Scheduler shape: configure this once per minute through cron or your host.
* * * * * cd /path/to/apps/server && php artisan schedule:run

# Reverb shape: keep this under a process manager when realtime is enabled.
php artisan reverb:start --host=127.0.0.1 --port=8080
```

If the queue is `sync` or `null`, switch to `database` or `redis` before real
traffic. If Reverb is enabled, keep `php artisan reverb:restart` in the deploy
script so long-running WebSocket workers refresh after releases.

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
`MAIL_SCHEME=smtps` only for implicit TLS providers on port `465`.

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

Set `WAYFINDR_VERSION` and `WAYFINDR_COMMIT` from the deploy pipeline when
available. They are optional, but they make `/operator` and
`/dashboard/readiness` much more useful when someone needs to confirm what is
running.

## Deploy Flow

For a simple non-zero-downtime deployment, the app server should do roughly
this from the monorepo root:

```bash
cd apps/server
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

if [ -f package-lock.json ]; then
    npm ci
    npm run build
fi

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

    proxy_pass http://127.0.0.1:8080;
}
```

Other reverse proxies can use the same idea: public HTTPS outside, private
Reverb port inside, WebSocket upgrade headers preserved.

## Docker, Coolify, And Similar Hosts

On application platforms, model Wayfindr as a Laravel monorepo with several
processes:

- one web process serving `apps/server/public`;
- one queue worker process;
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
3. Review `/operator` or `/dashboard/readiness`.
4. Resolve any app key, database, queue, mail, Reverb, storage, scheduler, or
   backup warnings.
5. Send a real mail smoke test with `php artisan wayfindr:mail-test
   --to="verified-recipient@example.com"`.
6. Confirm `php artisan queue:work`, the one-minute scheduler, and Reverb are
   managed by the host or process manager.
7. Send a test visitor message through the widget or smoke script.
8. Reply from the agent dashboard and confirm the visitor can see the reply.

The smoke path is intentionally boring. It should catch wiring mistakes before
real support traffic starts flowing through the instance.
