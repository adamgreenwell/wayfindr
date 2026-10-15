# AI Provider Policy and Transport Foundation

This is the first implementation slice of [#1168](https://github.com/adamgreenwell/wayfindr/issues/1168),
following the merged [account connection foundation](ai-support-assist-account-connections.md)
and [Assist contract](ai-support-assist-contract.md). It adds destination policy
and a guarded transport for later account adapters. It does not yet connect that
transport to SDK gateways, execute account probes, or discover provider models.
The existing account-call block and unavailable probe executor remain in place.

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

## Guarded transport and remaining gates

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

The transport first calls `AiOutboundAdmission` for the exact server-owned
attempt. Its default implementation refuses before DNS or network activity.
[#1174](https://github.com/adamgreenwell/wayfindr/issues/1174) must provide atomic
usage/concurrency reservations and reconciliation before new probes, discovery
or account support calls can execute. Endpoint safety alone grants no execution
authority. SDK/HTTP test fakes do not lift the production gate.

Remaining #1168 work must bind guarded gateways to each fresh SDK driver, connect
synthetic proposed-revision probes to current management authority and usage
admission, and implement optional bounded model discovery through this same
boundary. A proposal probe must recheck the proposed snapshot/test generation;
the active-connection check cannot authorize an inactive proposal. Existing
installation-owned manual assistance retains its current path until that
integration is deliberately qualified.

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

The optional discovery interface currently returns unavailable, so exact manual
model entry remains the required path. No claim is made that all proprietary AI
platforms support these protocols. Adding another adapter requires explicit
base/path and authentication mapping, bounded structured output validation,
per-call configuration, guarded transport and usage admission, sanitized failures
and synthetic isolation/redirect/DNS/credential tests. No implicit failover or
processor change is permitted. Discovery availability never proves model quality.

## Verification and source references

Tests use fake resolvers/handlers and synthetic keys only. Qualification covers
mixed answers, rebinding, IPv4/IPv6, precise private grants, hostile URLs/Host,
redirects, size/deadline limits, default-denied admission and SDK route drift.
Network policy/probe/discovery integration and full-flow qualification remain
open. No live provider test, release, deployment or production activation is
established by this foundation.

Address pinning follows [libcurl CURLOPT_RESOLVE](https://curl.se/libcurl/c/CURLOPT_RESOLVE.html).
The transport owns options and handler behavior described in the
[Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html)
and [handler documentation](https://docs.guzzlephp.org/en/stable/handlers-and-middleware.html).

Known IPv6 metadata destinations are documented by
[AWS](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/instancedata-data-retrieval.html)
and [Google Cloud](https://docs.cloud.google.com/compute/docs/metadata/overview);
the private-route policy excludes them as well as link-local metadata ranges.
