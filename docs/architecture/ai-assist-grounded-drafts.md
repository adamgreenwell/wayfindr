# Published knowledge grounding foundation

This is the first implementation slice of [#1172](https://github.com/adamgreenwell/wayfindr/issues/1172).
It supplies local selection and output-validation primitives. Runtime reply,
summary, knowledge and ticket preparation still use their existing recipes.
No article passages are added to current provider requests by this slice.

## Selection

`ConversationGroundingSelector::select(User, Conversation, int $maximumCharacters = 3000)`
constructs fresh bounded conversation text through `ConversationTextContextSelector`.
Callers cannot pass composer text or arbitrary retrieval queries. Fresh account,
site and reply authority is required. Effective reply-task permission and
`articleContextAllowed` are checked before any intentional article or catalogue
lookup. Relevant articles Off, missing account/site consent and legacy implicit
policy return `disabled` with no article context.

Local retrieval considers at most the first 50 published same-account SQL
substring matches in ID order. It scores whole-token windows against a bounded
set of terms from the latest three selected messages and the subject. This is a
bounded lexical baseline, with no embedding, external retrieval request or
guarantee that the best article in a large catalogue was considered. If no
relevant window fits, `no_sources` is explicit. Articles are account-owned;
site consent is a processing control and provides no article isolation.

At most three sources and 3,000 encoded characters are selected, further capped
by the requested budget and effective policy ceiling. The count includes titles,
opaque references, JSON escaping and array overhead. A budget below two characters
is invalid because even the empty JSON array requires two characters. Each source
window is at most 900 characters and each scrubbed title at most 160 characters.
Originals above 200,000 characters are excluded; the bounded fetch includes one
extra character to detect that case. The existing scrubber processes the entire
bounded article text before window selection or truncation, so a credential
crossing a cut cannot evade redaction. Transcript selection already excludes
attachments, cobrowse and known identity fields.

Only `providerSources()` may become future provider data:

```json
[{"ref":"src-0123456789abcdef01234567","title":"Published guidance","passage":"A bounded scrubbed excerpt."}]
```

References bind the account, article's complete revision and exact selected
passage through keyed hashes. Database IDs, original bodies, revision stamps and
URLs are absent from this payload. The selection object refuses generic JSON/PHP
serialization; callers must explicitly choose `providerSources()`. Its debug
representation contains counts only. `sourceSnapshot()` is server-only metadata:
signed scope/policy/catalogue/transcript proofs, original activity position,
source IDs, full article revisions and passage proofs, with no original prose.

## Revalidation

`sourceStillCurrent(User, Conversation, array $snapshot)` fresh-loads access and
policy, validates snapshot integrity, and checks the selected transcript originals
before reading catalogue or article rows. It rejects deleted, unpublished,
scheduled, foreign or changed sources, including same-second body changes beyond
the selected window. Catalogue identity includes the existing account watermark
and latest currently published timestamp, so scheduled publication also changes
the identity. Catalogue changes conservatively invalidate a selection even when
they affect another article.

Append-only conversation activity may leave the selected originals readable.
It does not make the draft current: future preparation identity must use the
recorded activity position and treat the draft as stale. These local reads are
not an atomic transmission or insertion capability. Runtime integration must
use the existing locked admission/publication/Use boundaries and revalidate
immediately before transmission, display and insertion.

## Model proposal contract

`ConversationGroundedReplyOutputParser::parse(string, array $suppliedSources)`
accepts exactly these fields:

```json
{"decision":"supported","reply":"An editable private proposal.","citations":["src-0123456789abcdef01234567"],"limitation":null,"missing_information":[]}
```

`supported` requires at least one known unique reference, no limitation and no
missing-information entries. `insufficient` requires no citations, an explicit
limitation and at least one missing-information entry. `conflicting` requires at
least two supplied references and an explicit limitation. Every decision requires
a nonblank reply within the actual 4,000-character composer limit. The parser
rejects malformed/duplicate JSON keys, extra fields, unknown/duplicate references,
invalid types and oversized input; it never truncates structured output to make it
parse. Cited source records are copied from the supplied catalogue rather than
from model URLs or invented IDs.

These are model-declared classifications and schema/citation checks. A valid
reference does not establish that a claim follows from its passage. Article and
transcript text remain untrusted data, never tool instructions or action authority.
The proposed reply is not a verified transcript fact. Later rendering must clearly
separate the reply, published guidance, limitations and missing information.

## Remaining integration

Before enabling passage sharing, deliver these changes together:

1. Build the grounded runtime prompt with explicit untrusted-source instructions
   and limitation/clarification behavior; preserve transcript-only help when the
   shared Relevant articles control is Off.
2. Register actual passage, policy, catalogue, full-source and prompt revisions in
   preparation identity, reservations and final-boundary source authorization.
   Budget the whole combined transcript-plus-source input and scrub again at the
   existing provider boundary. A 3,000-character source budget is not extra
   permission beyond the total context ceiling.
3. Parse once per existing external attempt, store citation/source identity with
   the result, and withdraw the whole article-derived draft on source or authority
   changes. Extend prepared and legacy read/Use/source-inspection paths coherently.
4. Update the truthful processor disclosure when passages actually enter runtime
   requests. Preserve private output, explicit Preview/Apply insertion and separate
   Send; refinement remains #1173.
5. Qualify conflict, injection, unsupported claims, fabricated citations and
   source/access changes during generation with synthetic guarded-provider cases.
   Schema tests cannot establish semantic quality. Reuse evaluation evidence only
   with current fixture, prompt and scorer identities; historical captures prove
   neither the new route nor the current model.

The first slice has no queue payload, persistent result schema, provider call,
usage reservation, UI action, autonomous reply, release or deployment.
