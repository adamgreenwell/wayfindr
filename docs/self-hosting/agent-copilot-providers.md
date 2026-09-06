# Agent Copilot Providers

Wayfindr's agent copilot is optional and disabled on a new installation. Leaving
it unconfigured keeps every support workflow manual; chat, tickets, cobrowse,
mail, and the operator console do not depend on a model provider.

The provider boundary powers an on-demand conversation summary, an editable
reply draft, suggested knowledge snippets, and suggested ticket details during
conversation-to-ticket conversion. It does not add autonomous replies or
customer-facing AI.

## Configure it in the operator console

A platform operator can open **Operator → Agent copilot** and choose:

- `anthropic`, `gemini`, `openai`, or `openrouter` for a hosted provider;
- `ollama` for a normally local model server; or
- `openai-compatible` for a local, self-hosted, or otherwise compatible API.

Every enabled provider needs an exact text-model identifier. Hosted providers
also need an API key. Ollama and OpenAI-compatible endpoints may omit a key when
their own network boundary supplies the protection. OpenAI-compatible always
needs an HTTP or HTTPS endpoint; Ollama defaults to `http://localhost:11434`.

API keys are encrypted in the operator-settings table, treated as write-only,
excluded from validation old input, and omitted from audit metadata. A custom
endpoint receives whichever key is configured, so only point it at a service
you trust. Settings take effect without restarting long-running workers.

After saving, run **Test provider**. The probe sends this class of synthetic
message only:

> Wayfindr provider configuration test. No support or visitor data is included.

A successful HTTP response proves that the selected driver, endpoint,
credential, and model accepted one request. It does not prove output quality,
provider retention, cost controls, or that a future agent suggestion is useful.

## Environment baseline

The same values can seed an install before the database-backed operator setting
exists:

```dotenv
WAYFINDR_AI_PROVIDER=
WAYFINDR_AI_MODEL=
WAYFINDR_AI_ENDPOINT=
WAYFINDR_AI_API_KEY=
WAYFINDR_AI_MAX_CONTEXT_CHARACTERS=30000
```

Once an operator saves the form, those explicit stored values win over the
matching environment values. Selecting **None** deliberately disables the
copilot even if the environment still names a provider; use the environment as
the initial baseline rather than a second competing control surface.

Examples:

```dotenv
# Local Ollama. A key is optional.
WAYFINDR_AI_PROVIDER=ollama
WAYFINDR_AI_MODEL=qwen3.5:4b
WAYFINDR_AI_ENDPOINT=http://localhost:11434

# A self-hosted OpenAI-compatible API. The endpoint is required.
WAYFINDR_AI_PROVIDER=openai-compatible
WAYFINDR_AI_MODEL=local-support-model
WAYFINDR_AI_ENDPOINT=https://models.internal.example/v1
WAYFINDR_AI_API_KEY=
```

Use TLS and network access controls for a model endpoint on another host. A
loopback endpoint is private only when the PHP process and model server really
share that network namespace; in containers, `localhost` usually means the PHP
container itself.

## Data boundary

The internal provider contract accepts text only. It has no attachment method,
conversation model, ticket model, cobrowse snapshot, or provider-specific type.
Before text reaches Laravel's AI SDK, Wayfindr:

1. applies deterministic redaction for common credentials, tokens, private-key
   blocks, email addresses, labelled phone numbers, IP addresses, UUIDs,
   Luhn-valid payment-card numbers, and URL query strings/fragments;
2. truncates the result to `WAYFINDR_AI_MAX_CONTEXT_CHARACTERS`; and
3. sends it through the stable `wayfindr` provider name with provider-side
   storage disabled where the selected driver supports that option.

Redaction is defense in depth, not a claim that arbitrary prose can be perfectly
classified. Each product feature must still select the minimum useful context
and omit known identity fields before calling the boundary. Attachments and
cobrowse snapshots are out of bounds. Operators remain responsible for their
provider agreement, region, access controls, retention, training policy, and
privacy notice.

Generated output is a suggestion for an agent to review. This boundary cannot
send a visitor reply or automatically change a support record.

### Conversation summaries

When the provider assessment is ready, a conversation with at least one text
message shows **Conversation summary**. An agent must request each summary. The
feature selects only the conversation subject and a bounded, newest-first set of
message bodies, labels senders by generic role, and applies the common scrubber
before provider delivery. It omits visitor and agent names, visitor fields,
message metadata, timestamps, attachments, and all cobrowse data.

The worker reads newest message bodies in fixed-size chunks with a per-row
database cap and stops fetching as soon as the prompt budget is full; it never
queries an unbounded transcript result. A claimed job receives its own freshness
timestamp, so a delayed queue item cannot be replaced while its provider call is
active.

Generation runs on Wayfindr's default queue. The queue payload contains only a
local summary-row ID and opaque generation UUID; it does not contain transcript
text. The worker rechecks the requesting agent's current conversation access
before calling the provider. A new message marks the displayed suggestion as
stale but never triggers automatic regeneration.

