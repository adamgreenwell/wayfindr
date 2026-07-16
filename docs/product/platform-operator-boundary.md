# Platform Operator Boundary

Wayfindr has three separate authority layers:

- `agent`: works support queues, replies to visitors, manages tickets, and requests cobrowse within site access.
- `account owner/admin`: manages a tenant's agents, roles, sites, site access, privacy settings, and support operations.
- `platform operator`: manages the Wayfindr installation itself.

The platform operator boundary is intentionally separate from account RBAC. It should help someone keep an instance healthy without silently granting them routine access to visitor conversations, ticket bodies, cobrowse state, transcripts, or account support queues.

## Why This Exists

Self-hosted installs often start with one person wearing every hat. A hosted Wayfindr service may later have staff who need to monitor infrastructure, recover failed jobs, inspect setup health, and support account lifecycle events without becoming support agents inside every customer account.

Those are different jobs. Wayfindr should model them that way before the product grows enough for the boundary to get blurry.

## Operating Modes

### Self-Hosted Community Edition

A self-hosted operator controls the server, database, backups, environment variables, logs, queues, Reverb process, mail transport, and deployment pipeline. The first Wayfindr user is created as both account owner and platform operator, but the product should still treat instance operation and account support authority as separate concepts.

Self-hosted operators remain responsible for local data handling, access policies, employment rules, privacy notices, and legal obligations. Wayfindr can provide guardrails and reminders, but it cannot decide the operator's compliance posture for them. See [Data Responsibility](../privacy/data-responsibility.md) for the broader self-hosting stance.

### Hosted Wayfindr

If Wayfindr Cloud exists, hosted platform staff may need instance-level tools for uptime, setup recovery, plan/account lifecycle, abuse handling, and support diagnostics. That authority should not imply access to customer support content.

Hosted support access to customer data should require explicit, scoped, audited, and time-bound approval. The default posture should be operational visibility without customer-content visibility.

## Initial Operator Responsibilities

Platform operator surfaces may eventually include:

- install readiness, environment diagnostics, and version/build visibility;
- queue, scheduler, Reverb, cache, mail, storage, and database health;
- aggregate cobrowse transport readiness without support codes, visitor
  identifiers, page URLs, snapshots, transcripts, or account/site names;
- setup/bootstrap recovery for misconfigured installs;
- account creation, suspension, or lifecycle controls for hosted operations;
- global retention-default visibility and warnings;
- maintenance notices and incident status;
- audit-log health and export tooling;
- security posture checks that do not expose visitor content.

Platform operator surfaces should avoid:

- routine conversation or ticket browsing;
- cobrowse replay or visitor page data access;
- agent impersonation as the normal support path;
- cross-account support queue visibility;
- using operator status as a shortcut around site access.

## Implementation Waypoints

1. Document the boundary before adding operator-only UI.
2. Keep `/dashboard` focused on account and agent work.
3. Add a separate `/operator` surface only when the first operator-only workflow exists.
4. Prefer a dedicated platform role or operator membership over overloading `account_role`.
5. Keep platform checks in policies, gates, or middleware instead of controller one-offs.
6. Audit every platform action that affects accounts, data retention, access, integrations, or instance availability.
7. Treat customer-data access as a separate break-glass workflow with explicit approval, audit events, scope, and expiry.
8. Never use platform authority to bypass site access inside ordinary account dashboards.

## First Scaffold

The first code scaffold should be small:

- no full platform-admin UI yet;
- no customer-data access;
- no account-wide support visibility shortcut;
- `users.platform_role` is nullable and grants explicit operator access only when set to `operator`;
- `/operator` starts with system identity, release/runtime details, documentation links, and instance readiness diagnostics;
- instance readiness includes a security posture check that flags `APP_DEBUG=true` in production (which would leak stack traces, environment values, and secrets on errors) without exposing any visitor content;
- `/operator` includes a platform action inventory that labels current read-only actions, audited manual readiness proof, and future break-glass work without exposing support data;
- browser and CLI bootstrap mark the first local user as both account owner and platform operator.

The break-glass workflow itself now exists (ADR 0008): `/operator/break-glass` is where an operator requests scoped, reasoned, time-bound, read-only access, and `/dashboard/account/operator-access` is where the account's owners and admins approve, deny, or revoke it. Every transition is audited as `break_glass.*` events. Grants — not platform authority — are what the upcoming read-only viewers will check.

Other account owners and admins remain account roles only. They do not become platform operators by implication.

## Sanity Checks

Every future platform-operator slice should prove:

- operator-only routes are inaccessible to normal account owners, admins, and agents;
- platform operators cannot see support data unless a separate audited access path grants it;
- account owners cannot affect another account through operator screens;
- account support policies still require site access;
- instance health tools can diagnose infrastructure without exposing visitor messages, tickets, cobrowse snapshots, or transcripts;
- hosted and self-hosted behavior is documented when they differ.
