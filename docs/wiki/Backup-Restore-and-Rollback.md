# Backup, Restore, and Rollback

[Back to Home](Home)

A backup is not trusted until its restore has succeeded somewhere disposable.
Wayfindr's archive contains the PostgreSQL dump, local attachment binaries, and
a manifest describing the release and any remote attachment disks it still
depends on.

## Take a Backup

```bash
docker compose -f wayfindr/compose.yml --env-file wayfindr/.env \
  exec web php artisan wayfindr:backup
```

Keep a copy away from the Wayfindr host. If attachments live in R2 or S3, the
archive restores their database rows but does not duplicate those remote
binaries; protect and retain that bucket too.

## Restore Safely

Restore on a disposable VM first. For a real recovery, quiesce HTTP writes and
background workers, enter maintenance mode, restore, reconcile version/schema
warnings, restart workers, and only then lift maintenance mode.

```bash
docker compose -f wayfindr/compose.yml --env-file wayfindr/.env \
  exec web php artisan wayfindr:restore /path/to/archive.tar.gz
```

Restoring over populated data requires `--force` and replaces the database.
Version skew or an unverifiable release identity deliberately leaves the site
in maintenance for operator review.

## Rollback

Before an upgrade, retain the previous image tag, stack files, environment, and
a current backup. Roll back code/image first when the schema remains compatible.
If the failed change altered data or schema incompatibly, follow the guarded
restore procedure; do not improvise against the production database.

Commands, remote-storage caveats, retention, GUI restore constraints, and the
maintenance procedure are authoritative in
[Backup and Restore](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/backup-restore.md).
