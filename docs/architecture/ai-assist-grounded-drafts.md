# Published knowledge grounded reply drafts

The [#1172](https://github.com/adamgreenwell/wayfindr/issues/1172) selection/parser
foundation merged in [#1192](https://github.com/adamgreenwell/wayfindr/pull/1192).
The completion branch integrates it with private reply preparation. This describes
development source, not a released build or a current-model quality qualification.
Refinement generation remains #1173.

## Consent and bounded selection

Relevant articles is the shared article-context permission. Explicit account and
site consent, effective reply permission and current account/site access are
required before article retrieval. Off performs no article/catalogue lookup for
reply preparation and retains the existing transcript-only recipe. Knowledge
suggestions keep their separate transcript-to-local-search recipe. Articles belong
to accounts; site switches do not create site-specific knowledge collections.

`ConversationGroundingSelector` derives its query from fresh selected conversation
text, never the unsent composer or a client query. Local retrieval considers at
most the first 50 published same-account SQL substring matches in ID order, then
scores whole-token windows against the subject and latest three selected messages.
This bounded lexical baseline can miss a better article in a large catalogue.
No relevant source is an explicit clarification outcome, not invented policy.

At most three sources and 3,000 encoded characters are selected. In the reply
recipe the source allowance is also capped at one quarter of the effective total
context ceiling. Transcript and JSON overhead share that ceiling; 3,000 is not an
additional allowance. The final combined input is scrubbed and checked as valid
JSON within the ceiling. It is never cut mid-JSON to make it fit.

Original articles above 200,000 characters are excluded. The entire bounded body
is scrubbed before selection, including secrets crossing a window boundary. Titles
are capped at 160 characters and windows at 900, using complete sentence units;
a sentence too large to fit is omitted rather than cut into a misleading claim.
Transcript selection excludes attachments, cobrowse and known identity fields.

The provider sees only this source shape inside the combined transcript/knowledge
input, with no database IDs, URLs, original bodies or catalogue dump:

```json
[{"ref":"src-0123456789abcdef01234567","title":"Published guidance","passage":"A bounded scrubbed excerpt."}]
```

Opaque references bind account, full article revision, exact title and passage.
`sourceSnapshot()` is signed server-only scope, policy, catalogue and transcript
metadata, including source IDs and proofs but no original prose. The selection
object refuses implicit serialization; callers explicitly choose provider data.

## One attempt and conservative evidence validation

The existing admitted external attempt receives explicit untrusted-data
instructions and requests exactly five JSON fields:

```json
{"decision":"supported","reply":"A complete supplied sentence.","citations":["src-0123456789abcdef01234567"],"limitation":null,"missing_information":[]}
```

The parser rejects malformed or duplicate keys, extra fields, unknown or repeated
references, invalid types and oversized output without truncation. A malformed
proposal makes the whole result unavailable. Sources and inspector links are
resolved by the server, never copied from model-supplied IDs or URLs.

The runtime guard accepts supported prose only when every complete sentence is
present in a cited passage, whitespace normalized, and every citation supports a
sentence. It prefixes localized attribution to published guidance, distinguishing
it from conversation facts or evidence that an action occurred. Attribution and
server clarification use the selected visitor text to detect English, German or
Italian, including short requests; a viewer locale never changes shared output. New claims,
dropped negations, changed numbers and known instruction/action/promise patterns
produce a server-owned clarification instead. Model-declared insufficiency or
conflict also uses this safe clarification; arbitrary model limitation prose is
not retained. Differing numeric limits and directly opposing negated statements
across selected sources conservatively force clarification with known citations.

This is intentionally extractive. It does not accept free paraphrases or translated
facts, prove applicability to a customer's case, detect every semantic conflict,
or establish general model safety. False conflicts can require manual replies.
Article and visitor instructions grant no tool or action authority. The ordinary
human review and separate Send remain essential. There is no second provider call,
semantic judge, autonomous action or live-provider qualification in this change.

## Cache identity, publication and insertion

Preparation v3 resolves transcript and constant-size catalogue metadata without
reading article bodies. Consented replies bind the article watermark even when
no sources match, so later publication changes a clarification's identity.
Actual selected passages and full-source references participate in the guarded
work fingerprint. Guard identity hashes the significant parser/selector/prompt/
guard source tokens and pinned language detector version; formatting alone does
not invalidate it. Recipe or validation changes cannot reuse old evidence.

Fresh source and permission checks run before dispatch, around publication,
before display, source inspection and every Use. The signed proof and stored
provider payload must agree, including exact title/passage hashes. Deletion,
unpublication, edits beyond the excerpt, catalogue changes, consent changes and
lost site/reply access withdraw the entire grounded draft. This includes queued
and late results and retained prior output. Append-only conversation activity
may leave the original sources inspectable, but marks the draft stale and blocks
insertion until refreshed.

The nullable `conversation_copilot_reply_drafts.grounding` JSON stores bounded
scrubbed supplied sources, signed snapshot, validated decision/citations/limitations
and prompt/scorer identities beside the editable draft. Historical plaintext rows
stay NULL and are not promoted into grounded evidence. Generation reset/failure
clears both fields. Conversation/account erasure cascades; visitor export keeps
the business draft but excludes private source proofs/mapping and validation
metadata. Rollback drops only the new nullable column; downgrade operators must
first stop the newer workers/application code that depends on it.

The rail discloses consented passage sharing and separates draft, limitations,
missing information and a collapsible Published sources section. Source inspection
uses a read-only, generation-bound conversation capability in an independent tab.
Agent text survives asynchronous completion and source withdrawal. Preview and
Apply require fresh authorization and exact composer/candidate checks; Send is a
separate ordinary action. Opening, polling and inspecting never insert or send.

## Evidence boundary

Synthetic guarded-provider integration cases cover budgets/redaction, conflict,
injection, fake citations, unsupported claims, source/access races, tampered stored
passages, stale output, exports and nullable migration behavior. The local claim
fixture and current prompt/scorer identities are distinct from historical grounded-
answer evaluation captures. See [evaluation](../development/ai-evaluation.md).
Passing this fixture proves these assertions, not quality of any current provider,
route or model. Release, deployment and provider activation are separate work.
