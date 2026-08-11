# Security Policy

Wayfindr handles support conversations, visitor context, and cobrowsing
sessions. Security and privacy issues are treated as product-critical. Thank you
for helping keep Wayfindr and the people who use it safe.

Wayfindr is pre-alpha. This policy describes how to report a vulnerability today
and how the project intends to respond. It will be tightened before the first
public release.

## Reporting a Vulnerability

**Please do not open a public GitHub issue, pull request, or discussion for
security or privacy vulnerabilities.** Public reports can put self-hosters at
risk before a fix is available.

Report privately through
[GitHub private vulnerability reporting](https://github.com/adamgreenwell/wayfindr/security/advisories/new).
This opens a private advisory that only the reporter and repository
maintainers can access while the report is investigated.

Wayfindr does not currently publish a separate security email address. If you
cannot use GitHub private vulnerability reporting, open a public issue that only
asks for a security contact. Do not include exploit details, affected URLs,
credentials, logs, or reproduction steps in a public issue.

### What to Include

A good report usually contains:

- a description of the issue and its security or privacy impact,
- the affected component (for example: server, widget, a specific package, or
  self-hosting templates) and version, commit, or environment,
- clear, minimal reproduction steps or a proof of concept,
- any relevant configuration, and
- a suggested fix or mitigation if you have one.

Please use synthetic data in reproductions. Do not include real visitor,
customer, or employee data.

## Scope

In scope:

- the Laravel core application in `apps/server`,
- the browser widget and other SDKs/integration packages in `packages/`,
- the WordPress plugin in `plugins/wordpress`,
- the official Docker and self-hosting templates in this repository.

Particularly valuable areas, given Wayfindr's design:

- authentication, account/role authority, and site-access enforcement,
- the platform-operator boundary (operators must not gain support-data access by
  implication),
- cobrowse consent, masking, and payload-budget enforcement,
- signed visitor tokens and widget intake,
- handling of secrets, credentials, and audit integrity.

Generally out of scope:

- vulnerabilities in third-party dependencies (report those upstream, though we
  welcome a heads-up),
- issues that require a misconfigured or already-compromised host,
- missing security hardening that is the self-hoster's responsibility (see
  [docs/privacy/data-responsibility.md](docs/privacy/data-responsibility.md)),
- social engineering, physical attacks, or denial-of-service via raw traffic
  volume.

If you are unsure whether something is in scope, report it privately and ask.

## Our Commitment and Response

While the project is small, expect best-effort handling rather than fixed SLAs.
We aim to:

- acknowledge a report within **3 business days**,
- provide an initial assessment within **10 business days**,
- keep you updated as we work on a fix, and
- credit you in the advisory once the issue is resolved, unless you prefer to
  remain anonymous.

## Coordinated Disclosure

Please give the project a reasonable opportunity to investigate and release a
fix before any public disclosure. We will work with you on timing and aim to
publish a security advisory describing the issue, affected versions, and the
fix or mitigation. Because Wayfindr is self-hosted, public advisories also serve
to tell operators when they need to upgrade.

## Safe Harbor

We will not pursue or support legal action against researchers who:

- make a good-faith effort to follow this policy,
- avoid privacy violations, data destruction, and service degradation,
- only interact with systems and accounts they own or are explicitly authorized
  to test, and
- give the project reasonable time to remediate before public disclosure.

If in doubt, ask before testing.

## Security Principles

These product-level principles guide how Wayfindr is built and what we consider
a defect:

- Mask sensitive cobrowsing content before it leaves the visitor browser, and
  treat server-side validation as the final enforcement boundary.
- Require explicit consent for cobrowsing, and separate consent for any remote
  control.
- Keep payloads bounded; prefer dropping or skipping low-value data over sending
  raw sensitive values or oversized snapshots.
- Treat widget site keys as public identifiers, not secrets.
- Use short-lived signed tokens for visitor intake and privileged
  viewer/session access.
- Enforce authority through Laravel policies and gates; never bypass site access
  through operator status.
- Keep secrets out of the repository, and out of logs.
- Avoid logging raw cobrowsing payloads or sensitive chat content unnecessarily.
- Audit actions that change who can see or affect visitor data.

For the broader privacy and data-handling stance, see
[docs/privacy/data-responsibility.md](docs/privacy/data-responsibility.md),
[docs/privacy/data-inventory.md](docs/privacy/data-inventory.md), and
[docs/privacy/cobrowse-data-boundaries.md](docs/privacy/cobrowse-data-boundaries.md).

## A Note for Self-Hosters

Wayfindr can provide safe defaults and guardrails, but self-hosters control
their own installation, infrastructure, agents, logs, backups, and privacy
notices. Keep your deployment patched, use HTTPS and secure WebSocket
configuration, protect your database and backups, and review
[docs/privacy/data-responsibility.md](docs/privacy/data-responsibility.md)
before running Wayfindr with real visitors.
