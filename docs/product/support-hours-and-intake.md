# Support hours and visitor intake

What a visitor meets *before* a conversation starts: whether the desk is open,
what they are told when it is not, and what is asked of them on the way in.

These belong in one document, and behind one configuration surface, because they
are the same moment from the visitor's side. Growing them into separate screens
was called out as a mistake to avoid in [#744](https://github.com/adamgreenwell/wayfindr/issues/744)
and [#746](https://github.com/adamgreenwell/wayfindr/issues/746).

## Support hours

A site can carry an availability schedule. Until one is configured a site is
**always open**, which is how every site behaved before this existed — absence of
configuration must never read as "closed", or enabling the feature would shut
every desk on upgrade.

Configured under **Sites → the site → When the desk is open**, by an account
admin.

| Setting | Meaning |
| --- | --- |
| Keep support hours | Off means the widget behaves identically at 3pm Tuesday and 3am Sunday |
| Timezone | The zone the schedule is read in. Per site, because one account can carry sites in different regions |
| Weekday hours | Open and close time per day. A day with no hours, or an end not after its start, is closed |
| Away message | What an out-of-hours visitor is told, in your own words |

### Why hours are per site, and read in the site's zone

An account can carry sites in different regions, so a shared schedule would be
wrong for at least one of them. Times are compared as minutes-from-midnight in
the site's own zone rather than as stored UTC instants, so a schedule does not
drift by an hour when daylight saving changes.

### Closing early

`closed_until` marks the desk closed regardless of the schedule, until the
moment it names. It is deliberately a time rather than a flag: closing early
should recover on its own at the next scheduled opening rather than becoming a
switch somebody forgets on Monday.

Editing the schedule does not clear it — reopening a desk somebody closed early
is a separate decision from changing the hours.

The reopening time reported to visitors is the *real* one. A close ending inside
open hours reopens at that moment rather than at the next scheduled start,
because promising tomorrow while the desk is answering in ten minutes sends
somebody away for nothing.

## What the visitor sees

The widget is told only what it needs: that the desk is away, the operator's
message, and when it opens next. **The schedule itself stays server-side**, so a
site's working pattern is not published to every visitor.

Away state is derived on the server and rides the existing bootstrap response, so
the widget needs no second request and no clock of its own — a visitor with a
wrong system clock cannot make support look open.

It is re-read **every time the panel is opened**, not once per page load, and
**sending waits for that answer**. A tab reopened on a slow connection would
otherwise let somebody type and send before the notice arrived, which is the one
thing this exists to prevent. Only the newest answer may update the panel, so a
slow earlier request cannot land afterwards and erase it. A tab
left sitting since before closing time would otherwise still show the desk as
open, and one opened while away would keep saying away long after support came
back — both silent, and both wrong at exactly the moment somebody decided to
type.

The return time is rendered in the *visitor's* locale and timezone. They care
what time it is where they are.

### The away message is operator copy

It is shown exactly as typed, as text rather than markup. Two consequences:

- **It is not an English string the widget invented**, so an install serving
  visitors in any language works today rather than after interface translation
  ([#749](https://github.com/adamgreenwell/wayfindr/issues/749)) lands.
- **It is public.** It reaches every visitor of that site, so it must not carry
  anything private.

If no message is set, the widget says something plain and true rather than
nothing at all.

## Visitor intake

An anonymous visitor — most traffic on most sites — used to start a conversation
with no name, no email and no stated reason, and end it with no way to be
reached about anything unresolved.

`visitors.name` and `visitors.email` have existed since the first migration and
were written by **nothing at all**. SDK identification writes `external_id` and
only that, and an external ID is deliberately not an email. So the columns were
there and the answer was never asked for.

Configured under **Sites → the site → What to ask before a conversation starts**.
Each of **name**, **email** and **reason** is off, optional, or required.

### Where the answers go

**Name and email go on the visitor**, so the next visit already knows them.
**The reason goes on the conversation**, because the next one may be about
something else entirely.

A blank optional answer never erases what an earlier conversation captured.

### The server decides, not the widget

The widget is told what to draw. The server applies the rules on the way in:
a required field is required, and **a field the site does not ask for is
refused** rather than quietly stored. Otherwise the configuration would be
advisory and anyone could post whatever they liked against a visitor.

### Already-identified visitors are not asked again

If the host app identified the visitor through the SDK, the form is skipped.
Asking a signed-in customer for their email is the fastest way to make a widget
feel unfinished.

This uses the **server's** view of whether the visitor is identified, exposed on
the bootstrap response. The widget's own option can be set while the value was
rejected — sanitised away, or already claimed by another visitor — so trusting
the client would ask the wrong people.

### Out of hours, email is required

Whatever the site normally asks, an email is required when the desk is away,
because it is the only way back to somebody. The same promotion is applied on
the server, not just in the form.

This is what turns a visitor who arrives at 3am from lost into answerable.

### Why this cannot ride on visitor context

`VisitorContextSanitizer` strips anything resembling an email from
`metadata.context` and from `external_id`, and should keep doing so: that channel
carries whatever a host page happens to hold, which nobody consented to send.

An address typed into a form that asked for it is a different thing. It gets its
own field, and is recorded in the [data inventory](../privacy/data-inventory.md)
as such.

## Why this is not the unattended-conversation alert

`SendUnattendedConversationAlertsCommand` already notices that a conversation
went unanswered. That is the right mechanism pointed at the wrong end of the
problem: it fires after the visitor has been ignored. Telling somebody nobody is
home, before they start typing, prevents the conversation the alert would
otherwise chase.

## Not covered here

- **Holiday calendars.** Out of scope.
- **Per-agent availability.** This is whether the *desk* is open, not who is at
  it. Agent presence belongs with routing.
- **Pausing SLA outside hours.** Real, and it belongs with SLA policies.
