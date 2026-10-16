# AI Provider Policy and Guarded Integration

This implements [#1168](https://github.com/adamgreenwell/wayfindr/issues/1168)
with the [account connection foundation](ai-support-assist-account-connections.md),
[usage admission](ai-support-assist-usage-admission.md) and
[Assist contract](ai-support-assist-contract.md). Account probes, optional model
discovery and the four explicit manual conversation tasks use scoped guarded
execution. Account/site UI and automatic preparation remain downstream work.

## Authority and destination policy

`AiNetworkPolicy` reads operator-owned `wayfindr.ai.network` configuration.
`public-only` is the default: requests need public HTTPS, and every resolved A/AAAA
address must pass the existing public-address policy. A mixed answer set is
refused. Public-only mode never honors a private grant. Neither `APP_ENV` nor
managed-update ownership is network authority, and an account form cannot write
these operator settings.

Self-hosted operators may deliberately choose `operator-private` and configure
an exact grant containing account ID, connection source, scheme/host/port origin
and approved IP addresses. Every answer must match that grant. Grants permit
only loopback, RFC1918 and IPv6 unique-local model addresses; metadata/link-local,
mapped/transition, wildcard and ambiguous addresses remain denied. A grant for
one account or installation reference never authorizes another account/source.
Hosted deployments serving untrusted accounts must retain public-only policy.

For example, this illustrates a native local model route for account 42; it is
not a deployment or activation recipe:

```dotenv
WAYFINDR_AI_NETWORK_MODE=operator-private
WAYFINDR_AI_PRIVATE_DESTINATIONS='[{"account_id":42,"source":"account","origin":"http://localhost:11434","ips":["127.0.0.1","::1"]}]'
```

Loopback refers to the application's own network namespace, including inside a
container. A real deployment must approve the actual model route/addresses.
The guarded resolver maps exact `localhost` to both `127.0.0.1` and `::1` without
a DNS lookup, so its grant must list both loopbacks. Use the corresponding IP
literal for a single-stack route. Other names use DNS; hosts-only aliases are
unsupported.
DNS subprocesses select a PHP CLI executable independently of the web SAPI and
fail closed if none is available. The self-hosting image carries the same PHP
CLI runtime used by its queue, scheduler and Reverb processes.
These environment settings require the normal config/worker restart lifecycle;
this slice provides no account-editable private-network switch.

The policy validates the configured base and actual wire URL, binding the request
to that origin and base path. Userinfo, query/fragment, trailing-dot/escaped hosts,
ambiguous IP literals and path escapes are rejected. DNS is resolved afresh under
a monotonic deadline; approved addresses are pinned while retaining the original
hostname for TLS. Policy revisions use a keyed identity over canonical operator
configuration and provider routes; private topology is not debug/audit output.

## Guarded transport and execution

`AiHttpTransport` exposes a narrow send operation, with no caller-supplied Guzzle,
handler, stream, proxy, TLS or raw-cURL options. It uses a fresh cURL-only terminal
handler, no redirect middleware, verified TLS, disabled proxies and address pins.
Its owned single-use cURL factory prevents internal handler retries. Response
headers are capped at 32 KiB and 128 lines before retention, cumulatively across
interim responses and trailers; every received line also checks the deadline.
All redirects fail without forwarding credentials. Request origin/Host must match
the connection. The 20-second total deadline includes DNS (itself capped at five seconds),
with connection time capped at five seconds. Request bodies are capped at 64 KiB and
received bodies at 256 KiB, including chunked responses. The transport requests identity encoding and rejects
encoded responses, avoiding decompression expansion. Errors contain stable reason
codes without request bodies, credentials, raw provider responses or exception
chains.

The unscoped `AiOutboundAdmission` binding remains unavailable. A fresh
`AiScopedOutboundAdmission` belongs to one server-owned execution identity and
checks authority inside the ledger's installation → Account transactions before
DNS and immediately before HTTP. Proposal probes bind actor, exact proposal,
test generation and processing policy; discovery permits an otherwise complete
draft without a selected model. Support tasks bind their actor, artifact,
generation, capability, connection and keyed context fingerprint. The actual
immutable prompt must match the reservation, and current source is rechecked.

Each fresh SDK driver receives a fresh guarded gateway. The dedicated
`GuardedAiPendingRequest` bypasses Laravel's global HTTP factory, callbacks,
retries and fakes; only `AiHttpTransport` performs I/O. Final SDK JSON and scoped
sessions independently enforce the exact model, text-only inputs, one step,
output cap, stateless storage and no tools/streaming/continuations. Operator
probes, offline evaluation and the untouched installation-default manual path
retain their existing explicit processing behavior. Selecting an account or
installation-reference connection uses the guarded path. Upgrade never starts
automatic preparation or silently selects a new processor.

| Driver | Text route relative to base | Output cap field |
| --- | --- | --- |
| Anthropic | `/messages` | `max_tokens` |
| Gemini | `/interactions` | `generation_config.max_output_tokens` |
| OpenAI | `/responses` | `max_output_tokens` |
| OpenRouter / compatible | `/chat/completions` | `max_tokens` |
| Ollama | `/api/chat` | `options.num_predict` |

Manual controllers reserve before enqueueing, after releasing their existing
Account-first transaction. Their bound covers encoded instructions/input plus
4 KiB of SDK envelope and 1 KiB of processor framing, capped at 66,560 tokens.
Direct synthetic probes reserve final JSON bytes plus 1 KiB. The final body is
limited to 64 KiB and must fit the reservation. These are conservative byte
bounds, not characters-per-token estimates. Explicit output caps are at most
2,048 for manual tasks and 64 for probes, narrowed by installation/account
policy. These defaults can produce truncated task output on reasoning models;
connectivity does not establish output quality.

Raw nullable usage is parsed before SDK default-zero conversion. Anthropic
input includes explicitly reported cache-read/write components; Gemini output
includes reported thought tokens. Missing or malformed components stay unknown.
Partial observed overruns increase the conservative debit; complete usage can
adjust it only in the original dispatch period. Usage above a claimed bound is
charged and the output withheld. Task validation precedes publication. Guarded
publication and final settlement occur atomically under installation-first
locks; a discarded result retains failure cooldown, and a failed usage write
rolls back publication. No database lock spans DNS or HTTP.

HTTP key/model/rate-limit failures use sanitized reason codes. Numeric and RFC
HTTP-date `Retry-After` advice becomes numeric metadata only, bounded to one year;
absolute advice uses the database terminal anchor so delayed parsing cannot
shorten it. Equivalent work shares cooldown across authorized viewers. Stored
provider/model provenance comes from the selected request configuration, never
an arbitrary provider response label. `test_failure_code` supplies an actionable
content-free cause to the future administration UI. Raw provider errors,
headers, catalogues, credentials and prompts are not audit metadata.

## Synthetic tests and model discovery

Connection tests use one fixed synthetic prompt, a bounded acknowledgement and
fresh proposal/actor checks. They never receive transcript/article/visitor text.
A fresh successful receipt proves connectivity and usable acknowledgement for
that exact configuration; model quality needs separate qualification.

Discovery makes one guarded GET to `/models` (`/api/tags` for Ollama), with no
query, pagination or response-URL following. It returns at most 100 unique
bounded identifiers and preserves manual entry. Anthropic/Gemini may return
only their first page. Gemini removes only its catalogue's literal `models/`
resource prefix; version suffixes remain exact. Unsupported, oversized or
malformed catalogues produce sanitized failure and leave manual entry available.
OpenRouter's full query-free catalogue can exceed the 256 KiB response cap.
Catalogue membership does not attest that a model supports the selected API.
Discovery charges request/concurrency allowance and reports known zero generated
tokens. Results are reauthorized against the proposal before return.

## Provider and extension contract

The route catalogue matches the installed Laravel AI SDK defaults:

| Driver | Default base | Credential/route contract |
| --- | --- | --- |
| Anthropic | `https://api.anthropic.com/v1` | Exact model and account key; no tools. |
| Gemini | `https://generativelanguage.googleapis.com/v1beta` | Exact model and account key; no tools. |
| OpenAI | `https://api.openai.com/v1` | Exact model and account key; storage remains disabled. |
| OpenRouter | `https://openrouter.ai/api/v1` | Exact model and upstream; preserve zero-retention requirement, attribution and no fallback. |
| Ollama | `http://localhost:11434` | Local/private routes require an exact operator grant in the guarded path. |
| OpenAI-compatible | Explicit endpoint required | Exact model; key may be absent where the destination's own boundary permits it. |

Exact manual model entry remains available alongside optional discovery. No claim is made that all proprietary AI
platforms support these protocols. Adding another adapter requires explicit
base/path and authentication mapping, bounded structured output validation,
per-call configuration, guarded transport and usage admission, sanitized failures
and synthetic isolation/redirect/DNS/credential tests. No implicit failover or
processor change is permitted. Discovery availability never proves model quality.

## Verification and source references

Tests use fake resolvers/handlers and synthetic keys only. Qualification covers
mixed answers, rebinding, IPv4/IPv6, precise private grants, hostile URLs/Host,
redirects, size/deadline limits, default-denied admission and SDK route drift.
The complete Assist UI, automatic preparation and full-flow qualification remain
open in their downstream slices. No live provider test, release, deployment or production activation is
established by this foundation.

Address pinning follows [libcurl CURLOPT_RESOLVE](https://curl.se/libcurl/c/CURLOPT_RESOLVE.html).
The transport owns options and handler behavior described in the
[Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html)
and [handler documentation](https://docs.guzzlephp.org/en/stable/handlers-and-middleware.html).

Known IPv6 metadata destinations are documented by
[AWS](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/instancedata-data-retrieval.html)
and [Google Cloud](https://docs.cloud.google.com/compute/docs/metadata/overview);
the private-route policy excludes them as well as link-local metadata ranges.

Discovery and usage contracts follow the official
[Anthropic models reference](https://platform.claude.com/docs/en/api/models/list),
[Gemini models](https://ai.google.dev/api/models) and
[Interactions thinking limits](https://ai.google.dev/gemini-api/docs/thinking/),
[OpenAI models reference](https://developers.openai.com/api/reference/resources/models/methods/list),
[OpenRouter catalogue](https://openrouter.ai/docs/api/api-reference/models/list-all-models-and-their-properties),
and [Ollama tags](https://docs.ollama.com/api/tags). API extensions must keep
synthetic tests for actual installed SDK request shapes and nullable usage.
