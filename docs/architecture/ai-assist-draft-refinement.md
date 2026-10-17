# Private agent draft refinement

[#1173](https://github.com/adamgreenwell/wayfindr/issues/1173) keeps Option 1's
stacked Assist rail and ordinary composer. Foundation [#1194](https://github.com/adamgreenwell/wayfindr/pull/1194)
is merged; the completion branch adds the private runtime and explicit composer
Preview/Apply/Cancel workflow. Automatic preparation never receives unsent text.
This is development documentation, not a release or provider-quality claim.

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
in policy/usage vocabulary; it is absent from the four shared
artifact stores. Runtime dispatch uses the separate guarded `refinement` execution
kind; it cannot use an unreserved provider path.

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
so the worker can validate encrypted scrubbed input without storing raw text.
`matchesInput(Input, Context)` is a separate local exact-original/option comparison;
it is not authorization or permission to Apply.

These construction checks run inside the runtime admission/publication/Apply
transactions described below. A signed construction snapshot is never accepted
from a client or used as a shared cache entry.

## Proposal contract

`DraftRefinementOutputParser` accepts exactly:

```json
{"candidate":"An editable private rewrite.","citations":[],"limitation":null}
```

Draft and output text must contain a Unicode letter, number, punctuation mark or
symbol. Whitespace, format characters or combining marks alone are refused;
joiners and combining marks in real text, surrounding spaces and line breaks
are retained exactly. Unicode control
characters are refused except for tabs, carriage returns and line feeds.
Candidate text is nonblank UTF-8 within the real 4,000-character composer limit;
whitespace is retained exactly for review. The 64 KiB JSON envelope admits
fully escaped supplementary characters in both the candidate and 1,000-character
limitation while bounding parsing work. Citations are unique refs from
the exact supplied selection, empty when none are used. A limitation is null or
a nonblank bounded explanation. Malformed/duplicate keys, escaped duplicate keys,
extra fields, invented refs/URLs, invalid types and oversized envelopes/candidates
are rejected without truncation. Explicit preview data contains only these three
fields, never signed scope proofs or server source mappings.

The parser checks shape, limits and citation membership. A separately versioned
conservative safety guard refuses obvious number/URL changes, polarity or caveat
loss, new commitment/completed-action/eligibility categories, injection, unsanitized
secrets, an uncertain limitation, and wrong selected translation language. Its
identity is bound to each request and rechecked for publication/read/Apply.
These checks are deliberately fallible refusal heuristics, not semantic proof.
Human review remains required. Real-model adversarial quality and rollout remain
separate #1176 qualification; synthetic responses prove runtime behavior only.

## Private storage, admission and worker

The additive migration creates `conversation_draft_refinements`: one mutable
slot per owning agent and conversation. Scrubbed input, signed scope/source data
and preview are encrypted using the installation key. Raw original text is
never persisted. Fixed capture/expiry timestamps bound content to 900 seconds;
retries cannot extend it. Input is removed on Ready and all terminal paths.
Cancel, failure, staleness and Apply clear scope and preview too. Expired content
is cleared by read/worker withdrawal; minute cleanup deletes expired rows. No durable
browser storage, draft history, shared result or visitor-export field is added.

Admission takes the existing installation → account usage locks, then actor,
conversation and private row locks. It fresh-checks reply authority, effective
policy, connection, erasure state and signed dependencies, reserves one bounded
ledger attempt and queues only private row ID/generation. A 60-second owner/slot
cooldown and ledger provider retry deadline prevent new UUIDs or private-data
cleanup from evading cooldowns. Usage budgets and concurrency still apply.

The worker claims once, commits an erasure-visible metadata send claim before
provider code, and uses the existing guarded SDK transport. Whole-input scrubbing,
actual-prompt binding, current connection/policy/source authority and reservation
are checked at the final wire boundary. Publication and settlement recheck the
same scope. Duplicate/superseded jobs cannot publish or clear newer work. One
attempt, an 85-second worker timeout and the bounded provider call have no
automatic provider retry. Errors retain only a fixed reason code.

`conversation_draft_refinement_sends` stores only account/conversation/generation
and start time. It survives cancellation, supersession and owning-agent deletion,
so those actions cannot hide an active call from visitor erasure. Erasure shares
the account lock and refuses a fresh claim within 180 seconds. Normal worker
return removes its own claim; interrupted calls retain the uncertainty window.
Cleanup removes only claims aged at least 180 seconds. Both tables cascade with
account/conversation/site deletion; the private slot also cascades with its user.
As with existing copilot sends, ordinary parent deletion cannot recall data
already sent to a processor. See ADR 0026 and the privacy inventory.

## HTTP and composer boundaries

Authenticated JSON-only routes under the conversation support code provide
POST capture, GET status, POST Apply and POST Cancel. Mutations use normal web
CSRF protection. Capture is throttled separately from status/actions and ordinary
Send. Responses and errors use `no-store, private`. The controller reads bounded
raw JSON to preserve exact whitespace and never flashes private draft input into
old input/session history. Status/Apply/Cancel accept opaque generation and draft
revision only; no client-supplied prompt or candidate is trusted.

Status and Apply fresh-check the owning agent's reply authority, active generation,
revision, fixed expiry, policy/connection/source proofs and guard identity under
the ledger lock. Apply consumes the private preview once and returns authorized
text; it changes no message, shared suggestion or support record. A disconnected
or cancelled client can leave the server receipt consumed without inserting text,
which safely requires a new explicit request.

The composer shows disclosure, processor/model and article-context consent before
Preview. Clarify/shorten/tone/translate capture only on that click. The exact raw
original and UUID edit revision live in this page's memory. Original and candidate
are shown together in the shared review region, including collapsed cited passages.
The rail opens and focus moves to review. Apply reauthorizes, then compares exact
original/revision/candidate/source identity immediately before assigning text.
Cancel returns focus and keeps the exact original. Typing and deleting back,
helper/option changes, source/policy changes, expiry, hidden/detached workspaces,
submission, offline/reconnect and navigation withdraw stale work. Status polling
continues while the preview is visible. A monotonic client timer cannot extend
server expiry. No background completion inserts text; ordinary Send stays separate.

## Operations, migration and rollback

From `apps/server`, the owned disposable fixture verified:

```sh
php artisan wayfindr:prune-draft-refinements
```

The normal scheduler runs this every minute without overlap. Keep scheduler and
queue workers healthy: expired content is always unreadable/unusable, while
physical deletion after idle expiry depends on the scheduler. The migration has
no data backfill and does not alter the four shared artifact tables. Both new
tables and encrypted columns participate in backup data responsibility; an older
backup can retain expired ciphertext until that backup is retired.

For rollback, first prevent new refinement requests (effective reply task Off or
Assist Off), stop/drain the relevant workers and allow the 180-second uncertain
send window to end before reverting code. Keeping the additive tables is safe;
remove them only after new code and pending refinement jobs are gone. Migration
down drops send claims before private slots. Ordinary support and shared Assist
remain usable when private refinement is unavailable. No release, deployment,
live-provider activation or external retention guarantee is implied.
