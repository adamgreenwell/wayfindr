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

## Delivery evidence

Effectiveness needs three explicit outcomes: shown, engaged, and dismissed.
Those receipts should be bounded, site-scoped records used for caps and
aggregate reporting. They must not become a browsing-history log: do not store
the matched URL, referrer, or a sequence of pages.

## Current implementation boundary

The first slice provides the site-scoped rule model and dashboard management
surface. It does not yet publish rules to the widget, authorize a display,
render an invitation, create a conversation from engagement, enforce a cap, or
record delivery evidence. Until that delivery slice lands, saved rules are
configuration only even when marked enabled.
