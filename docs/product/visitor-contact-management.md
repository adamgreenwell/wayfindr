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

## Deliberately not in this slice

Contact notes, identity merge, contact export, CSV import, segmentation, CRM
sync, and marketing automation are separate decisions and remain outside this
foundation.

