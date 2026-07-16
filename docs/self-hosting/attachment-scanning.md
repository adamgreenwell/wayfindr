# Attachment Malware Scanning

Wayfindr lets visitors and agents attach files to a conversation
([ADR 0007](../decisions/0007-conversation-message-attachments.md)). Every
upload is already protected by defense-in-depth — a **byte-sniffed type
allowlist** (images, PDF, and plain text/log only; no SVG, HTML, archives, or
executables), **private storage**, a forced **`Content-Disposition: attachment`**
plus **`nosniff`** on download, and server-enforced size/count limits.

On top of that, you can have every upload **virus-scanned** before it is stored.

## The default: accept with defense-in-depth

Out of the box **no scanner is configured**, and that is a safe, supported
default — the allowlist and storage protections above still stand. The operator
readiness screens surface this so you know uploads are not being scanned:

> **Attachment scanning — Ready.** No malware scanner configured (accepting with
> defense-in-depth).

Configure a scanner if your policy requires one.

## Recommended: ClamAV (local daemon)

The reference driver is **ClamAV**, and specifically its **`clamd` daemon**. It
runs locally and Wayfindr streams the file bytes to it over a socket — so
**attachments never leave the trust boundary**. (Cloud scanning services would
mean shipping visitor files to a third party, which contradicts Wayfindr's
conservative-export posture, so they are not a built-in option.)

### 1. Run clamd

Run `clamd` next to Wayfindr — as a system service (`apt install clamav-daemon`
on Debian/Ubuntu) or a container (e.g. the `clamav/clamav` image). Keep its
signature database current with `freshclam` (the ClamAV packages run it for
you). `clamd` listens on a TCP port (default `3310`) or a unix socket.

> **Memory matters.** clamd loads its full signature database into RAM —
> expect **~1 GB+ resident** just for the daemon. On a 1 GB host it will be
> pushed into swap, scans will crawl, and the OOM killer may take it down.
> Budget **2 GB+ of headroom** for the box (alongside PHP, the database, Redis,
> and Reverb), or run clamd on a neighboring host over TCP. If the box is too
> small, the honest choice is the no-scanner default, not a swapping scanner.

> **systemd socket-activation gotchas (Debian/Ubuntu).** The package ships a
> `clamav-daemon.socket` unit that accepts connections *even while the daemon
> itself is down* (dead, still loading signatures, or waiting on its first
> freshclam download). Wayfindr handles this — an unanswered scan times out and
> fails closed — but two operational notes follow: `systemctl stop
> clamav-daemon` alone does **not** stop scanning intake (the socket unit
> re-triggers the daemon); stop both with `systemctl stop clamav-daemon.socket
> clamav-daemon`. And right after install, the daemon will not start until
> freshclam finishes its first signature download (a few minutes).

### 2. Point Wayfindr at it

In `apps/server/.env`:

```dotenv
WAYFINDR_ATTACHMENT_SCANNER=clamav
# tcp://host:port, or unix:///var/run/clamav/clamd.ctl
WAYFINDR_CLAMAV_SOCKET=tcp://127.0.0.1:3310

# Optional:
WAYFINDR_ATTACHMENT_SCANNER_TIMEOUT=30      # per-scan timeout, seconds
WAYFINDR_ATTACHMENT_SCANNER_FAIL_CLOSED=true
```

Run `php artisan config:cache` after changing these on a cached-config install.

## How it behaves

Scanning is **synchronous**: an upload is scanned before its bytes are stored,
so the visitor or agent gets immediate feedback and an infected file never
reaches the disk.

- **Clean** → the upload is accepted as normal.
- **Infected** → the upload is **rejected** ("This file was rejected by a
  security scan."), nothing is stored, and an `attachment.quarantined` audit
  event records the detected signature.
- **Scanner unreachable** → controlled by `WAYFINDR_ATTACHMENT_SCANNER_FAIL_CLOSED`:
  - **`true` (default, fail-closed)** — the upload is **rejected** rather than
    stored unscanned ("This file could not be scanned for malware and was not
    accepted."). An **error is logged** and an `attachment.scan_unavailable`
    audit event is recorded.
  - **`false` (fail-open)** — the upload is **accepted** unscanned, with a
    **warning logged**. Use this only if availability matters more than
    guaranteed scanning.

## Readiness

The **Attachment scanning** check on `/operator` and `/dashboard/readiness`
reflects the live state:

- **Ready** — no scanner configured (defense-in-depth), _or_ the configured
  scanner is reachable.
- **Needs attention** — a scanner is configured but `clamd` is **unreachable**.
  With fail-closed (the default) that means uploads are being rejected until it
  recovers, so this is worth fixing promptly.
