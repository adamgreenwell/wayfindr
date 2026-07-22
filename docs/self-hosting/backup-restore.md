# Backup and Restore

Wayfindr holds customer support conversations, tickets, and audit history — the
kind of data whose loss is unrecoverable. Two artisan commands take and restore
a backup, and a restore drill in CI proves the round trip, because a backup
whose restore has never been run is a hope, not a backup (ADR
[0009](../decisions/0009-backup-and-restore.md)).

```bash
# Take a backup.
docker compose -f docker/self-hosting/compose.yml --env-file docker/self-hosting/.env \
  exec web php artisan wayfindr:backup

# Restore one (into an empty install; add --force to overwrite a populated one).
docker compose -f docker/self-hosting/compose.yml --env-file docker/self-hosting/.env \
  exec web php artisan wayfindr:restore /path/to/wayfindr-backup-YYYYMMDD-HHMMSS-xxxxxx.tar.gz
```

On a one-line installer stack the files live in `./wayfindr`, so run the same
commands with `-f wayfindr/compose.yml --env-file wayfindr/.env`. On Laravel
Forge or any non-container host, `artisan` lives under `apps/server` — `cd
apps/server && php artisan wayfindr:backup`.

## What a backup captures

`wayfindr:backup` writes one timestamped `.tar.gz` containing:

- **A PostgreSQL dump** (`pg_dump`) — the source of truth. Every account,
  conversation, message, ticket, audit event, user, break-glass grant, and
  attachment *metadata row*.
- **Local attachment binaries** — the files on the private `attachments`
  disk(s), for installs using local storage.
- **A manifest** recording the Wayfindr version, the storage disk in effect,
  which local disks were captured, and which disks rows depend on that the
  archive does *not* carry.

Ephemeral or credential-bearing table **data** is deliberately excluded — the
schema is kept, but sessions, password-reset tokens, cache, and queue rows are
not dumped (reviving a session or reset token is a security hole). Redis, Caddy
certificates, and framework caches are not backed up; they regenerate.

### The remote-storage split — read this if you use R2/S3

If `WAYFINDR_ATTACHMENT_STORAGE_DISK` points at an object store (Cloudflare R2
or S3), those attachment binaries are **already durable in the bucket and are
NOT copied into the backup.** The dump restores the metadata rows; the binaries
are served from the bucket exactly as before. This keeps backups small and
avoids duplicating what the object store already keeps — but it means:

> **A backup of a remote-storage install is only as complete as the bucket it
> still points at.** Keep the bucket (and its own lifecycle/versioning)
> reachable. Do not delete a bucket and expect a Wayfindr backup to hold its
> attachments.

Because every attachment row records its own `storage_disk` (ADR
[0007](../decisions/0007-conversation-message-attachments.md)), one install can hold
both local (in-archive) and remote (bucket-resident) attachments — for example
after switching new uploads from local to R2. The backup captures the local
ones and the manifest **names the remote disks** the rows still depend on. The
backup command prints those disks; heed them.

## Where backups land, and getting them off the box

By default the archive is written to `storage/app/backups` inside the
`wayfindr-storage` volume (override with `WAYFINDR_BACKUP_PATH` or the `--path`
flag). That is durable across container restarts, **but it is not offsite** — a
backup that never leaves the machine shares the failure mode of no backup at
all. Until Wayfindr grows a remote-push option (see [Deferred](#deferred)),
copying archives offsite is your job. Two common patterns:

**Write to a host directory.** Map a host path into the `web` service and point
the command at it, so archives land straight on the host where your existing
offsite tooling (rsync, restic, a provider snapshot) can reach them:

```yaml
# docker-compose override for the web service
services:
  web:
    volumes:
      - /srv/wayfindr-backups:/backups
    environment:
      WAYFINDR_BACKUP_PATH: /backups
```

**Or copy each archive out** after taking it:

```bash
docker compose ... cp web:/app/apps/server/storage/app/backups/. ./wayfindr-backups/
```

Cadence and retention are yours to own — the command never deletes an archive
it did not just write. A simple nightly cron on the host:

```cron
15 3 * * *  cd /opt/wayfindr && docker compose --env-file wayfindr/.env -f wayfindr/compose.yml exec -T web php artisan wayfindr:backup >> /var/log/wayfindr-backup.log 2>&1
```

For a guaranteed-consistent snapshot, back up with writes quiesced (see the
maintenance posture below); a live backup is safe, and restore verifies
attachment integrity either way, but a row whose binary was deleted in the same
instant is only ever a concern on a live backup.

## Restoring

Restore replaces the database wholesale and puts local attachment binaries back
on the disks their rows expect. An accidental restore is itself a data-loss
event, so it is guarded:

- It **refuses to run against a populated database** unless you pass `--force`.
  The default assumes you are restoring into an empty or freshly provisioned
  install.
- It **warns on a version skew** between the archive and the running image.
- It is the **authoritative attachment-integrity check**: once the dump is
  loaded, it verifies each locally-homed attachment row's binary was in the
  archive and reports any that are missing (dangling), and it names rows whose
  binaries live in an external object store (which it cannot verify from the
  box — keep those buckets reachable).

### Maintenance-posture procedure

Restore while the app is quiesced so nothing writes into a database that is
being replaced. The `web` container keeps its public ports, so stopping the
workers is not enough — put the app in maintenance mode too, which 503s every
HTTP request while leaving the `artisan` CLI fully working:

```bash
cd wayfindr    # where compose.yml and .env live

# 1. Maintenance mode (503 to the world) + stop the background writers. The web
#    container stays up so you can still exec artisan.
docker compose --env-file .env exec web php artisan down
docker compose --env-file .env stop queue scheduler

# 2. Restore. Into a fresh install, omit --force; over existing data, it is
#    required and confirms you intend to overwrite.
docker compose --env-file .env exec web \
  php artisan wayfindr:restore /backups/wayfindr-backup-20260722-181500-a1b2c3.tar.gz --force

# 3. If the archive predates a schema change, the command will have warned;
#    bring the schema forward.
docker compose --env-file .env exec web php artisan migrate --force

# 4. Restart the workers and lift maintenance mode.
docker compose --env-file .env start queue scheduler
docker compose --env-file .env exec web php artisan up
```

Behind your own reverse proxy, you can instead stop routing to Wayfindr at the
proxy for the restore window; the point is that no HTTP request reaches a
database mid-swap.

Read the restore summary: **Attachments verified present** is how many local
binaries checked out, **dangling** are rows whose binary is gone, and any
**external object store** line lists rows served from a bucket you must keep
reachable. On a remote-storage install, seeing zero verified and a list of
external disks is correct — the bucket is doing its half.

Restore expects the app's database role to own its schema (the bundled Postgres
service does). It applies the whole restore in a single transaction, so a
failure rolls back and leaves the database untouched rather than half-restored.

## Deferred

These are named, valuable, and out of the v1 scope:

- **Remote backup destinations** — pushing the archive to S3/R2 with a
  retention policy. Until this lands, offsite copying is your responsibility.
- **Archive encryption at rest** — encrypt archives with your own tooling (age,
  gpg) today.
- **Scheduled backups out of the box** and **point-in-time / WAL recovery.**

See ADR [0009](../decisions/0009-backup-and-restore.md) for the full rationale.
