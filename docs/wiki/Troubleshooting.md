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
- If first boot fails while creating `wayfindr-storage` directories, refresh to
  the current `compose.yml`; the shared Laravel storage volume uses Docker
  `nocopy` plus a one-shot `storage-init` helper so parallel app-service
  creation does not race while Docker prepares the named volume and the non-root
  app user still owns the storage tree.

## Browser Will Not Load a Local or IP Address

Two different failures look similar in a browser and mean opposite things, so
read the error code before changing anything.

- **`ERR_CERT_AUTHORITY_INVALID`, or a warning you can click through.** The
  handshake succeeded and a certificate was served; your browser does not
  recognise the authority that signed it. This is expected on an install at
  `localhost`, an IP address, or a reserved name — nothing is wrong. Trust the
  exported root, as described in [Quick Start](Quick-Start), or click through
  to proceed for a throwaway evaluation.
- **`ERR_SSL_PROTOCOL_ERROR`, with no warning to click through.** The handshake
  itself failed, so no certificate was ever offered. Trusting a root will not
  help. Confirm the install is on 0.4.1 or later: earlier releases could not
  serve an IP-address install, because a client connecting to an IP sends no
  server name and the certificate could not be selected. `./wayfindr/install.sh
  --upgrade` repairs an environment file generated before that fix.

The logs are misleading for the second case: the certificate is obtained
successfully and simply never served, so `docker compose ... logs web` shows a
healthy startup either way. Check `curl -k https://<your-url>/up` from the host
instead — it answers `200` when the handshake works, whatever your browser
thinks of the certificate.

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
