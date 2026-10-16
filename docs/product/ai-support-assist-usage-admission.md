# AI Support Assist usage admission foundation

This first backend slice of [#1174](https://github.com/adamgreenwell/wayfindr/issues/1174)
supplies the usage ledger and transport admission seam needed by the remaining
[#1168](https://github.com/adamgreenwell/wayfindr/issues/1168) integration. The
[behavior contract](ai-support-assist-contract.md) remains authoritative.

## Scope and execution gate

The ledger reserves request/token allowance, admits one worker and one dispatch,
reconciles reported usage, and bounds abandoned holds. It stores identifiers,
limits, counters, timestamps and sanitized outcomes, without prompts, provider
output, credentials or endpoint copies. Connection rotation never resets usage.

The unscoped outbound binding remains unavailable. The
[#1168 integration](ai-support-assist-provider-policy.md) constructs a fresh
ledger-backed scoped admission for each authorized request. Its actual SDK JSON
and session enforce the reserved input/output bounds. Proposal execution proves
current management authority and the exact proposed revision; support execution
proves fresh capability, source, generation and connection identity. The ledger
alone grants none of those permissions. Automatic preparation remains #1170.

## Allowance policy

Installation policy supplies daily request/token ceilings, concurrent dispatch
capacity and a maximum output-token cap. Its source defaults deny new attempts.
Each account may inherit a ceiling with null, deny attempts with zero, or narrow
the installation ceiling with a positive value. Reads clamp account settings
after an operator lowers installation policy. Limit edits use fresh account
authority and remain independent of connection selection, credentials and
enablement. The administration UI belongs to #1169.

The ledger returns an internal, content-free summary for later authorized
surfaces. Account UI must expose its own account usage; installation totals are
operator-only. Request/token counters are observed or conservatively reserved usage;
they are not provider invoices or a claim about dollar cost.

## Attempt lifecycle

1. Reserve one request and conservative input-plus-output tokens against both
   installation and account periods before enqueueing. Equivalent work reuses
   the existing receipt, including terminal work, so an unchanged open cannot
   retry or charge again. Queue payloads carry IDs and revisions only.
2. Claim the attempt before DNS. An opaque nonce admits one preflight worker;
   duplicate or expired claims cannot dispatch.
3. Begin dispatch immediately before invoking the terminal HTTP handler. Recheck
   the receipt, limits and concurrency. Known-unsent work crossing midnight must
   release its old hold and reserve today atomically. If admission fails, commit
   cancellation and release without transmitting.
4. Convert holds into conservative charges and acquire concurrency once. The
   dispatch receipt binds the exact account, source, revision, purpose, nonce and
   original UTC dispatch day. A second dispatch of the same attempt is refused.
5. A known-unsent cancellation releases its hold. Missing or partial usage,
   timeout and disconnect retain the conservative debit. Complete reported input
   and output usage may adjust tokens once in the original dispatch period.
   Genuine reported zero must remain distinguishable from missing usage. Partial
   observed overruns raise the conservative debit without granting a credit. Known
   usage with a failed outcome still preserves its failure cooldown.
6. Expire abandoned work in bounded batches. Unknown dispatches retain their
   concurrency lease until the fixed dispatch expiry, unless complete usage
   safely completes them sooner. Expiry and reconciliation release concurrency
   at most once, including after midnight or account deletion.

The transport reports an unknown usage outcome before returning its server-only
HTTP result. An adapter can then reconcile explicit provider usage using the
exact attempt and dispatch nonce. The receipt must never become an agent-facing
response, queue payload or audit capability. If outcome persistence fails, the
transport withholds its usable result and expiry remains the recovery path.

The existing scheduler runs bounded expiry every minute, processing at most
1,000 receipts and prioritizing expired concurrency leases. Claim and dispatch
also fence their own target's expiry under locks, independent of sweep backlog.
Global cleanup never runs inside the transport's claim/dispatch critical path.
Guarded result publication and final usage settlement share an installation-first
transaction. Invalid or discarded output keeps its failure cooldown; settlement
failure rolls publication back. This transaction has one attempt and never
retries a provider call. Ledger reconciliation remains idempotent.

When an explicit manual retry replaces a stale artifact generation, the ledger
retires only its latest equivalent obsolete receipt after checking that the new
generation still owns the artifact. Known-unsent reservations/claims release their
holds and permit that requested replacement, preserving any existing retry boundary.
Dispatched work keeps its conservative debit, unexpired concurrency lease and
failure/provider cooldown; targeted cleanup releases an expired lease without
waiting for the global sweep. A newer generation or non-equivalent receipt is
never retired by that retry. Late reconciliation stays bound to the old receipt.

Explicit Refresh/Try again may replace a named terminal receipt. Concurrent
replacements coalesce; failed/expired/unknown work requires at least a 60-second
database-enforced cooldown. This is a backend seam for #1170, whose generation
cache and automatic/manual workspace behavior remain to be integrated. No vendor
fallback or retry-on-open is authorized.

## UTC windows and locking

Allowance periods are database-time UTC days: [00:00 UTC, next 00:00 UTC). Sample
wall time after acquiring locks. PostgreSQL uses `clock_timestamp()` because
transaction-start `now()` can become stale while a worker waits. SQLite also
reads database UTC time; an unavailable clock fails closed without PHP/browser
time fallback. Tests inject a clock only for deterministic midnight scenarios.

The lock order is installation usage mutex, Account where present, account usage
scope, deterministically ordered period rows, then attempt. No lock spans DNS or
HTTP. Future ensure code must acquire the installation mutex before locking the
account; calling the ledger while already holding the account would invert this
order. PostgreSQL ledger transactions bound lock waits to five seconds and
individual statements to ten seconds; failures are sanitized and cannot transmit.
Account-limit and connection writers lock the account without acquiring
the usage mutex. Expiry processes one account per transaction in the same order.

Period identity never includes a connection revision. Already-dispatched work,
late reconciliation and duplicate receipts affect only the original dispatch
day, never a newer day's allowance. Concurrency uses stable scope records across
all periods, so midnight cannot erase a still-running call. Content-free active
receipts and installation charges survive account deletion for safe expiry and
reconciliation; account configuration limits may be deleted with the account.

## Remaining qualification

This source foundation uses synthetic fixtures and fake/local processors. It
does not establish deployed model quality or a live-provider result. #1168
supplies guarded SDK/probe/discovery execution and nullable raw usage parsing.
Legacy result defaults do not prove reported zero for guarded execution.
#1169 owns the account UI, #1170 the shared generation/cache lifecycle, and #1175
the integrated account-visible audit/export/retention surfaces. Keep #1174 open
until its complete acceptance criteria are qualified.

Release, deployment, live-provider tests and production activation remain
separate authorized steps.
