# Wayfindr Wiki

Wayfindr is an open-source, self-hostable support platform for live chat, email,
a help centre, consent-based cobrowsing, and durable ticketing — with reporting
over the conversations and tickets it handles. The project reached `v1.0.0` on
September 30, 2026.

The latest public release is [`v1.4.0`](https://github.com/adamgreenwell/wayfindr/releases/tag/v1.4.0),
at `0f4312f8`, with release notes dated October 10, 2026. Exact-main CI and
the guarded publisher passed. Independent public verification and a separate
raw-evidence audit verified Release assets, source declarations, both amd64/arm64
OCI chains and stable aliases. Helper `0.6.0` fixes Docker process listings and
supports separate administrator replacement from exact published 0.4/0.5
bundles. Application image updates preserve the installed helper. No operator
action or migration is required for the application; standing backup-queue
guidance remains. See [Releases](Releases) for the public receipt and identities.
The genuine 1.2.0 → 1.4.0 pair is available for testing; managed updates remain
not qualified for production use, with zero managed scenarios qualified.
Development source is `1.5.0-dev`; this next-cycle reset publishes no release.

The earlier public release is `v1.2.0`. Its ARM64 published-source install,
enrollment, idle restart/reboot and separate ordinary restore passed over
private HTTP. Those observations and older dated evidence remain on
[Disposable VM Evidence](Disposable-VM-Evidence) and [Releases](Releases).

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
