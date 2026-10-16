# AI Assist preparation lifecycle

The shared lifecycle for [#1170](https://github.com/adamgreenwell/wayfindr/issues/1170)
implements shared admission, durable receipts, worker fencing and visible-open
preparation. It builds on merged [#1186](https://github.com/adamgreenwell/wayfindr/pull/1186) and the
[behavior contract](../product/ai-support-assist-contract.md).

## Automatic-processing approval on upgrade

Earlier administration described Prepare as future configuration with manual
execution. Those saved choices remain configured but resolve to **Manual** after
this upgrade. Each account and site policy has a nullable
`preparation_consent_revision`; only a Prepare policy whose approval equals its
current UUID revision permits automatic opens. The migration adds NULL columns
without scanning content, creating policies or backfilling approval. Manual, Off,
legacy installation behavior and existing business output are preserved.

Fresh account and selected-site saves under the current disclosure stamp their
new policy revisions, including an otherwise unchanged Prepare choice. Both
scopes must be approved. The editor token namespace changes so an old form cannot
authorize new automatic behavior, even when no prior policy row existed. Old
writers that rotate revisions cannot carry approval forward during an upgrade.
Rollback drops the stamps, so re-upgrade requires review again. Saving alone
never queues work. Admin copy in English, German and Italian explains permitted,
scrubbed transcript transmission on visible opens and the upgrade hold.

## Implemented identity boundary

`AiAssistPreparationIdentityResolver::forTask` reloads the actor and conversation,
checks current account/site/task authority and policy, resolves the exact active
connection, and selects the existing bounded transcript recipe. Ticket details
also require a currently unlinked conversation. Shared tasks exclude personalized
unsent drafts; refinement is rejected by this resolver.

The keyed fingerprint includes account/site/conversation and capability, the
shared conversation-text partition, effective policy/context ceiling, exact
connection route/model revision, prompt instructions/deadline and recipe version,
selected source revisions, all-message activity position, and applicable local
catalogue revision. Equivalent authorized viewers share the identity; the
requesting actor still belongs to the eventual reservation and audit.

Source proofs are opaque HMACs, captured during the same bounded selection as the
prompt. They cover selected message IDs, generic roles and the captured raw text
prefix (at most 8,000 characters per message), plus subject identity. No raw text
is retained in the proof. A selected edit hidden by redaction still invalidates
work; seen receipts, profiles, private metadata and incidental timestamp writes
do not. Attachment-only activity changes the activity position without exposing
attachment content. Unselected older messages do not expand the input partition.
The optional source proof on existing context DTOs does not join provider input.

Knowledge and ticket identities read server-maintained account catalogue UUIDs,
without scanning article bodies or label rows. PostgreSQL/SQLite triggers rotate
these tokens atomically on relevant inserts, edits, deletes and account moves,
including bulk SQL. Both owners are invalidated when a record moves. Draft
article edits and timestamp-only writes leave tokens unchanged; non-null future
publication edits conservatively invalidate them. Rollback restores the token
with the source. Account deletion removes the metadata with its parent row.

Knowledge also reads the latest eligible `published_at` using the existing
`(account_id, published_at)` index and `LIMIT 1`. This watermark advances when a
scheduled article becomes eligible without a database write. No eligible article
means insufficient context. Existing installations begin with NULL revision zero;
the migration does no content scan or backfill. PostgreSQL function definitions
are replaced on migration, including functions retained after `migrate:fresh`
drops tables; rollback removes the owned functions and triggers. Ticket identity skips local label
matching, while ordinary generation still matches labels by default. Summary and
reply identities remain transcript-only. No catalogue content leaves the install.
A future grounded recipe must add its actual dependencies before admission; bump
the recipe version when matching or output-schema semantics change.

## Implemented cache classification

`reuseState` classifies only a receipt with the exact current fingerprint. Fresh
pending/running work is Preparing; current validated output is Ready; failures,
unknown states and expired pending work are Unavailable. Insufficient context is
terminal for that fingerprint too. Reopening unchanged input cannot turn those
outcomes into an automatic retry. A different fingerprint is a cache miss, which
still needs fresh admission and safe supersession of any existing work.

## Admission and retained data

`AiAssistPreparation::ensure` serves both explicit Manual actions and Prepare
opens. It recomputes identity under installation → Account → requesting User →
Conversation → artifact locks. Equivalent authorized viewers admit one generation,
one usage reservation and one ID-only queued job. The reservation and artifact
commit together; enqueue failure cancels only the captured unsent generation.
Admitted generations retain the existing per-task request/generated/failed audit
names. Reservation and queue failures record only IDs, generation, processor
revision and a fixed failure category, never exception bodies, prompts or output.
Reused terminal receipts create no duplicate attempt events. Queue failure locks
Account before artifacts/audits and compares the captured generation, so late
acknowledgements cannot fail a successor or recreate erased audit references.

GET rendering, status and Use never reserve or enqueue. Status reads allow
60 requests per viewer/task per minute and 180 across the viewer's tasks, leaving
room for three tasks polling at two seconds. Use has a separate 60/minute bucket,
shared with native Use routes. Ensure has its own 60/minute bucket, isolated from
ordinary support/export throttles; native Manual generation retains its existing
6/minute bucket. HTTP query limits are separate from provider usage reservations.

`ai_assist_preparation_receipts` keeps one mutable metadata row per account,
conversation, task and fingerprint until conversation erasure. It contains no
prompt or output. Returning from failed input A through B to A still reuses A's
terminal receipt; reopening cannot obtain a free automatic retry. Explicit Refresh
can replace that receipt only after database-clock cooldown of at least 60 seconds
and any longer ledger/provider advice. Insufficient context is terminal until
inputs change. Missing stores, connection, queue or budget fail closed.

Each existing artifact retains its current generation and at most one preceding
Ready snapshot while refresh is pending/running. Terminal success or failure
clears that snapshot. Append-only activity permits authorized previous text to be
read as Stale, with Use disabled. Selected-source edits/deletion, policy,
connection or applicable catalogue changes withdraw derived output. Snapshots
contain opaque source HMACs, not prompts. Export includes permitted business output
and staff roles while omitting internal proofs; conversation erasure cascades to
both artifact slots and receipts. See the [data inventory](../privacy/data-inventory.md).

Fresh pending work coalesces even explicit Refresh. A live running claim cannot
be overwritten, including after policy or processor changes: erasure must still
see it. Expired dispatch recovery releases only that attempt's concurrency lease;
it preserves charged usage and longer retry advice. An obsolete completion cannot
refund or replace its successor's hold.

## Dispatch, publication and Use

Prepared workers claim under the same lock order and database clock. Requesting
actor, scope, eligibility, policy, processor and selected source identity are
rechecked before claim, at actual dispatch, and before and after validated output
publication. Generation comparisons fence duplicate delivery and late completion.
Dispatched uncertainty remains conservatively charged through reconciliation.
All new Manual requests use these protections too; pre-existing unprepared output
keeps its compatibility read path until deliberately refreshed. Upgrade does not
silently enable automatic calls.

Status and Use freshly resolve the viewing actor's access. Use requires the exact
current Ready generation and policy; stale or withdrawn suggestions cannot be
inserted/applied. Reply and article insertion requires an empty composer and
rechecks edits after its response. Ticket details always require deliberate
**Apply** into an untouched form. Neither action sends a reply or creates a ticket.

## Visible workspace opens

Authenticated, CSRF-protected POST commands accept only task, action and workspace
identity, never an unsent draft or client prompt. Prepare opens summary, reply and
knowledge tasks only when the conversation workspace is visible. Ticket details
wait for the visible conversion workspace. Manual offers **Prepare suggestions**
and **Suggest ticket details**; Off exposes no processing controls. Native Manual
forms use the same coordinator.

Document visibility, prerender state and active tabs gate opens. Prefetch headers
are refused. Repeated opens share receipts. Source-change events and bounded
status polling perform reads only; they never continuously regenerate. Refresh
preserves composer and conversion edits. Unchanged polling preserves focused
buttons and avoids repeated live-region announcements. Ready, Preparing, Stale,
Unavailable and insufficient-context states remain readable without JavaScript.

## Qualification boundary

Synthetic guarded provider fixtures exercise admission, budgets, failure recovery,
source/configuration/deletion races, export and erasure without external I/O.
PostgreSQL tests use independent contending processes to prove one reservation/job
and one charged provider dispatch. Browser acceptance uses isolated synthetic
accounts and a disposable database. These checks establish application behavior;
they do not activate a production connection or prove a real provider's service.
The unified Assist rail remains [#1171](https://github.com/adamgreenwell/wayfindr/issues/1171).
