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

> **Memory matters.** [ClamAV recommends at least 3 GiB of RAM, preferably
> 4 GiB](https://docs.clamav.net/manual/Installing/Docker.html), for the scanner.
> Signature reloads can briefly hold two engines in memory; `freshclam` also
> needs memory to validate downloaded databases. Budget the application's PHP,
> database, Redis and Reverb memory separately. Measure both scans and signature
> reloads under load before choosing a host or container memory limit.

> **Protect the socket.** A Unix socket with restricted ownership and permissions
> is the simplest local connection. [Raw clamd TCP provides neither encryption
> nor authentication](https://docs.clamav.net/manual/Usage/ClamdProtocol.html).
> Keep it within a trusted, restricted network; use authenticated, encrypted
> transport between hosts. Never publish port `3310` to untrusted networks.
> Restrict access to daemon administration commands as well as file scanning.

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
WAYFINDR_ATTACHMENT_SCANNER_TIMEOUT=30      # whole scan budget, seconds
WAYFINDR_ATTACHMENT_SCANNER_FAIL_CLOSED=true
```

Run `php artisan config:cache` after changing these on a cached-config install.

## How it behaves

Scanning is **synchronous**: an upload is scanned before its bytes are stored,
so the visitor or agent gets immediate feedback and an infected file never
reaches permanent attachment storage. PHP's temporary upload file already
exists when scanning starts.

Socket connection, streaming and verdict operations share one monotonic timeout
budget. The connection also has a five-second cap, shortened when less scan time
remains. PHP cannot interrupt hostname resolution or a stalled local file read;
either can overrun that budget. Use a Unix socket or numeric IP endpoint and keep
temporary uploads on local storage when predictable request timing matters.
Only one complete NUL-framed verdict is accepted: `stream: OK`, or an infected
verdict with a bounded signature token. Truncated replies, extra or contradictory
records, oversized responses and unreadable files count as scanner unavailable.
A valid early infected verdict still rejects the upload; an early clean verdict
cannot approve a file whose transmission did not finish.

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

The **Attachment scanning** check on `/operator`
reflects the live state:

- **Ready** — no scanner configured (defense-in-depth), _or_ the configured
  scanner is reachable.
- **Needs attention** — a scanner is configured but `clamd` is **unreachable**.
  With fail-closed (the default) that means uploads are being rejected until it
  recovers, so this is worth fixing promptly.

Readiness uses a bounded `PING`/`PONG` exchange. It confirms protocol response,
not signature freshness, a successful file scan, or complete scan coverage.
Monitor `freshclam` updates and the daemon's loaded database version separately,
and exercise clean and harmless antivirus test files during deployment checks.

Scanning applies to **new uploads**. Enabling it does not retroactively scan
existing attachments or rescan files restored from backups. Decide how those
files will be reviewed before describing an installation as fully scanned.
