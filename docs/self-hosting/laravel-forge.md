# Laravel Forge Deployment

This is the first-class staging/demo deployment path for Wayfindr. Forge gives
us a plain Laravel runtime, managed deploy hooks, queue workers, scheduled
jobs, TLS, and health checks without introducing our own platform layer yet.

Forge is recommended, not required. Wayfindr can run on any infrastructure that
can provide the same Laravel, Postgres, Redis, queue, scheduler, and realtime
services. The Forge path gets the most complete docs first because this project
is Laravel-first and Forge is a strong fit for Laravel applications.

No partnership, sponsorship, or hosting requirement is implied.

Wayfindr is still pre-alpha. Use this environment for product validation and
integration smoke tests, not for real customer data.

## Source Control Access

For anonymous-user testing, deploy from your own fork of Wayfindr. A random
self-hoster cannot add deploy keys to the upstream Wayfindr repository, and
they should not need to.

Recommended flow:

1. Fork Wayfindr into your GitHub account or organization.
2. In Forge, choose `Custom Git` as the source control provider.
3. Turn on `Generate a site deploy key for your source control provider`.
4. Add the generated key to your fork's GitHub `Settings > Deploy keys`.
5. Leave `Allow write access` unchecked.
6. Use the fork's SSH repository URL in Forge.

Example repository URL:

```text
git@github.com:your-org/wayfindr.git
```

If GitHub says `Key is already in use`, Forge is probably showing a server-level
SSH key that has already been attached to another repository. GitHub does not
allow the same public key to be reused as a deploy key across repositories. Use
a site deploy key instead so the key is unique to this Wayfindr site.

Using Forge's connected GitHub provider is also fine for teams that prefer
OAuth/App-based access, but the fork plus site deploy key path is the cleanest
repo-scoped install flow to document for self-hosters.

## Forge Site Shape

- Project type: Laravel.
- Repository: your fork, for example `your-org/wayfindr`.
- Initial deploy branch: `main` for stable releases, or a feature branch when
  intentionally testing unreleased work.
- PHP: 8.3 or newer.
- Database: Postgres.
- Cache/queue: Redis.
- Root directory: `/`.
- Web directory: `/apps/server/public`.
- Health check URL: `/up`.

For a new Forge site, leave zero-downtime deployments enabled. Forge enables
this by default for new sites, and it cannot be added to an existing site
later. Add `apps/server/storage` as a shared path so logs and uploaded files
survive release swaps. If Forge pre-populates a `storage -> storage` shared
path, change it to `apps/server/storage -> apps/server/storage` for Wayfindr's
monorepo layout. Forge automatically shares `.env` for zero-downtime sites.

Turn off Forge's creation-time `Install Composer dependencies` option. Wayfindr
is a monorepo, and Forge runs that automatic Composer install from the repository
root. Our `composer.json` lives in `apps/server`, so the install belongs in the
deploy script instead. Also leave the creation-time frontend build disabled; the
deploy script will run it only after the Laravel app has a lockfile to build
from.

## Environment

Manage these values in Forge's site environment editor, not in Git:

```dotenv
APP_NAME=Wayfindr
APP_ENV=staging
APP_KEY=base64:replace-with-generated-key
APP_DEBUG=false
APP_URL=https://replace-with-forge-site-host
WAYFINDR_VERSION=
WAYFINDR_COMMIT=

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=replace-with-forge-db-host
DB_PORT=5432
DB_DATABASE=replace-with-forge-db-name
DB_USERNAME=replace-with-forge-db-user
DB_PASSWORD=replace-with-forge-db-password

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

BROADCAST_CONNECTION=log
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

REVERB_APP_ID=replace-with-reverb-app-id
REVERB_APP_KEY=replace-with-random-public-key
REVERB_APP_SECRET=replace-with-random-secret
REVERB_HOST=replace-with-websocket-host
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_SERVER_PATH=

MAIL_MAILER=log
MAIL_FROM_ADDRESS=hello@example.test
MAIL_FROM_NAME="${APP_NAME}"
```

