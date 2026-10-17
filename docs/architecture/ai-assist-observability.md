# AI Assist audits, feedback and retention

Issue [#1175](https://github.com/adamgreenwell/wayfindr/issues/1175) adds account-visible
operational evidence without collecting prompts, suggestions or staff edit histories.
This first slice implements private-refinement lifecycle audit events and locks the
measurement/integration contract below. Feedback collection, reporting and additional
retention controls remain follow-up work. It is not a release or effectiveness claim.

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
an observed zero. No prompts, raw or scrubbed original, preview, article passages,
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
claims have the separate bounded uncertainty window. Content-free audit events inherit
the existing documented audit posture: no automatic retention. This slice does not
silently promise an audit cleanup policy. Operator-controlled audit/feedback retention,
usage-metadata cleanup, backups and processor retention need explicit coverage in the
remaining implementation. No longer-lived content store is introduced here.

## Feedback and reporting contract for the next slice

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

## Remaining work

1. Bring the four shared-task audit envelopes to the same explicit provenance/timing
   contract while preserving legacy action names and erasure fencing.
2. Add feedback storage/admission/receipts and visible-workspace hooks with duplicate,
   conflicting-retry, stale revision, cross-account/site and deletion-race coverage.
3. Add authorized bounded reports with the distinct measures and coverage above.
4. Complete applicable feedback/usage export, deletion, retention and operator controls;
   qualify the complete journey without storing content for measurement.
