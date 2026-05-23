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
  development/         Contributor setup and workflows
  governance/          Public project governance
  product/             Product principles, editions, roadmap
  self-hosting/        Installation and operations docs
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

## Status

Pre-alpha. The current prototype supports a local first support loop: a plain
JavaScript widget can create a visitor conversation, and an authenticated agent
can read and reply to the conversation in the dashboard. The public widget API
can also retrieve the visitor-visible message timeline, and the embedded widget
renders that timeline with live Reverb updates when configured and a manual
refresh fallback when realtime is unavailable. The Laravel app serves the
classic widget script from `/widget.js`. Cobrowsing is beginning with explicit
visitor consent and lightweight connection telemetry; page-state streaming,
ticket workflows, and production hardening are still ahead.
