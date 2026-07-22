# 0009: Backup and Restore

Date: 2026-07-22

## Decision

Wayfindr holds customer support conversations, tickets, and audit history — the
kind of data whose loss is unrecoverable and unforgivable. Until now the
self-hosting story has been honest but thin: "your data is in Docker volumes,
back them up yourself." For a platform of this kind that is the single largest
trust gap. Wayfindr adds **first-class backup and restore**: an artisan command
that produces a restorable archive, a companion restore command guarded against
catastrophe, and a restore drill that proves the round trip — because a backup
whose restore has never been run is a hope, not a backup.

This is deliberately scoped to a boundable, shippable v1. It is not a
disaster-recovery platform; it is the tooling a careful operator needs to take,
verify, and restore a backup without inventing the procedure themselves.

### What a backup captures

`php artisan wayfindr:backup` writes a single timestamped archive containing:

- **A PostgreSQL dump** (`pg_dump`) — the source of truth. Every account,
  conversation, message, ticket, audit event, user, break-glass grant, and
  attachment *metadata row* lives here.
- **Local attachment binaries** — the files under the private `attachments`
  disk in the storage volume, for installs using local storage.

It deliberately does **not** include:

- **Remote (R2/S3) attachment binaries.** When
  `WAYFINDR_ATTACHMENT_STORAGE_DISK` points at an object store, those binaries
  are already durable in the bucket, and copying them into every backup would
  double storage cost and duplicate what the object store keeps. The archive
  restores the metadata rows; the binaries are served from the bucket as
  before. **This split is documented loudly**: a restore is only as complete as
  the bucket it still points at, and per-row `storage_disk` means a single
  install can hold both local (in-archive) and remote (bucket-resident)
  attachments.
- **Redis** (cache and the queue) — not a source of truth; regenerates.
- **Caddy volumes** (certificates) — regenerate on the next request.
- **Framework caches, sessions, compiled views, logs** — ephemeral or
  operator-owned.

The archive is self-describing: it records the Wayfindr version that produced
it and the storage disk in effect, so a restore can warn on a version skew and
name what the metadata expects.

### What restore does, and what protects you from it

`php artisan wayfindr:restore <archive>` restores the dump and the local
binaries. Restore is the half of backup that is usually skipped and almost
never tested, and an accidental restore is itself a data-loss event — so it is
guarded hard:

- It **refuses to run against a populated database** unless given an explicit
  `--force`. The default posture assumes you are restoring into an empty or
  freshly provisioned install, not overwriting live data by muscle memory.
- It surfaces the archive's recorded version and warns on a mismatch with the
  running image before doing anything destructive.
- It is designed to run with the app quiesced (a documented maintenance
  posture), and it is **drilled end to end** as part of shipping — a real
  backup taken, the data wiped, the archive restored, and the data verified —
  the same standard we held the upgrade path to.

### Where backups go, and how often

v1 writes the archive to a **mounted host path** (a directory the operator
maps into the stack). Cadence and retention are the operator's: the command is
theirs to schedule (documented), and pruning old archives is theirs to own,
because the destination and its lifecycle are infrastructure decisions Wayfindr
cannot see. The command never deletes an archive it did not just write.

Readiness surfaces backup as it does today — a manual responsibility Wayfindr
cannot prove from inside a request — but the docs now point at a real command
instead of "good luck."

## Deferred (named, not hidden)

These are real and valuable, and explicitly out of v1 scope so v1 stays
shippable:

- **Remote backup destinations** — pushing the archive to S3/R2 (we already
  have the object-storage plumbing) with a retention/prune policy. This is the
  most valuable fast-follow: a backup that never leaves the box shares the
  failure mode of no backup at all, so the docs must be loud that offsite
  copying is the operator's job until this lands.
- **Archive encryption at rest.** Operators can encrypt the archive with their
  own tooling (age, gpg) today; baking a key-management story into v1 is scope
  creep.
- **Scheduled backups out of the box** and **point-in-time / WAL-based
  recovery** — deferred to real demand.

## Implementation notes (constraints found at decision time)

- **The image has no `pg_dump`.** `pdo_pgsql` is the PHP driver only; the
  runtime image (FrankenPHP, Debian-based) ships no PostgreSQL client. Slice 1
  must add `postgresql-client` — at the **matching major version (17)**,
  because `pg_dump` refuses a server newer than the client. That means the
  PGDG apt source or `postgresql-client-17`, and the image's Postgres version
  and the client version must move together in future upgrades. This adds a
  little image weight; it is the price of an in-container backup command and is
  cheaper than a host-script alternative that lives outside our test loop.
- The command runs in the app container, which already reaches the `postgres`
  service over the compose network with the app's `DB_*` credentials — so no
  new secret plumbing, but the dump must use those same credentials.

## Consequences

- Self-hosters get a supported, tested way to take and restore a backup — the
  biggest remaining trust gap for a platform holding customer conversations.
- The local-vs-remote attachment split is a sharp edge that must stay
  loudly documented: a Postgres-only-feeling backup on a remote-storage install
  is correct, but only because the bucket is doing its half.
- Restore is a command we can drill and keep honest, rather than prose that
  rots. The cost is guardrails that must never be weakened — an unguarded
  restore is a footgun aimed at the exact data this whole feature protects.
- v1 leaving the box is the operator's job. Naming that plainly is more honest
  than a remote-push feature we half-ship; closing it is the first fast-follow.

## Delivery slices

1. `wayfindr:backup` — pg_dump + local-binary archive to a mounted path, with
   the self-describing manifest; unit + feature coverage.
2. `wayfindr:restore` — guarded restore (populated-DB refusal, version-skew
   warning), with the drill.
3. Docs — a `docs/self-hosting/backup-restore.md` that leads with the two
   commands, states the R2 split and the offsite-copy responsibility, and gives
   the maintenance-posture restore procedure. Update the install-doc backup
   line to point at it.
4. (Fast-follow, separate) remote destination + retention.

Related: [[attachments-epic]] (the local/remote storage split this must respect)
and the self-hosting release work that makes a real operator the audience.
