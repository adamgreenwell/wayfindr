# Data Inventory

This inventory describes the intended product data surface for the current
pre-alpha Wayfindr application. It is a planning and operator-awareness
document, not a complete compliance register.

## Current Records

| Record | Examples | Current storage | Notes |
| --- | --- | --- | --- |
| Accounts | Support team name | `accounts` | Tenant boundary for sites, agents, tickets, and conversations. |
| Agents | Name, email, hashed password, account ID | `users` | Agents are authenticated Laravel users. |
| Sites | Name, domain, public widget key, settings | `sites` | Site settings can include mask selectors. Only widget-safe settings should be exposed to public bootstrap responses. |
| Site access | Site and agent links | `site_user` | Controls which agents can support each site. Empty site access keeps an account-wide fallback for first-run installs. |
| Visitors | Anonymous ID, optional external ID, optional name/email, last seen time, metadata | `visitors` | Widget traffic is scoped by site public key and signed visitor token. Host-provided external IDs should be non-sensitive references; obvious direct PII is ignored by widget intake before it appears in agent context. |
| Conversations | Subject, status, support code, visitor/site links, page URL metadata | `conversations` | Conversation subjects and page URLs can contain personal data depending on the host site. |
| Messages | Visitor and agent message bodies, sender references, timestamps, metadata | `conversation_messages` | Message bodies are user-supplied support data. |
| Tickets | Subject, status, priority, category, requester, assignee, conversation link, metadata | `tickets` | Tickets are durable support records and may outlive the original chat. |
| External provider connections | Provider name, base URL, encrypted credentials, capability flags | `external_issue_provider_connections` | Credentials are account-owned and should be rotated in the external provider if compromised. |
| Site external project mappings | Site, provider connection, project or repository key, optional project URL | `site_external_issue_projects` | Mappings decide where a site's tickets may be sent. Operators should avoid mapping private visitor-heavy support to public projects unless they have explicit export controls. |
| Ticket external links | Provider, project or repository key, external ID/key, URL, sync status, last sync time, metadata | `ticket_external_links` | External links point to third-party systems. Operators should assume linked providers have their own access, retention, and privacy rules. |
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

The current application does not yet ship automatic data retention controls.
Until those controls exist, operators should assume database records, logs, and
backups persist according to their infrastructure defaults.

Future retention controls should let operators decide how long each data class
is kept and should show the data responsibility reminder before saving unusually
long or indefinite retention windows.
