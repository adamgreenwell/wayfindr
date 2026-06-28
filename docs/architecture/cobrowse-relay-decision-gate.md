# Cobrowse Relay Decision Gate

[ADR 0005](../decisions/0005-cobrowse-shared-page-state.md) anticipates that a
separate, higher-throughput cobrowse relay "may be introduced later for
high-throughput, ephemeral streams without moving auth, consent, audit, or
account ownership out of Laravel." This document defines *when* that becomes
warranted, so the decision is driven by real dogfood data instead of intuition.

The default remains: **do not add a relay.** Reverb plus the bounded
shared-page-state transport is the baseline, and it must stay good enough for a
small single-server install. A relay is only justified when the signals below
show that the baseline is failing real cobrowse sessions under real load.

## Non-Negotiable Constraints

A relay, if introduced, must not change these. They are not part of the gate;
they are conditions on any relay design:

- Laravel keeps authentication, visitor consent, audit, payload-budget
  enforcement, masking-as-source-of-truth, and account/site ownership.
- Consent ordering is unchanged: nothing streams before explicit visitor consent.
- The relay carries only already-sanitized, already-masked, bounded page state.
  It is a transport optimization, not a new data path.
- Telemetry, drop policy, and degraded-state honesty continue to apply.

If a proposed relay cannot hold all of these, it is out of scope regardless of
the metrics.

## Metric Sources

Every signal below maps to data the transport layer already collects, so the
gate can be measured without speculative instrumentation:

| Signal | Source |
| --- | --- |
| Round-trip latency (`rtt_ms`, `max_rtt_ms`) | cobrowse telemetry intake (`metadata.telemetry`) |
| Dropped batches | telemetry + `CobrowseTransportPressure` |
| Skipped mutations | mutation batches + `CobrowseTransportPressure` |
| Reconnects | cobrowse telemetry intake |
| Replay drift ratio | `CobrowseReplayDrift` (unresolved / addressable) |
| Resync exhaustion | telemetry `resync_attempts_exhausted` |
| Concurrent active sessions | `CobrowseSession` rows in an active transport state |
| Transport state mix | `CobrowseTransportReadiness` (live / degraded / reconnecting / stale) |

Latency percentiles assume aggregation across telemetry samples; until a p95 is
computed centrally, `max_rtt_ms` per session is a conservative proxy.

## Gate Thresholds

Measured over a rolling **7-day dogfood window**, across sessions that reached
consent, on an install that already passes operator readiness (queue, scheduler,
Reverb, HTTPS all green — so the baseline is not merely misconfigured):

1. **Latency** — p95 flush-to-render `rtt_ms` exceeds **1,500 ms**, or per-session
   `max_rtt_ms` exceeds **3,000 ms** for more than 10% of active sessions.
2. **Drop/skip pressure** — more than **10%** of consented sessions report any
   dropped batches, or aggregate skipped mutations exceed **5%** of submitted
   mutations, despite payload-budget tuning.
3. **Reconnect churn** — median **reconnects per active session per 10 minutes**
   exceeds **2**.
4. **Replay drift** — `CobrowseReplayDrift` reports `drifting` (recommend-resync)
   on more than **15%** of sessions that were not themselves degraded by
   drop/skip pressure (i.e. drift not explained by known loss).
5. **Concurrency ceiling** — a single-server reference install cannot hold more
   than a documented number of concurrent live sessions before transport state
   tips majority `degraded`/`reconnecting`, and that ceiling is below real
   dogfood demand.

**Decision rule:** evaluate a relay when **two or more** signals breach their
threshold and stay breached across the window, *and* the breach is not
attributable to a fixable misconfiguration or a single pathological host page.
One signal breaching is a tuning task (budgets, batching, throttling), not a
relay.

## What "Evaluate" Means

Hitting the gate does not auto-approve a relay. It opens a scoped decision:

- Confirm the breach is load/transport, not configuration or a single host page.
- Try baseline tuning first (payload budgets, flush cadence, batch sizing,
  snapshot resync policy) and re-measure.
- Only if tuning cannot bring signals back under threshold, design a relay that
  honors every non-negotiable constraint above, and record it as a new ADR that
  supersedes the relevant part of ADR 0005.

The goal of this gate is to keep the transport story honest: ship the boring
baseline, watch real numbers, and let measured pain — not anticipation — justify
new infrastructure.
