# Wayfindr

Wayfindr is an open source, self-hostable customer support platform for live chat, cobrowsing, and ticketing.

A visitor can reach support through a widget, through the help centre before
they ask, or by email. An agent works the queue, replies, cobrowses with
consent, and turns any of it into a durable ticket. An owner can see whether the
desk is actually working.

Published `v0.9.0` accepts Mailgun and Postmark deliveries directly at
`POST /api/mail/inbound` when the matching provider verification is configured,
and still accepts the original Wayfindr-signed proxy format for existing
integrations. Older `v0.7.0` predates that direct-provider support. See the
[inbound mail guide](docs/self-hosting/inbound-mail.md) for the exact contracts.

The capability list below describes the public `v0.9.0` release. The Status
section separates published evidence from the work still needed for 1.0.0.

- install a small widget on a site, themed to match it and speaking the
  visitor's language;
- identify anonymous or authenticated visitors, and ask who they are when the
  site needs to know;
- answer before the question — searchable help-centre articles inside the
  widget;
- chat with a support agent, by widget or by email;
- say when the desk is open, and take the question when it is not;
- request consent-based cobrowsing;
- create a durable ticket from the support session;
- route and automate support work with SLA policies, assignment rules, macros,
  bulk actions, shortcuts, alerts, quiet hours, and de-duplicated delivery;
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

Pre-1.0. The latest public release is
[`v0.9.0`](https://github.com/adamgreenwell/wayfindr/releases/tag/v0.9.0)
(September 24, 2026). Its protected tag resolves to `b9ae8bcc`, and the
published multi-architecture image resolves to
`sha256:5799f89e3561c0e8b8a0e2f2e9933cc1edf0292604ff36cc127608090093138a`.
Current `main` identifies its next development line as `1.0.0-dev`; there is
no public 1.0.0 release.

The public `v0.9.0` artifact passed a
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
Work toward 1.0.0 includes the [account area](https://github.com/adamgreenwell/wayfindr/issues/994)
and [#797](https://github.com/adamgreenwell/wayfindr/issues/797): a person who
is not the author must install a published artifact using the public
instructions, recording where they need to guess or get stuck. #994's 1.0.0
scope also calls for that person to test an upgrade. Publication and scripted
evidence do not satisfy the human install gate.

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

The list below describes the published `v0.9.0` capability set.

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
- an optional provider-configured agent copilot for summaries and editable
  suggestions, bounded by a provider-free evaluation harness and refusal policy;
- operator readiness diagnostics, database-backed operator settings, guided
  onboarding, backup/restore surfaces, safe operator activity, self-hosting
  docs, and Forge-first deployment guidance;
- scoped, audited break-glass grants for read-only platform-operator support;
- release manifests, upgrade guards, advisory notices, branch protection,
  Dependabot, pull-request CI, and a repo-authored GitHub Wiki;
- provider-neutral external issue links plus GitHub/GitLab/Jira issue creation,
  state reflection, and comment relay foundations.

The full disposable bare-metal recovery matrix remains specific to `v0.3.2`.
The newer `v0.9.0` public-artifact hosted-runner paths above prove clean install,
an upgrade from `v0.2.0`, support-loop health, and backup/restore within that
environment. See [disposable-vm-evidence.md](docs/self-hosting/disposable-vm-evidence.md)
for the evidence contract. A prior cold, no-context Claude sandbox run matched
the `v0.7.0` release, commit, and image digest and completed a synthetic support
loop after working around two defects. Those defects were fixed in `v0.9.0` by
[#929](https://github.com/adamgreenwell/wayfindr/pull/929) and
[#931](https://github.com/adamgreenwell/wayfindr/pull/931), but that run did not
exercise a real VM, public TLS/origin, local-CA trust, or an unwarmed image pull,
and an agent is not the human non-author required by #797. Product expansion
remains demand-gated around ticket workflow comfort, external integration field
mapping, and any future cobrowse replay work.
