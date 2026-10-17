# Private agent draft refinement foundation

This is the first implementation slice of [#1173](https://github.com/adamgreenwell/wayfindr/issues/1173),
following the grounded reply completion merged in [#1193](https://github.com/adamgreenwell/wayfindr/pull/1193).
Option 1 keeps the stacked Assist rail and ordinary composer. This slice adds
in-process input, prompt, scope/revalidation and proposal-parsing primitives.
It adds no route, UI action, persistent store, queue job, usage reservation or
provider call. Existing automatic preparation never receives unsent draft text.

## Input and operation contract

`DraftRefinementInput` accepts an exact nonblank UTF-8 draft of at most 4,000
characters and a UUID draft revision. It preserves leading/trailing whitespace
and line breaks; invalid controls, overlong originals and invalid revisions are
rejected before building a prompt. Revision changes on every composer edit, even
when an agent types and deletes back to the same text. A keyed commitment also
binds the exact original, including details hidden by redaction.

Operations are closed enums, never freeform instructions:

| Action | Allowed parameter | Meaning |
|---|---|---|
| clarify | None | Improve wording without inventing missing facts. |
| shorten | None | Reduce length while preserving meaning and qualifications. |
| tone | calm, friendly, professional | Change expression, not facts or commitments. |
| translate | en, de, it | Faithful translation into an initial supported target language. |

Unused parameters are forbidden. Initial translation targets match the three
current dashboard languages; additional targets need explicit validation and
qualification. The raw original is a sensitive in-process parameter, never
queued or saved by these primitives. Generic JSON/PHP serialization of input,
context and proposal is refused. Normal debug output contains only task labels, counts and flags.
Explicit provider/preview accessors require a deliberate private data boundary.

## Bounded prompt construction

`ConversationDraftRefinementPromptBuilder::build(User, Conversation, Input)`
fresh-loads the actor, account/site conversation scope, reply authority, effective
refinement policy and current connection. Refinement uses the existing reply-task
permission through `conversation_draft_refinement`. That purpose already exists
in policy/usage vocabulary; it is still absent from the four shared artifact and
execution-authority stores. A vocabulary entry is not dispatch permission.

The builder scrubs the whole bounded original, then budgets a single JSON input:

```json
{"operation":{"action":"shorten","tone":null,"language":null},"draft":"Scrubbed private text.","conversation":null,"knowledge":{"sources":[]}}
```

The complete draft has priority. If it cannot fit within the effective context
ceiling with the envelope, fail explicitly rather than refine a silent substring.
Remaining space may hold minimum selected transcript context, then at most three
published same-account passages within a 1,500-character encoded source allowance.
Sources use opaque refs, scrubbed titles and complete excerpts, never database
IDs, URLs or the catalogue. Their allowance is part of the total ceiling, not
additional permission. With little space, optional context/sources may be omitted;
with no eligible transcript, the draft alone remains usable for construction.
The final whole input is scrubbed again and verified as valid JSON within the
ceiling, with the draft and selected source payload unchanged by that second pass.

Relevant articles Off, missing consent and legacy implicit policy perform no
refinement article/catalogue lookup. The draft and permitted transcript remain
available. On permits selected published account guidance subject to the same
fresh scope and source proofs as #1172. Retrieval queries derive from selected
conversation text, never arbitrary client queries or instructions in the draft.
Attachments, cobrowse and known identity/metadata fields remain excluded.

Instructions preserve numbers, negations, caveats and uncertainty; drafts and
articles are unverified data, not evidence that an action occurred or permission
to act. Clarify/tone/shorten preserve the draft language; translate uses the
validated target. No operation may add a policy, completed action, promised result
or unknown timeline. These instructions require later adversarial qualification;
they are not a proof of semantic preservation.

## Private scope and expiry

The construction snapshot binds account, actor, conversation/site, operation,
draft UUID revision and keyed exact-original commitment, current policy and
connection revisions, total context ceiling, activity position, transcript/source
proofs and the actual prompt hash. It contains no draft or source prose. Its
signature and fixed 900-second validity prevent scope tampering or expiry extension.
Equivalent drafts owned by different agents get different proofs and never join
shared preparation. Bump the recipe version when this construction contract changes.

`isCurrent(User, Conversation, Context, draftRevision)` checks signed expiry,
fresh ownership/authority/policy/connection, the current draft revision and all
selected source dependencies. New activity, selected transcript edits, article
edits/unpublication/deletion and revoked access or consent invalidate the context.
Expiry is exclusive at 15 minutes and is checked before database/source lookup.
A clock earlier than capture fails closed. This method needs no retained original,
so a future worker can validate encrypted scrubbed input without storing raw text.
`matchesInput(Input, Context)` is a separate local exact-original/option comparison;
it is not authorization or permission to Apply.

These are read-only construction/revalidation checks. They are not atomic dispatch,
publication or Apply capabilities and do not replace locks, active-request checks,
usage or erasure admission. No signed construction snapshot may become a client-
submitted provider prompt or a shared cache entry.

## Proposal contract

`DraftRefinementOutputParser` accepts exactly:

```json
{"candidate":"An editable private rewrite.","citations":[],"limitation":null}
```

Draft and output text must contain a character beyond Unicode whitespace; valid
text retains its exact surrounding spaces and line breaks. Unicode control
characters are refused except for tabs, carriage returns and line feeds.
Candidate text is nonblank UTF-8 within the real 4,000-character composer limit;
whitespace is retained exactly for later review. The 64 KiB JSON envelope admits
fully escaped supplementary characters in both the candidate and 1,000-character
limitation while bounding parsing work. Citations are unique refs from
the exact supplied selection, empty when none are used. A limitation is null or
a nonblank bounded explanation. Malformed/duplicate keys, escaped duplicate keys,
extra fields, invented refs/URLs, invalid types and oversized envelopes/candidates
are rejected without truncation. Explicit preview data contains only these three
fields, never signed scope proofs or server source mappings.

This parser checks shape, limits and citation membership. It neither proves that
a paraphrase/translation preserves facts nor grants Apply authority. Do not reuse
the extractive #1172 guard as a semantic validator for transformed text: faithful
translation and shortening intentionally change sentences. Qualify actual rewrite
semantics before enabling runtime, bind evidence to the current fixture/prompt/
scorer/route/model, and withhold unsafe or uncertain candidates from Apply.

## Remaining runtime integration

Deliver the following together before exposing a refinement action:

1. Add an owner-bound active-request store with encrypted scrubbed input and
   encrypted private preview, fixed capture/expiry timestamps, operation/revision/
   source identity and metadata-only failure receipts. No raw original, shared
   result, durable browser storage or draft history. Supersession/cancel must fence
   old workers. Delete input on every terminal/cancel path; expire input and preview
   at the same 15-minute maximum without extending retries. Cleanup and read/worker
   paths must remove expired content.
2. Register the new store in visitor export/erasure/retention inventories and
   conversation/site/account/user deletion. Decide export fields explicitly and
   keep private input/proofs out of unrelated viewers' export surfaces. Join ADR
   0026 erasure admission and an erasure-visible bounded in-flight claim before
   any text transmission; late-write checks or cascade deletion alone are insufficient.
3. Add explicit authenticated/CSRF-protected request/status/cancel/Apply routes.
   Never flash the draft into validation old input, session history or request/audit
   logs. Recheck current owner, active request, policy/connection, source scope,
   expiry and draft revision under established lock order at all final boundaries.
4. Enroll the private purpose in guarded execution/usage authority with one attempt,
   bounded cooldown and budget. Queue IDs only; workers read encrypted scrubbed
   input under the owning scope, rescrub/check the whole payload immediately before
   transport, settle usage correctly and clear input on failure/cancel/completion.
5. Add clarify/shorten/tone/translate beside the composer with visible data-use
   disclosure before the explicit request. Keep original/candidate in a private
   Apply/Cancel preview. Compare the exact original and edit revision immediately
   before applying; later edits, reconnect, workspace end and expiry refuse stale
   replacement. Completion/cancel/failure never writes captured text over newer text.
   Sending remains the ordinary separate composer action.
6. Qualify simultaneous agents/edits, deletion/erasure, consent/source changes,
   cancellation, budget/provider failures, reconnect, injection and unsupported
   rewrites, keyboard/screen reader behavior, themes and locales with fake-provider
   journeys. Real provider quality and rollout are separate evidence boundaries.

No schema migration or rollback is needed for this first slice. The new classes
are unused by runtime entry points; withdrawing them returns to the same existing
support workflow. Queue/store integration will require its own additive migration
and compatibility/rollback plan before activation.
