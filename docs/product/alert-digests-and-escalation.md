# Alert Digests and Escalation

Status: foundation implemented. This document records the current alert digest
and manual escalation boundary, plus the guardrails for future automatic
escalation work.

## Principle

Wayfindr alerts should help agents decide what to do next without making the
workspace feel like a fire alarm panel.

Immediate alerts are for work that is active, directed, or likely to block a
visitor. Digests are for lower-pressure awareness. Escalation should be
explicit, auditable, and scoped before it becomes automatic.

## Current Foundation

Wayfindr already has the pieces needed for calm alert routing:

- dashboard notifications for support work that needs attention;
- queued email delivery for configured installs, including digest email;
- mail readiness checks and a mail smoke command;
- agent profile preferences for all supported-site alerts, assigned-only alerts,
  quiet mode, email delivery, and email cadence;
- hourly alert digest scheduling through Laravel's scheduler;
- digest delivery state on the agent profile, account roster, and operator
  readiness screens;
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

The current email on/off preference remains the master switch. Immediate cadence
sends configured email alerts as events happen. Digest cadence keeps dashboard
notifications immediate, skips event-by-event email, and lets operators queue
metadata-only digest email through `php artisan wayfindr:send-alert-digests`.
Digest delivery records which alert notifications were queued so unchanged
unread alerts do not resend on every run. Wayfindr registers the digest command
hourly with Laravel's scheduler; self-hosted operators still need the normal
one-minute `php artisan schedule:run` entry or equivalent platform scheduler.

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

## Current Checkpoint

The first digest path is intentionally modest and useful:

- agents choose immediate or digest email cadence from their profile;
- dashboard notifications stay immediate so the app remains current;
- digest-enabled agents skip event-by-event email for eligible support alerts;
- `php artisan wayfindr:alert-digest-preview` shows metadata-only candidates
  without sending mail;
- `php artisan wayfindr:send-alert-digests` queues metadata-only digest email;
- Laravel's scheduler registers the digest command hourly;
- the digest runner records queued, no-alerts, and failed delivery states;
- agents can see their latest digest delivery state on their profile;
- account admins can review each agent's alert cadence and latest digest state
  from the account roster;
- operator readiness flags failed digest delivery without exposing raw provider
  errors in the UI.

Manual escalation is also in place: agents can escalate a ticket to another
eligible site agent with a reason, assignment notification, audit event, and
ticket timeline entry.

This closes the first alert-calm foundation. Automatic account-level escalation
rules remain a separate future product track in #156.

## Implementation Waypoints

1. Document the digest and escalation boundary. Done: this document records the
   current foundation, operator expectations, and deferred policy work.
2. Add an agent-facing delivery cadence preference without changing delivery
   behavior. Done: agents can store immediate or digest cadence while current
   alert delivery remains unchanged.
3. Add a digest candidate service with tests for account, site, role,
   quiet-mode, and deactivated-agent boundaries. Done: the collector now
   gathers visible unread metadata-only candidates for digest-enabled agents
   without sending mail.
4. Add a dry-run console command that prints which digest items would be sent to
   which agent. Done: operators can run
   `php artisan wayfindr:alert-digest-preview` to inspect metadata-only digest
   candidates without sending email.
5. Add queued digest mail with safe metadata-only content. Done: operators can
   run `php artisan wayfindr:send-alert-digests` to queue digest email for
   digest-enabled agents with current candidates. Wayfindr also registers this
   command with Laravel's scheduler hourly, so self-hosted installs only need
   the normal one-minute `php artisan schedule:run` job for digest delivery to
   move.
6. Record digest delivery state for agents and operators. Done: digest attempts
   record queued, no-alerts, or failed state; agents can see their own latest
   state, account admins can review team delivery state, and operator readiness
   flags failed delivery without leaking provider errors.
7. Add a simple manual escalation event and audit trail. Done: agents can
   escalate a ticket to another eligible site agent with a reason, assignment
   notification, and ticket timeline entry.
8. Add account-level default cadence and escalation timing only after the
   per-agent path is proven.
9. Add automatic escalation policies only when there is a clear account setting,
   tests, and UI copy that explains what will happen.

## Open Questions

- Should the first cadence be daily only, or should Wayfindr also support a
  short working-hours digest?
- Should digest scheduling use local server time, account timezone, or agent
  timezone first?
- How visible should skipped digest items be when an agent loses site access
  before send time?
- Should support codes become the primary email reference for conversations so
  agents can search their inbox without exposing more visitor data?
- When account-level escalation policies arrive, should manual escalation target
  one agent, all site agents, or a named team?
