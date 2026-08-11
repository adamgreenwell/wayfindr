# Testing

Wayfindr's Laravel server uses Pest 4 for PHP tests.

Run the server suite from `apps/server`:

```bash
composer test
```

The suite currently has three layers:

- `tests/Feature` covers Laravel HTTP, console, database, and product workflow behavior.
- `tests/Unit` covers isolated runtime or domain behavior that does not need the Laravel app.
- `tests/Architecture` keeps lightweight structural rules around application code.

Prefer Pest-style tests for new PHP coverage. Use Laravel feature tests for
public APIs, dashboard flows, commands, authorization, and persistence behavior.
Use architecture tests for durable project rules that should stay true across
many future slices.

## Public-Info Boundary Check

Run the repository boundary guard from the root before opening a pull request:

```bash
make public-info-check
```

The guard scans tracked files for sensitive markers so non-public material stays
out of the public repository. To turn on the committed pre-commit hook template
for this checkout:

```bash
git config core.hooksPath .githooks
```

The guard has its own fixture test:

```bash
make public-info-test
```

## Self-Hosting Tests

The installer is shipped code — operators pipe it into `bash` — so it has its own
suite. Run all of it from the root:

```bash
make self-host-test
```

Docker is required, because two of these compare against Docker Compose's own
behaviour rather than against an assumption about it.

| Script | What it holds down |
| --- | --- |
| `test-php-version-contract.sh` | Composer, the self-hosting image, generated environment, and operator docs all declare PHP 8.4. |
| `test-self-host-env-generator.sh` | The generated environment file: secret shapes, URL-derived values, refusal to overwrite. |
| `test-self-host-compose-template.sh` | The compose stack renders and wires services as intended. |
| `test-self-host-env-value.sh` | `install.sh`'s dotenv reading agrees with Compose's, across every spelling an operator might write. |
| `test-self-host-classification.sh` | The installer preflight and the artifact guard classify actions identically. |

### Why the last two are differential tests

[ADR 0013](../decisions/0013-upgrade-preflight-and-release-requirements.md)
records that the installer preflight is a *second implementation* of the upgrade
guard's decision, in another language, running a version behind — and that a
divergence is silent by construction: the preflight reports "clear", the operator
pulls, and the artifact refuses on a release that is now already installed.
`install.sh` also parses the environment file itself, which diverged from what
actually consumes it five separate times.

Neither duplicate can simply be deleted:

- The preflight runs **inside the image being upgraded from**, so it cannot call a
  helper that the release being installed introduced.
- `php_in_current_image()` needs `INSTALLED_IMAGE`, which comes from
  `env_value WAYFINDR_IMAGE` — you cannot shell out to the artifact to learn
  *which* artifact to shell out to.

So the rule is **pin, don't merge**: each script lifts the real function out of
`install.sh` and runs it against the same fixtures as the authority it has to
agree with. `install.sh` gains nothing for this — no probe, no hook, no test-only
branch — which keeps the file operators download as lean as it was.

Both scripts assert the *expected* value as well as the agreement, so that two
implementations drifting the same direction together is still caught.

If you change `UpgradeGuard`, `UpgradeRequirements`, `ActionDisposition`, or the
preflight block in `install.sh`, run `make self-host-test` before opening the PR.

### If extraction breaks

Each script lifts code from `install.sh` by matching markers rather than line
numbers, and fails loudly when a marker stops matching. That failure means the
installer was restructured, not that the guard is wrong — re-point the marker in
the test script rather than working around it.

Browser-level Pest tests are intentionally not enabled yet. When Wayfindr needs
full browser coverage for the dashboard or embedded widget, add Pest's browser
plugin as its own slice so the Playwright dependencies and CI expectations are
clear. The smoke scripts may still use an ephemeral Playwright install for
runtime checks; that is separate from adding browser tests to the automated
suite.

## Wiki Documentation

Run the Wiki navigation and authority-link check from the repository root:

```bash
make wiki-test
```

It requires the curated page set, prevents pages from becoming orphaned from
Home or the sidebar, validates internal Wiki links, and confirms that linked
repository files still exist. The authoring and review-gated publication flow
is documented in [wiki.md](wiki.md).

## Smoke Scripts

Use smoke scripts when you need runtime proof against a local, staging, or
self-hosted Wayfindr instance.

The public widget intake smoke proves the visitor API can bootstrap a visitor,
create a conversation, and accept a visitor message:

```bash
WAYFINDR_BASE_URL="https://support.example.com" \
WAYFINDR_SITE_PUBLIC_KEY="site_public_key_here" \
scripts/smoke/widget-intake.sh
```

The full support-loop smoke signs in as an agent, opens the conversation,
creates a ticket, and verifies the ticket detail page. When
`WAYFINDR_HOST_PAGE_URL` is set, the visitor side uses Chromium to load the
real host page widget and send the first message before the agent/ticket checks
continue:

```bash
WAYFINDR_BASE_URL="https://support.example.com" \
WAYFINDR_HOST_PAGE_URL="https://docs.example.com/help" \
WAYFINDR_SITE_PUBLIC_KEY="site_public_key_here" \
WAYFINDR_AGENT_EMAIL="agent@example.com" \
WAYFINDR_AGENT_PASSWORD="agent-password" \
scripts/smoke/support-loop.sh
```

If Chromium is missing for Playwright, install it once with:

```bash
npx --yes --package playwright playwright install chromium
```

Set `WAYFINDR_VISITOR_SMOKE_MODE=api` to skip the browser visitor path and use
direct API calls instead. API mode is useful for local fallback checks, but it
does not prove the host page loaded the widget.

Both scripts create real test records in the target Wayfindr install. Use a
staging site key or disposable local data, and keep credentials outside the
repository.