Use `MAIL_MAILER=log` only for smoke installs that should not send email.
Before real support traffic, configure a real outbound provider such as `smtp`,
`ses`, `postmark`, or `resend`, set a monitored `MAIL_FROM_ADDRESS`, and send a
test email from the deployed environment. For SMTP, replace the local
`MAIL_HOST=127.0.0.1` and `MAIL_PORT=2525` defaults with the provider's real
host and port. Leave `MAIL_SCHEME` unset or `null` for port 587 STARTTLS SMTP
providers; use `MAIL_SCHEME=smtps` only for implicit TLS providers on port 465.
For a worked provider example — Google Workspace SMTP relay — plus SPF/DKIM/DMARC
deliverability and a troubleshooting table, see
[email-delivery.md](email-delivery.md).

Keep `BROADCAST_CONNECTION=log` until a Reverb process and WebSocket routing are
ready. Switch it to `reverb` when the site should publish live conversation
message events. `REVERB_APP_KEY` and `REVERB_APP_SECRET` should be long random
strings; `openssl rand -hex 16` is fine for each value.

Generate the `APP_KEY` on the server with:

```bash
cd ~/wayfindr.on-forge.com/current/apps/server
php artisan key:generate --show
```

Forge's zero-downtime `current` directory points to the monorepo root, not the
Laravel application root. Wayfindr ships a root-level `artisan` shim that
forwards to the real console entrypoint under `apps/server`, so `php artisan`
works from the monorepo root as well:

```bash
cd ~/wayfindr.on-forge.com/current
php artisan about
```

That shim is what lets Forge's own root-level tooling — the **Commands** panel,
the managed **scheduler** cron, and the **queue** worker — run `php artisan ...`
from the site root without a path prefix or `Could not open input file:
artisan` error. Running from `apps/server` directly works too:

```bash
cd ~/wayfindr.on-forge.com/current/apps/server
php artisan about
```

Forge stores the site environment file at the monorepo root. The Wayfindr deploy
script links that file into `apps/server/.env` before Composer and Artisan run.
If web routes fail with `No application encryption key has been specified` even
after `APP_KEY` was set in Forge, verify the link exists:

```bash
cd ~/wayfindr.on-forge.com/current/apps/server
readlink .env
php artisan tinker --execute="app('encrypter'); echo 'encrypter ok'.PHP_EOL;"
```

The link should resolve to `../../.env`.

## Deploy Script

Use [zero-downtime-deploy.forge](../../deploy/forge/zero-downtime-deploy.forge)
as the Forge deploy script for new sites. It assumes Forge's zero-downtime
release macros are available and that commands run from the monorepo root. The
macro lines are Forge-specific and are not meant to run in a local shell. After
the release is activated, the script restarts Laravel queues from the active
`current` release with `php artisan queue:restart` and asks Laravel Reverb to
gracefully reload with `php artisan reverb:restart`.

If the site was created with `Install Composer dependencies` enabled and Forge
reports `Composer could not find a composer.json file`, the site can still be
used. Replace the generated deploy script with Wayfindr's script, set the
environment variables, and run a fresh deploy.

If deployment reaches `view:cache` and fails with `View path not found`, verify
the shared storage path is `apps/server/storage -> apps/server/storage` and that
the deploy script includes Wayfindr's runtime-directory preparation step. The
failure usually means Forge linked an empty shared storage directory before the
Laravel storage subdirectories existed.

If zero-downtime deployments were disabled when the site was created, use
[standard-deploy.sh](../../deploy/forge/standard-deploy.sh) instead.

Both scripts:

- link Forge's root `.env` into `apps/server/.env`,
- install production Composer dependencies from `apps/server`,
- skip the frontend build until a `package-lock.json` exists,
- run migrations with `--force`,
- cache config, routes, and views,
- restart queues and Reverb after deploy.

## First Account Setup

After the first deploy, visit `/setup` on the Forge site to create the first
account, owner, and install site from the browser. This is the preferred
first-run path because it keeps the normal self-hosting flow in the web
interface instead of requiring SSH for routine activation.

The setup screen is locked after Wayfindr has an account-scoped user. If an
interrupted deploy or CLI run already created account or site records but no
owner, `/setup` reuses those first-run records instead of asking the operator to
perform database cleanup. If you prefer to create the first records from the
terminal, run the CLI bootstrap command from the Laravel app directory:

