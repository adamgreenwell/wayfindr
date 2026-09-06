# Data Inventory

This inventory describes the intended product data surface for the current
pre-alpha Wayfindr application. It is a planning and operator-awareness
document, not a complete compliance register.

## Current Records

| Record | Examples | Current storage | Notes |
| --- | --- | --- | --- |
| Accounts | Support team name | `accounts` | Tenant boundary for sites, agents, tickets, and conversations. |
| Agents | Name, email, hashed password, account ID | `users` | Agents are authenticated Laravel users. |
| Sites | Name, domain, public widget key, settings | `sites` | Site settings can include mask selectors and support hours. Only widget-safe settings should be exposed to public bootstrap responses. The bootstrap response carries a site's *derived* availability — away or not, the operator's away message, and the next opening time — never the schedule itself, so the desk's working pattern is not published to every visitor. |
| Site access | Site and agent links | `site_user` | Controls which agents can support each site. Empty site access keeps an account-wide fallback for first-run installs. |
| Visitors | Anonymous ID, optional external ID, optional name/email, last seen time, current visit start, metadata | `visitors` | **What a row means depends on whether the site enables presence reporting.** Off (the default), a row means somebody opened the chat. On, it means somebody loaded a page — see [ADR 0019](../decisions/0019-presence-for-visitors-who-have-not-made-contact.md) for the disclosure and decline the visitor gets, and the Retention Posture below for how long a row that never made contact is kept. **Stored page addresses carry no query string, no fragment, and no path segment that looks like a credential**, and an address whose host is not the site's own is not stored at all. Widget traffic is scoped by site public key and signed visitor token. Host-provided external IDs should be non-sensitive references; obvious direct PII is ignored by widget intake before it appears in agent context. **`name` and `email` are populated only by a pre-chat form the operator configured and the visitor filled in** — an answer to a question that was asked, which is why it is kept where the same channel's incidental PII is stripped. Nothing else writes them. A `manage_contacts` CSV export is limited to the 500 most recent rows matching the current directory filters and supported-site scope. It includes identity fields, timestamps, and normalized values of currently defined attributes, while omitting raw metadata, page addresses, contact notes, support records, and alias history. |
| Visitor identity aliases | Site, canonical visitor, prior anonymous browser ID, prior internal visitor-ID lineage | `visitor_identity_aliases` | Created only by an explicit `manage_contacts` merge within one site. Aliases let old tabs, tokens, and returning browsers continue to the chosen contact without copying name, email, host context, or note bodies. They are searchable only on already-authorized support surfaces, cascade with the canonical visitor or site, and cannot authorize an unrelated visitor that later reuses the same browser ID. |
| Visitor attribute definitions | Account, safe context key, agent-facing label, value type | `visitor_attribute_definitions` | Definitions interpret selected values already stored under `visitors.metadata.context`; they do not collect or copy visitor values. Definition audit events contain the key, label, and type only. Deleting a definition does not erase the underlying visitor context. |
| Visitor contact notes | Visitor, author, private note body, timestamps | `visitor_notes` | Notes are internal team context attached to the person rather than one ticket. Anyone authorized to open the visitor record may read them; `manage_contacts` is required to add or delete them. Note bodies cascade with visitor deletion and are never copied into audit metadata; lifecycle audit events retain only the note ID. Backups may still retain deleted bodies under the operator's backup policy. |
| Conversations | Subject, status, priority, support code, visitor/site links, page URL metadata, current support-wait timing | `conversations` | Conversation subjects and page URLs can contain personal data depending on the host site. The support-wait fields are operational counters that keep business time stable across calendar changes. |
| Messages | Visitor and agent message bodies, sender references, timestamps, metadata | `conversation_messages` | Message bodies are user-supplied support data. |
| Conversation ratings | Score (good/ok/bad), optional free-text comment, conversation/site links, time answered | `conversation_ratings` | Written only by a visitor answering a prompt the operator switched on, and only about a conversation that is already closed. **The comment is the visitor's own words** and carries whatever they decided to type, so it is support data of the same class as a message body and inherits the same handling. Absence of a row means *unrated*, never *neutral* — no aggregate is computed over people who did not answer. Rows are deleted with their conversation, so purging a site removes its ratings with everything else. |
| Tickets | Subject, status, priority, category, requester, assignee, conversation link, metadata | `tickets` | Tickets are durable support records and may outlive the original chat. |
| SLA policies and clocks | Priority targets, work-item links, elapsed business time, warning/breach/completion timestamps, alert handoff recipient IDs and timestamps | `sla_policies`, `sla_clocks` | Operational metadata only; clocks inherit the account and site visibility of their conversation or ticket and are not included in alert email with visitor content. Alert handoff fields prevent retrying recipients whose notification was already queued. |
| Support notifications | Alert kind, work-item references, subject or message preview, read/delivery timestamps | `notifications` | Notification metadata inherits the recipient's account and site access. Some existing conversation alerts include a short visitor-message preview, so operators should handle notification rows as support content. |
| Automation rules and executions | Event conditions, ordered actions, target agent/label IDs, private note actions, matched work-item references, outcomes, errors, timestamps | `automation_rules`, `automation_rule_executions` | Account-owned operational data. Execution rows snapshot matched rule definitions so deleting a rule does not erase why prior work changed; private-note action text should be handled as support content. Visitor message bodies are evaluated in place and are not copied into execution logs. |
| Proactive message rules | Internal name, visitor-facing message, URL/referrer substring conditions, timing, visit and availability conditions, frequency limits, dismissal limits, order, enabled state | `proactive_message_rules` | Site-owned configuration. The message is public widget copy. URL and referrer conditions are also intended for public widget configuration, while the visitor's actual matched URL and referrer remain in the browser and must not be copied into delivery or audit records. Audit metadata omits the message and match strings. |
| Proactive message deliveries | Site, optional visitor, rule and conversation links, keyed visitor digest, opaque claim and public IDs, rule public ID, exact message snapshot, claim/expiry time, optional shown/engaged/dismissed times | `proactive_message_deliveries` | Site-scoped evidence for cross-tab caps, dismissal handling, transcript handoff, and 90-day aggregate results. The keyed digest preserves a still-active cap after a presence-only visitor row is deleted without retaining the browser's raw anonymous ID. It deliberately stores no matched URL or referrer. The exact public message is retained so a later rule edit or deletion cannot rewrite what the visitor actually saw. |
| External provider connections | Provider name, base URL, encrypted credentials, capability flags | `external_issue_provider_connections` | Credentials are account-owned and should be rotated in the external provider if compromised. |
| Site external project mappings | Site, provider connection, project or repository key, optional project URL | `site_external_issue_projects` | Mappings decide where a site's tickets may be sent. Operators should avoid mapping private visitor-heavy support to public projects unless they have explicit export controls. |
| Ticket external links | Provider, project or repository key, external ID/key, URL, sync status, last sync time, metadata | `ticket_external_links` | External links point to third-party systems. Operators should assume linked providers have their own access, retention, and privacy rules. |
| Agent copilot settings | Provider, model, optional endpoint, encrypted API key | `operator_settings` or environment | Optional and instance-wide. The key is write-only and omitted from audit metadata. A custom endpoint receives the configured key, so operators should trust and protect that destination. |
| Agent copilot requests | A bounded, feature-selected text prompt and generated suggestion | Not stored by Wayfindr's provider boundary | Text is scrubbed immediately before transmission and attachments/cobrowse snapshots have no provider path. The selected provider may process or retain requests under its own terms; operators must review that policy. No customer-facing action is automatic. |
| Conversation copilot summaries | Conversation and requesting-agent links, latest generated summary, source message position/count, provider/model and token counts, lifecycle timestamps and generic failure code | `conversation_copilot_summaries` | Stores only the latest agent-requested suggestion, never its prompt. Queue payloads contain IDs only. The worker rechecks access before provider delivery; refresh replaces the prior result, new messages mark it stale, and conversation deletion cascades to the summary. AI audit metadata excludes prompt and output text. |
| Cobrowse sessions | Consent status, requested/consented/ended timestamps, telemetry, page state, sanitized snapshot, mutation buffer | `cobrowse_sessions` | Cobrowse data should be masked in the browser before transmission. |
| Audit events | Actor, subject, event type, metadata, timestamps | `audit_events` | Intended for accountability and important lifecycle events. |
| Sessions, cache, queues | Laravel runtime data | Laravel cache/session/job tables or configured drivers | Runtime stores may include identifiers or serialized job payloads. |
| Logs | Errors, deployment diagnostics, request/runtime details | Application and infrastructure logs | Operators should avoid logging secrets and should rotate logs. |
| Backups | Database and file snapshots | Operator infrastructure | Backups can retain deleted application data unless the operator has a backup lifecycle policy. |

