# Troubleshooting

[Back to Home](Home)

Start with evidence: the exact release, deployment path, failing command,
service status, and a redacted log excerpt. Never paste credentials, visitor
data, transcripts, raw cobrowse payloads, or private URLs into a public issue.

## Stack Will Not Start

- Run `docker compose ... ps` and inspect the unhealthy service's logs.
- Confirm Docker Compose v2, supported CPU architecture, disk space, and memory.
- Check that ports 80/443 are free, DNS resolves, and the generated `.env` was
  not partially edited.

## Web Works but Background Features Do Not

- Queue: inspect `php artisan queue:failed` and confirm both workers are alive.
- Scheduler: run `php artisan schedule:list` and confirm the minute runner exists.
- Realtime: verify Reverb, WebSocket proxy headers, and secure browser settings.
- Mail: run `php artisan wayfindr:mail-test` to a verified recipient.

## Upgrade Refuses to Continue

Read the refusal before changing anything. A release action may need to run on
the old code, require an acknowledgement, or require an intermediate version.
Keep the old release serving until the documented preflight succeeds. See
[Upgrading](Upgrading).

## Restore Leaves Maintenance Enabled

That is expected after version skew, an unverifiable release identity, or a
failed integrity check. Reconcile code, schema, and attachment findings before
running `php artisan up`. See
[Backup, Restore, and Rollback](Backup-Restore-and-Rollback).

Exact commands and known constraints live in the
[self-hosting documentation](https://github.com/adamgreenwell/wayfindr/tree/main/docs/self-hosting).
