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
