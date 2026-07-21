# Self-Hosting Install

Wayfindr self-hosts as a Docker Compose stack: FrankenPHP web server (with
automatic HTTPS), queue worker, scheduler, Reverb websockets, Postgres, and
Redis. Two supported paths get you there, plus Laravel Forge for
Laravel-native hosting.

Self-hosters control their own visitor data, logs, backups, retention
windows, privacy notices, and deletion/export workflows. Review
[data-responsibility.md](../privacy/data-responsibility.md) before using
Wayfindr with real visitors.

## Path 1: the one-line installer

On a machine with Docker installed, DNS for your support hostname pointed at
it, and ports 80/443 free:

```bash
curl -fsSL https://raw.githubusercontent.com/adamgreenwell/wayfindr/main/scripts/self-host/install.sh \
  | bash -s -- --app-url https://support.example.com
```

The installer checks Docker, downloads the stack files into `./wayfindr`,
mints application/database/Reverb secrets, starts the services, runs
migrations, waits for health, and prints the `/setup` URL. FrankenPHP
obtains and renews the TLS certificate automatically. Re-running converges;
secrets are preserved.

Upgrades later:

```bash
./wayfindr/install.sh --upgrade --dir ./wayfindr   # or re-run the curl line with --upgrade
```

Running behind your own TLS-terminating reverse proxy? Keep the real
`https://` URL and add `--behind-proxy`:

```bash
curl -fsSL https://raw.githubusercontent.com/adamgreenwell/wayfindr/main/scripts/self-host/install.sh \
  | bash -s -- --app-url https://support.example.com --behind-proxy
```

Every port then binds to loopback and your proxy points at
`127.0.0.1:8000` (websockets are routed internally, so that single
upstream is enough), while URLs, secure cookies, and browser websockets
stay https and the stack honors your proxy's `X-Forwarded-*` headers.
Plain `http://` URLs are for local smoke tests only.

## Path 2: Docker Compose by hand

Clone or download the repo, then follow
[docker/self-hosting/README.md](../../docker/self-hosting/README.md):

```bash
scripts/self-host/generate-env.sh --app-url https://support.example.com
$EDITOR docker/self-hosting/.env
docker compose -f docker/self-hosting/compose.yml --env-file docker/self-hosting/.env up -d
```

The stack pulls `ghcr.io/adamgreenwell/wayfindr` by default. To build the
same image from source, add the build overlay:

```bash
docker compose -f docker/self-hosting/compose.yml -f docker/self-hosting/compose.build.yml   --env-file docker/self-hosting/.env up -d --build
```

Optional attachment malware scanning is one profile away
(`--profile clamav`); see the stack README.

## Path 3: Laravel Forge

Wayfindr is Laravel-first, and Forge maps cleanly to its runtime pieces.
Use [laravel-forge.md](laravel-forge.md) for the deployment checklist,
deploy script templates, environment values, queue worker, scheduler, and
smoke test. Use [runtime-requirements.md](runtime-requirements.md) when
translating Wayfindr to any other Laravel-capable host — it documents the
generic runtime contract.

## After the stack is up

Visit `/setup` on your `APP_URL` to create the first account owner and
install site from the browser. The setup screen is available until the
database has an account-scoped user, and it reuses interrupted first-run
records so nobody cleans up SQL by hand.

The first owner is also marked as the initial platform operator for
`/operator` instance diagnostics. Platform operator access remains separate
from account roles and does not grant support-data visibility by itself.

Then work through the readiness screens:

- **Mail is the first gate.** The generated env leaves `MAIL_MAILER=log` so
  the stack boots before mail exists — but alert emails and password resets
  go nowhere until you configure a real provider. Use
  [email-delivery.md](email-delivery.md) (SPF/DKIM/DMARC included) and run
  the mail smoke test.
- `/dashboard/readiness` and `/operator` flag what the app can inspect
  directly (queues, realtime, scheduler, storage, scanning) and mark
  backups as a manual responsibility — backup coverage lives in your
  infrastructure, and restore drills are yours to run.
- Before routing real visitor traffic, review
  [MVP Dogfood Readiness](../product/mvp-dogfood-readiness.md).

Set `WAYFINDR_VERSION` and `WAYFINDR_COMMIT` when your deployment process
can provide release identity values; they make `/operator` more useful when
someone needs to confirm what code is running.
