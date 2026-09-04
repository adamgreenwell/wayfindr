# Automatic assignment and routing

Wayfindr can assign new conversations and tickets as they arrive. Routing is
configured per site and is **off by default**, so an upgrade does not change the
manual claim workflow for any existing desk.

Configured under **Sites → the site → Automatic assignment**. Each agent sets
their own current state under **Agent profile → Assignment availability**.

## Who enters the rotation

An agent is eligible only when all of these are true:

- they belong to the site's account and are not deactivated;
- their explicit assignment status is **Online** rather than **Away**;
- the site's fallback or explicit support-access roster includes them; and
- their role can view conversations for conversation routing, or manage tickets
  for ticket routing.

No eligible agent is a normal result. The work stays unassigned and the existing
manual claim or ticket-assignment controls remain available. Routing never falls
back to an agent outside the site's explicit roster.

Online and away describe eligibility for *new* work. Going away does not move or
unassign existing work, because silently handing off a conversation somebody is
already handling would be more disruptive than leaving it visible in their
queue. Deactivation moves an online agent to away, and reactivation does not opt
them back in behind their back. Reassignment and unassignment remain explicit
actions.

## Round-robin behavior

Each site keeps a durable last-assigned cursor. Conversations and tickets have
separate cursors, so a run of ticket creation cannot change which agent receives
the next live conversation. Eligible agents are ordered by their stable database
ID; routing chooses the first ID after the cursor and wraps to the beginning.

The cursor is advanced in the same transaction as the assignment. Concurrent
arrivals for one account serialize through the account row before reading agent
state, site access, capacity, or the cursor, so two requests cannot both consume
the same turn.

## Conversation capacity

Each site sets a maximum number of active conversations per agent, from 1 to
100. The default shown before configuration is 5. Capacity counts every
conversation assigned to that agent in the account whose status is not
`closed`, including work from other sites. This is an agent workload ceiling,
not a per-site allowance that can be multiplied by adding sites.

An agent at the ceiling is skipped. If every eligible agent is full, the new
conversation stays unassigned. Closing a conversation releases capacity for the
next arrival.

Tickets do not consume the conversation ceiling. They still rotate among online,
authorized agents, but ticket workload and live-conversation concurrency are
different operating pressures. Manual assignments are not rejected or moved by
the ceiling.

## Arrival and audit boundaries

Routing runs synchronously when the conversation or ticket is created, before
the corresponding outbound created webhook is published. A consumer therefore
sees the committed arrival assignment rather than an intermediate unassigned
state.

Assignment changes use these audit actions:

- `conversation.assignee_updated`
- `ticket.assignee_updated`
- `agent.routing_status_updated`
- `site.routing_updated`

Assignment events preserve old and new agent IDs and names. Automatic events
have a system actor plus `source=automatic` and `strategy=round_robin`; dashboard
changes carry the acting agent and `source=manual`. The site configuration and
agent availability changes are audited only when their stored value changes.

## Deliberate limits

This first strategy does not infer skills, language, teams, schedules, or future
availability from realtime socket presence. Site support hours describe whether
the desk is open to visitors; an agent's online/away status describes whether
automatic routing may choose that person. Keeping those two facts separate
avoids a site schedule silently overriding an explicit individual decision.
