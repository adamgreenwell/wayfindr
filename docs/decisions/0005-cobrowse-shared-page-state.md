# 0005: Cobrowse Uses Shared Page State

Date: 2026-05-23

## Decision

Wayfindr cobrowsing will begin as shared page state, not video or pixel streaming.

After explicit visitor consent, the browser widget will send sanitized page state to Wayfindr so an agent can reconstruct enough context to help the visitor. Early cobrowse work will prioritize consent, masking, telemetry, snapshots, viewport state, and compact mutation streams before remote control.

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
- Transport telemetry is first-class so hosts can see latency, payload size, reconnects, and dropped updates before tuning infrastructure.
- Reverb can carry early cobrowse events while traffic is modest.
- A separate cobrowse relay may be introduced later for high-throughput, ephemeral streams without moving auth, consent, audit, or account ownership out of Laravel.
