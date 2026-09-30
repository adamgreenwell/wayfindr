# Visitor Contact Management

Wayfindr's contact-management foundation builds on the visitor record and its
existing safe host context. It does not introduce another visitor tracker or a
second copy of contact values.

## Defined attributes

An account owner or admin can define up to 20 visitor attributes. Each
definition gives one safe `visitors.metadata.context` key an agent-facing label
and one of four interpretations:

- `text`: a non-empty value of at most 160 characters;
- `number`: an unambiguous integer or decimal;
- `boolean`: a known true/false value; or
- `date`: a real ISO `YYYY-MM-DD` calendar date.

Keys are lowercase snake case and immutable after creation. Keys that the
visitor-context sanitizer treats as sensitive — including identity,
authentication, payment, and address fields — cannot be defined. The label and
type may change, but changing the type does not rewrite stored visitor data. A
stored value that does not match the current type appears as **Not set**.

The visitor directory offers an exact typed-value filter for defined
attributes. The visitor profile displays definitions with their friendly labels
and removes the same keys from the raw Host context table, avoiding duplicate
and inconsistently interpreted values.

## Authority and privacy boundary

`manage_contacts` is a delegable account permission. It permits definition
management and visitor directory/profile access, but site assignments still
decide which visitors a person can see. The permission does not grant access to
conversation or ticket history. A contacts-only role therefore sees the contact
record for an assigned site without learning conversation subjects, support
codes, entry pages derived from conversations, or ticket details.

Definitions are account-owned operational metadata. Creating, editing, and
deleting them writes an account audit event containing only the key, label, and
type. Visitor values are never copied into that audit trail.

Deleting a definition removes the friendly display and filter; it does not
silently delete the underlying host context. Erasing visitor data needs a
separate, explicit retention or deletion workflow.

## Contact notes

Contact notes are private, append-only team context attached directly to a
visitor. They remain available when an individual ticket closes or is deleted,
so a later teammate can understand durable preferences and prior context
without treating one ticket as the person record.

Anyone authorized to open the visitor profile may read its contact notes.
Adding or deleting a note requires `manage_contacts`; the same site assignment
boundary still applies. Notes are never sent to the visitor or relayed to an
external ticket. Each body is limited to 4,000 characters, and the UI asks
agents to avoid unnecessary sensitive data.

Notes cannot be edited in place. A teammate can add a correction or delete a
note. Deletion permanently removes the body from the application database,
while the account audit retains only the note ID and lifecycle action. The note
body cascades with visitor, site, or account deletion, though infrastructure
backups remain subject to the operator's separate backup retention policy.

## Identity merge

A contact manager can search for another visitor on the same site and merge the
current duplicate into the contact the team chooses to keep. This is an
explicit human identity decision: a host visitor ID arrives through a public
browser request and is useful as a reference, but it is not authentication and
does not silently merge customer history. Different populated host visitor IDs
therefore block the operation.

The chosen contact keeps populated name, email, host ID, and custom attribute
values; the duplicate fills only blanks. The newest sightings and page context
are retained, while conversations, tickets, contact notes, cobrowse sessions,
visitor-authored messages, and uploads move to the chosen record. The operation
is permanent. Existing audit facts keep their action, type, time, and metadata;
only visitor actor/subject row IDs are re-anchored to the chosen contact so the
events remain labeled and searchable. The merge writes a body-free audit receipt
containing only internal IDs and moved-row counts.

The deleted contact's anonymous browser ID and any earlier merged IDs become
private aliases of the chosen contact. Widget presence, bootstrap, conversation
intake, signed sessions, and authorized support search all resolve those
aliases. Conversation creation, visitor messages, and pending uploads resolve
again after taking the shared site lock, so a request that began just before a
merge cannot recreate the duplicate or write an orphaned sender. Visitor-authored
cobrowse and rejected attachment-scan audit receipts use the same locked
re-resolution, keeping their actor attached to the chosen contact. All widget
conversation authorization resolves the token, alias, and conversation under
that same site lock; writes that persist visitor ownership or presence then
re-resolve at their final write boundary under shared account and site locks,
so a merge cannot create a false 404 or leave a stale owner without serializing
independent widget traffic across the account. Alias token
lineage is retained across later merges, but cascades when the chosen visitor or
site is deleted. Each alias keeps at most the 50 most recent prior visitor IDs;
an older tab beyond that unusual chain must bootstrap again. An old token cannot
authenticate a new visitor that later reuses the browser ID.

## Contact export

A contact manager can export up to the 500 most recent visitors matching the
directory's current search, site, presence, and defined-attribute filters. The
same supported-site scope used by the screen is applied again by the download;
the URL cannot widen it. CSV headers are stable English machine fields, dates
use the downloading agent's timezone in a sortable format, and values are
neutralized before a spreadsheet can interpret visitor content as a formula.

