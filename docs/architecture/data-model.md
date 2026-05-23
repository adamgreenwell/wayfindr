# Data Model

Wayfindr starts with a small relational model owned by the Laravel server. The model is intentionally conservative: plain tables, Eloquent relationships, string statuses, and JSON metadata where the product shape is not stable yet.

## Core Records

- `accounts`: tenant boundary for a support team.
- `users`: Laravel users; currently treated as support agents and attached to one account.
- `sites`: install targets owned by an account. Each site has a public key used by widgets and integrations.
- `visitors`: anonymous or identified people seen on a site.
- `conversations`: chat/support sessions between a visitor and support agents. Each conversation has a unique support code for later lookup.
- `conversation_messages`: messages or system events inside a conversation. The sender is polymorphic so visitors, agents, and future system actors can share one message stream.
- `tickets`: durable support records that may be created from a conversation.
- `cobrowse_sessions`: consent-based cobrowsing attempts tied to a conversation, site, and visitor. Early connection telemetry is kept in `metadata.telemetry` while the transport shape is still changing.
- `audit_events`: append-style records for important user, visitor, or system actions.

## Design Notes

- Status fields are strings instead of database enums so early product states can change without database-type churn.
- Visitor identity supports both `anonymous_id` and optional host-provided `external_id`. Public widget requests bootstrap a signed visitor token before they can create conversations or read/write visitor messages.
- Cobrowsing state is separate from conversations because consent, start, end timing, and connection telemetry need their own lifecycle.
- Audit actors and subjects are polymorphic so the model can track agent, visitor, conversation, ticket, and cobrowse events without creating a new audit table per feature.
- Integration packages should stay thin and write through Laravel APIs rather than owning product persistence directly.
