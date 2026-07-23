# 0010: Remote Backup Destinations and Retention

Date: 2026-07-23

## Context

ADR [0009](0009-backup-and-restore.md) shipped `wayfindr:backup` and
`wayfindr:restore`, but deliberately bounded v1 to a **local archive on the
box**. That left the loudest gap in the backup story: an archive that never
leaves the machine shares the failure mode of no backup at all, and the docs
had to say offsite copying was the operator's job. This ADR closes that gap —
the named first fast-follow — with a remote destination and a retention policy.

## Decision

### Remote destination — a dedicated backup disk

`wayfindr:backup` builds the local archive exactly as today, then, if
`WAYFINDR_BACKUP_DISK` names a configured filesystem disk, **uploads the
finished archive to it**. The disk is any Laravel/Flysystem disk, so this reuses
the object-storage plumbing attachments already use (ADR
[0007](0007-conversation-message-attachments.md)) and speaks S3, Cloudflare R2,
MinIO, or any other supported backend with no new transport code. Unset =
local-only, unchanged.

- The **local copy is retained** — remote is a mirror, not a move. Belt and
  suspenders: a restore can run from the local archive without a round-trip to
  the bucket, and the offsite copy is durability insurance.
- The upload is **verified** (the object exists and its size matches the local
  archive) before the backup is reported remotely durable.
- A configured-but-failed upload **fails the command** (non-zero exit) while
  leaving the local archive intact. An operator who configured an offsite
  destination must never be told "backup succeeded" when the offsite copy did
  not land — that is the exact false confidence backups exist to avoid. The
  message says the local archive is intact and the remote push failed.

Archives are stored under a **per-install prefix** (set with
`WAYFINDR_BACKUP_PREFIX`, or derived from `APP_KEY` by default), and retention
prunes only within that prefix — on **both** the remote disk and the local path
(archives land in `{backup path}/{prefix}/`). Two installs may therefore share
one backup bucket *or* one host backup directory without one install's retention
window erasing another's archives — the archive names are otherwise
indistinguishable, so an unscoped prune would be a cross-install data-loss
footgun. The stack ships a ready `backups` disk (an S3-compatible disk with its
own `WAYFINDR_BACKUP_S3_*` credentials) so offsite backup is turn-key.

The backup disk **must not be an attachment disk** (a disk named `attachments*`,
which `wayfindr:sweep-orphaned-attachments` reconciles): the sweep deletes any
object on those disks with no matching attachment row, so it would treat backup
archives as orphans and delete them. `wayfindr:backup` refuses such a disk with
an actionable error. Any other disk is fine — it need not follow a dedicated
naming convention, because the only thing that ever deletes on it is retention,
and retention only removes files it can positively identify as Wayfindr archives
(below). It may therefore be a shared bucket, though a dedicated one is cleaner.

### Retention — age-based, opt-in

`WAYFINDR_BACKUP_RETENTION_DAYS=N` (default unset/0 = keep everything, today's
behavior) prunes archives **older than N days**, after a fully successful
backup, on **both** the local backup path and the remote disk. Age-based fits
the natural "keep a month of history" intent.

The prune is guarded hard, because it deletes data:

- It only ever removes files matching the exact archive naming
  `wayfindr-backup-YYYYMMDD-HHMMSS-xxxxxx.tar.gz`, judged by the **timestamp in
  the name** (not mtime, which an upload or copy resets). Any other file on the
  path or bucket is untouched — safe even on a shared destination.
- It never removes the archive just written.
- It runs **only after the backup (and its upload, if configured) fully
  succeeds**. A failed run never prunes, so a bad backup can never take the last
  good history down with it.

Retention applies uniformly to local and remote in v1 — one window for both.
Split per-location windows are deferred (below).

## Deferred (named, not hidden)

- **Split retention** — different windows for local vs remote (e.g. keep 2
  local, 30 remote). One uniform window covers the common case; revisit on
  demand.
- **Count-based retention** (keep newest N) — age-based ships first; count-based
  is a small addition if wanted.
- **Archive encryption at rest** — still the operator's tooling (age, gpg); a
  remote push does not change the encryption story.
- **Scheduled backups out of the box** and **point-in-time / WAL recovery** —
  unchanged from 0009.

## Consequences

- Self-hosters get a real offsite backup with no bespoke scripting — point a
  disk at a bucket and set a retention window. The install docs stop saying
  "getting archives offsite is your job."
- Retention deletes data, so the strict archive-name match and the
  only-after-success gating are the safety rails that must never be loosened —
  the same standard 0009 held restore's guardrails to.
- A remote-storage attachment install now has a coherent story: the metadata
  dump and local binaries ride offsite in the archive, and the remote attachment
  binaries were already durable in their own bucket (0009's split).

## Delivery slices

1. Remote upload in `wayfindr:backup` (`WAYFINDR_BACKUP_DISK`), verified, with
   the fail-on-upload-failure semantics.
2. Age-based retention prune (`WAYFINDR_BACKUP_RETENTION_DAYS`) across local and
   remote, name-matched and after-success gated.
3. Docs — the remote destination and retention in
   `docs/self-hosting/backup-restore.md`, the env example, and dropping the
   "offsite is your job" caveat; live validation against R2.

Related: ADR [0009](0009-backup-and-restore.md) (the backup this extends) and
ADR [0007](0007-conversation-message-attachments.md) (the object-storage
plumbing this reuses, proven against R2 on stage).
