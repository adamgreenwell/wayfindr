# Architecture Overview

Wayfindr is planned as a Laravel-first support platform with portable client integrations.

## Core Components

- Laravel server application.
- Agent workspace.
- Browser widget SDK.
- Realtime WebSocket layer.
- Cobrowse page-state telemetry and relay path.
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
6. After consent, the widget streams sanitized page state to the agent viewer.
7. The conversation can become a ticket.

## Architecture Biases

- Start as a modular monolith.
- Keep integrations thin.
- Keep masking client-side.
- Treat cobrowse as shared page state instead of video streaming.
- Measure cobrowse latency and payload pressure before adding heavier transport.
- Keep public APIs stable only after the prototype proves them.
- Avoid microservices until operational pressure justifies them.
