# Data Model

Wayfindr starts with a small relational model owned by the Laravel server. The model is intentionally conservative: plain tables, Eloquent relationships, string statuses, and JSON metadata where the product shape is not stable yet.

## Core Records

- `accounts`: tenant boundary for a support team.
- `users`: Laravel users attached to one account with a starter `account_role` of `owner`, `admin`, or `agent`, optional `platform_role` instance authority, and an explicit online/away routing status for new automatic assignments.
- Platform/instance operator authority should not be overloaded onto `account_role`. The first scaffold uses `users.platform_role` for explicit operator access while keeping it separate from account support access.
- `sites`: install targets owned by an account. Each site has a public key used by widgets and integrations.
- `site_user`: support-agent access for sites. Empty site membership means account-wide fallback for early installs; explicit rows narrow the support queue to assigned agents.
- `site_routing_states`: one durable row per configured site containing separate last-agent cursors for conversation and ticket round-robin assignment.
- `visitors`: anonymous or identified people seen on a site.
- `conversations`: chat/support sessions between a visitor and support agents. Each conversation has a unique support code for later lookup.
- `conversation_messages`: messages or system events inside a conversation. The sender is polymorphic so visitors, agents, and future system actors can share one message stream.
- `conversation_message_attachments`: private message-scoped files. Rows carry
  denormalized account, site, conversation, message, upload, scan, and
  `storage_disk` state so pending uploads can bind atomically and old storage
  surfaces can coexist after a disk change.
- `conversation_read_states`: per-user/per-conversation read markers used by
  calm unread and unattended-alert behavior.
- `tickets`: durable support records that may be created from a conversation.
  Tickets can carry provider-neutral category values for local triage without
  depending on an external issue tracker.
- `sla_policies`: optional account targets for first response and resolution,
  keyed by support priority.
- `sla_clocks`: persisted business-time consumption and warning, breach,
  satisfaction, or cancellation history for a conversation or ticket target.
- `ticket_labels`: account-owned labels assignable to tickets.
- `reply_templates`: account-owned snippets that help agents answer common
  support questions without changing the ticket model.
- `ticket_external_links`: provider-neutral records that connect a local
  Wayfindr ticket to an external issue tracker record without making that
  external provider the source of truth.
- `external_issue_provider_connections`: account-owned provider connection
  records with encrypted credential storage and explicit capability flags.
- `site_external_issue_projects`: site-scoped mappings from a Wayfindr site to
  a provider project or repository.
- `cobrowse_sessions`: consent-based cobrowsing attempts tied to a
  conversation, site, and visitor. Early connection telemetry is kept in
  `metadata.telemetry`, the latest passive page state is kept in
  `metadata.page_state`, the latest sanitized DOM snapshot is kept in
  `metadata.snapshot`, a bounded recent mutation buffer is kept in
  `metadata.mutations`, and the active cobrowse intake limits are kept in
  `metadata.payload_budget`, while the transport shape is still changing.
- `operator_readiness_confirmations`: operator acknowledgements for readiness
  checks that require human confirmation.
- `operator_settings`: database-backed instance settings for mail, attachment
  storage, malware scanning, backups, and related operator-controlled values.
- `backup_runs`: operator-triggered backup and restore history with status,
  destination, artifact, and error metadata.
- `break_glass_grants`: scoped, reasoned, time-bound, read-only platform
  operator access grants for support events.
- `audit_events`: append-style records for important user, visitor, or system actions.

See [../privacy/data-inventory.md](../privacy/data-inventory.md) for the
operator-facing data inventory and retention posture.

## Design Notes

- Support lifecycle fields stay portable strings in the database, while PHP-backed enums validate every `Conversation` and `Ticket` status or priority model write. Automation rules can therefore persist stable scalar values without accepting typo-only states.
- Visitor identity supports both `anonymous_id` and optional host-provided `external_id`. Public widget requests bootstrap a signed visitor token before they can create conversations or read/write visitor messages.
- Cobrowsing state is separate from conversations because consent, start, end timing, connection telemetry, visitor page state, sanitized page snapshots, and mutation diagnostics need their own lifecycle.
- Attachments are separate from messages because upload, scan, bind, storage,
  retention, and streaming authorization have their own lifecycle. The row's
  denormalized scope columns are a defense-in-depth check, not a replacement
  for re-deriving the conversation/site/account boundary on every read.
- External ticket integrations should link to Wayfindr tickets through explicit
  local records and audit events. Provider-specific identifiers, capabilities,
  and sync metadata should stay outside the core `tickets` table.
- Provider credentials belong to the account, while project routing belongs to
  the site. This lets one account support many sites without leaking unrelated
  project destinations across site boundaries.
- External links store provider, project or repository key, external ID or key,
  URL, sync status, last sync time, and metadata separately from the canonical
  Wayfindr ticket lifecycle.
- Audit actors and subjects are polymorphic so the model can track agent, visitor, conversation, ticket, and cobrowse events without creating a new audit table per feature.
- Automatic assignment configuration lives in `sites.settings.routing`, while
  cursor state has its own locked row because operational rotation state should
  not rewrite the site's whole JSON settings document on every arrival. Agent
  capacity is counted account-wide from non-closed assigned conversations.
- Operator settings are database-backed overrides so operators can change
  runtime behavior through reviewed application flows. Browser settings must not
  rewrite `.env`.
- Backup runs record the application-level backup/restore attempt; they do not
  replace host, database, object-storage, or VM-level durability evidence.
- Break-glass grants outlive the content they exposed and audit every
  transition; platform operator authority stays separate from account support
  access.
- Release identity and upgrade state live outside tenant data: official images
  bake their identity, source builds derive development identity, and
  self-hosted installs persist their current version state for upgrade checks.
- Integration packages should stay thin and write through Laravel APIs rather than owning product persistence directly.
- Platform operator data should describe instance authority only. It should not grant account support visibility, customer content access, or site-access bypass by default.
