# Proactive messaging

Proactive messages are small, site-owned invitations that can open the support
widget when a visitor appears likely to need help. They should feel like a
timely welcome, not a pop-up campaign system.

## Rule contract

An agent with the `manage_automations` permission and access to the site can
create, edit, order, enable, and delete its rules. New rules start disabled.
Every rule contains:

- an internal name and visitor-facing plain-text message;
- optional page-URL and referrer substring conditions;
- a time-on-page delay and minimum visit count;
- an optional requirement for an eligible support agent to be online;
- mandatory display-frequency and dismissal-snooze limits; and
- an explicit position, with row ID breaking ties deterministically.

All filled conditions must match. The first eligible rule in that stable order
wins, so a page load cannot stack several invitations.

## Privacy and delivery boundaries

URL and referrer matching belongs in the visitor's browser. The configuration
needed for that comparison is public widget configuration, but the page and
referrer values used in the comparison are not a new server payload and must
not be copied into delivery evidence.

The server remains the authority for whether an otherwise matching rule may be
shown. Delivery must re-check that live visitor presence is enabled, support is
inside configured hours, any required eligible agent is online, and the
visitor's frequency and dismissal caps still allow the invitation. A rule can
be enabled in configuration while those gates keep it inert.

The visitor-facing message is rendered as text, never trusted HTML. Lifecycle
audit events retain the rule name, state, order, and changed field names, but do
not copy the message, URL condition, or referrer condition into audit metadata.

A display is not a conversation. The normal conversation opening should happen
only when the visitor engages with the invitation, avoiding empty conversations
and preserving the distinction between browsing and asking for support.

Authorization is a five-minute, idempotent claim. It is intentionally separate
from the shown receipt: a response lost before rendering must not spend the
visitor's display cap. Claims and caps are serialized through the visitor row,
so simultaneous tabs cannot both win. Once shown, the frequency cap applies
site-wide across rules; a dismissal likewise snoozes every rule for the
configured window. Browser storage mirrors those controls for an immediate
cross-tab experience, while the server remains authoritative.

“Available” means an active account agent who can view conversations, is in the
site's explicit support roster when one exists, and has marked their routing
status online. Support hours reuse the site's ordinary availability result.

## Delivery evidence

Effectiveness needs three explicit outcomes: shown, engaged, and dismissed.
Those receipts are bounded, site-scoped records used for caps and aggregate
reporting. They do not store the matched URL, referrer, or a sequence of pages.
The dashboard reports per-rule shown, engaged, and dismissed counts for the
latest 90 days, and a daily scheduled command deletes the underlying evidence
90 days after its last outcome. The fixed window preserves the full server-side
dismissal promise while keeping this browsing-adjacent evidence bounded.

## Current implementation boundary

The delivery slice publishes enabled rules only while opted-in presence is on.
The stock widget matches page, referrer, visit count, and delay locally; asks
the server to authorize the first eligible rule; renders one plain-text
invitation; and records shown, engaged, or dismissed outcomes. Viewing an
invitation does not create a conversation. If the visitor opens it and sends a
reply, the server inserts the exact invitation snapshot as the support-side
opening in the ordinary conversation, then the visitor's message follows it.

This remains a small invitation system rather than a campaign engine: there
are no chatbot branches, variants, inferred audiences, or automatic empty
conversations. A visitor action is required before anything enters the support
queue.
