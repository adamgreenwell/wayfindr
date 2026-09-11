# Data Model

Wayfindr starts with a small relational model owned by the Laravel server. The model is intentionally conservative: plain tables, Eloquent relationships, string statuses, and JSON metadata where the product shape is not stable yet.

## Core Records

- `accounts`: tenant boundary for a support team.
- `users`: Laravel users attached to one account with a starter `account_role` of `owner`, `admin`, or `agent`, optional `platform_role` instance authority, and an explicit online/away routing status for new automatic assignments.
- `custom_roles`: account-owned, deny-by-default permission sets for
  responsibilities the fixed Owner/Admin/Agent hierarchy cannot express. Checks
  read the currently assigned role rather than a copy on the user, so editing a
  set re-authorizes its holders at once, and a role that still has holders or
  claim mappings cannot be deleted. Assigning one resets the holder's built-in
  `account_role` to Agent, so losing a role can never promote anybody.
- `oidc_connections`: one account's OpenID Connect provider, capped at one per
  account by a unique `account_id`. The client secret is encrypted and never
  serialized back, and `configuration_version` is rotated on every
  authorization-relevant edit so a sign-in that began under an older contract
  cannot complete against the new one.
- `oidc_identities`: the binding between a provider subject and one existing
  account user. A verified email finds the user once, at first link; every
  later sign-in resolves through connection-plus-`subject`, so a changed
  provider email cannot move a login onto a different Wayfindr user, and
  uniqueness on both `user_id` and the connection/subject pair makes concurrent
  first links fail closed. Rows hold no tokens and no provider claims, and
  `provisioned_at` marks the federation-provisioned identities that
  claim-driven re-roling may touch.
- `oidc_role_mappings`: exact claim values mapped to a role for one connection.
  They govern JIT-managed identities only: an agent the provider created, and
  every later sign-in by one, is refused when no mapping matches or when
  matches resolve to different roles, rather than guessed at. An agent who
  already existed locally and was linked by verified email is never routed
  through a mapping at all, so a missing one cannot lock them out. Owner is
  never a valid target, and the custom-role target restricts deletion, so a
  mapped role cannot be removed out from under the provider.
- `agent_realtime_evictions`: one pending instruction per agent to close their
  open realtime sockets, written in the same transaction as the role change,
  site-access removal, or OIDC role remap that revoked the access. `agent_id`
  is deliberately not a foreign key, because deleting the user must not erase
  the order to disconnect sockets that still identify them.
- Platform/instance operator authority should not be overloaded onto `account_role`. The first scaffold uses `users.platform_role` for explicit operator access while keeping it separate from account support access.
- `sites`: install targets owned by an account. Each site has a public key used by widgets and integrations.
- `site_user`: support-agent access for sites. Empty site membership means account-wide fallback for early installs; explicit rows narrow the support queue to assigned agents.
- `site_routing_states`: one durable row per configured site containing separate last-agent cursors for conversation and ticket round-robin assignment.
- `visitors`: anonymous or identified people seen on a site.
- `visitor_identity_aliases`: browser IDs retained by an explicit same-site
  contact merge, including the internal visitor-ID lineage needed to keep old
  signed sessions valid without authorizing later ID reuse.
- `visitor_attribute_definitions`: account-owned typed labels for selected safe
  keys already present in visitor host context.
- `visitor_notes`: private, visitor-owned team context that survives individual
  tickets and cascades with the visitor record.
- `conversations`: chat/support sessions between a visitor and support agents. Each conversation has a unique support code for later lookup.
- `conversation_messages`: messages or system events inside a conversation. The sender is polymorphic so visitors, agents, and future system actors can share one message stream.
- `conversation_message_attachments`: private message-scoped files. Rows carry
  denormalized account, site, conversation, message, upload, scan, and
  `storage_disk` state so pending uploads can bind atomically and old storage
  surfaces can coexist after a disk change.
- `conversation_read_states`: per-user/per-conversation read markers used by
  calm unread and unattended-alert behavior.
- `conversation_ratings`: a visitor's answer to how a closed conversation went.
  It is a row rather than a column on `conversations`, so absence is what
  "unrated" means and no average can be taken over the people who said nothing,
  and `episode_event_id` names the `conversation.closed` audit event being
  rated: a conversation closed twice earns two answers, while a unique index
  holds one answer per close. That column deliberately has no foreign key,
  because pruning the audit log must not delete answers the reports still
  count.