The provider call has a 75-second timeout inside an 85-second job budget, which
fits the shipped default worker's 90-second timeout. Keep a custom default worker
at 90 seconds or longer.

Wayfindr stores only the latest generated suggestion for that conversation in
`conversation_copilot_summaries`. Refreshing replaces it, and deleting the
conversation deletes it. Request, completion, and failure audit events contain
IDs, state, provider/model names, and token counts where available—never prompt
or generated text.

### Suggested reply drafts

An agent with current reply permission can request a reply suggestion from the
existing **Reply assist** area. It uses the same bounded, scrubbed subject and
message selection as conversation summaries, with a separate instruction that
asks for one plain-text visitor reply. Profiles, metadata, timestamps,
attachments, and cobrowse data remain outside the provider path.

Generation is queued and the worker rechecks reply permission before provider
delivery. The queue payload contains only a local draft-row ID and opaque
generation UUID. Wayfindr stores only the latest suggestion in
`conversation_copilot_reply_drafts`; its prompt is never stored, and audit
metadata excludes both prompt and output text.

The suggestion is visibly labelled **Suggested**. It is never placed in the
composer automatically. **Use suggested draft** fills an empty composer but
refuses to overwrite an agent's existing message, and it never submits the
reply form. New transcript activity marks the draft stale and blocks insertion
until the agent explicitly refreshes it. The agent remains responsible for
reviewing, editing, and separately sending every reply.

### Suggested ticket details

An agent with ticket-management permission can request suggested details while
creating a ticket from a conversation. The queued worker uses the shared
bounded, scrubbed subject and message selection and asks the provider for a
strict JSON object containing only a title and one of Wayfindr's four existing
priority values. Wayfindr rejects malformed, blank, oversized, extra-field, or
unknown-priority output rather than guessing at a support-record value.

Existing account labels are matched locally against the same bounded text. The
label catalogue never enters the provider prompt, and a suggestion can only
refer to labels that still belong to the account when the worker stores it.
This keeps the deterministic part of routing deterministic while reserving the
provider for the language-dependent title and priority judgment.

The queue payload contains only the local suggestion-row ID and opaque
generation UUID. The worker rechecks ticket-management permission and confirms
that no linked ticket exists before provider delivery. Wayfindr stores only the
latest suggestion in `conversation_copilot_ticket_suggestions`; prompts and
provider output are excluded from audit metadata.

The panel is visibly labelled **Suggested**. **Use suggested details** copies
the title, priority, and local label matches into an untouched ticket-creation
form but never submits it. It refuses to replace agent edits. New transcript
activity marks the result stale and disables insertion until the agent requests
a refresh. The ticket is created only after the agent reviews the ordinary form
and submits **Create ticket** separately.

### Suggested knowledge snippets

An agent with current reply permission can request knowledge suggestions from
the existing **Reply assist** area when the account has at least one published
article. The queued worker uses the shared bounded, scrubbed subject and message
selection. It asks the provider for one to five short search phrases in a strict
JSON object; it does not send article titles, bodies, IDs, or the account's
knowledge catalogue to the provider.

Wayfindr ranks the returned phrases against published articles from that account
inside the install. At most three matches are kept, and only their local article
IDs are stored in `conversation_copilot_knowledge_suggestions`. Search phrases,
prompts, article bodies, and rendered snippets are not stored in that row or in
AI audit metadata. The queue payload contains only the local suggestion-row ID
and opaque generation UUID. The worker rechecks reply permission and the
presence of published knowledge before and after provider delivery.

The panel is visibly labelled **Suggested**. Each **Insert snippet** action
copies one locally resolved article excerpt into an empty reply composer. It
refuses to overwrite agent text and never submits the reply. Articles are
re-resolved against the current account and published state for every display,
so an unpublished or deleted article disappears immediately. New transcript
activity marks the result stale and disables insertion until the agent requests
a refresh. A no-match result is safe and explicit; the ordinary reply workflow
continues unchanged.

## Runtime and testing

The provider boundary uses Laravel's first-party AI SDK. The connection probe
runs inside the operator request with a 20-second application timeout. Summary
generation uses Wayfindr's existing default queue, so at least one normal queue
worker must be running; the agent request only records and queues the action.

The automated suite uses fake providers and synthetic fixtures for summaries,
reply drafts, knowledge suggestions, and ticket suggestions. CI and contributors
do not need live provider keys, and a test must never call an external model
endpoint.

The separate [grounded-answer evaluation harness](../development/ai-evaluation.md)
is offline by design. It scores versioned fixtures and recorded responses and
does not resolve this configured provider, send a prompt, or read support data.
Its green bundled baseline is a check of the evaluator—not approval for an
autonomous visitor-facing agent.

Operators may explicitly capture a synthetic provider run:

```bash
php artisan wayfindr:ai-evaluate:capture \
  --allow-provider \
  --output=/absolute/outside/repository/run.json
```

The command makes one request per fixture case, records provider/model and
token metadata, refuses to overwrite, and keeps the response file outside the
public checkout. It does not run automatically and may consume provider tokens.
