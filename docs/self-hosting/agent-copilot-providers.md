# Agent Copilot Providers

Wayfindr's agent copilot is optional and disabled on a new installation. Leaving
it unconfigured keeps every support workflow manual; chat, tickets, cobrowse,
mail, and the operator console do not depend on a model provider.

The provider boundary powers an on-demand conversation summary for agent
handoff. It does not add autonomous replies or customer-facing AI.

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

## Runtime and testing

The provider boundary uses Laravel's first-party AI SDK. The connection probe
runs inside the operator request with a 20-second application timeout. Summary
generation uses Wayfindr's existing default queue, so at least one normal queue
worker must be running; the agent request only records and queues the action.

The automated suite uses the SDK fake gateway and synthetic fixtures. CI and
contributors do not need live provider keys, and a test must never call an
external model endpoint.
