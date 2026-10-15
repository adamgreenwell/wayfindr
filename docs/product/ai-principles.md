# AI Principles

Wayfindr treats AI as a tool for better support, not as a product identity.

AI should help agents and contributors move faster when the task genuinely
benefits from language understanding, summarization, classification, retrieval,
or generation. It should not replace clear support workflows, consent, privacy,
or boring deterministic code.

## Product Principles

- Use AI to assist people, not to hide product gaps.
- Keep agents in control of customer-facing replies and support decisions.
- Make AI optional for self-hosters and safe to leave unconfigured.
- Prefer small AI features with obvious value over broad assistant surfaces.
- Use the minimum necessary support context for each prompt.
- Label generated suggestions clearly.
- Keep provider choices configurable.
- Test AI behavior with fakes, fixtures, and structured outputs.

## Good Early Fits

- Reply drafts that an agent reviews before sending.
- Conversation summaries for handoff or ticket creation.
- Suggested ticket titles, priorities, tags, and next steps.
- Knowledge-base snippets that an agent chooses before inserting.
- Development helpers that expose safe local project context through MCP.

## Poor Fits For Now

- Autonomous customer replies.
- Unbounded analysis of all customer conversations.
- Sending masked, private, or sensitive data to providers by default.
- Features that fail when no model provider is configured.
- Marketing copy that says "AI-powered" without explaining the concrete value.

When in doubt, build the normal workflow first. Add AI only when it makes that
workflow more useful, clearer, faster, or easier to operate.

## Implemented Provider Boundary

The runtime boundary is documented in
[Agent Copilot Providers](../self-hosting/agent-copilot-providers.md). Product
features depend on Wayfindr's text-only provider contract rather than a vendor
SDK directly. The operator chooses the driver, exact model, credential, and
optional endpoint; an unset provider removes the capability without degrading
the underlying support workflow.

Every request is deterministically scrubbed and bounded at the last point before
the Laravel AI SDK. That is defense in depth: feature code still owns minimum
context selection, must omit attachments and cobrowse state, and must present
output as a suggestion a human reviews.

The first product uses are an on-demand conversation summary for agent handoff,
an editable suggested reply, suggested knowledge snippets, and suggested ticket
details at conversation-to-ticket conversion. All run through the default
queue, recheck the requesting agent's current authority at execution time,
select only bounded subject/message text, and store only the latest suggestion
with its source position. New conversation activity marks output stale; it
never causes automatic regeneration or a support-record action. A reply
suggestion only fills an empty composer after an explicit agent choice and never
submits it. Knowledge suggestions ask the provider only for validated search
phrases, then rank published same-account articles inside the install without
exposing the knowledge catalogue; the agent explicitly inserts one locally
resolved snippet into an empty composer. Ticket suggestions use strictly
validated title/priority output, while existing labels are matched
deterministically inside the install without exposing the account label
catalogue. Applying ticket details only fills an untouched form; the agent
reviews and submits ticket creation separately.

## Planned Option 1 Assist Contract

The selected [AI Support Assist contract](ai-support-assist-contract.md) records
the next assistive layer. This is a documentation contract, not shipped runtime:
the operator-scoped, on-demand behavior above remains current. Account-owned
connections, automatic preparation, grounded drafts, and the selected UI require
their downstream implementation and qualification.

Option 1 keeps the transcript and composer dominant beside a collapsible right
Assist rail stacking **Catch up**, **Suggested reply**, and **Sources**. Insert
and Refresh sit beneath the draft; freshness sits at the rail foot. The ordinary
composer owns Send. Account administration uses one grouped connection form,
with separate behavior, task, site, and usage controls below it.

"Private" means hidden from the visitor, not local processing. Planned grounded
drafts may send bounded relevant passages from allowed published same-account
articles through the scrubber. Today's knowledge flow instead sends bounded
transcript context and receives search phrases for local article matching.
Automatic support calls require explicit account/site enablement and
must never activate silently on upgrade. Proposed, tested, and active connection
revisions stay separate, with automatic preparation enabled separately.

The future worker, display, and insertion paths must recheck account/site and
support-operation authority. Changed, unpublished, deleted, or inaccessible
sources must withhold affected output from use until current authorized context
is established. Suggestions cannot overwrite agent edits, send replies, or apply
support-record changes automatically. This contract preserves ADR 0004's
autonomous-reply deferral; documenting it does not run provider tests or activate
the planned runtime.

Changing site-level Assist policy also requires current access to every affected
site in addition to the account management permission, including bulk and delayed
changes. Account connection management never grants site access.

## Evaluation Gate

The first provider-free evaluation layer is documented in
[AI Evaluation](../development/ai-evaluation.md). It scores versioned synthetic
grounded-answer fixtures and recorded outputs for answer accuracy, confidence,
refusal reasons, citations, fact coverage, unsafe answers, and overconfident
errors. The scoring path runs without a provider key or customer data. A
separate explicit capture command can record provider/model-tagged output from
synthetic fixtures into a private file outside the repository.

This is evidence infrastructure, not an autonomous answer feature. A green
bundled baseline proves that the deterministic scorer works; it does not prove
a live model is suitable for visitors, and the bundled 80% confidence threshold
is not production approval. The September 8, 2026
[ADR 0004 reassessment](../decisions/0004-ai-as-assistive-product-and-development-layer.md#reassessment-on-2026-09-08)
considered a live synthetic provider run and reaffirmed the deferral of
autonomous visitor replies. The agent-controlled copilot remains the approved
product boundary until broader repeated evidence and a future explicit ADR
decision say otherwise. Four later private captures used the same expanded
sixteen-case suite and prompt. Two GPT-5.2/Azure runs scored 15/16 and 16/16;
Claude Sonnet 5/Bedrock scored 15/16; Gemini 3.8 Flash/Vertex scored 13/16.
The owner's [September 10 adjudication](https://github.com/adamgreenwell/wayfindr/issues/762#issuecomment-5625334565)
attributed two Gemini misses to matcher brittleness and one to a real omitted
retry step. The [final disposition](https://github.com/adamgreenwell/wayfindr/issues/762#issuecomment-5625831620)
was no fixture or matcher change; the recorded 13/16 failure and evidence
identities stand. #997 later changed the fixture policy and bound a versioned
scorer contract, including evaluator and Unicode phrase matching, into the suite
identity; the prompt identity stayed the same. The four captures are historical
and cannot be compared with the current suite. #762 first needs every proposed
route recaptured under the current identity, then same-route evidence after
meaningful elapsed time or a model revision before revisiting ADR 0004. The
cross-model samples also changed upstream route, and all four were recorded
within about 78 minutes.
That point-in-time evidence is neither long-term drift resistance nor provider
or runtime approval, so #762 and the ADR boundary remain unchanged.
