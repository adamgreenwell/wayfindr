# Wayfindr

Wayfindr is an open source, self-hostable customer support platform for live chat, cobrowsing, and ticketing.

The project is intentionally early. The first goal is to prove a focused support loop:

- install a small widget on a site,
- identify anonymous or authenticated visitors,
- chat with a support agent,
- request consent-based cobrowsing,
- create a durable ticket from the support session.

Wayfindr is a Laravel-first monorepo. Laravel owns the core product, while SDKs and integrations make it portable across WordPress, Laravel, Next.js, React, and plain JavaScript sites.

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
  react-widget/        React integration package
  laravel-sdk/         Laravel host-app integration package
plugins/
  wordpress/           WordPress integration plugin
examples/
  plain-html/          Minimal script-tag example
  nextjs/              Next.js example app
  laravel/             Laravel host-app example
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

Pre-alpha. The latest public release is `v0.3.1` (August 11, 2026), and the
next development line is `0.4.0`. Wayfindr now has a usable internal alpha spine
for the first support loop and a review-gated self-hosting path, but
release-readiness claims are still being proven on disposable VMs before the
project should be treated as a boring appliance.

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
- visitor profiles, support-code lookup, and safe cross-record context;
- alert preferences, dashboard alerts, queued email notifications, welcome
  emails, and mail smoke testing;
- operator readiness diagnostics, database-backed operator settings, guided
  onboarding, backup/restore surfaces, safe operator activity, self-hosting
  docs, and Forge-first deployment guidance;
- scoped, audited break-glass grants for read-only platform-operator support;
- release manifests, upgrade guards, advisory notices, branch protection,
  Dependabot, pull-request CI, and a repo-authored GitHub Wiki;
- provider-neutral external issue links plus GitHub/GitLab/Jira issue creation,
  state reflection, and comment relay foundations.

The current `0.4.0` reliability cycle is about proving the self-hosting story
with repeatable evidence: clean installs, supported upgrades, advisory behavior,
backup/restore, rollback, reboot recovery, and deployment-fork readiness. See
[disposable-vm-evidence.md](docs/self-hosting/disposable-vm-evidence.md) for the
evidence contract. Product expansion is intentionally demand-gated around ticket
workflow comfort, external integration field mapping, and any future cobrowse
replay work.
