# Wayfindr Wiki

Wayfindr is an open-source, self-hostable support platform for live chat, email,
a help centre, consent-based cobrowsing, and durable ticketing — with reporting
over the conversations and tickets it handles. The project reached `v1.0.0` on
September 30, 2026.

The latest public release is `v1.2.0`, at `56374e95`, published October 9,
2026 EDT (October 10 UTC). Its public Release, manifest, both platform image
chains and stable aliases were independently verified. It adds release review and
terminal-upgrade hardening, with optional managed host execution still under VM
qualification. No operator action is required; one additive audit-event
migration runs automatically. ARM64 never-started baked identity verification
passed. Actual ARM64 official installation/enrollment, idle helper restart and
autonomous idle guest reboot passed over private HTTP with runtime/nonce/API
checks. Ordinary archive/restore on a separate clean, unenrolled VM also passed
without force, with real post-archive erasure replay and survivor/file/decryption/
sequence checks. These ARM64/private HTTP results do not qualify managed
updates; U8 and U9 remain open. Managed upgrades need both a real newer
compatible published target and the [Docker 29 image-identity fix
#1131](https://github.com/adamgreenwell/wayfindr/issues/1131).
An already-enrolled 1.2.0 host also needs a supported helper upgrade path or a
new published source enrolled with the fixed helper; an application image
update alone does not replace host helper code. Development `main` now
identifies as `1.3.0-dev`, after the
separate version change at `bcb5474a`.
The previous public artifact is `v1.1.1` (October 2, 2026); its dated
evidence is preserved on [Releases](Releases).

It is a working support desk, but operators should still treat every
installation as an actively managed system rather than a set-and-forget
appliance.

Inbound email depends on which version you are running. Public `v0.9.0` and
later let Mailgun and Postmark post straight to `POST /api/mail/inbound` once
`WAYFINDR_INBOUND_MAIL_PROVIDER` names the matching verification scheme. The
original Wayfindr-signed proxy contract still verifies, so an install that
built one can keep it. On `v0.7.0`, direct provider webhooks are not supported:
that release verifies only Wayfindr's `X-Wayfindr-Signature` scheme, and a
provider's normal webhook returns `401` until a proxy re-signs it.
`docs/self-hosting/inbound-mail.md` covers all three.

## Start Here

- Evaluating Wayfindr or standing up a disposable VM? Start with
  [Quick Start](Quick-Start) and
  [Disposable VM Evidence](Disposable-VM-Evidence).
- Choosing a deployment shape? Read [Installation](Installation) and
  [Runtime and Operations](Runtime-and-Operations).
- Already running it? Read [Upgrading](Upgrading) before pulling a new release.
- Protecting real support data? Read
  [Backup, Restore, and Rollback](Backup-Restore-and-Rollback) and
  [Security and Privacy](Security-and-Privacy) first.
- Wondering what is shipped versus parked? Read
  [Project Status](Project-Status).

## All Pages

- [Quick Start](Quick-Start)
- [Project Status](Project-Status)
- [Installation](Installation)
- [Upgrading](Upgrading)
- [Runtime and Operations](Runtime-and-Operations)
- [Disposable VM Evidence](Disposable-VM-Evidence)
- [Backup, Restore, and Rollback](Backup-Restore-and-Rollback)
- [Security and Privacy](Security-and-Privacy)
- [Operator Runbook](Operator-Runbook)
- [Troubleshooting](Troubleshooting)
- [Releases](Releases)
- [Contributing](Contributing)

## How This Wiki Works

The Wiki is a curated front door, not a second source of technical truth. The
authoritative contracts live in the repository's
[documentation](https://github.com/adamgreenwell/wayfindr/tree/main/docs).
Wiki source is reviewed in
[`docs/wiki`](https://github.com/adamgreenwell/wayfindr/tree/main/docs/wiki)
and published through the documented sync workflow after it reaches `main`.
