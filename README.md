# Wayfindr

Wayfindr is an open source, self-hostable customer support platform for live chat, cobrowsing, and ticketing.

A visitor can reach support through a widget, through the help centre before
they ask, or by email. An agent works the queue, replies, cobrowses with
consent, and turns any of it into a durable ticket. An owner can see whether the
desk is actually working.

Wayfindr accepts Mailgun and Postmark deliveries directly at
`POST /api/mail/inbound` when the matching provider verification is configured,
and still accepts the original Wayfindr-signed proxy format for existing
integrations. Older `v0.7.0` predates that direct-provider support. See the
[inbound mail guide](docs/self-hosting/inbound-mail.md) for the exact contracts.

The capability list below describes public `v1.2.0` at `56374e95`.
The Status section separates implementation, verified public artifacts and
ARM64 source/ordinary-recovery evidence from open managed-update qualification.

- install a small widget on a site, themed to match it and speaking the
  visitor's language;
- identify anonymous or authenticated visitors, and ask who they are when the
  site needs to know;
- answer before the question — searchable help-centre articles inside the
  widget;
- chat with a support agent, by widget or by email;
- say when the desk is open, and take the question when it is not — with the
  reply emailed to a visitor who left an address while the desk was away;
- request consent-based cobrowsing;
- create a durable ticket from the support session;
- route and automate support work with SLA policies, assignment rules, macros,
  bulk actions, shortcuts, alerts, quiet hours, and de-duplicated delivery;
- administer the account from one sidebar: the team and its site access, roles,
  security, API tokens and outbound webhooks, and issue-tracker integrations;
- manage contacts and typed visitor attributes, and send conservative,
  operator-enabled proactive messages;
- optionally give agents summaries and editable drafts through the assistive
  copilot boundary — Wayfindr does not autonomously answer visitors;
- measure the conversations and tickets — volume, response and resolution times,
  reopens, workload, and whether the visitor said it helped. Help-centre usage
  and cobrowse sessions are not reported on.

Wayfindr is a Laravel-first monorepo. Laravel owns the core product, and the
browser widget installs on any site with a single script tag, so a host page
needs no framework support to run it. Framework-specific SDKs and integrations
for WordPress, Laravel, Next.js, and React are intended but not yet built: the
directories for them under `packages/`, `plugins/`, and `examples/` are
placeholders today.

## Deployment Posture

Wayfindr treats Laravel Forge as a first-class deployment path because the
platform is built on Laravel and Forge maps cleanly to Laravel apps, queues,
schedulers, TLS, and deploy hooks.

Forge is recommended, not required. Wayfindr should remain launchable anywhere
that can run the required Laravel, Postgres, Redis, queue, scheduler, and
realtime services.

Start with [self-hosting/install.md](docs/self-hosting/install.md). The
fastest path is the one-line installer, which sets up the official Docker
Compose stack (FrankenPHP with automatic HTTPS, queue, scheduler, Reverb,
Postgres, Redis):

```bash
curl -fsSL https://raw.githubusercontent.com/adamgreenwell/wayfindr/main/scripts/self-host/install.sh \
  | bash -s -- --app-url https://support.example.com
```

Replace `support.example.com` with the hostname your operators will visit. The
installer refuses the example rather than installing something that cannot
serve: a reserved name gets no certificate, so a pasted-as-is run would report
success over a stack that answers nothing.

Use the [Forge deployment guide](docs/self-hosting/laravel-forge.md) for
Laravel-native hosting, or the
[generic runtime requirements](docs/self-hosting/runtime-requirements.md) when
mapping Wayfindr to another VPS, Docker, Coolify-style, or Laravel-capable
host.

Official Linux VM installations can review the optional
[managed updater enrollment guide](docs/self-hosting/managed-updater.md).
The helper supports preparation, application updates and explicit root recovery
on eligible enrolled hosts. Managed updates remain under disposable-VM
qualification and are not qualified for production use.

