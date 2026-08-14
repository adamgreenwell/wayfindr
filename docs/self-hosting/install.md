# Self-Hosting Install

Wayfindr self-hosts as a Docker Compose stack: FrankenPHP web server (with
automatic HTTPS), queue worker, scheduler, Reverb websockets, Postgres, and
Redis. Three paths get you there: the one-line installer, Docker Compose by
hand, or Laravel Forge.

Self-hosters control their own visitor data, logs, backups, retention
windows, privacy notices, and deletion/export workflows. Review
[data-responsibility.md](../privacy/data-responsibility.md) before using
Wayfindr with real visitors.

## What you need

- A Linux machine (or VM) with **Docker and the Compose plugin** installed.
  Images are published for amd64 and arm64.
- **1 GB of RAM** to try it; **2 GB** recommended for real traffic, and add
  ~1.5 GB more if you enable ClamAV attachment scanning.
- A few GB of disk for images, the database, and attachments.
- For automatic HTTPS: a **DNS record** for your support hostname pointing
  at the machine, with ports **80 and 443** free.
- For a local or internal install, none of that — `localhost`, an IP, or a
  `.local`/`.internal` name works, on whatever port you pick.

## Path 1: the one-line installer

```bash
curl -fsSL https://raw.githubusercontent.com/adamgreenwell/wayfindr/main/scripts/self-host/install.sh \
  | bash -s -- --app-url https://support.example.com
```

The installer checks Docker, downloads the stack files into `./wayfindr`,
mints application/database/Reverb secrets, starts the services, runs
migrations, waits for health, and prints the `/setup` URL. The first run
downloads the application image, so expect a couple of minutes. FrankenPHP
obtains and renews the TLS certificate automatically. Re-running converges;
secrets are preserved.

Upgrading later is one command — it refreshes the stack files at the newest
release, pulls its image, restarts, and runs any new migrations
automatically:

```bash
./wayfindr/install.sh --upgrade
```

Running behind your own TLS-terminating reverse proxy? Keep the real
`https://` URL and add `--behind-proxy`:

```bash
curl -fsSL https://raw.githubusercontent.com/adamgreenwell/wayfindr/main/scripts/self-host/install.sh \
  | bash -s -- --app-url https://support.example.com --behind-proxy
```

Every port then binds to loopback and your proxy points at the value of
**`WAYFINDR_LOCAL_BIND`** in the generated env file — usually
`127.0.0.1:8000`, but it moves if your own public port would collide with
it, and the installer prints the address to use. Websockets are routed
internally, so that single upstream is enough. URLs, secure cookies, and
browser websockets stay https, and the stack honors your proxy's
`X-Forwarded-*` headers.

### Installing on localhost or an internal network

The URL you give is the URL that serves — including its port. A bare host
works too, and the installer prints the URL it settled on:

```bash
./install.sh --app-url localhost                  # http://localhost
./install.sh --app-url https://localhost:2345     # TLS on 2345
./install.sh --app-url https://wayfindr.local     # TLS on your LAN
```

Loopback hosts (`localhost`, `127.0.0.1`, `::1`) infer `http://` and publish
**only on loopback** — nothing is exposed to your network. Every other host
infers `https://` and publishes on all interfaces, as its URL implies.

No public certificate authority can issue for `localhost`, an IP address, or
a `.local`/`.internal`/`.test` name, so Caddy signs those with its own CA
during install rather than attempting an ACME challenge that could only fail.
That also means they are not restricted to port 443 the way a public
certificate is — any port you name works.

Browsers will warn until that CA root is trusted. The installer prints the
export command when it applies:

```bash
cd ./wayfindr && docker compose -f compose.yml --env-file .env cp web:/data/caddy/pki/authorities/local/root.crt ./wayfindr-local-ca.crt
```

Add `wayfindr-local-ca.crt` to the trust store of each machine that browses
there — on macOS via Keychain Access (System → drag in → Always Trust), on
Debian/Ubuntu by copying it to `/usr/local/share/ca-certificates/` and
running `update-ca-certificates`. The root lives in a Docker volume, so it
survives upgrades and only needs trusting once per client machine.

DNS for a `.local` name is still yours to arrange (mDNS or a hosts entry) —
the certificate does not make the name resolve.

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
docker compose -f docker/self-hosting/compose.yml \
  -f docker/self-hosting/compose.build.yml \
  --env-file docker/self-hosting/.env up -d --build
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
  backups as a manual responsibility. Wayfindr ships `wayfindr:backup` and
  a guarded `wayfindr:restore` for the round trip —
  [backup-restore.md](backup-restore.md) — but scheduling them and copying
  archives offsite is yours to own.
- Before routing real visitor traffic, review
  [MVP Dogfood Readiness](../product/mvp-dogfood-readiness.md).

`/operator` reports which release is running — the official image bakes its
version and commit in, so an install from the one-liner or the published
image answers "what code is this?" with no configuration.

A **source build** derives `<VERSION>-dev` from the repository's `VERSION` file.
That names the lineage but not the build, and two source builds many commits
apart both report it — so anything that compares versions treats a bare `-dev` as
unverifiable, and a **restore cannot confirm an archive matches the install**
(it keeps the site in maintenance for you to check). Pass the commit to pin the
build and make the identity comparable:

```bash
# The commit is passed ONLY from a clean checkout. `git rev-parse HEAD` reports
# the last commit even when the tree has uncommitted edits, but Docker builds the
# edited files — so a dirty build would claim to be a commit it is not, and two
# differently-edited trees would claim to be the same one. A dirty build
# therefore gets no commit and identifies by lineage only (`0.1.0-dev`), which
# version checks correctly treat as unverifiable.
# `git diff` only inspects TRACKED files, so the untracked check matters too: a
# brand-new migration or class is invisible to it but goes straight into the
# build context. `--exclude-standard` honours .gitignore, so vendor/ and friends
# do not count as dirt.
if git diff --quiet && git diff --cached --quiet \
   && [ -z "$(git ls-files --others --exclude-standard)" ]; then
  export WAYFINDR_BUILD_COMMIT="$(git rev-parse HEAD)"
else
  unset WAYFINDR_BUILD_COMMIT
  echo 'Uncommitted or untracked changes — building without a pinned commit identity.'
fi

docker compose \
  -f docker/self-hosting/compose.yml \
  -f docker/self-hosting/compose.build.yml \
  --env-file docker/self-hosting/.env up -d --build
```

`WAYFINDR_BUILD_VERSION` overrides the derived version outright — use it when
building a specific release from source. Setting `WAYFINDR_VERSION` /
`WAYFINDR_COMMIT` in your env file overrides the baked values (including blanking
them), so leave those alone unless you are deploying a custom build. Quote the
version when you file an issue.

## Where your data lives

Everything durable sits in named Docker volumes under the
`wayfindr-self-hosting` compose project. The two your backups must cover
are `wayfindr-self-hosting_wayfindr-postgres` (the database) and
`wayfindr-self-hosting_wayfindr-storage` (uploaded attachments and logs) —
`docker volume ls` shows them; certificates live in the caddy-data volume
and regenerate if lost. Stopping the stack
(`docker compose ... down`) keeps all data. **Adding `-v` deletes every
volume — database included.** There is no undo.

For a portable, restorable snapshot of the database and local attachments
(rather than a raw volume copy), use `wayfindr:backup` /
`wayfindr:restore` — see [backup-restore.md](backup-restore.md), which also
covers the R2/S3 attachment split and getting archives offsite.
