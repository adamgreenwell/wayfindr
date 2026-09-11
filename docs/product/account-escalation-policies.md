# Account Escalation Policies

Status: still planned, and still the open gap it always was — nothing in the
product escalates ownership of neglected work on elapsed time. What has changed
is the ground underneath it. This document was written before Wayfindr had any
policy UI, timers, or background jobs; it now has all three, for SLA deadlines
rather than for escalation, which means the question is no longer how to build
that machinery but which parts an escalation policy should inherit rather than
reinvent. Read the settings below with that in mind: several name a clock or a
schedule the product has since chosen a different home for.

## Principle

Escalation should help teams catch neglected support work without making the
dashboard feel punitive.

The foundation is now larger than manual escalation plus alert digests. Account
SLA policies keep business-time clocks against site support hours and raise
approaching, breached, met and missed states; a cross-channel delivery ledger
decides which channel carries an alert and stops the others repeating it; and
automation rules react to bounded ticket, conversation and visitor-message
events. None of that escalates *ownership* — an SLA breach tells the desk a
target was missed, it does not move the work to somebody else — which is
exactly the gap this document still describes.

Automatic escalation should only arrive after the account can explain what will
happen, who will be notified, when it will happen, and how to turn it off.
Every automatic escalation path should be opt-in, auditable, and easy to
disable.

## Policy Shape

The first policy should belong to the account, not to the platform operator and
not to an individual agent. It should define account-level escalation defaults
that every supported site can inherit.

Minimum account settings:

- whether automatic escalation is enabled;
- default waiting thresholds by priority;
- default fallback behavior when the assignee cannot respond;
- who may manage the policy;
- who receives policy-change audit events.

Three settings this list originally named are deliberately gone, because the
product has since put those clocks somewhere else and a fourth copy would be a
fourth thing to disagree:

- **Account timezone and account working hours.** Business time belongs to the
  site, not the account: SLA clocks already pause against each site's support
  hours, and automatic assignment is configured per site for the same reason. An
  escalation policy should read the work item's site schedule.
- **Whether site-level overrides are allowed later.** The question answered
  itself — the schedule is already per site.

Per-agent timezone exists too, and governs quiet-hour suppression rather than
business time. An escalation policy inherits both of those clocks; it should not
introduce a third.

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

Business time comes from the work item's site support-hours schedule — the same
source the SLA clocks read, so a breach and an escalation cannot disagree about
whether the desk was open. Per-agent timezone already governs quiet-hour
suppression, and an escalation policy should inherit that too rather than
deciding separately when somebody may be interrupted.

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

The first three of those already exist. `SlaAlertRouting` routes deadline alerts
by exactly that cascade — assigned agent who still has site access, else the
site's eligible support agents, else account agents where the site uses
account-wide fallback — and filters the result by recipient eligibility. An
escalation policy should reuse it rather than write a second one that can
disagree about who may be told.

The fourth has no counterpart. When nothing is eligible, the SLA path returns an
empty recipient set and the alert simply does not go; it raises no
account-visible warning. Silence is a reasonable answer for a missed deadline
and a poor one for work nobody owns, so that bullet remains unbuilt rather than
already solved.

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
- site support-hours handling, and agreement with the SLA clocks about whether
  the desk was open;
- priority thresholds do not escalate by priority alone.

## Implementation Waypoints

1. Keep this document as the product contract, over a foundation that now
   includes SLA clocks, the delivery ledger, and automation rules as well as
   digests and manual escalation.
2. Add a read-only account policy preview that says automatic escalation is not
   enabled yet and explains the future shape.
3. Add escalation policy storage alongside the existing account SLA policies,
   gated on a named account permission rather than a hardcoded role check, so an
   account-owned custom role can be granted it.
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
  recipients from site access only? The cascade under *Fallback Behavior* already
  describes the site-access answer, and `SlaAlertRouting` implements that shape
  for deadline alerts — so the question is now whether to reuse it or offer a
  team target instead, not which one is possible.
- Should policy warnings live on the account overview, operator readiness, or a
  dedicated admin settings route?
- Should a future hosted Wayfindr service offer default templates while keeping
  self-hosted policy ownership local?
