# Account Escalation Policies

Status: planned. This document defines the product boundary for future automatic
account-level escalation before Wayfindr adds policy UI, timers, or background
jobs.

## Principle

Escalation should help teams catch neglected support work without making the
dashboard feel punitive.

The current foundation is manual escalation plus alert digest delivery. Automatic
escalation should only arrive after the account can explain what will happen,
who will be notified, when it will happen, and how to turn it off.
Every automatic escalation path should be opt-in, auditable, and easy to
disable.

## Policy Shape

The first policy should belong to the account, not to the platform operator and
not to an individual agent. It should define account-level escalation defaults
that every supported site can inherit.

Minimum account settings:

- whether automatic escalation is enabled;
- account timezone;
- working hours and working days;
- default waiting thresholds by priority;
- default fallback behavior when the assignee cannot respond;
- whether site-level overrides are allowed later;
- who may manage the policy;
- who receives policy-change audit events.

Site-level overrides can come later if real teams prove they need different
coverage by property. Until then, one account-level policy is easier to explain,
test, and disable.

## Timing

Timing should be based on support work waiting for a human decision, not on raw
record age.

Good timing anchors:

- a visitor message waiting for an agent reply;
- a ticket marked as needing reply;
- a high-priority ticket without an active assignee;
- a manually escalated item that has not been acknowledged.

Avoid timing anchors that create noise:

- any new message, regardless of sender;
- any ticket update, regardless of status;
- every old open ticket;
- work outside the agent's site access scope.

Working hours should use the account timezone first. Agent timezone can become a
later refinement, but the first implementation should keep the policy easy to
reason about for small self-hosted teams.

## Priority Thresholds

Priority thresholds should shorten or lengthen waiting time. Priority alone
should not trigger escalation.

Suggested first defaults:

| Priority | Default threshold |
| --- | --- |
| Urgent | 15 minutes during working hours |
| High | 1 hour during working hours |
| Normal | 4 working hours |
| Low | Next working day |

These are placeholders until the product has enough dogfood data. The UI should
make clear that each account owns its own thresholds.

## Fallback Behavior

Fallback behavior should be deliberate and boring:

- if a ticket has an active assignee with site access, escalate to that assignee
  first;
- if the assignee is deactivated or no longer has site access, escalate to
  eligible agents for the site;
- if the site uses account-wide fallback access, escalate to active account
  agents whose alert preferences allow it;
- if no eligible recipient exists, create an account-visible policy warning
  instead of sending cross-account or platform-operator alerts.

The fallback path should never notify a user who cannot view the underlying
conversation, ticket, or site.

## Agent Preferences

Automatic escalation must respect existing agent preference boundaries:

- quiet mode suppresses automatic escalation notifications to that agent;
- assigned-only mode should only notify when the work is assigned to that agent;
- digest cadence should not turn an escalation into immediate email unless the
  account policy explicitly says escalations bypass digest cadence;
- deactivated agents should never receive support escalation notifications.

The account policy may define a stronger team rule later, but the first version
should not surprise agents who already opted into quieter alerts.

## Copy And Content

Escalation copy should be calm and specific.

Good copy answers:

- what needs attention;
- which support code or ticket is involved;
- which site is affected;
- why the escalation happened;
- who configured the policy;
- what action the recipient can take.

Email and digest content should stay metadata-first. Do not include visitor
messages, transcript excerpts, cobrowse snapshots, visitor page data, or private
notes in automatic escalation mail unless a later account-level setting makes
that explicit and the privacy documentation is updated.

## Audit Requirements

Every escalation policy change should create an audit event with:

- actor;
- account;
- changed setting names;
- before and after values safe enough for audit display;
- timestamp;
- source route or command.

Every automatic escalation event should record:

- target record;
- matched policy;
- timing reason;
- recipient set;
- skipped recipients and safe skip reasons;
- notification channels attempted;
- delivery status when available.

Audit views should be metadata-first. Raw provider errors, transcript content,
and visitor supplied data should stay out of account activity feeds.

## Sanity Checks

No automatic escalation should ship until tests prove:

- opt-in behavior;
- easy to disable behavior;
- same-account boundaries;
- site access boundaries;
- quiet mode respect;
- assigned-only respect;
- digest cadence behavior;
- deactivated agents are skipped;
- no eligible recipient creates a safe warning instead of a leaked alert;
- policy changes create audit events;
- automatic escalation events create audit events;
- metadata-first email and notification content;
- account timezone and working hours handling;
- priority thresholds do not escalate by priority alone.

## Implementation Waypoints

1. Keep this document and the existing digest/manual-escalation foundation as
   the product contract.
2. Add a read-only account policy preview that says automatic escalation is not
   enabled yet and explains the future shape.
3. Add account-level policy storage behind owner/admin authorization.
4. Add policy-change audit events before any background escalation runner.
5. Add a dry-run command that reports which records would escalate and why.
6. Add automatic dashboard notifications only after the dry-run path is trusted.
7. Add email escalation only after metadata-safe content, mail readiness, and
   delivery-state behavior are tested.
8. Add site-level overrides only after account-level defaults prove too coarse.

## Open Questions

- Should urgent escalations bypass digest cadence by default, or should accounts
  explicitly opt into that behavior?
- Should the first policy have a single team fallback target, or derive eligible
  recipients from site access only?
- Should policy warnings live on the account overview, operator readiness, or a
  dedicated admin settings route?
- Should a future hosted Wayfindr service offer default templates while keeping
  self-hosted policy ownership local?
