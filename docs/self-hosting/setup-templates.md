# Self-Hosting Setup Templates

Wayfindr should get easier to launch without pretending that self-hosting has
no operational responsibilities. This page is the first template waypoint: a
Docker Compose and Coolify-style process map that operators can adapt while the
project is still pre-alpha.

This is not a production installer yet. Treat it as a scaffold for the runtime
shape described in [runtime-requirements.md](runtime-requirements.md).

## First Target

Start with Docker Compose and Coolify-style application platforms.

That target gives Wayfindr the clearest portable shape:

- one web process;
- one queue worker process;
- one scheduler process or scheduled job;
- one Reverb process when realtime is enabled;
- Postgres;
- Redis;
- persistent Laravel storage;
- host-managed TLS, DNS, outbound mail, and backups.

Do not make the first one-command path a generic VPS shell script. A shell
script would need to own operating system packages, firewall policy, TLS,
database provisioning, Redis provisioning, process supervision, and upgrades.
That can come later only if the project has enough runtime experience to make
those choices responsibly.

## Template Files

The prototype files live in [`../../docker/self-hosting`](../../docker/self-hosting):

- `compose.prototype.yml` models the required Wayfindr services.
- `server.Dockerfile` builds a prototype Laravel server image for the Compose
  process map.
- `.env.example` lists the environment shape operators need to provide.
- `README.md` explains how to adapt the template.
- [`../../scripts/smoke/self-host-compose.sh`](../../scripts/smoke/self-host-compose.sh)
  proves the prototype stack can build, boot, migrate, list scheduled tasks,
  and answer `/up` with throwaway local secrets.

The template can build a local application image. Until Wayfindr publishes an
official image, operators should treat that image as a prototype and translate
the process map into a host-specific setup when needed.

## One-Command-Ish Flow

The aspirational flow should feel like this:

```bash
cp docker/self-hosting/.env.example docker/self-hosting/.env
$EDITOR docker/self-hosting/.env
docker compose --env-file docker/self-hosting/.env -f docker/self-hosting/compose.prototype.yml build web
docker compose --env-file docker/self-hosting/.env -f docker/self-hosting/compose.prototype.yml up -d
```

That is intentionally not a blind pipe-to-shell one-liner. Operators still need
to set secrets, DNS, TLS, mail, storage, and backup policy deliberately.

For local template verification without touching a real operator `.env`, run:

```bash
scripts/test-self-host-compose-template.sh
scripts/smoke/self-host-compose.sh
```

The smoke runner uses an isolated Compose project, `127.0.0.1:18080` for HTTP,
`127.0.0.1:18081` for Reverb, and tears down containers and volumes when it
finishes.

After the stack starts:

```bash
docker compose --env-file docker/self-hosting/.env -f docker/self-hosting/compose.prototype.yml exec web php artisan migrate --force
docker compose --env-file docker/self-hosting/.env -f docker/self-hosting/compose.prototype.yml exec web php artisan schedule:list
```

Then visit `/setup` on the public `APP_URL`, create the first operator/account
owner, and follow `/operator` or `/dashboard/readiness`.

## Secret Generation

Generate secrets locally and paste them into the host secret manager or
environment file. Do not commit generated secrets.

```bash
cd apps/server
php artisan key:generate --show
openssl rand -base64 32
```

Use the Artisan key output for `APP_KEY`. Use independent random values for
`REVERB_APP_SECRET`, database passwords, and mail credentials. The Reverb app
key can be public browser configuration; the Reverb secret must stay private.

## What The Template Can Automate

The template can safely model:

- the web, queue, scheduler, and Reverb process split;
- Postgres and Redis service wiring;
- persistent storage mounts;
- Laravel environment variable names;
- predictable internal service hostnames;
- the first post-install smoke commands.

## What Must Stay Explicit

Operators still need to own:

- DNS and public `APP_URL`;
- TLS termination and WebSocket proxying;
- outbound mail provider verification;
- backup schedules, retention, monitoring, and restore drills;
- storage durability;
- log retention and privacy policy alignment;
- upgrades and rollback strategy;
- whether a public site should receive real visitor traffic.

Wayfindr can make those steps visible. It should not hide them behind cheerful
automation.

## Coolify Translation

For Coolify-style platforms, translate the Compose services into separate
processes using the same working directory and commands:

| Process | Command |
| --- | --- |
| Web | Host-specific PHP web process serving `apps/server/public` |
| Queue | `php artisan queue:work redis --sleep=3 --tries=3 --timeout=90` |
| Scheduler | `php artisan schedule:work` or host scheduler running `php artisan schedule:run` once per minute |
| Reverb | `php artisan reverb:start --host=0.0.0.0 --port=8080` |

Keep Postgres, Redis, and storage persistent. Route `/app` and `/apps`
WebSocket paths to Reverb through public HTTPS before enabling
`BROADCAST_CONNECTION=reverb`.

## Implementation Waypoints

1. Document the first setup-template target and boundaries. Done here.
2. Add a prototype Compose process map. Done here.
3. Add a prototype application image build. Done here.
4. Add a local Compose smoke path that builds, boots, migrates, checks the
   scheduler, and hits `/up`. Done here.
5. Add registry publishing only after the server packaging path is tested and
   supportable.
6. Add host-specific examples such as Coolify only after the generic process
   map survives real smoke tests.
7. Consider a true one-command installer only after image publishing, backups,
   upgrades, and restore guidance are boring.
