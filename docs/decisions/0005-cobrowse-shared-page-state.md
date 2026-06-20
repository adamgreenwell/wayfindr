# 0005: Cobrowse Uses Shared Page State

Date: 2026-05-23

## Decision

Wayfindr cobrowsing will begin as shared page state, not video or pixel streaming.

After explicit visitor consent, the browser widget will send sanitized page state to Wayfindr so an agent can reconstruct enough context to help the visitor. Early cobrowse work prioritizes consent, masking, telemetry, an initial sanitized DOM snapshot, viewport state, and bounded mutation streams before remote control.

## Rationale

Wayfindr is self-hostable, so cobrowse must work across a wide range of infrastructure. A small single-server install should still be useful, while larger deployments can add more realtime capacity later.

Shared page state gives Wayfindr better defaults for:

- privacy, because masking can happen before data leaves the visitor browser,
- bandwidth, because DOM and viewport changes are smaller than video frames,
- latency tolerance, because state can be batched, coalesced, and resynced,
- self-hosting, because the baseline does not require high-throughput media infrastructure.

## Consequences

- Cobrowse is not positioned as full remote desktop streaming.
- The first useful experience is passive co-viewing with agent guidance.
- Agents can request or end cobrowse sessions, but the visitor still has to grant consent before page state leaves the browser.
- The widget only shows cobrowse allow/decline controls after it sees an active agent request; visitors cannot create cobrowse sessions by granting consent on their own.
- Initial snapshots are sanitized in the visitor browser with explicit mask selectors and sensitive-field inference, then shown to agents as safe preview text plus an inert replay preview before richer observe mode exists.
- Mutation batches are compact and capped so small self-hosted installs can observe change pressure while the agent preview applies the safe mutation types it understands.
- Agents receive live Reverb notices when cobrowse page state, snapshots, or mutation batches arrive, then refresh the inert preview when they are ready.
- Transport telemetry is first-class so hosts can see latency, payload size, reconnects, and dropped updates before tuning infrastructure.
- Resync and transport audit events stay metadata-only. They may record request IDs, outcomes, reasons, timestamps, and safe counters, but never raw snapshot HTML, page text, mutation payloads, or visitor-entered content.
- Reverb can carry early cobrowse events while traffic is modest.
- A separate cobrowse relay may be introduced later for high-throughput, ephemeral streams without moving auth, consent, audit, or account ownership out of Laravel.