The [GitHub Wiki](https://github.com/adamgreenwell/wayfindr/wiki) provides a
guided operator map. Detailed contracts remain in this repository, and the
reviewed Wiki sources live under `docs/wiki/`.

## Repository Layout

```text
apps/
  server/              Laravel core application
packages/
  widget-js/           Browser widget SDK
  react-widget/        React integration package (placeholder, not yet built)
  laravel-sdk/         Laravel host-app integration package (placeholder)
plugins/
  wordpress/           WordPress integration plugin (placeholder)
examples/
  plain-html/          Minimal script-tag example
  nextjs/              Next.js example app (placeholder)
  laravel/             Laravel host-app example (placeholder)
docs/
  architecture/        Technical architecture notes
  decisions/           Public product and engineering decisions
  development/         Contributor setup, workflows, and the engineering handoff/roadmap
  governance/          Public project governance
  privacy/             Data responsibility, inventory, and cobrowse boundaries
  product/             Product principles, editions, roadmap
  self-hosting/        Installation and operations docs
  wiki/                Reviewable source for the operator-facing GitHub Wiki
docker/                Local and self-hosting templates
```

## Licensing

Wayfindr uses a deliberately mixed license structure:

- Core product/server code is licensed under `AGPL-3.0-or-later`.
- Embeddable SDKs and host-app integrations use permissive licenses, with MIT as the initial package default.
- WordPress plugin code is intended to use a GPL-compatible license.
- Wayfindr names, logos, and marks are not covered by the code license.

Everything in this repository is Community Edition, and there is no hosted
service. [Editions](docs/product/editions.md) records that, and the constraint
that would apply if a commercial tier ever arrived: it may not take away
functionality Community Edition has already published.

See [0001-license-and-repo-structure.md](docs/decisions/0001-license-and-repo-structure.md) for the current licensing rationale.

See [0003-laravel-forge-as-first-class-deployment-path.md](docs/decisions/0003-laravel-forge-as-first-class-deployment-path.md) for the current Forge deployment posture.

See [0004-ai-as-assistive-product-and-development-layer.md](docs/decisions/0004-ai-as-assistive-product-and-development-layer.md) for the current AI posture.

## Public Documentation Boundary

This is a public open source repository. Product, architecture, security, license, and contribution decisions should be documented here when they affect users or contributors.

Business strategy, pricing strategy, customer/prospect information, private infrastructure, revenue planning, and commercially sensitive notes must stay outside this repository.

See [public-information-policy.md](docs/governance/public-information-policy.md).

## Privacy and Data Responsibility

Wayfindr should help operators collect less, protect what they keep, and make
data retention choices deliberately. Self-hosters control their own
installation, infrastructure, agents, logs, backups, and privacy notices, so
they are responsible for operating Wayfindr in line with the laws and policies
that apply to them.

Start with [data-responsibility.md](docs/privacy/data-responsibility.md), the
[data inventory](docs/privacy/data-inventory.md), and the
[cobrowse data boundaries](docs/privacy/cobrowse-data-boundaries.md).

## Status

The latest public release is
[`v1.2.0`](https://github.com/adamgreenwell/wayfindr/releases/tag/v1.2.0),
at `56374e9574ed84616aae430d06589cfe2f0b33a0`.
[Exact-main CI](https://github.com/adamgreenwell/wayfindr/actions/runs/38006329309)
and the [guarded publisher](https://github.com/adamgreenwell/wayfindr/actions/runs/38007100329)
passed. Independent readback verified the public Release, 1,111-byte manifest,
both amd64/arm64 image chains and stable `1.2`/`latest` aliases. The OCI index is
`sha256:052a2897b503ebfdec4cfba232d2893cd48f3cdadeb3478c4161627d38e01683`.
Publication was October 10 at 00:26 UTC, October 9 in the owner's EDT timezone,
matching the October 9 release notes. See the
[artifact evidence](docs/self-hosting/evidence/managed-update/README.md).

1.2.0 adds read-only release review in Operator → Updates and
hardens terminal upgrades. Optional, explicitly enrolled Linux/systemd hosts
also have independent update execution, durable progress and explicit host
recovery. Managed updates remain under disposable-VM qualification and are not
qualified for production use. **No operator action required:** one additive
audit-event deduplication migration runs automatically. Existing unenrolled
installations retain their update path; enrollment is optional.

ARM64 never-started-image verification passed for the baked version, commit,
manifest and canonical history. Official source installation, optional
enrollment, idle helper restart and autonomous idle guest reboot passed on ARM64,
including PostgreSQL/Redis, private WebSocket delivery and the authenticated
support loop. Ordinary archive/restore on a separate clean, unenrolled VM also
passed without force, using the retained effective key and real post-archive
erasure ledger. Exact replay, surviving data/files/decryption and the new-contact
sequence were verified. These observations cover ARM64 over private HTTP; they do
not establish TLS or user-session broadcasting authorization. The merged
[Docker 29 image-identity/helper fix #1131](https://github.com/adamgreenwell/wayfindr/issues/1131)
passed a separate ARM64/containerd [clean helper upgrade and atomic-exchange
recovery rehearsal](docs/self-hosting/evidence/managed-update/2026-10-10-helper-lifecycle-rehearsal.json).
The helper remains unpublished; managed apply still needs a real newer compatible
published target and the full native qualification matrix. An application image
update alone does not replace host helper code. The exchange check proves
same-kernel process-crash recovery before the parent-directory fsync, without
establishing power-loss or reboot durability. Managed scenarios remain **0**;
[U8/#1115](https://github.com/adamgreenwell/wayfindr/issues/1115) and
[U9/#1116](https://github.com/adamgreenwell/wayfindr/issues/1116) remain open.
See the [qualification boundary](docs/self-hosting/managed-update-qualification.md).

The previous verified public release is
[`v1.1.1`](https://github.com/adamgreenwell/wayfindr/releases/tag/v1.1.1)
(October 2, 2026). Its protected tag resolves to `648caa1b`, and the
published multi-architecture image resolves to
`sha256:c816a46187549ea35ab04e7cae5fcbb96c3d80b1c06842951228a772ddc956a9`.
Its [release workflow](https://github.com/adamgreenwell/wayfindr/actions/runs/36966810612)
verified the tagged commit, manifest, image, GitHub Release and stable aliases.
It needs no operator action and has no migrations. It fixes dashboard text,
forms, pagination and work context; Reports filters, comments and chart
scrolling; authentication feedback and keyboard selection; phone widget
controls and attachment-removal focus; and release-check diagnostics.

The previous `v1.1.0` release added erasing a contact on request and exporting
everything held about one, as
[ADR 0026](docs/decisions/0026-erasing-and-exporting-a-visitor.md) records.
Its five migrations run themselves. Each erasure is also recorded in
`storage/app/erasure-ledger/` on the storage volume, which backups do not carry,
so a restore erases again anyone the archive brings back. Keep that directory
alongside your backups.

From 1.0.0 the version number carries the operator-action signal, as
[ADR 0012](docs/decisions/0012-platform-versioning.md) defines it:
- a new **major** version is the only kind that can ask you to do anything
  beyond pulling and restarting;
- a **minor** version adds features, and any schema it adds migrates itself;
- a **patch** only fixes.

See the [release notes](CHANGELOG.md). If you are several releases behind, read
each release in between. `v0.11.0` carries a session-secret security fix, and
`v0.10.0` sends an email reply backlog left over from `v0.9.0`.
Development `main` now identifies the next line as `1.3.0-dev`, following the
separate `VERSION` change at `bcb5474a`. The empty required-action declaration
and standing advisory notices remain intact; this does not select the next
release's scope.

The public `v1.1.1` artifact passed a
[fresh Ubuntu hosted-runner install](https://github.com/adamgreenwell/wayfindr/actions/runs/36969131572)
and an [upgrade from public `v0.2.0` with a custom backup queue](https://github.com/adamgreenwell/wayfindr/actions/runs/36969207425).
Both matched the published image digest, completed the support loop, passed
backup/restore and stack restart, and read `v1.1.1` from authenticated
`/operator` after install or upgrade and after restore. The upgrade's backups
notice retired itself once the custom-queue worker was observed.

The public `v1.1.0` artifact passed a
[fresh Ubuntu hosted-runner install](https://github.com/adamgreenwell/wayfindr/actions/runs/36881545824)
and an [upgrade from public `v0.2.0` with a custom backup queue](https://github.com/adamgreenwell/wayfindr/actions/runs/36881550403).
Both completed the support loop, passed backup/restore and restart checks, and
read `v1.1.0` from the authenticated `/operator` console, including after
restore. The upgrade resolved the published image digest.

The earlier public `v1.0.0`, `v0.11.0` and `v0.10.0` artifacts passed the same
two paths: `v1.0.0`'s
[fresh install](https://github.com/adamgreenwell/wayfindr/actions/runs/36723360923)
and [upgrade from `v0.2.0`](https://github.com/adamgreenwell/wayfindr/actions/runs/36723365996),
`v0.11.0`'s
[fresh install](https://github.com/adamgreenwell/wayfindr/actions/runs/36613505205)
and [upgrade from `v0.2.0`](https://github.com/adamgreenwell/wayfindr/actions/runs/36613509014),
and `v0.10.0`'s
[fresh install](https://github.com/adamgreenwell/wayfindr/actions/runs/36463449869)
and [upgrade from `v0.2.0`](https://github.com/adamgreenwell/wayfindr/actions/runs/36463452977).

The earlier public `v0.9.0` artifact passed a
[fresh Ubuntu hosted-runner install](https://github.com/adamgreenwell/wayfindr/actions/runs/36008767661)
and an [upgrade from public `v0.2.0` with a custom backup queue](https://github.com/adamgreenwell/wayfindr/actions/runs/36008785768).
Both runs matched the published image digest, completed the support loop, and
passed backup/restore checks. A separate
[fresh-install check](https://github.com/adamgreenwell/wayfindr/actions/runs/36013000938)
also opened the authenticated `/operator` console and read its rendered
`v0.9.0` identity. These are hosted-runner paths, not a bare-metal or human
non-author acceptance result. Earlier disposable bare-metal evidence remains
specific to `v0.3.2`.

The feature gaps tracked in Tier 1
([#741](https://github.com/adamgreenwell/wayfindr/issues/741)) and Tier 2
([#751](https://github.com/adamgreenwell/wayfindr/issues/751)) shipped in
`v0.9.0`. Host-managed PHP upgrades need the runtime check and acknowledgement
documented in the [release notes](CHANGELOG.md); the published image already
contains those requirements. The guarded-release preconditions in
[#970](https://github.com/adamgreenwell/wayfindr/issues/970) are complete.
The [account area](https://github.com/adamgreenwell/wayfindr/issues/994) that
1.0.0 was scoped to finished its restructure in `v0.11.0`, after the sidebar in
`v0.10.0`. The independent-install gate,
[#797](https://github.com/adamgreenwell/wayfindr/issues/797), closed on
September 29, 2026: a person who is not the author installed Wayfindr on a clean
Ubuntu VM using only the public documentation and got it running. The owner
decided that install is enough for 1.0.0: #994's scope also named an upgrade by
a non-author, which is not required, and no person has observed one (the
scripted runs above upgrade from `v0.2.0`). `v1.0.0` was published on
September 30, 2026.

The deferred autonomous-answer capability is tracked separately in
[#762](https://github.com/adamgreenwell/wayfindr/issues/762), outside the 1.0.0
milestone. Four same-contract sixteen-case provider captures across GPT-5.2,
Claude Sonnet 5, and Gemini 3.8 Flash produced one 16/16 pass plus 15/16,
15/16, and 13/16 failures. The owner's [September 10 adjudication](https://github.com/adamgreenwell/wayfindr/issues/762#issuecomment-5625334565)
attributed two Gemini misses to matcher brittleness and one to a real omission.
The [final disposition](https://github.com/adamgreenwell/wayfindr/issues/762#issuecomment-5625831620)
was no fixture or matcher change; the recorded 13/16 failure stands. #762 now
needs every proposed route recaptured under the current suite identity: #997
subsequently changed the fixture policy and bound the scorer contract, including
evaluator and Unicode phrase matching, into that identity. The prompt identity
did not change. The September captures remain historical and cannot form the
current baseline. Only then can same-route evidence after meaningful elapsed
time or a model revision support revisiting ADR 0004. The cross-model samples
also changed upstream route, and all four were recorded within about 78 minutes.
That is point-in-time variability—not long-term drift resistance,
model-revision evidence, provider or runtime approval, or authority to change
ADR 0004.

The list below describes the published 1.2.0 capability set; managed-update
qualification remains pending as stated above.

- browser and CLI first-run setup;
- authenticated account owners, admins, agents, and platform operators;
- site-scoped widget install targets and agent access;
- visitor identity, live chat, Reverb updates, and manual refresh fallbacks;
- consent-based cobrowse state, snapshots, mutation diagnostics, telemetry, and
  an inert agent-side replay preview;
- private conversation-message attachments with visitor and agent upload UI,
  retention sweep, malware-scanner hook, and S3-compatible storage routing;
- durable tickets with assignment, status changes, categories, priorities,
  labels, notes, replies, queue filters, and support reference panels;
- email as a second conversation channel, outbound, and inbound through direct
  Mailgun/Postmark verification or the compatible Wayfindr-signed proxy format;
- a help centre: articles authored in the dashboard, searchable from the widget;
- per-site support hours in the site's own timezone, an away state, offline
  capture, and a configurable pre-chat form;
- reporting over conversations and tickets — volume, first-response and
  resolution times, reopen rates, agent workload, and visitor satisfaction
  ratings. Resolution and reopen figures are read from lifecycle logs and the
  page states the date each half began; volume, first responses and agent
  replies come from data the product always kept;
- per-site widget appearance, and a widget language catalogue with German
  complete;
- an agent-selectable dashboard language in English, German, and Italian across
  the operator console and most dashboard workflows; the ordinary pages still
  intentionally outside the extracted route boundary are the agent home,
  and support-code lookup; readiness and its guided checks are translated;
- a visitor directory, and agent-initiated password recovery;
- a public API with a decided isolation model, scoped reads, and a narrow write surface;
- visitor profiles, support-code lookup, and safe cross-record context;
- alert preferences, dashboard alerts, queued email notifications, welcome
  emails, Web Push, quiet hours, cross-channel de-duplication, and mail smoke
  testing;
- SLA policies, automatic assignment and routing, automation rules, macros,
  bulk queue actions, and keyboard-first command surfaces;
- visitor presence, typed contact attributes, private contact notes, explicit
  same-site identity merge, and operator-enabled proactive messages;
- erasure of a contact on request, re-applied after a restore, and a one-ZIP
  export of everything held about a contact, both behind the Handle data
  requests permission;
- an optional provider-configured agent copilot for summaries and editable
  suggestions, bounded by a provider-free evaluation harness and refusal policy;
- operator readiness diagnostics, database-backed operator settings, guided
  onboarding, backup/restore surfaces, safe operator activity, self-hosting
  docs, and Forge-first deployment guidance;
- scoped, audited break-glass grants for read-only platform-operator support;
- release manifests, upgrade guards, advisory notices, branch protection,
  Dependabot, pull-request CI, and a repo-authored GitHub Wiki;
- read-only release review and hardened terminal upgrades; optional enrolled
  host execution and reconnecting progress remain under VM qualification;
- provider-neutral external issue links plus GitHub/GitLab/Jira issue creation,
  state reflection, and comment relay foundations.

The full disposable bare-metal recovery matrix remains specific to `v0.3.2`.
The newer `v1.1.1`, `v1.1.0`, `v1.0.0`, `v0.11.0`, `v0.10.0` and `v0.9.0`
public-artifact hosted-runner paths above prove clean install, an upgrade from `v0.2.0`,
support-loop health, and backup/restore within that environment. See [disposable-vm-evidence.md](docs/self-hosting/disposable-vm-evidence.md)
for the evidence contract. A prior cold, no-context Claude sandbox run matched
the `v0.7.0` release, commit, and image digest and completed a synthetic support
loop after working around two defects. Those defects were fixed in `v0.9.0` by
[#929](https://github.com/adamgreenwell/wayfindr/pull/929) and
[#931](https://github.com/adamgreenwell/wayfindr/pull/931), but that run did not
exercise a real VM, public TLS/origin, local-CA trust, or an unwarmed image pull,
and an agent is not the human non-author #797 required. Product expansion
remains demand-gated around ticket workflow comfort, external integration field
mapping, and any future cobrowse replay work.
