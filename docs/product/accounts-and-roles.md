# Accounts, Agents, Site Access, and Roles

Wayfindr should let one support organization run support across many sites without assuming every agent works every property. This document separates the concepts so the product can grow without turning early access checks into a permanent permissions model.

## Current Model

### Account

An account is the tenant boundary. Sites, agents, tickets, conversations, audit events, and privacy settings belong inside an account.

### Agent

An agent is a Laravel user attached to one account. Agents can sign in, view the agent dashboard, reply to conversations, request cobrowse consent, and work tickets when they have access to the relevant site.

The starter RBAC implementation stores account authority on `users.account_role` while Wayfindr still supports one account per user. A dedicated membership model can replace this when multi-account users become a real product need.

Owners and admins can create new agents from the account overview. New dashboard-created agents start with the `agent` role and receive a generated temporary password that is shown once. When outbound mail is configured, the creator can also send the agent a welcome email with the sign-in URL and temporary password; the one-time password display remains the fallback. Agents can use the profile screen to update their display name, replace a temporary password after sign-in, and choose whether alerts should cover all supported sites, only assigned work, or quiet mode.

Agents can be deactivated without deleting their historical messages, tickets, assignments, or audit records. Deactivated agents cannot log in, and existing dashboard sessions are signed out before protected routes continue. Owners can deactivate or reactivate another same-account user. Admins can deactivate or reactivate non-owner agents, but cannot manage owner or admin access.

Deactivation is also enforced at the support policy layer. Stale site assignments, ticket assignments, conversation assignments, and unread alerts should not keep a deactivated agent authorized after their account access is suspended.

### Site access

Site access controls which agents support which sites. Conversations, tickets, alerts, ticket assignment options, and realtime conversation channels should respect site access.

If a site has no assigned support agents yet, Wayfindr treats it as account-wide so first-run installs and existing self-hosted setups do not lock themselves out. Once one or more support agents are assigned to a site, only those agents should see and work that site's support queue.

Account owners and admins can manage site support access from the site settings screen when they also have access to that site. The UI requires at least one assigned support agent and at least one assigned owner or admin so an operator does not accidentally reopen a configured site to the account-wide fallback or remove every person who can manage access later.

The account overview includes a site access matrix for the sites visible to the signed-in agent. It shows whether each site is still using the account-wide fallback or explicit support-agent assignments, lists the active assigned agents for explicit sites, and links back to the site settings screen for management. The agent roster also summarizes each visible agent's active support scope so account operators can spot explicit assignments, fallback coverage, and deactivated agents without opening every site.

The account overview also surfaces recent account activity for access-related audit events such as agent creation, role changes, password updates, deactivation/reactivation, and site access updates. This feed should use curated labels and summaries instead of rendering raw audit metadata so secrets or operational payloads do not leak into the dashboard. Site-backed activity still follows the signed-in agent's visible site boundary.

Deactivated agents are not assignable from site access management. Stale historical assignments may remain in the database for audit/history, but they do not count as eligible support agents or site managers.

## Account Roles

Account roles are about authority, not queue membership. The starter role helpers are implemented on `users.account_role`; owners can change another same-account agent's role from the account overview. The working RBAC map lives in [RBAC Waypoints](rbac-waypoints.md).

The first roles are:

- `owner`: can manage account-level settings, billing or hosted-plan details, agents, roles, sites, and site access. Support queue visibility should still follow site access unless a future elevated-access decision explicitly changes that.
- `admin`: can manage agents, sites, site access, privacy settings, and support operations for sites they can access, but may not own billing, account transfer controls, or role ownership.
- `agent`: can work assigned support queues, reply to visitors, create and update tickets, request cobrowse consent, and manage their own alert workflow.

Possible later roles:

- `billing`: can manage billing and plan details without support queue access.
- `viewer`: can view reports or support history without participating in conversations.

## Platform Operators

Platform operators are outside account RBAC. They operate the Wayfindr installation itself: infrastructure health, setup recovery, background processes, hosted account lifecycle, and other instance-level work.

A platform operator is not automatically an account owner, account admin, support agent, or site-access bypass. Operator authority should not grant routine access to conversations, tickets, cobrowse state, transcripts, alerts, or visitor page data. Any future customer-data access path should be explicit, scoped, time-bound, and audited.

See [Platform Operator Boundary](platform-operator-boundary.md) for the product guardrails before adding platform-admin UI.

## Guardrails

- Site access should land before broad role management.
- RBAC should be implemented through Laravel policies and gates instead of scattered controller conditionals.
- Role checks should build on explicit account membership and site access, not replace them.
- Platform operator authority should stay separate from account roles and must not bypass site access in account support workflows.
- New agents should start as `agent`; `UserPolicy` keeps dashboard agent creation limited to active owners and admins.
- Role changes start owner-only, with same-account boundaries in `UserPolicy`, plus self-change denial, last-owner protection, and audit events behind the dashboard role controls.
- Agent password changes are self-service from the profile screen and should be audited without exposing password material.
- Agent deactivation should preserve history, block sign-in, clear stale dashboard sessions, deny self-deactivation, stay inside the account through `UserPolicy`, and create audit events for deactivation/reactivation.
- Account activity visibility should stay metadata-only and access-focused until Wayfindr has a deliberate audit export/search workflow.
- Support policies should deny deactivated agents even when stale assignments still reference them.
- Ticket assignment should only target agents who can support the ticket's site.
- Alerts should notify the smallest useful group: assigned agent first, otherwise agents assigned to the site, otherwise the account-wide fallback.
- Agent alert preferences can narrow or suppress new unread alerts without changing the underlying site access or assignment rules.
- Cobrowse access should require both account membership and site access.
- Self-hosted operators remain responsible for mapping roles to their local policies, employment rules, and data obligations.

## Open Questions

- Should future invitation flows ask for site assignments during agent creation?
- Should WordPress and future integrations expose site-agent assignment hints during install?
- Should account roles be stored as a simple enum on account membership, or should Wayfindr introduce a dedicated membership model before hosted multi-tenant plans?
