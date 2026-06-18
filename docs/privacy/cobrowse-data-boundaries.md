# Cobrowse Data Boundaries

Wayfindr cobrowse is shared page state, not a video feed of a visitor browser.
The browser widget should send only the state needed for an agent to understand
where the visitor is stuck, and sensitive values should be masked before
anything leaves the visitor browser.

## Consent Boundary

Cobrowse requires an active agent request and explicit visitor consent. A
visitor granting cobrowse consent means Wayfindr may send sanitized page state
for that session. It does not mean the visitor has agreed to disclose passwords,
payment details, tokens, or unrelated personal data.

The visitor consent prompt should be easy to reach by keyboard and assistive
technology. When a new cobrowse request appears in the open widget, Wayfindr can
move focus to the consent choice so the visitor can allow or decline without
hunting for controls. Status polling should not repeatedly steal focus while the
same request remains visible.

## Before Consent

Before cobrowse consent, the widget may:

- bootstrap the site and anonymous visitor,
- create or update a conversation,
- send visitor messages,
- fetch the current cobrowse request status.

It must not send page snapshots, mutation batches, or cobrowse telemetry until
consent is granted for an active cobrowse session.

## After Consent

After consent, the widget may send:

- page URL and title,
- viewport, scroll, focus, and visibility state,
- lightweight connection telemetry,
- a sanitized DOM snapshot,
- bounded mutation batches.

Those payloads should remain compact, bounded, and recoverable. They should not
be treated as a permanent recording of the visitor's browser.

## Payload Budget Boundary

Wayfindr treats cobrowse payload budgets as a product contract, not incidental
validation. Current server-side limits are:

- snapshot HTML: 65,535 characters,
- snapshot text: 10,000 characters,
- mutation batch: 50 mutation records,
- mutation text: 5,000 characters per record,
- mutation HTML: 10,000 characters per record,
- retained mutation batches: 20 recent batches,
- telemetry payload sample: 10,485,760 bytes.

The widget may send smaller payloads than these limits. The server remains the
source of truth because older widgets, custom integrations, and hostile clients
cannot be trusted to self-report their size honestly.

The stock JavaScript widget also trims mutation batches to a 60,000-byte
serialized payload budget before sending them. Operators can tune that browser
budget with the widget `mutationPayloadMaxBytes` option, but server-side
validation remains the final enforcement boundary.

The widget also caps the pending mutation queue at 250 records between flushes.
When a page changes faster than the widget can report, older queued records are
skipped and newer page state is preferred. That keeps noisy pages from building
an unbounded browser backlog while still reporting skipped-count diagnostics.
After reporting dropped or skipped mutation pressure, the stock widget sends a
fresh sanitized snapshot to give the agent preview a clean recovery point. That
snapshot includes the last reported mutation sequence so replay can ignore
already-covered batches and apply only newer page changes.

Agents can also request a fresh sanitized snapshot when the preview appears
stale or confusing. The visitor widget should answer a single pending request
once with fresh page state and a masked snapshot, then wait for another explicit
request ID before sending another agent-requested resync. This is a recovery
control for the current consented session, not permission to send raw values or
unbounded page history.

Under pressure, Wayfindr should prefer dropping or skipping lower-value mutation
details over sending raw sensitive values, unbounded snapshots, or oversized
session metadata.

## Masking Boundary

The widget masks explicit selectors and inferred sensitive elements before
cobrowse data leaves the page. Current built-in explicit selectors include:

- `input[type="password"]`,
- `input[type="hidden"]`,
- `[data-wayfindr-mask]`,
- `[data-wayfindr-private]`,
- `[data-secret]`.

The widget also infers common sensitive fields from attributes such as `id`,
`name`, `autocomplete`, `aria-label`, `placeholder`, `data-field`,
`data-wayfindr-field`, `data-testid`, `data-test`, and `data-cy`. Early terms
include password, token, API key, SSN, tax ID, credit card, CVV, routing number,
account number, username, email, phone, address, and birthdate language.

Host applications can use `data-wayfindr-mask` or `data-wayfindr-private` for
known sensitive areas. They can use `data-wayfindr-allow` only for deliberate
false positives where the surrounding content is safe to share.

Operators can also add site-level mask selectors in the Wayfindr dashboard.
Those selectors are public widget configuration, so they should contain only
CSS selectors and never private notes or secrets.

## AI Boundary

AI can eventually help suggest masking selectors or flag risky page structures,
but it must work from sanitized structure, labels, and selectors. Raw visitor
values should not be sent to an AI provider for masking decisions.

## Agent Experience

Agents should see whether cobrowse is unavailable, requested, granted, revoked,
or ended. They should also see when no snapshot or telemetry has arrived yet.
When telemetry or mutation diagnostics show reconnects, dropped batches, skipped
records, or stale reports, Wayfindr should tell agents how much to trust the
preview and when to confirm fast-changing details through chat. Fresh reports
with recent dropped or skipped page changes should be treated as degraded rather
than fully live. The visitor experience should stay simple: allow, decline,
stop, and clear copy about sensitive fields being masked.