```bash
cd ~/wayfindr.on-forge.com/current/apps/server
php artisan wayfindr:bootstrap \
  --account="Acme Support" \
  --name="Ada Agent" \
  --email="ada@example.com" \
  --site="Acme Website" \
  --domain="example.com"
```

When `--password` is omitted, the command generates and prints the first agent
password. When `--site-public-key` is omitted, it generates and prints the
public widget key for the site. The browser setup path asks for the owner
password directly and generates the site public key automatically.

After browser setup completes, Wayfindr signs in the first owner and sends them
directly to the new site's install snippet. The site settings page includes a
copy-ready widget script generated from the site public key, `APP_URL`, and any
public Reverb settings available to the application. It also links platform
operators to `/operator` so they can review instance health before chasing site
install issues. Use the dashboard's `Add site` action when you need separate
public keys for staging, production, or public dogfood sites.

The first owner is also marked as the initial platform operator. Open
`/operator` after bootstrap to review safe system identity and instance
readiness checks. Account owners and admins can still use
`/dashboard/readiness` for the same install checkup from the account dashboard.
These pages flag common self-hosting setup gaps such as missing app keys,
local or insecure public URLs, database connectivity problems, local-only mail
transport, queue worker configuration, Reverb settings, storage permissions,
scheduler setup, and backup/restore planning.

The backup check is intentionally manual. Wayfindr cannot prove Forge snapshots,
database dumps, storage retention, monitoring, or restore drills from inside the
application request. Confirm those pieces in Forge or your infrastructure
provider before putting real visitor conversations through the instance.

`WAYFINDR_VERSION` and `WAYFINDR_COMMIT` are optional release identity values.
Set them from your deploy process when available so the operator console can
show the running build without exposing secrets.

The command refuses to run when bootstrap records already exist. Use `--force`
only when you intentionally want to create or update the supplied account,
agent, and site records:

```bash
php artisan wayfindr:bootstrap \
  --force \
  --account="Acme Support" \
  --name="Ada Agent" \
  --email="ada@example.com" \
  --site="Acme Website"
```

## Queues And Scheduler

Create one Forge queue worker for the site:

```bash
php artisan queue:work redis --sleep=3 --tries=3 --timeout=90
```

Use the Laravel Scheduler toggle in Forge's Application panel. Forge will
configure it to run once per minute using the site's selected PHP version.
Forge's scheduler cron and the queue worker both invoke `php artisan ...` from
the site root; the root-level `artisan` shim (above) is what makes that resolve
in the monorepo layout, so neither needs a custom path.

Alert digest email is registered with Laravel's scheduler and runs hourly for
agents who choose digest cadence. After enabling the scheduler, run this from
the site root (or `apps/server`) to confirm Forge can see the task:

```bash
php artisan schedule:list
```

You should see `php artisan wayfindr:send-alert-digests` in the scheduled task
list. Dashboard alerts still appear immediately; this scheduled job only moves
metadata-only digest email. If `schedule:list` cannot run, check the configured
cache/Redis connection as well as the scheduler itself; Laravel inspects
scheduler mutex state while rendering the list.

## Reverb Process

Wayfindr can broadcast conversation messages over Laravel Reverb. Add a Forge
daemon/background process from the Laravel app directory:

```bash
php8.4 artisan reverb:start --host=127.0.0.1 --port=8080
```

Use the `forge` user, one process, and autorestart. The process is long-running;
`Starting server on 127.0.0.1:8080 (...)` means Reverb is listening.

Reverb's public connection settings are intentionally separate from the private
server bind address. In a TLS deployment that proxies WebSockets through the
same site hostname, the common shape is:

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=wayfindr-production
REVERB_APP_KEY=replace-with-public-reverb-key
REVERB_APP_SECRET=replace-with-private-reverb-secret
REVERB_HOST=replace-with-forge-site-host
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
```

Forge-managed Reverb sites should route secure WebSocket traffic to the private
Reverb process. When using the same hostname as `APP_URL`, add Nginx proxy
blocks for `/app` and `/apps` before the main `location /` block:

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

If the WebSocket host differs from `APP_URL`, make sure that hostname has TLS
before testing from an external smoke site.

The widget needs the public Reverb connection settings plus `pusher-js` on the
host page. The dashboard-generated site install snippet includes these values
automatically when Reverb is configured. Manually, the shape looks like this:

```html
<script src="https://js.pusher.com/8.3.0/pusher.min.js"></script>
<script
  src="https://replace-with-forge-site-host/widget.js"
  data-wayfindr-api-base-url="https://replace-with-forge-site-host"
  data-wayfindr-site-key="replace-with-bootstrap-site-public-key"
  data-wayfindr-reverb-app-key="replace-with-public-reverb-app-key"
  data-wayfindr-reverb-host="replace-with-websocket-host"
  data-wayfindr-reverb-port="443"
  data-wayfindr-reverb-scheme="https"