## Data Wayfindr Should Avoid

Wayfindr should not intentionally collect or store:

- visitor passwords,
- raw payment card numbers or CVV values,
- raw API keys, access tokens, or secrets from host pages,
- full video or pixel streams of a visitor browser,
- unbounded page snapshots or mutation streams,
- AI training datasets made from customer conversations without explicit,
  separate controls.

## Retention Posture

**Two automatic retention controls ship for browsing-derived data.**
Visitors who have never made contact — no conversation, no ticket, no message —
are deleted 30 days after their last sighting. That is measured from activity
rather than from creation, so a row is only ever removed once nobody has been
behind it for a month, and a visitor who returns is not deleted mid-visit.

Thirty days is the **maximum**, not merely the default: an operator may shorten
the window with `WAYFINDR_PRESENCE_RETENTION_DAYS`, and a longer value is
clamped rather than honoured. It exists because presence reporting
([ADR 0019](../decisions/0019-presence-for-visitors-who-have-not-made-contact.md))
changes `visitors` from *people who opened the chat* to *people on the site*,
which turns the absence of pruning from a gap into a defect.

Proactive-message delivery evidence is deleted 90 days after its last recorded
outcome (or claim when no outcome exists). This fixed window is long enough to
enforce the product's maximum dismissal cap without quietly turning a 90-day
promise into a shorter server-side limit. It removes the message snapshot and
outcome receipts even when the visitor later starts a conversation. The
ordinary conversation message created after an engagement is support history
and follows the conversation's retention posture.

**Everything else still persists indefinitely.** Conversations, messages,
tickets, ratings, SLA history, automation execution history, and audit events have no automatic retention, and operators
should assume database records, logs and backups persist according to their
infrastructure defaults.

Future retention controls should let operators decide how long each data class
is kept and should show the data responsibility reminder before saving unusually
long or indefinite retention windows.
