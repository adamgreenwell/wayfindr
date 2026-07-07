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

## Capture Scope

The snapshot captures the page body, including the content of **open** shadow
roots, which are inlined so web-component content is visible to the agent.
Sensitive-field masking and form-value clearing run over inlined shadow content
the same way they run over light DOM, so nothing in an open shadow tree bypasses
masking.

Some page content is intentionally out of scope and will simply be absent from
the preview rather than partially captured:

- **Closed shadow roots** are inaccessible to the widget by browser design.
- **Cross-origin iframes** are inaccessible by browser design; same-origin
  iframe capture is not implemented yet.
- **`<template>` content** is an inert, unrendered fragment that the masking
  helpers cannot traverse, so it is dropped rather than captured (it can never be
  partially serialized and leak).
- `canvas`, `iframe`, `script`, and `style` elements are dropped before send.

Inline **SVG** survives capture only through a hard allowlist, applied both in
the widget and again by the server sanitizer: elements outside the allowlist
(`script`, `foreignObject`, `image`, `animate`, filters, …) are dropped with
their entire subtree, event handlers and external `href`/`xlink:href` are
stripped, and `url(#…)` internal paint references are the only `url()` form
permitted. Oversized SVGs (likely data visualizations rather than logos) are
dropped whole rather than partially. Text inside SVG is the same class of data
as page text.

**Images are never transmitted.** Every `<img>` is replaced — in the widget and
again by the server — with a same-size placeholder that preserves layout space
and carries only the alt text (masked under the same rules as other content).
No image URL reaches the agent, who must never fetch visitor resources, and no
pixel data ever leaves the visitor page. Rendering real imagery would require a
future explicit, site-configured opt-in; it is not part of the default posture.

Content-empty decorative elements (skeleton blocks, panels, dots, spacers)
additionally carry their rendered pixel box (width and height) so they keep
their footprint in the replay instead of collapsing. Masked elements and form
controls are excluded from this, as from all style capture: an element's box
can encode value-derived signals (`ch`-unit widths, validity styling), and no
styling of masked content is ever serialized.

The **page-level background** is captured as a style summary, not a resource:
the widget reads the body's (or the root element's) background color,
gradient-only background image, and tile size under the same rules as element
style capture — `url()`-based backgrounds are never captured or fetched — and
the server re-sanitizes the reported value down to the background family alone
before rendering it on the preview shell, so it can never restyle or hide the
preview. Like snapshot HTML, the reported page background is pruned by the
retention command once an ended session ages out.

Live mutation streaming observes the main document tree. Changes made *inside*
an existing shadow root are not streamed as individual mutations; they are
picked up on the next snapshot (including pressure- or agent-requested resyncs).
These gaps are visibility limitations, not masking gaps: absent content cannot
leak.

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
The stock widget flushes mutation batches every 50 ms, checks cobrowse status
every 5,000 ms, and waits at least 30,000 ms between pressure-triggered
snapshot resync attempts. Agent-requested snapshot resyncs retry up to 3 times
for the same request ID before reporting exhaustion.

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
If the widget cannot deliver that recovery snapshot, it may retry the same
request ID a small bounded number of times. After the retry bound is exhausted,
it reports that exhaustion once for the matching request ID and waits for a new
explicit request ID instead of creating an unbounded recovery loop.

Agents may see whether that recovery request is pending, delayed, fulfilled,
exhausted, or ignored because it arrived late, matched an older request, or
duplicated an already accepted response. Those status cues help agents retry or
confirm details through chat, but they do not change consent, masking, payload
limits, or retention boundaries.
Fresh duplicate requests may be coalesced briefly so repeated clicks do not
force the visitor widget to answer overlapping recovery requests.

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

