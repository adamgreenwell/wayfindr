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
