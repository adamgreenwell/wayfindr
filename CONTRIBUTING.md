# Contributing

Thanks for your interest in Wayfindr. Wayfindr is an open source, self-hostable
support platform for live chat, consent-based cobrowsing, and ticketing. It is
still pre-alpha, so the contribution model is intentionally lightweight and will
grow as the product stabilizes.

This guide explains how to get the project running, what kinds of changes are
welcome right now, and the conventions a contribution should follow before it is
likely to be merged.

## Before You Start

A few things are worth knowing up front:

- Wayfindr is a **Laravel-first monorepo**. Laravel owns the core product; SDKs,
  plugins, and examples live beside it so server and client changes can be
  developed and tested together.
- The project is **public-first**. Everything committed here should be safe for
  a public repository. See [Public-First Product Work](#public-first-product-work).
- The project is **privacy-first**. Wayfindr handles visitor conversations,
  visitor context, and cobrowse page state, so changes are reviewed with data
  minimization, consent, and masking in mind.
- The repository uses a **mixed license structure**. Check the nearest `LICENSE`
  file before reusing code. See [License Boundaries](#license-boundaries).

If you are planning anything beyond a small fix, please open an issue first to
check direction. The roadmap in [docs/product/roadmap.md](docs/product/roadmap.md)
describes what is in scope now versus later.

## Public-First Product Work

Contributions must be suitable for a public open source repository. Do not
include:

- customer or prospect names,
- private business strategy,
- pricing experiments or revenue planning,
- credentials, secrets, or API keys,
- private infrastructure details,
- real visitor, customer, or employee data in examples, fixtures, or tests,
- commercially sensitive planning notes.

Use synthetic or clearly fake data in examples and tests. See
[docs/governance/public-information-policy.md](docs/governance/public-information-policy.md)
for the full policy.

Issues and pull requests should describe public product, code, documentation, or
self-hosting concerns. Keep private go-to-market context and customer-specific
details out of the repository.

## License Boundaries

Wayfindr uses a deliberately mixed license structure:

- Core product/server code (`apps/server`): `AGPL-3.0-or-later`.
- Embeddable SDKs and host-app integrations (`packages/`): permissive, with MIT
  as the initial default.
- WordPress plugin (`plugins/wordpress`): GPL-compatible.
- Docker and self-hosting templates: permissive (MIT).
- Wayfindr names, logos, and marks: not covered by the code license. See
  [TRADEMARKS.md](TRADEMARKS.md).

Rules of thumb:

- The repository root license applies to the core product by default.
- A subdirectory with a different license must include its own `LICENSE` file,
  and package metadata must match the nearest license.
- Do not copy AGPL core code into a permissively licensed package, or vice
  versa, without confirming the license implications.

By submitting a contribution, you affirm that you wrote it (or have the right to
submit it) and that it can be distributed under the license of the directory it
lands in. Before accepting substantial external contributions, the project may
adopt a Contributor License Agreement (CLA) or Developer Certificate of Origin
(DCO) process. Until then, maintainers will keep accepted external changes
focused on clear, well-scoped improvements that do not create unclear ownership.

See [docs/decisions/0001-license-and-repo-structure.md](docs/decisions/0001-license-and-repo-structure.md)
for the licensing rationale.

## Repository Layout

```text
apps/server/        Laravel core application (AGPL)
packages/           Embeddable SDKs and host-app integrations (MIT)
plugins/wordpress/  WordPress integration plugin (GPL-compatible)
examples/           Minimal host-app examples (plain HTML, Next.js, Laravel)
docs/               Architecture, decisions, privacy, product, self-hosting
docker/             Local and self-hosting templates
deploy/             Deployment scripts and templates
```

Most current work happens in `apps/server`. Run Composer, Artisan, queue,
scheduler, and Reverb commands from that directory.

## Development Setup

### Requirements

- PHP 8.4 or newer
- Composer 2
- Docker Compose v2
- Node.js and npm (only when working on Vite-built frontend assets)

### First Run

The root `Makefile` wraps the common commands:

```bash
make services-up      # start Postgres and Redis
make server-install   # install dependencies and create .env
make server-migrate   # run database migrations
make server-test      # run the Pest suite
make server-serve     # serve on http://localhost:8000
```

To create a first account, agent, and install site, run the bootstrap command
from `apps/server` (see [docs/development/local-setup.md](docs/development/local-setup.md)
for the full first-run walkthrough, including the example credentials and the
widget public key):

```bash
php artisan wayfindr:bootstrap \
  --account="Demo Support Co" \
  --name="Demo Agent" \
  --email="agent@example.com" \
  --password="password" \
  --site="Demo Site" \
  --domain="demo.test" \
  --site-public-key="site_demo_public_key"
```

The server runs at <http://localhost:8000> with a health check at `/up`. The
browser setup flow is available at `/setup`, and operator readiness diagnostics
at `/operator`.

### Realtime (Reverb)

The default `.env.example` keeps `BROADCAST_CONNECTION=log` so the app works
without an extra long-running process. To smoke test WebSocket broadcasts
locally, switch the connection to `reverb` and run `php artisan reverb:start`
from `apps/server`. See
[docs/development/local-setup.md](docs/development/local-setup.md) for the full
Reverb configuration.

## Testing

The Laravel server uses **Pest 4**. Run the suite from `apps/server`:

```bash
composer test
```

The suite has three layers, and contributions should keep all three green:

- **Feature tests** for end-to-end HTTP and workflow behavior.
- **Unit tests** for focused logic.
- **Architecture tests** that enforce structural conventions.

See [docs/development/testing.md](docs/development/testing.md) for the current
testing posture.

Before opening a pull request, also run the repository boundary guard from the
root:

```bash
make public-info-check
```

You can opt in to the committed pre-commit hook template with
`git config core.hooksPath .githooks`.

### Tests That Security- and Privacy-Sensitive Changes Should Include

Wayfindr's authority and privacy boundaries are part of the product contract,
not incidental. If your change touches access control or cobrowse, please add
the relevant guards.

Any change to roles, site access, or support data visibility should include
tests for the cases listed in
[docs/product/rbac-waypoints.md](docs/product/rbac-waypoints.md), including:

- cross-account denial,
- wrong-site denial,
- role denial and role-escalation denial,
- deactivated-agent and stale-assignment denial,
- last-owner protection,
- platform/operator boundary denial (operators must not gain support-data access
  by implication),
- realtime channel authorization,
- audit event creation for permission changes.

Any change to cobrowse should respect the boundaries in
[docs/privacy/cobrowse-data-boundaries.md](docs/privacy/cobrowse-data-boundaries.md):
honor consent, keep masking client-side, keep payloads bounded, and keep
server-side validation as the final enforcement boundary.

## Coding Conventions

- Implement authorization through Laravel policies and gates, not ad hoc
  controller conditionals. Policy methods should compose account membership,
  role permission, and (for support data) site access.
- Keep integration packages thin. They should write through Laravel APIs rather
  than owning product persistence directly.
- Prefer simple, durable records over speculative abstractions while the product
  shape is still changing.
- Audit any action that changes who can see or affect visitor data.
- Keep AI features optional and safe to leave unconfigured; missing provider
  keys must not break core chat, cobrowse, ticketing, or admin flows. See
  [docs/product/ai-principles.md](docs/product/ai-principles.md).

## Commits and Pull Requests

- Keep pull requests focused; one logical change per PR is easier to review.
- Write a clear description of what changed and why, and link any related issue.
- Note any user-facing, security, privacy, or migration impact explicitly.
- Make sure `composer test` passes before requesting review.
- Update the relevant docs when behavior changes. Documentation is a
  first-class part of this project, not an afterthought.

## Reporting Bugs and Requesting Features

- Use GitHub issues for public product, code, documentation, and self-hosting
  topics.
- For anything that could be a **security or privacy vulnerability**, do not open
  a public issue. Follow [SECURITY.md](SECURITY.md) instead.

## Code of Conduct

Participation in this project is governed by
[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). In short: be clear and constructive,
critique ideas rather than people, keep discussion focused, and never publish
private user, customer, or business information.

## Questions

If you are unsure whether a change fits Wayfindr's direction or its public-first
and privacy-first posture, open an issue and ask before investing significant
effort. Early alignment saves everyone time while the project is still young.
