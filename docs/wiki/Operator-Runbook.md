# Operator Runbook

[Back to Home](Home)

This is a short operating rhythm, not a substitute for host monitoring or the
authoritative self-hosting docs.

## After Every Deploy or Upgrade

1. Confirm `/up`, `/operator`, and `/dashboard/readiness` load.
2. Verify the reported release and commit.
3. Check migrations, queues, the backup worker, scheduler, and Reverb.
4. Send a visitor message, reply as an agent, and confirm live or fallback delivery.
5. Review failed jobs and recent application/container logs.

## Daily or Alert-Driven

- Investigate readiness warnings and failed queue jobs.
- Watch disk, database, Redis, and object-store health.
- Confirm mail and realtime provider errors are not accumulating.
- Review security-sensitive operator and break-glass activity.

## Weekly

- Confirm scheduled backups completed and an offsite copy exists.
- Review storage growth, log retention, and attachment scan health.
- Check for dependency, image, host, and Wayfindr release updates.

## Regular Recovery Drill

Provision a clean disposable VM, install the previous release when testing an
upgrade, restore a real-shaped synthetic backup, upgrade, reboot, and repeat the
support-loop smoke. Use `scripts/smoke/public-artifact-reverify.sh` after
reboot when the VM was installed with a persistent evidence target directory.
Record commands, versions, timestamps, and any repair made.

Use the full
[runtime contract](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/runtime-requirements.md)
and [backup/restore guide](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/backup-restore.md)
when a check needs exact commands or recovery semantics.
