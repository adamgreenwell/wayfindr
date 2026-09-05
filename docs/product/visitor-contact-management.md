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
re-resolution, keeping their actor attached to the chosen contact. Alias token
lineage is retained across later merges, but cascades when the chosen visitor or
site is deleted. Each alias keeps at most the 50 most recent prior visitor IDs;
an older tab beyond that unusual chain must bootstrap again. An old token cannot
authenticate a new visitor that later reuses the browser ID.

## Deliberately not in this slice

Contact export, CSV import, segmentation, CRM sync, and marketing automation
remain separate decisions.
