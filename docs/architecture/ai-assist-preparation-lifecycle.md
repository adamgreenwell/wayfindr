# AI Assist preparation lifecycle

The first implementation slice of [#1170](https://github.com/adamgreenwell/wayfindr/issues/1170)
defines internal work identities and cache classification. It builds on merged
[#1185](https://github.com/adamgreenwell/wayfindr/pull/1185) and the
[behavior contract](../product/ai-support-assist-contract.md).

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
the migration does no content scan or backfill. Ticket identity skips local label
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

`canPrepareOnOpen` distinguishes conversation tasks (summary/reply/knowledge) from
conversion-only ticket details. Manual never enables automatic preparation.
Neither helper proves an actual open, reserves usage, authorizes provider I/O,
validates output, or replaces fresh read/Use authority. This slice adds no endpoint,
queue work, persistent receipt store, automatic trigger or provider call. Its
only schema change is two nullable account catalogue UUIDs and their source-write
triggers. These configuration tokens contain no visitor links or source content;
see the [data inventory](../privacy/data-inventory.md).
Existing context DTO constructors remain compatible.

## Remaining execution order

1. Add durable generation/fingerprint receipts and atomic ensure admission using
   the ledger's installation → Account lock order. Recompute this identity under
   admission; reserve usage before enqueueing, and prove real concurrent-open
   coalescing. Metadata and output stores must join export/erasure inventories.
2. Recheck requesting actor, scope, target eligibility, source, policy, processor
   and work identity before dispatch and publication. Preserve the erasure-visible
   running claim and conservative dispatched accounting during supersession.
3. Retain at most one preceding authorized output during refresh. Withdraw revoked
   sources, keep insertion disabled for stale output and fence old completions.
4. Add explicit Refresh/Try again with database-clock cooldown of at least 60
   seconds (or longer provider advice). Initial ensure/open never retries a cached
   failure or insufficient result; queue/timeout/usage failures remain bounded.
5. Wire authenticated POST ensure to actual visible workspace/conversion opens,
   with Manual initial actions using the same coordinator. GET, rendering,
   prefetch and background tabs remain read-only. Preserve composer/form edits.
6. Qualify all four tasks, concurrent opens/workers, deletion/configuration races,
   budgets, stale reads/Use, failure recovery and real browser open behavior before
   treating #1170 as complete. The unified rail remains #1171.

Synthetic identity/cache tests establish this first slice only. They do not prove
concurrent reservation, provider behavior, automatic preparation, or deployment.
