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

The logs mislead for the second case: the certificate is obtained successfully
and simply never served, so `docker compose ... logs web` shows a healthy
startup either way. Two probes separate the possibilities.

**Is TLS working?** Run this from a machine that actually reaches the URL —
normally the one you browse from:

```bash
curl -k https://<your-url>/up
```

`200` means the handshake completed and only certificate trust is in question.
A connection or protocol error means the handshake failed. Run it from the
browsing machine rather than from the host: an install at a cloud IP that is
NAT'd rather than assigned to the machine, or a `.local` name that resolves
only on your laptop, will fail from the host while working perfectly from
elsewhere.

**Is the application running at all?** From the host, the always-on loopback
site answers regardless of the public TLS arrangement. Read its address out of
the environment file rather than assuming it — the installer moves it when your
own public port would otherwise collide with it, so it is not always
`127.0.0.1:8000`:

```bash
curl -fsS "http://$(grep '^WAYFINDR_LOCAL_BIND=' ~/wayfindr/.env | cut -d= -f2)/up"
```

A `200` here with a failure above isolates the problem to the public endpoint
rather than the application. Probing a guessed port instead can hit your own
public socket and report the application as down when it is running normally.

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
