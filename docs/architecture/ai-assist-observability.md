# AI Assist audits, feedback and retention

Issue [#1175](https://github.com/adamgreenwell/wayfindr/issues/1175) adds account-visible
operational evidence without collecting prompts, suggestions or staff edit histories.
The implementation includes private and shared lifecycle audits, explicit client
observations, bounded account reports and operator-controlled metadata retention.
Qualification and rollout are separate; merged code is not an effectiveness claim.

## Delivered first slice: private lifecycle

Admitted private work writes `conversation.ai_draft_refinement.*` events into the
existing `audit_events` store. Requested, started, ready, failed, stale, cancelled,
applied, superseded and expired are closed states. The requester, account, site and
conversation are server-derived. Content-free grant allocation/cancellation, ordinary
polls, invalid input and rejected budget admission do not pretend to be admitted work.

`applied` means the server authorized and consumed a private replacement. A client
can still discard that response after an edit or navigation. It does **not** establish
actual composer insertion, a sent reply, usefulness, or resolution. Started means a
worker claimed work; final transport/usage admission remains a separate boundary.

The explicit metadata allowlist contains version, purpose, private row ID/generation,
state/operation, connection/policy revisions, keyed work fingerprint, configured source
and closed provider code, account-scoped keyed model/route tags, server elapsed/queue
milliseconds, nullable reported token counts and a closed failure code. Freeform model,
upstream and endpoint values are represented by opaque keyed identities, never copied
from provider output. Historical tags support correlation, not recovery of a deleted
configuration's name. The keyed work fingerprint binds the source/recipe/private scope;
it is not a copied transcript or a public content digest.

Elapsed time includes queue/review time at the recorded transition, not just model
latency. Millisecond fields derive from second-resolution lifecycle timestamps;
they do not establish subsecond precision. Unknown, negative or out-of-bound timing/token values remain null. Zero means
an observed zero. Available guarded-response counts survive parsing/safety rejection
and stale publication; missing or unguarded response claims remain unknown. Reports
must group usage by generation rather than sum lifecycle events as separate calls.
No prompts, raw or scrubbed original, preview, article passages,
credentials, raw exceptions or free-text feedback enter these events.

Writes share the existing installation/account/private-row lock order and transaction
with the state transition. The writer fresh-loads the exact generation and its live
parent/actor; it cannot resurrect a footprint after erasure or attach an old worker's
event to a replacement. Repeated transitions are idempotent per generation/state under
the account lock. Cancel of an already terminal record is a no-op. Expiry cleanup
processes at most 500 slots per run and audits only still-active captured work before
deleting it. Unreadable expired scope ciphertext remains removable after key loss;
its route tags are unknown. The fixed 15-minute usability limit does not depend on
physical cleanup keeping pace. Existing send claims retain their 180-second uncertainty
window; audit events never release those claims or authorize transmission.

## Visibility, exports and retention

Account audit requires `view_audit`, the current account, and the same visible-site
filter as other audit activity. Private lifecycle labels are translated in English,
German and Italian. The audit screen and its CSV omit raw metadata. Adding content-free
metadata does not grant access to a draft or an out-of-scope conversation.

Existing visitor export includes applicable conversation audit metadata; private
refinement input/scope/preview and send claims stay excluded. Visitor erasure scrubs
conversation audit metadata under the account lock and deletes private slots through
parent cascades. Queued/late work cannot recreate either footprint. Account/site/user
and conversation lifecycle boundaries remain those in ADR 0026 and the data inventory.

Private content expires within 15 minutes and clears on terminal/Cancel/Apply; send
claims have the separate bounded uncertainty window. Content-free audit events keep the default no-automatic-retention posture. The optional
operator controls below add bounded feedback/audit/usage cleanup; backups and processor
retention still need operator policies. Feedback stores only observation metadata.

## Feedback and reporting contract

A feedback identity binds account, site, conversation, capability, generation and
viewer. Shared preparation remains deduplicated; each viewer's response is independent.
Private refinement stays bound to its owning actor/draft and is never a fifth shared
artifact. Client input cannot choose tenancy or another actor. Accept only closed enums,
opaque work/receipt IDs and bounded numbers; reject extra/free-text fields.

- **Viewed:** the client reports an actually displayed suggestion in a visible workspace.
  Cache hits, polling, prefetch, hidden preparation and Ready are not views. Record once
  per viewer/generation; it remains a visibility signal, not proof of reading.
- **Used:** the client reports successful insertion after its final local edit/revision
  guard, tied to a fresh server authorization receipt for that actor/generation. Clicks,
  preview offers and server Apply acknowledgement are not uses. This never means sent.
- **Rejected:** an explicit rejection/dismissal, once per viewer/generation. Navigation,
  editing, timeout, unavailable output and Cancel alone are not negative quality votes.
- **Usefulness:** an explicit useful/not-useful/unsure assessment. Unanswered is unknown.
  Report useful rate among definite assessments and show assessment coverage separately.
- **Correction effort:** explicit none/minor/material/unassessed categories. Do not infer
  effort by logging keystrokes, original/edited text, or retained diffs/history. Report
  material-correction rate among assessed uses, alongside coverage and sample counts.

Identical feedback retries are idempotent under a database uniqueness fence and the
same account/parent lock order. A conflicting retry cannot create another counted vote.
The storage/API must define deliberate assessment updates before exposing that UI.
Authorization receipts are bounded metadata, not a new copy of a suggestion. Recheck
account/site/actor, live parent, generation/receipt ownership and private expiry at
feedback admission. Erasure/supersession and duplicate delivery must be tested using
independent transactions, including completion after deletion.

Reports show distinct viewed/used/rejected counts and explicit numerators/denominators.
Use and reject can both occur for the same generation; their percentages need not sum
to 100. A zero denominator yields no rate, not zero percent. Bound report windows and
queries, enforce current visible-site scope, and disclose missing observations rather
than silently truncating them. Separate refused admission, admitted failure, unknown
transport outcome, reported tokens and charged reservation bounds. None is spend or
billing without a pricing source. Server elapsed time is not end-to-end UI latency.

Customer outcomes must come from observed support records/ratings with their sample
coverage and cohort rules. Correlation with Assist use is not causal resolution evidence.
Connectivity checks, synthetic fixtures and merged code establish none of those outcomes.
Real-provider quality, direct assistive-technology acceptance and rollout remain #1176.

## Delivered integration

The four shared tasks retain their legacy requested/generated/failed action names
and add started. Their explicit envelope includes purpose, policy and connection
revisions, keyed work/source identities, configured closed provider and opaque route
and model tags, coarse server timings, nullable guarded/ledger reported counts and
closed failure codes. Admission refusal (`usage`) stays distinct from admitted failure.
A fresh exact-generation parent/actor lookup and the account lifecycle lock prevent
late erased or replaced workers from appending history. Historical records are not
backfilled with invented provenance. Existing support suggestion content remains in
its normal artifact store, outside measurement metadata.

`POST /dashboard/conversations/{supportCode}/assist-feedback/{task}` accepts bounded
JSON only: generation UUID and viewed/used/rejected/assessment event; used additionally
requires its opaque receipt; assessment additionally requires usefulness, correction
and expected_revision. Tasks are summary/reply/knowledge/ticket/refinement. Extra
fields, arbitrary text, prefetch/prerender intents, unknown enums, stale generations,
revoked/cross-site actors and invalid receipts are refused. Limits and account/actor/
parent locks precede the unique feedback row. Identical retries do not add observations.

Fresh Use/Apply responses issue a content-free authenticated encrypted receipt bound
to account, site, conversation, actor, purpose, generation and keyed current source
identity. It expires after at most 60 seconds, and no later than private draft expiry.
The receipt is never stored. A client must confirm insertion after the final local
guard; server authorization alone leaves used unknown. Private views require a ready,
current owning draft; use requires the applied state and current receipt. Private
assessment additionally checks the retained keyed source proof after payload clearing.
No other agent can report on that private draft, and supersession/expiry/erasure fence
all later observations. No feedback protocol records Send or captures an edit history.

The visible workspace reports one view per displayed generation; collapsed rails,
hidden workspaces, output below the viewport, polling and preparation do not report
views. A displayed output must intersect the viewport; scrolling can reveal it later. Explicit rejection
and assessment controls are separate from Cancel. A missing answer is unknown;
`unsure` and `unassessed` remain distinct. An assessment requires a recorded view,
and assessed correction effort requires a recorded use. An identical assessment retry
succeeds without incrementing its revision. A different assessment requires the current
expected revision and replaces the existing values once; conflicting stale updates fail
with 409. Reloading a view returns the retained assessment and revision. There is no
free-text assessment or inferred correction measurement.

Account navigation exposes AI Assist evidence to `view_audit` holders. HTML and JSON
at `/dashboard/account/ai-assist/report?days=7&task=all` share authorization and current
visible-site filtering, including authorized archived sites. Windows are 1–31 days;
tasks can filter one capability. Feedback cohorts use record creation within the UTC
window; an explicit later assessment updates that cohort rather than creating a new
vote. Useful-draft rate includes only reply drafts/private refinements with definite
assessments; its coverage is shown separately. General usefulness, adoption, correction
coverage and unknown rates remain independent. The report refuses complete measurement
if either feedback or lifecycle input exceeds 10,000 rows; it shows an incomplete
message instead of partial rates. Query chunk sizes are bounded at 500.

Work counts group lifecycle observations by generation and use the latest retained
state within the window. Elapsed samples use bounded observed lifecycle durations,
including queue/review; they are not model/UI latency or subsecond measurements.
Reported tokens and charged/held bounds come from distinct visible usage attempts,
never a sum of repeated lifecycle events or account totals from hidden sites. Missing
input/output samples and unknown transport outcomes have separate coverage/counts.
No billing or monetary spend is estimated. Uses without observed views and generations
without retained attempt records are counted explicitly. A token sum with no reported
samples is unknown, not a measured zero. A pruned attempt is missing usage coverage,
not a measured zero. Historical admission failures are not magically reconstructed.

Observed customer cohorts are distinct viewed/used conversations from the feedback
window, with recorded close events and recorded rating responses in that same window.
The report shows conversation and response counts, score distributions and rating
coverage. Cohorts overlap; results may precede or follow Assist, with no untreated
comparison or causal interpretation. Closure does not establish successful resolution.
Real-provider quality and assistive-technology acceptance remain #1176.

## Operator retention and privacy

Visitor exports include applicable feedback observations with the viewer projected to
a staff role; the keyed source proof and opaque authorization receipts are excluded.
Conversation/site/account/user deletion cascades feedback. Visitor erasure scrubs the
existing audit metadata and deletes feedback with its parent, under the same account
lock that serializes feedback admission. Source and receipt checks do not resurrect
an erased record. Usage accounting remains content-free installation/account metadata;
erasure removes its visitor association through scrubbed audits and deleted artifacts,
rather than releasing uncertain transport or changing charged budgets. Account report
JSON is an authorized bounded aggregate export, not a raw suggestion archive.

The following operator environment settings default to zero (disabled):
`WAYFINDR_AI_FEEDBACK_RETENTION_DAYS`, `WAYFINDR_AI_AUDIT_RETENTION_DAYS`, and
`WAYFINDR_AI_USAGE_RETENTION_DAYS`. Feedback/audit accept 1–3650 days; usage accepts
32–3650 days to retain current budget/retry safety. Invalid settings fail before any
store is modified. Zero retains the existing no-automatic-cleanup posture.

The hourly `wayfindr:prune-assist-observability` command handles at most 500 rows per
store per run, rechecking eligibility under account/installation lifecycle locks.
Feedback ages from its last update. Audit cleanup targets only closed Assist lifecycle
actions, leaving other security/support audits alone. Usage cleanup removes old terminal
attempts only after concurrency, dispatch uncertainty and retry holds end; reserved and
claimed work and active holds remain. At most 500 old zero-hold daily accounting periods
are also removed under the installation lock; current-day budgets and scopes remain.
The dry-run reports eligible feedback/audit/attempt batches without deleting any rows;
period housekeeping is performed only by a real run. Backups, host logs and processor
retention still require operator policies. The 15-minute private content expiry and
180-second send uncertainty window remain independent of metadata retention.

For local operator inspection, from `apps/server` with the qualified PHP runtime:

```sh
php artisan wayfindr:prune-assist-observability --dry-run
```

Set the environment variables through the installation's normal environment/config-cache
workflow; self-hosted containers already load the shared runtime env_file. Run the real
command only after choosing and reviewing the intended policy. This feature does not
activate a processor, deploy, release, or impose a retention policy on existing operators.