The export contains internal visitor and site IDs, site name, the visitor's
name, email, host and anonymous IDs, contact and web-sighting timestamps,
creation time, and normalized values for the account's currently defined
attributes. Raw host context, page addresses, contact notes, support history,
and browser-ID alias lineage are intentionally omitted. Export requires the
delegable `manage_contacts` permission; directory readers who have only ticket
or conversation access cannot make a bulk download.

## Erasing a contact

When a person asks the operator to delete everything held about them, an
agent with `handle_data_requests` erases the contact from its profile
([ADR 0026](../decisions/0026-erasing-and-exporting-a-visitor.md)). The
permission is granted to Owner and Admin, is assignable to a custom role only
alongside `manage_contacts`, and needs support access to the contact's site,
like every other contact action.

Before anything is deleted, a summary shows what goes, which tickets stay, and
what erasure cannot reach, including the linked external issues by URL. The
agent confirms by typing `ERASE` and their current password. An agent who
signs in only through single sign-on has no password they know, and sets one
through the password reset link first. The erasure then, in one transaction:

- deletes the contact's identity, browser-ID aliases, contact notes, cobrowse
  sessions, conversations, messages, ratings, uploaded files, proactive-message
  deliveries, the alerts that name their conversations or tickets, and those
  conversations' SLA clocks and automation history;
- keeps each of their tickets as a work item, with status, priority, labels,
  assignee, SLA outcome and external issue link. The subject becomes
  `Ticket #N (requester erased)`, and the description, visitor details,
  support code, note bodies and any note copies queued for the provider go.
  The ticket stops copying new comments from its linked issue, and SLA alerts
  not yet sent for it are cancelled;
- keeps every audit entry about them, with its text replaced and the contact
  removed as an actor, so the account's record of who did what survives;
- cancels webhook deliveries still pending for their conversations, and
  clears the stored reply on every webhook delivery about their
  conversations or tickets, which can echo what the subscriber fetched;
- clears text kept in passing that can quote them: the queue search saved by
  a bulk action that selected their work, which stays undoable, the error
  text of a failed automation run on one of their tickets, and failed
  background jobs that name them by email address or support code;
- keeps the record of any break-glass access to their conversations, with its
  reason, but relabels it `Conversation (deleted)`.

Erasure refuses, and erases nothing, while something about the contact is
being sent to an outside service at that moment: a note posting to a linked
issue, the AI assistant working on one of their conversations, or an alert
email about their work on its way to the mail server. The agent is asked to
try again in a few minutes. Once that call has ended, whatever it sent is out
of reach like any other copy already sent. An alert email built before the
erasure but not yet sent is stopped by the check it makes just before
sending.

Uploaded files are removed from storage after the transaction commits. The
erasure's record lists them from inside the transaction and strikes each off
as it goes, so if storage is unreachable, or the server stops mid-way, the
hourly `wayfindr:finish-erasures` removes what is left. Live boards drop
the contact at once.

The erasure is recorded in `visitor_erasures` and in a `visitor.erased` audit
event, with counts and a receipt reference, never who was erased. The ledger
row keeps only internal IDs: the contact's, and those of any contacts merged
into it, which a restore from before that merge would bring back. The
reference is shown after erasing, for the reply to the person who asked.

**Erasures survive a restore.** Each erasure is also written to a ledger on
the storage volume, in `storage/app/erasure-ledger/`. Backups do not carry
that directory, so a restore cannot roll it back
([ADR 0026 §8](../decisions/0026-erasing-and-exporting-a-visitor.md#8-erasures-survive-a-restore)).
`wayfindr:restore` reads it:
- **Before** it replaces the database, it settles any erasure that was
  interrupted.
- **After**, it moves the visitor ID sequence past every erased ID, so no new
  contact can inherit one.
- **Then** it erases again everyone the archive brought back, including
  contacts merged into them, and their files, and says how many.

An archive older than the running code needs its migrations first, so its
erasures are re-applied when `php artisan migrate --force` finishes.

Two things it cannot do:
- **Restore onto a new storage volume**, such as disaster recovery onto new
  hardware, has no ledger to read. Keep `storage/app/erasure-ledger/` alongside
  your backups.
- **Load a dump any other way**, by hand, and nothing is re-applied.

In either case, keep receipt references outside Wayfindr, and erase those
people again.

Erasure does not reach backups taken before it, external issue trackers, mail
already sent, the AI provider, API consumers or logs. It does not stop future
collection either: a returning browser is a new contact, and a host page that
sends the same visitor ID recreates the record. A `VisitorEraser::COVERAGE`
map, held to the live schema by a test, says what erasure does to every table
that can reach a visitor.

## Deliberately not in this slice

CSV import, segmentation, CRM sync, and marketing automation remain separate
decisions.