></script>
```

The Reverb app key is safe to expose to browsers. Keep `REVERB_APP_SECRET`
server-side only.

The deploy scripts call `php artisan reverb:restart` after each deploy so the
background process reloads the active release.

## First Deploy Checklist

1. Provision a Forge server with PHP 8.3+, Postgres, Redis, and Nginx.
2. Fork Wayfindr to your GitHub account or organization.
3. Create the Forge site using `Custom Git`.
4. Enable `Generate a site deploy key for your source control provider`.
5. Add the generated key to the fork as a read-only GitHub deploy key.
6. Use the fork's SSH repository URL and the target deploy branch.
7. Set root directory to `/` and web directory to `/apps/server/public`.
8. Turn off Forge's creation-time Composer install and frontend build options.
9. Keep zero-downtime deployments enabled.
10. Add the environment values above in Forge.
11. Replace the generated deploy script with Wayfindr's deploy script.
12. Run the first deploy.
13. Enable TLS before testing the widget from another origin.
14. Enable the deployment health check against `/up`.
15. Visit `/setup` and create the first account owner and install site.
16. Add the queue worker and scheduler, then confirm
    `php artisan wayfindr:send-alert-digests` appears in
    `php artisan schedule:list`.
17. Add the Reverb process when switching `BROADCAST_CONNECTION` to `reverb`.
18. Configure real outbound mail when email alerts, password resets, or
    notifications should leave the app, then run `php artisan wayfindr:mail-test
    --to="you@example.com"` from `apps/server` to confirm a real inbox receives
    mail.
19. Confirm database and storage backups are scheduled, retained, monitored,
    and restorable.
20. Sign in with the generated first agent credentials.
21. Review `/operator` or `/dashboard/readiness`, resolve any setup gaps, and
    follow the post-install smoke path before real visitor traffic.

## Smoke Test

From a local machine with `curl` and PHP available:

```bash
WAYFINDR_BASE_URL=https://replace-with-forge-site-host \
WAYFINDR_SITE_PUBLIC_KEY=replace-with-bootstrap-site-public-key \
./scripts/smoke/widget-intake.sh
```

Then sign in to the Forge site as the demo agent, confirm the smoke conversation
appears in the agent inbox, and send a short agent reply.

After configuring outbound mail, run a real mail smoke test from the Laravel
application directory:

```bash
cd ~/wayfindr.on-forge.com/current/apps/server
php artisan wayfindr:mail-test --to="verified-recipient@example.com"
```

If your mail provider still has sandbox restrictions, send to a verified
recipient until production access is approved. The command prints the configured
mailer, SMTP host, sender, and recipient, but it never prints SMTP credentials.

For browser embed checks from an external static site, use the deployed widget
script URL:

```text
https://replace-with-forge-site-host/widget.js
```

## Rollback

For zero-downtime deployments, use Forge's deployment history to reactivate the
previous release. If a migration shipped with the failed deploy, inspect it
before rolling back the database. Do not run destructive rollbacks against a
shared staging database unless the data can be thrown away.

For standard deployments, redeploy the previous known-good commit or branch and
run:

```bash
cd apps/server
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan reverb:restart
```

## References

- [Forge deployments](https://forge.laravel.com/docs/sites/deployments)
- [Forge environment variables](https://forge.laravel.com/docs/sites/environment-variables)
- [Forge queues](https://forge.laravel.com/docs/sites/queues)
- [Forge Laravel application panel](https://forge.laravel.com/docs/sites/laravel)
- [Forge source control](https://forge.laravel.com/docs/source-control)
- [Forge SSH and deploy keys](https://forge.laravel.com/docs/ssh)
