# Alert Digests and Escalation

Status: planning. This is a product boundary for future notification work, not
a commitment that every self-hosted install needs alert automation on day one.

## Principle

Wayfindr alerts should help agents decide what to do next without making the
workspace feel like a fire alarm panel.

Immediate alerts are for work that is active, directed, or likely to block a
visitor. Digests are for lower-pressure awareness. Escalation should be
explicit, auditable, and scoped before it becomes automatic.

## Current Foundation

Wayfindr already has the pieces needed for calm alert routing:

- dashboard notifications for support work that needs attention;
- queued email delivery for configured installs;
- mail readiness checks and a mail smoke command;
- agent profile preferences for all supported-site alerts, assigned-only alerts,
  quiet mode, email delivery, and future email cadence;
- site access rules that keep alert visibility inside the agent's support scope;
- deactivated-agent checks so stale assignments do not keep sending actionable
  support alerts.

These should remain the base contract. Digest and escalation work should narrow
delivery, summarize safely, or add deliberate urgency. It should not bypass site
access, role policy, quiet mode, or deactivated-agent checks.

## Delivery Model

The first digest setting is per-agent because alert fatigue is personal and
support workloads differ. Account-level defaults can come later when teams need
a shared operating policy. Site-level overrides should wait until site staffing
patterns prove they are needed.

Suggested preference shape:

- `immediate`: send dashboard notifications and configured email alerts as
  events happen.
- `digest`: keep dashboard notifications immediate, but roll eligible email
  alerts into scheduled summaries.
- `quiet`: suppress new support alerts, matching the current quiet-mode intent.

The current email on/off preference remains the master switch. The profile
screen now stores an email cadence preference, but digest cadence does not change
delivery until the digest candidate service and scheduled mail path exist.
Until scheduled digest delivery exists, product copy should describe digest
cadence as a future preference instead of an active delivery mode.

## Immediate Alert Candidates

Keep these as immediate dashboard alerts, and email them only when the agent has
enabled immediate email delivery:

- a visitor message on an assigned conversation;
- a visitor message on an unassigned conversation for agents who support that site;
- a ticket newly assigned to the agent;
- an explicit manual escalation to the agent or team;
- later, high-confidence operational failures that block the support loop.

Immediate alerts should point to the support code, ticket, site, subject, and
last activity. They should avoid dumping full transcript bodies into email by
default.

## Digest Candidates

Digests should summarize work that is still useful but does not need to
interrupt the agent as it happens:

- conversations still waiting for an agent reply;
- assigned tickets updated since the last digest;
- tickets with stale next-action guidance;
- site-level counts for open conversations, open tickets, and needs-reply items;
- gentle reminders for support work that is aging but not escalated.

Digest content should prefer safe references over raw support content:

- support code or ticket ID;
- site name;
- subject;
- priority and status;
- assigned agent;
- last activity time;
- a direct dashboard link.

Transcript excerpts, visitor messages, cobrowse data, and visitor profile
details should stay out of email digests unless an account-level policy later
makes that explicit.

## Escalation Model

Start with manual escalation. It is easy to understand, easy to audit, and does
not create accidental pressure loops.

A manual escalation should record:

- who escalated it;
- what was escalated;
- the reason or note;
- the target agent or group;
- when the escalation happened;
- whether the target had site access at the time.

Automatic escalation should wait until account-level policy exists. When it does
arrive, good triggers are:

- visitor message waiting longer than a configured threshold;
- high-priority ticket with no owner or no recent agent action;
- assigned agent deactivated or no longer able to support the site;
- explicit SLA-like policy configured by the account.

Priority should influence escalation thresholds, but priority alone should not
create noisy alerts.

## Guardrails

- Respect quiet mode.
- Re-check site access and deactivated-agent status at send time.
- Keep platform operators out of customer support alerts unless a separate
  customer-data access path grants it.
- Keep digest email bodies metadata-first and safe by default.
- Do not send cross-account digests.
- Do not introduce AI triage or auto-prioritization into the alert path until
  the baseline rules are trustworthy and explainable.
- Make every escalation auditable.
- Let self-hosted operators configure timing without hiding their responsibility
  for mail, queues, and local data handling.

## Implementation Waypoints

1. Document the digest and escalation boundary.
2. Add an agent-facing delivery cadence preference without changing delivery
   behavior. Done: agents can store immediate or digest cadence while current
   alert delivery remains unchanged.
3. Add a digest candidate service with tests for account, site, role,
   quiet-mode, and deactivated-agent boundaries.
4. Add a dry-run console command that prints which digest items would be sent to
   which agent.
5. Add queued digest mail with safe metadata-only content.
6. Add a simple manual escalation event and audit trail.
7. Add account-level default cadence and escalation timing only after the
   per-agent path is proven.
8. Add automatic escalation policies only when there is a clear account setting,
   tests, and UI copy that explains what will happen.

## Open Questions

- Should the first cadence be daily only, or should Wayfindr also support a
  short working-hours digest?
- Should manual escalation target one agent, all site agents, or both?
- Should digest scheduling use local server time, account timezone, or agent
  timezone first?
- How visible should skipped digest items be when an agent loses site access
  before send time?
- Should support codes become the primary email reference for conversations so
  agents can search their inbox without exposing more visitor data?
