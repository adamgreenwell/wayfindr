# Account AI Connection Foundation

This implements the backend foundation for [#1167](https://github.com/adamgreenwell/wayfindr/issues/1167)
under the [Assist behavior/data contract](ai-support-assist-contract.md).
Existing copilot assistance remains explicitly requested. This slice adds no
automatic preparation or account connection screen. The subsequent
[#1168 integration](ai-support-assist-provider-policy.md) supplies guarded account
execution, proposal probes and optional discovery.
The [delivery plan](ai-support-assist-feature-plan.md#backlog-and-delivery-order)
keeps actual destination policy/probes in #1168 and account/site UI in #1169.

## Authority

`manage_ai_connections` is a dedicated, delegable account permission. Built-in
owners/admins receive it; ordinary agents do not. Every lifecycle mutation locks
the account then rereads the actor and their current role/membership. Account
management does not grant conversation, ticket or site support access.

Proposing settings and synthetic testing require account management authority.
Activating/replacing the shared processor and re-enabling processing additionally
require current access to every affected site. Until narrower site enablement
exists, the service conservatively checks every account site, since each can use
the existing manual path. Disable/disconnect remains available without a probe.
Future site-policy writes must also check each affected site's access.

## Proposed, tested and active

`AccountAiConnections` owns the server-side lifecycle:

| Operation | Result |
| --- | --- |
| `propose(actor, accountId, input)` | Store a proposed processing revision; retain active processing. Name-only edits retain the test receipt. Provider/model/endpoint/upstream/key changes invalidate it. |
| `test(actor, accountId, proposedRevision)` | Claim one bounded synthetic test, release DB locks, invoke the server-owned executor, then recheck actor, revision, generation and processing policy before attesting success. |
| `activate(actor, accountId, proposedRevision)` | Check affected-site access and activate only the exact successfully tested revision under the account lock. It never enables automatic preparation. |
| `setEnabled(actor, accountId, enabled)` | Disable independently of testing; re-enabling checks affected-site access and retains the explicitly selected processor. |
| `disconnect(actor, accountId)` | Disable and erase both credential-bearing snapshots. Re-enabling requires a newly chosen, tested and activated connection. |

Browser input cannot supply a successful receipt. `GuardedAccountAiConnectionProbe`
uses a fixed synthetic sample with current proposal/generation/actor and atomic
usage checks. Processing policy binds guarded adapter and network revisions.
`discoverModels` accepts an otherwise complete draft without a model, performs
one bounded catalogue request, then rechecks authority and revision before return.
A content-free `test_failure_code` distinguishes actionable unavailable causes.
Tests qualify this lifecycle with synthetic terminal responses only.

One account row holds only proposed and active encrypted snapshots, opaque
revision/test identity and content-free state. Installation selection stores an
installation revision reference, never a copied operator key. Credentials are
excluded from model serialization and audit. The immutable resolved context
redacts JSON/debug output and refuses serialization, so queues carry IDs and
revision stamps. No credential or configuration history is retained.
Changing provider or endpoint never inherits a saved credential for the new
destination; the manager must explicitly supply a replacement or proceed
without a key where that driver permits it. Active processing remains unchanged.

Unreadable encrypted snapshots show an `unreadable` credential status and cannot
execute. Recovery requires explicit replacement or clearing of the credential;
it never inherits unreadable data or silently changes processor. A recovered
proposal must receive a new successful test before activation.

## Resolution and fences

`AgentCopilotConnectionResolver` resolves each account afresh. An untouched
account, including its first in-progress draft, has an explicit legacy
installation-default path. Selecting a tested source or disconnecting retires
that default eligibility. An unavailable selected source never falls back.

Installation resolution reads uncached authoritative operator values through
`OperatorSettings::freshValuesForGroup('ai')`. It neither applies overrides nor
changes shared global configuration. A failed store read or unreadable secret
blocks processing. Each SDK driver is constructed from explicit per-call values;
OpenRouter's upstream/ZDR/no-fallback options belong to that call's agent.

All four manual copilot requests stamp the resolved connection revision. Jobs
recheck it and requester authority before calling, then again before publishing
under the account lock. Main and fragment reads withhold obsolete results.
Fresh running attempts retain their erasure-visible claim during rotation;
replacing pending work cannot cause late generation writes to win.

Guarded account calls require a server-owned execution context and pre-enqueue
usage reservation. Final wire checks repeat the capability and source fences;
actual prompt text must match reserved context. Publication and settlement are
atomic in installation-first lock order. SDK/HTTP fakes never grant authority.
Operator probes and offline evaluation retain their installation path.

## Upgrade, deletion and rollback

The additive migration creates account storage and nullable connection stamps on
the four existing copilot stores. Old unversioned output is withheld and queued
work cancelled because it cannot prove its processor identity. Agents can still
make a fresh explicit manual request. No automatic request starts on upgrade.

Account deletion cascades connection storage. Copilot stamps join the existing
visitor export inventory; output remains in the existing erasure/retention
stores. Connections contain no visitor text. Rolling back drops only the new
connection table and stamp columns, removing account connection configuration;
it does not alter conversation content or publish/deploy anything.
