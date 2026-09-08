# 0004: AI As An Assistive Product And Development Layer

Date: 2026-05-23

## Decision

Wayfindr may use Laravel's first-party AI tooling as an assistive layer for
product features and project development, but AI must not become a buzzword,
default behavior, or substitute for clear support workflows.

AI features should be optional, disabled unless configured, and designed around
specific user outcomes:

- help agents understand conversations faster,
- help agents draft better replies while keeping humans in control,
- help teams summarize and route support work,
- help self-hosters and contributors inspect and operate Wayfindr safely.

Wayfindr should prefer Laravel-native AI primitives when they fit the job:
agents, structured output, queueing, streaming, provider configuration,
testing fakes, and MCP servers/tools. If the Laravel layer is still too new for
a given production path, Wayfindr should isolate that integration behind a
small internal boundary rather than scattering provider-specific code through
the product.

## Rationale

Wayfindr is a support platform. Support conversations already contain rich
context, uncertainty, and time pressure, which makes them a natural place for
thoughtful AI assistance.

That does not mean every workflow needs AI. The product should stay useful when
AI is unavailable, unconfigured, expensive, or inappropriate for a team's data
policy. Users should be able to run Wayfindr as a normal Laravel support app
without configuring model providers.

The project is Laravel-first, so Laravel's AI SDK and MCP tooling are a strong
fit when Wayfindr needs AI capabilities:

- the APIs match the Laravel application shape,
- provider credentials and defaults can live in normal Laravel config,
- heavy AI work can run through Laravel queues,
- structured output can keep AI features predictable,
- built-in fakes make AI behavior testable without calling live providers,
- MCP can expose safe project and runtime context to development agents.

## Guardrails

- AI is assistive by default. It may draft, summarize, classify, search, or
  explain; it should not send customer-facing replies or take irreversible
  actions without a human approving the result.
- AI must be optional for self-hosters. Missing provider keys should not break
  the core chat, cobrowse, ticketing, or admin experience.
- AI must respect support privacy. Prompts should use the minimum context
  needed, honor masking and retention rules, and avoid sending secrets,
  credentials, payment data, or unnecessary personal data to model providers.
- AI output should be marked as generated or suggested when shown to agents.
- AI actions should be auditable when they affect support records.
- AI behavior should be covered by automated tests using fakes, fixtures, and
  structured output assertions instead of relying on live model calls.
- AI provider choices should remain configurable. Wayfindr should not hard-code
  one commercial provider into product logic.
- AI should not be used as marketing filler. If a feature is not materially
  better with AI, it should stay a normal deterministic workflow.

## Initial Product Uses

The first product-facing AI features should stay low-risk and agent-controlled:

- draft an agent reply for the current conversation,
- summarize a conversation timeline,
- suggest a ticket title, priority, tags, or next step,
- suggest relevant knowledge snippets once a knowledge base exists,
- transcribe or summarize audio only after explicit file and retention controls
  exist.

Autonomous support replies, broad customer-data analysis, and background
training/export workflows are out of scope until the privacy, audit, consent,
and evaluation model is much more mature.

## Initial Development Uses

AI can also improve how Wayfindr is built and operated:

- expose local MCP resources for routes, config shape, docs, and smoke-test
  helpers,
- provide safe diagnostic tools for local and staging support data,
- generate draft operational summaries from deployment or smoke-test evidence,
- help contributors understand Wayfindr's domain model without granting access
  to secrets or private business notes.

Development-facing AI tools should default to read-only access. Any write or
mutation capability must be explicit, narrow, and documented.

## Reassessment On 2026-09-08

[Issue #764](https://github.com/adamgreenwell/wayfindr/issues/764) completed the
evaluation preconditions for reconsidering autonomous visitor replies. Wayfindr
now has a provider-free regression harness, an explicit confidence and refusal
policy, and a private opt-in provider-capture path. On the controlled Forge
stage, a fresh nine-case synthetic run through `openrouter/azure` with
`openai/gpt-5.2` passed every fixture and policy threshold: answer and refusal
metrics were 100%, unsafe and overconfident-answer rates were 0%, and the Brier
score was 0.31.

That is materially better evidence than existed when this ADR was written, but
it is not production approval. It is one complete run of one model and route
over nine synthetic cases. It does not measure model drift, wider adversarial
coverage, multilingual behavior, stale or conflicting knowledge, or operation
by a representative set of self-hosters. The product roadmap also keeps the AI
tier behind the core support product and 1.0 hardening work.

**Decision: reaffirm the deferral of autonomous visitor replies.** No
visitor-facing answer-agent implementation should begin from this evaluation
result. This is not a permanent prohibition; a future ADR may permit a bounded
slice after the 1.0 core is proven and the proposed runtime design can show:

- answers grounded only in published same-site articles, with citations;
- default human handoff when confidence or grounding is insufficient;
- per-site enablement that is off by default and plainly disclosed to visitors;
- an account-visible audit record for every autonomous reply;
- no customer-side effects, transcript corpus, or training workflow; and
- a broader versioned suite with repeated current-model results on every route
  the project proposes to support.

Until then, the existing agent-controlled copilot remains the approved product
boundary. Reaffirming the deferral completes the decision requested by #764;
it does not claim that the deferred answer-agent work shipped.

## Consequences

- AI package installation, provider environment variables, queue/process needs,
  and Forge notes should be documented when the first AI runtime slice lands.
- AI feature slices should include tests that run without live provider keys.
- Product documentation should describe what context an AI feature uses and who
  approves the result.
- Public examples should use fake or synthetic support data.
- Private customer transcripts, provider keys, prompts containing sensitive
  data, and evaluation datasets with real user information must not be committed
  to the public repository.
