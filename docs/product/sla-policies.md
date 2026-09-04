# SLA policies

Status: shipped. Agents with site-management access configure targets under
**Account → SLA policies**.

Wayfindr can keep first-response and resolution commitments for each support
priority. The clocks are operational guidance, not contract, billing, or
penalty machinery: they tell the desk what is approaching, what has passed, and
what happened in the selected reporting period.

## Targets

An account can set separate targets for urgent, high, normal, and low priority
work. Each priority has two independently optional values:

- **First response** applies to conversations. The first message from an agent
  satisfies it.
- **Resolution** applies to conversations and tickets. Closing the work
  satisfies it; reopening starts a new resolution episode.

A standalone ticket has no guaranteed visitor-reply channel, so it does not
pretend to have a first-response clock. Either target can be left blank without
disabling the other.

Conversations now carry the same four priority values as tickets. Changing a
conversation or ticket priority applies the target for the new priority to its
active clocks. Disabling a target cancels active clocks without deleting their
history.

## Business time

Only time inside the work item's site's support hours counts. With no schedule,
the site is always open, matching the visitor-facing availability rule. A
manual early close pauses the clock too.

Each clock persists seconds already consumed. Before support hours, a manual
closure, or an account SLA policy changes, Wayfindr advances affected clocks
under the settings that were in force. The new settings therefore change the
future without rewriting yesterday.

When a target is first enabled, existing untracked open work starts at that
moment with zero consumed time. Backdating those clocks to the work's creation
would invent breaches under a policy that did not exist.

## States and alerts

The queue and the work detail show the same states:

- **On track** while less than 80% of the target is consumed;
- **Paused** while the site's desk is closed;
- **Approaching breach** from 80% until the deadline;
- **Breached** once the active clock reaches the target;
- **Met** or **Missed** after the work satisfies the clock.

When several clocks exist, the queue shows the one needing the most attention;
the detail shows all of them. Completing work exactly on its target is met if
the deadline evaluator has not already recorded a breach.

The scheduler advances active clocks each minute and creates one warning and
one breach alert at most for each clock. The assigned eligible agent receives
the alert; unassigned work falls back to eligible agents for the site. Quiet
mode, deactivation, permissions, and current site access are rechecked. Email
follows the agent's immediate-or-digest preference and contains support
metadata rather than transcript or visitor content.

The fixed unattended-conversation alert now uses the same business-time
calculator. It no longer wakes agents at night for five wall-clock minutes that
the SLA view correctly calls paused. Each unread waiting episode persists the
business seconds already consumed before support hours or a manual closure
changes, so reopening a desk cannot turn its closed interval back into waiting
time.

## History

The Speed report counts persisted breaches within its 7, 30, or 90 day range
and current warning/breached pressure for the selected site scope. The recent
table links to the 25 newest breached work items. A breach remains in history
after the work is completed; a policy edit or disabled target does not erase
it.

History begins when SLA policies are enabled and work receives a clock. It is
not reconstructed from old conversations or tickets, because the earlier
calendar and target are unknowable.

## Deliberate boundaries

- No automatic escalation or priority changes.
- No contract-specific calendars, holiday calendars, penalties, or billing.
- No per-agent work schedules; support hours describe the site desk.
- No retroactive breach fabrication for work predating the policy.
