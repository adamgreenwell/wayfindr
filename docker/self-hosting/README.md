# Wayfindr Self-Hosting Prototype

This directory contains the first Docker Compose / Coolify-style process map
for Wayfindr. It is a prototype, not a production installer.

Use it with the runtime contract in
[`../../docs/self-hosting/runtime-requirements.md`](../../docs/self-hosting/runtime-requirements.md)
and the setup-template notes in
[`../../docs/self-hosting/setup-templates.md`](../../docs/self-hosting/setup-templates.md).

## Files

- `compose.prototype.yml` describes the web, queue, scheduler, Reverb,
  Postgres, and Redis process shape.
- `server.Dockerfile` builds a prototype Laravel server image for the Compose
  process map.
- `.env.example` lists the environment values the template expects.
- `../../scripts/smoke/self-host-compose.sh` runs a local end-to-end smoke
  check against this template.

The Compose file can build a local application image through
`server.Dockerfile` and tags it as `WAYFINDR_IMAGE`. Wayfindr does not publish
an official server image yet.
The example HTTP and Reverb ports bind to `127.0.0.1` so public traffic should
still pass through a host-managed TLS proxy. The application image should make
`apps/server/bootstrap/cache` writable, but that directory should not be a
durable volume because cached Laravel artifacts can become stale across image
or environment changes. The prototype web command uses Laravel's built-in
server so operators can smoke-test the process map; official images may switch
to a production web server once packaging and release workflows mature.

Set `WAYFINDR_HTTP_BIND` or `WAYFINDR_REVERB_BIND` when the default local
ports are already in use. Set `WAYFINDR_ENV_FILE` only for smoke tests or
other controlled runs that need an env file outside this directory; normal
operators should keep using `.env`.

## Smoke Test

From the repository root:

```bash
scripts/test-self-host-compose-template.sh
scripts/smoke/self-host-compose.sh
```

The smoke runner creates a temporary env file with throwaway secrets, builds
the prototype image, starts Postgres, Redis, web, queue, scheduler, and Reverb
under an isolated Compose project, runs migrations, confirms
`wayfindr:send-alert-digests` appears in `php artisan schedule:list`, and
checks `/up`.

By default it binds the web process to `127.0.0.1:18080` and Reverb to
`127.0.0.1:18081`, then tears the stack down with volumes. Override those
ports with `WAYFINDR_SMOKE_HTTP_PORT` and `WAYFINDR_SMOKE_REVERB_PORT`. Set
`WAYFINDR_SMOKE_KEEP=1` only when you want to inspect the running containers
after the smoke check.

## Prototype Flow

```bash
cp docker/self-hosting/.env.example docker/self-hosting/.env
$EDITOR docker/self-hosting/.env
docker compose --env-file docker/self-hosting/.env -f docker/self-hosting/compose.prototype.yml config
docker compose --env-file docker/self-hosting/.env -f docker/self-hosting/compose.prototype.yml build web
docker compose --env-file docker/self-hosting/.env -f docker/self-hosting/compose.prototype.yml up -d
```

After the containers start, run migrations and inspect scheduled tasks:

```bash
docker compose --env-file docker/self-hosting/.env -f docker/self-hosting/compose.prototype.yml exec web php artisan migrate --force
docker compose --env-file docker/self-hosting/.env -f docker/self-hosting/compose.prototype.yml exec web php artisan schedule:list
```

Then visit `/setup` on the configured `APP_URL`.

The prototype build uses `npm install` when no `package-lock.json` exists.
Before Wayfindr publishes official images, the project should add a lockfile
and a release workflow that builds the image from an immutable git ref.

## Operator Responsibilities

This template does not solve DNS, TLS, mail provider verification, WebSocket
proxy routing, backups, restore drills, storage durability, log retention, or
upgrades. The operator readiness screens should remain part of the launch
path.
