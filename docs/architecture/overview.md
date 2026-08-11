# Architecture Overview

Wayfindr is planned as a Laravel-first support platform with portable client integrations.

## Core Components

- Laravel server application.
- Agent workspace.
- Browser widget SDK.
- Realtime WebSocket layer.
- Cobrowse page-state, snapshot, telemetry, and relay path.
- Private attachment upload, scanning, storage, and streaming path.
- Ticketing and external issue integration layer.
- Operator settings, readiness, backup/restore, and break-glass support surfaces.
- Release identity, release manifests, upgrade guards, and advisory notices.
- Queue workers.
- Postgres database.
- Redis for cache, queues, and realtime support.
- Integration packages for WordPress, Laravel, React, and plain JavaScript.

See [data-model.md](data-model.md) for the initial Laravel-owned domain records.

## Runtime Flow

1. A host site loads the Wayfindr widget.
2. The widget boots with a site key and optional signed visitor identity.
3. The visitor appears in the agent workspace.
4. Visitor and agent can chat.
5. Agent can request cobrowsing consent.
6. After consent, the widget reports sanitized page state, an initial DOM snapshot, and bounded mutation batches so the agent viewer can render an inert replay preview and receive live update notices.
7. Visitor and agent messages may carry private attachments that stream through
   the Laravel app after account/site/conversation authorization is re-derived.
8. The agent can turn the conversation into a durable ticket and optionally link
   it to an external tracker.
9. Operators manage readiness, mail, attachment storage, malware scanning,
   backup/restore, and release upgrade guidance through first-class surfaces
   rather than hidden deployment folklore.

## Architecture Biases

- Start as a modular monolith.
- Keep integrations thin.
- Keep masking client-side.
- Treat cobrowse as shared page state instead of video streaming.
- Measure cobrowse latency and payload pressure before adding heavier transport.
- Keep mutation streams bounded while agent-side replay proves the event shape.
- Use Reverb for early cobrowse update notices before attempting in-place live DOM replay.
- Keep visitor messaging calm when realtime is unavailable; polling and manual refresh remain the degraded-mode path.
- Keep attachments private by default: no public disks, no direct object-store
  URLs unless a future opt-in explicitly designs that boundary.
- Keep operator settings database-backed and audited; do not rewrite `.env` from
  the browser.
- Keep release safety in the artifact, not only in the installer, because older
  installs upgrade with older installers.
- Keep public APIs stable only after the prototype proves them.
- Avoid microservices until operational pressure justifies them.