- `conversation_reply_deliveries`: the outbox for support replies mailed on to
  visitors who arrived by email. The unique `conversation_message_id` binds one
  outbox row to one message, so a reply survives a queue or worker failure
  without becoming two rows — but delivery is deliberately at-least-once, and
  the table is shaped for that: `accepted_at` is the only marker, so a worker
  lost between SMTP accepting the message and that receipt being written leaves
  the row eligible and a later attempt mails it again. A generic SMTP server
  cannot confirm mailbox delivery atomically, so the choice is a possible
  duplicate or a possible silence, and support replies take the duplicate.
  `ticket_external_comment_deliveries` faces the same question and answers it
  the other way. Nor does the constraint deduplicate an agent who submits the
  reply form again: that writes a second message, which earns its own row and
  its own mail. The RFC `message_id` is minted with the row so later mail
  threads against exactly what was sent, and `recipient` is encrypted so a
  queue-support table does not become a second plaintext copy of visitor
  addresses.
- `agent_alert_deliveries`: one claim per alert version and state, taken by
  whichever off-dashboard channel reaches it first — Web Push, immediate,
  digest, or unattended mail — so the others do not send that copy. The live
  dashboard receipt stays on the notification row rather than here, and
  `state_key` scopes a claim to the activity it covered so work that advances
  without a new alert row stays eligible for a later digest.
- `push_subscriptions`: one browser an agent has opted in from, holding the
  endpoint its push service issued and the keys that encrypt to it. It is the
  one table whose name and connection are read from configuration rather than
  written literally, because the upstream package owns them —
  `WEBPUSH_DB_TABLE` and `WEBPUSH_DB_CONNECTION` — and moving either is a way
  to break the feature rather than tune it. `vapid_public_key_hash` marks which
  generation of keys a row can still be reached with. Rotating through the
  operator console deletes every other generation, emptying the table for every
  agent at once; rotating through the environment only hides them, because an
  environment value is process-local and two generations may legitimately
  coexist mid-deployment, so no process may delete another's rows. Those hidden
  rows are never collected. The subscribable side is polymorphic because the
  package is; only agents are ever attached.
- `conversation_copilot_summaries`: the latest agent-requested summary of a
  conversation, one row per conversation, overwritten by each refresh instead
  of kept as history. Every worker write is guarded on `generation`, so a
  superseded job cannot publish its answer, and `source_last_message_id` is
  what lets a ready summary be marked stale once the conversation moves on. No
  prompt text is stored; `requested_by_id` is kept so the worker can re-derive
  that agent's access immediately before any text reaches the provider.
- `conversation_copilot_reply_drafts`: the latest suggested agent reply, under
  the same single-row, `generation`-guarded contract as summaries. A draft the
  conversation has moved past is blocked from insertion rather than quietly
  reused, and the stored text only ever fills an empty composer — nothing in
  this table can send a message to a visitor.
- `conversation_copilot_ticket_suggestions`: a suggested title and priority for
  a conversation that has no ticket yet; applying one prefills the ticket form
  rather than creating a ticket. The model never names a label — `label_ids`
  holds account labels matched locally against the transcript and re-scoped to
  the account before storage, so the label catalogue never leaves the install.
- `conversation_copilot_knowledge_suggestions`: up to three published
  same-account articles an agent can cite in a reply. The model returns search
  phrases rather than identifiers, and `article_ids` holds what ranking those
  phrases against the account's own published articles produced locally, so the
  knowledge catalogue never leaves the install and no suggestion can surface an
  unpublished or cross-account article.
- `tickets`: durable support records that may be created from a conversation.
  Tickets can carry provider-neutral category values for local triage without
  depending on an external issue tracker.
- `sla_policies`: optional account targets for first response and resolution,
  keyed by support priority.
- `sla_clocks`: persisted business-time consumption and warning, breach,
  satisfaction, or cancellation history for a conversation or ticket target.
