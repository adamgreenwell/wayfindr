# Attachment Storage (Local and S3-Compatible)

Conversation attachments ([ADR 0007](../decisions/0007-conversation-message-attachments.md))
are stored on a dedicated, **private** filesystem disk and only ever reached by
streaming through an authorized Wayfindr endpoint — there is no public path or
guessable URL, whichever surface holds the bytes.

Two surfaces are supported:

- **Local (default)** — `storage/app/private/attachments` on the app server.
  Zero configuration; right for most installs.
- **S3-compatible** — AWS S3, MinIO, Cloudflare R2, Backblaze B2, DigitalOcean
  Spaces, or GCS via its S3-interop endpoint. Right when the app server's disk
  shouldn't grow with attachments, or you want object-storage durability.

## Switching new uploads to S3

```dotenv
WAYFINDR_ATTACHMENT_STORAGE_DISK=attachments-s3

WAYFINDR_ATTACHMENT_S3_KEY=...
WAYFINDR_ATTACHMENT_S3_SECRET=...
WAYFINDR_ATTACHMENT_S3_REGION=us-east-1
WAYFINDR_ATTACHMENT_S3_BUCKET=my-wayfindr-attachments

# Non-AWS stores (MinIO, R2, B2, Spaces, GCS interop) also set:
# WAYFINDR_ATTACHMENT_S3_ENDPOINT=https://minio.internal:9000
# WAYFINDR_ATTACHMENT_S3_USE_PATH_STYLE=true

# Object ACL sent with each write. The default (bucket-owner-full-control) is
# the one canned ACL modern AWS buckets (Object Ownership: bucket owner
# enforced — the AWS default) accept, and it keeps same-account objects
# private on ACL-enabled buckets too. Override (e.g. to private) only if your
# S3-compatible store rejects it. Public ACLs are refused.
# WAYFINDR_ATTACHMENT_S3_ACL=bucket-owner-full-control

# Optional key prefix inside the bucket (default "attachments"):
# WAYFINDR_ATTACHMENT_S3_ROOT=attachments
```

Run `php artisan config:cache` after changing these, then check the
**Attachment storage** readiness check on `/operator` — it write/read/delete
probes the active disk and reports which surface new uploads land on.

## How the switch behaves

- **Only new uploads move.** Every attachment row records the disk it lives on
  (`storage_disk`), so existing local files keep serving exactly as before —
  local and S3 attachments coexist in the same conversations indefinitely.
  Switching back is equally safe. There is no forced migration.
- **The authorization boundary is identical.** Downloads stream through the
  app's authorized endpoints on both surfaces; a bucket URL is never handed to
  a client. Pre-signed URLs are deliberately not used.
- **The retention sweep covers both.** Abandoned uploads are removed by row
  (whatever their disk), and the orphaned-object pass reconciles the local disk
  plus the active disk.
- **Misconfiguration fails loud.** An unknown disk name rejects uploads and
  shows as *needs attention* on readiness. Only **dedicated** disks are
  accepted — the name must start with `attachments` (define your own
  `attachments-*` disk for custom setups). Shared disks such as `local`,
  `public`, or `s3` are refused outright: the retention sweep deletes any
  object on a swept disk that has no attachment row, which on a shared disk
  would remove unrelated application files. A custom disk must also be
  **private** — any exposure marker (`url`, `serve`, or
  `visibility: public`) is refused, because attachments are only ever served
  through authorized Wayfindr endpoints.

## Bucket posture

- **Keep the bucket private** — block public access at the bucket/account
  level. Wayfindr never serves from the bucket directly, so nothing about the
  feature requires any public access.
- Scope the credentials to that bucket only (get/put/delete/list).
- **Durability and backups remain operator-owned**, same as the local surface:
  Wayfindr's readiness verifies the disk is usable, not that it is backed up.

## Malware scanning

Scanning ([attachment-scanning.md](attachment-scanning.md)) happens **before**
the bytes are stored, so it applies identically to both surfaces.