The built-in sensitive-term inference is English-term based. Operators can add
site-level sensitive terms in the Wayfindr dashboard to extend inference for
their own language or domain (for example `contraseña` or `NHS number`) without
modifying the widget source. Like mask selectors, these terms are public widget
configuration and must contain only plain words, never secrets. Terms are
normalized before matching, so non-Latin scripts that normalize away (for
example terms written only in CJK characters) may not match through inference;
explicit `data-wayfindr-mask` / `data-wayfindr-private` markers and mask
selectors remain the reliable cross-language control, and the widget still
clears all non-allowed form-control values regardless of language.

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
than fully live.

Wayfindr also watches for replay drift on the agent side. Mutations are
addressed by structural paths, so when the visitor's live page diverges from the
snapshot the agent is viewing, those paths stop resolving. Wayfindr counts
mutations that resolve to no node separately from unsupported or malformed
records, and when a sustained share of addressable mutations drift it recommends
the agent request a fresh snapshot rather than trusting an increasingly stale
reconstruction. Drift detection is metadata-only: it counts outcomes and never
records raw snapshot HTML, page text, or mutation payloads. The visitor experience should stay simple: allow, decline,
stop, and clear copy about sensitive fields being masked.

When the agent preview updates live, the realtime path keeps the same boundary.
The cobrowse broadcast carries only metadata (page URL, title, counts, sequence,
snapshot freshness, transport pressure, telemetry) and never raw HTML or mutation
payloads. On each update the agent dashboard re-fetches the preview from a
server endpoint that runs the same sanitizer used to render the page, so the
sanitizer stays the single enforcement boundary on every refresh — the socket
never becomes a side channel for unsanitized page content.

Preview access itself is audited. When an agent actually sees a rendered replay
preview (on the conversation page or through the live refresh endpoint),
Wayfindr records a `cobrowse.preview_viewed` audit event — throttled per agent
and session so the refresh loop cannot flood the log — whose metadata carries
provenance only (support code, trigger, snapshot report time, applied/skipped
counts, drift state) and never preview content. "Who watched the visitor's
screen, and when" stays answerable without storing anything new about the
visitor.

Snapshots leave a matching provenance record. Each reported snapshot writes a
`cobrowse.snapshot_received` audit event carrying the capture time, node and
masked counts, a **content hash** of the snapshot HTML, and — crucially — the
masking ruleset the widget actually applied (its bootstrap-cached mask
selectors and sensitive terms), pinned by a hash, bounded for readability, and
flagged when it diverges from the site's current settings. Masking is
therefore provable per keyframe even after a site's rules change later, and
the event never contains snapshot content. Paired with the preview-view log,
"what ruleset governed what the agent saw at time T" is reconstructible from
the audit trail alone.

Cobrowse content is deliberately **not** retained as history. Wayfindr keeps
only the latest snapshot per session, and the scheduled
`wayfindr:prune-cobrowse-content` command strips raw snapshot HTML, page text,
and retained mutation batches from ended sessions after a configurable window
(`WAYFINDR_COBROWSE_CONTENT_RETENTION_HOURS`, default 72), keeping the
content-free provenance — counts, timestamps, page URLs, hashes, and audit
events — on its own longer terms. The content hash makes ephemeral keyframes
*verifiable* without being *stored*: a later claimed copy of what an agent saw
can be confirmed or refuted against the trail. Storing keyframe content
history was considered and rejected for the default posture — cobrowse is
shared page state, not a recording — and would only return as an explicit
site-level opt-in.

## Operator Readiness Boundary

Platform operator readiness may summarize aggregate cobrowse transport health
so an instance operator can spot broken Reverb, queue, deploy, or widget
configuration before agents depend on cobrowse. That surface should stay
aggregate-only.

Operator readiness may show:

- counts of active cobrowse sessions grouped by transport state,
- whether active sessions are live, degraded, reconnecting, stale, or waiting
  for their first report,
- generic recovery guidance for operators and agents.

Operator readiness must not show support codes, visitor identifiers, account or
site names, page URLs, sanitized snapshots, transcripts, mutation payloads, or
conversation subjects. Those details belong in scoped account support surfaces
where normal account, role, and site-access policies apply.