- `sla_alert_deliveries`: one durable row per SLA clock stage, channel, and
  recipient. Its `public_id` is minted before the send and becomes the
  notification's own ID, so a retried dashboard alert reuses one identity
  rather than posting a second alert, and `deduplicated_at` marks a stage the
  shared agent-alert ledger already covered. A row stamped `started_at` with no
  `accepted_at` crossed the application's mail boundary and may or may not have
  reached SMTP — the stamp is written when Laravel hands the message off, which
  is before a transport connection is attempted — so it is left for a human
  rather than retried automatically.
- `ticket_labels`: account-owned labels assignable to tickets.
- `ticket_label_ticket`: which of an account's `ticket_labels` are on a ticket.
  The pair is unique so an agent, an automation rule, and a bulk change can all
  apply the same label without stacking duplicates, and the reverse index
  answers the label-side question: how many tickets carry this label, and
  whether it may be deleted yet.
- `automation_rules`: disabled-by-default, account-owned event, condition, and
  action definitions evaluated in a stable order.
- `automation_rule_executions`: append-style success and failure records with
  the rule definition snapshot and per-action outcomes. The rule reference may
  be cleared later without erasing the historical explanation.
- `automation_macros`: named, ordered, disabled-by-default action lists an
  agent applies on demand to one ticket or conversation. They are automation
  rules with the trigger and conditions removed: the same action vocabulary,
  validated against the macro's `subject_type`, recorded in
  `automation_rule_executions` rather than a second history table, and bounded
  at apply time by the running agent's own permissions.
- `conversation_bulk_action_runs`: one row per confirmed bulk change made from
  the conversation queue, holding the per-conversation before/after snapshot in
  `changes`. The edits run through the same automation action executor as
  automation rules, so the audit and lifecycle side effects they cause stand
  once the run commits and undo is a replay of the snapshots: it restores only
  the field the action wrote, skips any conversation whose value or last
  relevant audit event has moved since, and will not hand one back to an agent
  who has lost access in the meantime. `return_query` keeps the agent's queue
  filter on the run so the confirm and undo redirects land back on the list
  they acted from.
- `ticket_bulk_action_runs`: the ticket queue's counterpart, identical in shape
  to `conversation_bulk_action_runs` but with its own action set — only tickets
  take labels — and a `changes` payload keyed by ticket. `undone_at` and
  `undo_result` make a run reversible exactly once and leave the partial
  outcome readable afterwards.
- `proactive_message_rules`: disabled-by-default, site-owned visitor invitation
  definitions with stable order, browser-evaluated URL/referrer conditions,
  timing and visit thresholds, agent-availability gating, and mandatory
  frequency and dismissal limits.
- `proactive_message_deliveries`: bounded, site-and-visitor-scoped display
  claims and shown/engaged/dismissed receipts. They snapshot the exact public
  invitation, may link to the conversation created after engagement, and use a
  keyed visitor digest to preserve caps after the shorter-lived presence row is
  pruned. They omit matched browsing values and are automatically deleted 90
  days after their last recorded outcome (or claim when no outcome exists).
- `reply_templates`: account-owned snippets that help agents answer common
  support questions without changing the ticket model.
- `articles`: account-owned answers a visitor can find without asking, scoped
  like `reply_templates` because an answer about refunds belongs to the desk
  rather than to one site. `search_text` keeps the rendered plain text beside
  the Markdown the author edits, so a visitor's search matches phrases that
  cross formatting without re-rendering every article per query, and
  publication is one nullable timestamp: null is a draft, and published means
  published already, so a future date reads as scheduled rather than live.
- `ticket_external_links`: provider-neutral records that connect a local
  Wayfindr ticket to an external issue tracker record without making that
  external provider the source of truth.
- `external_issue_provider_connections`: account-owned provider connection
  records with encrypted credential storage and explicit capability flags.
- `site_external_issue_projects`: site-scoped mappings from a Wayfindr site to
  a provider project or repository.
- `ticket_external_comment_deliveries`: an outbox row per relayed agent note
  per linked external issue, written only for the notes an agent explicitly
  sends to the tracker, so a ticket linked to two trackers relays twice and one
  note never comments twice on the same issue. Delivery is at-most-once by
  design: a committed `started_at` with no `accepted_at` is left for
  reconciliation rather than retried, because provider comment APIs offer no
  idempotency key and a duplicate comment is visible to the customer.
