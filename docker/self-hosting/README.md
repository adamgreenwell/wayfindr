# Wayfindr Self-Hosting Stack

The official Docker Compose stack for Wayfindr: FrankenPHP web server,
queue worker, scheduler, Reverb websockets, Postgres, and Redis — one
application image shared by every PHP process.

Use it with the runtime contract in
[`../../docs/self-hosting/runtime-requirements.md`](../../docs/self-hosting/runtime-requirements.md).

## Files

- `compose.yml` — the stack. Pulls `ghcr.io/adamgreenwell/wayfindr` by
  default and can build the same image locally from source.
- `server.Dockerfile` — the multi-stage application image build
  (dashboard assets, composer vendor tree, FrankenPHP runtime). The image
  keeps the monorepo shape because the widget script is served from
  `packages/widget-js` at runtime.
- `Caddyfile` — FrankenPHP/Caddy config. `SERVER_NAME` is the TLS knob
  (see below). Reverb websockets are proxied under the same hostname, so
  one domain and one certificate cover the app and realtime.
- `docker-entrypoint.sh` — recreates the storage tree on (possibly empty)
  volumes and, when `WAYFINDR_AUTO_MIGRATE=1`, waits for the database and
  runs migrations. The compose web service opts in, so fresh installs and
  upgrades converge without a manual `exec`.
- `../../scripts/self-host/generate-env.sh` — creates a starter `.env`
  with fresh application, database, and Reverb secrets.
- `../../scripts/smoke/self-host-compose.sh` — local end-to-end smoke
  check.

## TLS: one knob

| `SERVER_NAME`         | Behavior                                                                                          |
| --------------------- | ------------------------------------------------------------------------------------------------- |
| `support.example.com` | FrankenPHP binds 80/443 and obtains + renews Let's Encrypt certificates automatically.             |
| `localhost`           | Same, but Caddy signs with its own CA (`tls internal`) — no public CA can issue for this name.     |
| `:80` (default)       | Plain HTTP — for smoke tests or an operator-managed reverse proxy.                                 |

A loopback ops site on `:8000` exists in **every** mode: health probes and
behind-proxy upstreams use it, so the public site's TLS never gates
container health.

`generate-env.sh` picks the mode from the `--app-url` scheme and the
`--behind-proxy` flag: an `https://` URL alone sets `SERVER_NAME` to the
hostname and publishes the URL's port; adding `--behind-proxy` keeps every
bind on loopback while `APP_URL`, cookies, and the browser websocket values
stay https, and sets `TRUSTED_PROXIES` so Laravel honors your proxy's
`X-Forwarded-*` headers. Point the proxy at `127.0.0.1:8000` — the in-stack
Caddy still routes `/app` and `/apps` to Reverb, so one upstream covers the
app and websockets.

**The URL's port is the port that serves.** The container always listens on
80/443; the port from `--app-url` lives on the host side of the publish, so
`https://localhost:2345` publishes `127.0.0.1:2345 -> 443`. A certificate
covers a hostname and not a port, so SNI still matches on the way through and
`SERVER_NAME` stays portless.

### Locally-issued certificates

Hosts no public certificate authority can issue for get one from Caddy's own
CA instead of an ACME challenge that could only fail: `localhost`, IP
literals, the RFC-reserved suffixes (`.localhost`, `.local`, `.internal`,
`.home.arpa`, `.test`, `.example`, `.invalid`), and any single-label name.
`generate-env.sh` writes `CADDY_SERVER_EXTRA_DIRECTIVES=tls internal` and
`CADDY_GLOBAL_OPTIONS=skip_install_trust` for those, so the decision is
visible in the env file rather than left to Caddy's own classification.

Because ACME never runs for them, these are **not** limited to port 443 the
way a publicly-issued certificate is. Browsers will warn until the CA root is
trusted on each machine that browses there:

```bash
docker compose -f docker/self-hosting/compose.yml --env-file docker/self-hosting/.env \
  cp web:/data/caddy/pki/authorities/local/root.crt ./wayfindr-local-ca.crt
```

The root lives in the `wayfindr-caddy-data` volume, so it survives recreates
and only has to be trusted once per client machine.

## Quick start

```bash
scripts/self-host/generate-env.sh --app-url https://support.example.com
$EDITOR docker/self-hosting/.env   # review mail settings at minimum
docker compose -f docker/self-hosting/compose.yml --env-file docker/self-hosting/.env up -d
```

Migrations run automatically on the web service. When the stack is
healthy, visit `/setup` on the configured `APP_URL` to create the first
operator/account owner. To build from source instead of pulling the
published image, add the build overlay:

The shared `wayfindr-storage` volume is mounted with Docker's `nocopy`
option. Every app service can be created in parallel on first boot, so the
stack does not ask Docker to copy the image's bundled storage tree into the
named volume. A one-shot `storage-init` service creates the Laravel storage
directories as root, hands them to the non-root `wayfindr` user, and then exits
before the app services start.

```bash
docker compose -f docker/self-hosting/compose.yml \
  -f docker/self-hosting/compose.build.yml \
  --env-file docker/self-hosting/.env up -d --build
```

(`compose.yml` itself is pull-only because the installer places it in a
directory with no source tree.)

## Optional malware scanning

Enable the bundled ClamAV service (needs ~1.5 GB of memory for
signatures):

```bash
docker compose -f docker/self-hosting/compose.yml --profile clamav up -d
```

and set in the env file:

```env
WAYFINDR_ATTACHMENT_SCANNER=clamav
WAYFINDR_CLAMAV_SOCKET=tcp://clamav:3310
```

## Smoke test

From the repository root:

```bash
scripts/test-self-host-compose-template.sh
scripts/smoke/self-host-compose.sh
```

## Upgrading from the prototype

Envs generated by the pre-FrankenPHP prototype need three touches:

- Delete `WAYFINDR_HTTP_BIND` and `WAYFINDR_REVERB_BIND` (both are ignored
  now; the app stays reachable at `127.0.0.1:8000` via the
  `WAYFINDR_LOCAL_BIND` default, so an existing reverse proxy keeps
  working unchanged).
- Add `SERVER_NAME` (`:80` behind a proxy, or your hostname for automatic
  TLS).
- If ports 80/443 are occupied on the host, set
  `WAYFINDR_PUBLIC_HTTP_BIND` / `WAYFINDR_PUBLIC_HTTPS_BIND` to loopback
  ports (e.g. `127.0.0.1:18080` / `127.0.0.1:18443`).

## Operator responsibilities

The stack does not solve DNS, mail provider verification, backups,
restore drills, storage durability, log retention, or upgrade timing.
The operator readiness screens (`/dashboard/readiness`, `/operator`)
remain part of the launch path.