- `api_tokens`: account-owned credentials for programmatic access, stored as a
  SHA-256 hash plus a last-four display hint because the plaintext exists for
  exactly one response and no surface may show it again. Whether a token was
  pinned to specific sites is recorded on the token in `restricts_sites`, never
  inferred from surviving pivot rows: purging a site cascades those rows away,
  and a restricted token would otherwise inherit the whole account the moment
  an admin tidied up.
- `api_token_site`: the sites one API token may reach, clamped at issue time to
  what the issuing agent could then see. The list is frozen — a site the
  account adds later does not join it, and no path widens an existing token —
  so a credential that needs more reach is reissued rather than edited.
- `api_idempotency_keys`: per-token receipts, kept for a day, that let a
  retried public API write return the record the first attempt created rather
  than creating a second one. They hold a hash of the caller's key and a
  pointer to the resource, not the response body, so retry safety does not
  become a second retention surface for transcript data; a key reused with a
  different `request_hash` is refused as a conflict instead of replayed against
  the wrong record.
- `outbound_webhook_endpoints`: account-owned subscriptions to the thin
  notification events that carry identifiers rather than support content. The
  destination and signing secret are encrypted rather than hashed because
  Wayfindr has to reproduce the secret to sign every future request, and
  `next_sequence` is allocated under a row lock so each endpoint owns one
  monotonic stream that retries reuse instead of advancing. Disabling sets
  `disabled_at` and cancels pending work rather than deleting the endpoint, so
  its history survives the decision to stop publishing.
- `outbound_webhook_endpoint_site`: the sites an endpoint may publish for,
  pinned at creation to the site ceiling of the administrator who created it —
  choosing sites can narrow that list, and a site created later is never added.
  Publishing requires a matching row here, so an endpoint whose named sites
  have all been purged reaches nothing rather than falling back to the whole
  account.
- `outbound_webhook_deliveries`: the outbox row, written inside the originating
  resource's own transaction so the database rather than the queue is the
  acceptance boundary for a published event. It stores the exact signed
  `payload` so every retry sends identical bytes, and keeps `delivered_at`,
  `failed_at`, and `cancelled_at` as separate terminal markers so an exhausted
  retry budget stays distinguishable from an endpoint disable. Rows cascade
  with the site, because a surviving delivery would both retain its payload and
  let the recovery scheduler send it after the purge.
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
- Automation rules belong to one account, default to disabled, select a typed event, and keep ordered condition and action lists as JSON. Equal positions are evaluated by row ID so order remains deterministic while an operator is reordering rules. Conditions use a typed field/operator/value vocabulary and actions are limited to assignment, labels, priority, status, agent notification, and private ticket notes; no action in this contract can send a visitor message.
- Proactive message rules are a separate site-owned contract because they are
  evaluated before a conversation exists. Equal positions are resolved by row
  ID. Page and referrer matching stays in the browser; those matched values are
  not copied into server-side delivery evidence. A short server claim protects
  live availability and cross-tab caps; a shown receipt spends the cap; an
  engaged receipt may be used once to copy the saved message snapshot into a
  normal conversation. See
  [Proactive messaging](../product/proactive-messaging.md).
- Creation and update rules enter through explicit domain events after intake has established its final initial state and after the causal audit event is stored. Eloquent observers still maintain infrastructure such as SLA clocks, routing, and webhook outboxes, but they do not run business automation against half-finished workflows.
- Visitor identity supports both `anonymous_id` and optional host-provided
  `external_id`. Public widget requests bootstrap a signed visitor token before
  they can create conversations or read/write visitor messages. Because the
  host identifier arrives through a public browser request, it is not proof
  that two rows are one human. A `manage_contacts` agent makes that merge
  explicitly within one site; different populated external IDs or email
  addresses fail closed so later inbound mail cannot recreate the duplicate.
  The source browser IDs become aliases of the chosen contact, and their prior
  internal visitor IDs form a token lineage capped at the 50 most recent rows
  across later merges.
- Contact notes are separate from ticket activity so deleting or closing one
  ticket cannot erase person-level context. Their lifecycle audit events omit
  note bodies, avoiding a second copy outside the visitor-owned record.
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
