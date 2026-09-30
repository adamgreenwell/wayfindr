# 0026: Erasing a visitor, and exporting what is held about them

Date: 2026-09-30

Status: Accepted

## Context

The operator of a Wayfindr installation is responsible for how visitor data is
"collected, retained, secured, disclosed, exported, and deleted"
([data responsibility](../privacy/data-responsibility.md)). When a visitor asks
that operator to delete everything held about them, or to send them a copy, the
operator has no supported way to do either:

- **Nothing in the product deletes a visitor who has made contact.** The only
  paths that delete a visitor are the presence pruner, which only removes rows
  that never made contact, a contact merge, and deleting a whole site.
- **The only export is the contacts directory CSV.** It covers the 500 most
  recent rows matching a filter, with identity fields and attributes. It is not
  a copy of one person's messages, tickets or files.
- **The [retention posture](../privacy/data-inventory.md#retention-posture)
  says the rest persists indefinitely:** conversations, messages, tickets and
  ratings have no automatic retention.

The obvious implementation, `$visitor->delete()`, is wrong in a way that looks
right. No foreign key that can reach a visitor restricts deletion (the schema's
only `restrictOnDelete` guards custom roles), so it always succeeds.
It cascades through conversations, messages, ratings and copilot output, and
reports nothing about what it left behind. A survey of the schema on
2026-09-30 found what it leaves:

| Where | What survives a plain delete | Why |
|---|---|---|
| `tickets` | The ticket, its subject, a `description` that is a **copy of up to the first 20 transcript messages**, and `metadata.visitor_context` (page addresses and host context) plus the support code | `requester_id` and `conversation_id` are `nullOnDelete` |
| `audit_events` | Ticket note bodies, pending/resolution/reopen notes, escalation reasons, inbound provider comment authors and bodies, attachment filenames, cobrowse page addresses | Subject and actor are polymorphic, with no foreign key |
| `notifications` | Agent inbox rows holding a 160-character preview of the visitor's message, the subject, the support code and the anonymous browser ID | They reference conversations only inside a JSON column |
| Attachment storage | The binaries, on the local disk or S3 | The file is removed only by an Eloquent `deleting` hook, which a database cascade never fires. The hourly sweep reaps them eventually |
| `proactive_message_deliveries` | Delivery rows, detached but keyed by an HMAC of the browser ID | `nullOnDelete`, by design, so frequency caps survive presence pruning |
| `sla_clocks`, `automation_rule_executions` | Rows pointing at conversations and tickets that no longer exist | Polymorphic subject, no foreign key |
| `ticket_external_comment_deliveries` | Encrypted note bodies posted to a provider | They cascade only with the ticket, which survives |
| Backups | Everything | `wayfindr:backup` keeps archives forever by default, and restoring one brings the person back |

The data also leaves the database in ways Wayfindr cannot recall:
- **External issue trackers:** a linked GitHub, GitLab or Jira issue carries the
  ticket subject and description, and any notes an agent chose to post.
- **Mail:** replies already emailed to the visitor.
- **AI provider:** whatever the configured copilot provider retains of prompts
  it was sent.
- **Pull-based consumers:** anything a subscriber fetched through API v1.
  Webhook payloads are thin identifiers by [ADR 0020](0020-outbound-webhook-contract.md),
  but a subscriber may have pulled content with them.
- **Logs:** a few log lines carry inbound recipient addresses and attachment
  filenames.

The product needs one operation that removes a person completely from
everything Wayfindr controls, and says plainly what it could not reach.

## Decision

### 1. Erasure deletes; it does not anonymise the visitor

Erasing a visitor deletes the `visitors` row and every record that exists
because of that person. It does not blank the name and keep the row, because a
kept row still links the conversations, the browser ID, the aliases and the
timeline. The one exception is records the account needs to keep as its own
history, which §3 and §4 keep with the personal content removed.

Deleted, for the visitor, including everything earlier merges moved onto them:

- the visitor row: name, email, external ID, anonymous browser ID, and
  `metadata`, including attribute values, which live in `metadata.context`;
- identity aliases, contact notes and cobrowse sessions (all cascade today);
- **every conversation whose visitor this is**, and with them their messages,
  ratings, read states, the four copilot tables, attachment rows and reply
  deliveries (all cascade today);
- **the attachment binaries**. Their disks and keys are collected before the
  delete and removed after commit, the pattern `SitePurge` already uses, with
  the orphan sweep as the backstop if storage is unavailable;
- **proactive message deliveries**: rows naming the visitor, and detached rows
  whose `visitor_key` matches the key derived from the browser IDs being erased.
  The keys can be computed before the row goes;
- **agent notifications** whose data names one of the erased conversations, or
  a ticket stripped under §3. A ticket's alerts (assignment, SLA, automation)
  store its subject, which may be the person's words;
- **SLA clocks and automation executions** whose subject is an erased
  conversation. A ticket stripped under §3 keeps its own: they are the work
  item's history and hold no content;
- **pending outbound webhook deliveries** for erased resources, which are
  cancelled. Delivered rows keep only identifiers (ADR 0020) and stay as
  delivery history.

### 2. Audit history keeps who did what, not what was said

Audit events are the account's accountability record. Deleting the ones about
an erased person would also delete the record of what agents did. Events whose
subject or actor is the visitor, one of their conversations, cobrowse sessions,
or a ticket stripped under §3 are kept, but:
- their `metadata` is replaced with `{"erased": true}`;
- a visitor actor is cleared.

The action name, the agent actor and the timestamps stay. The event that says
"an agent added a note to ticket 812 on 3 September" survives. What the note
said does not.

### 3. Tickets are kept as work items, with the person stripped out

A ticket is where this gets hard. It can be the person's support history, a
product defect they happened to report, or both. Its subject may be the
visitor's own words or an agent's summary, and nothing records which. The owner
weighed three options and chose B:

- **A. Delete every ticket requested by the visitor or linked to an erased
  conversation.** This is the most complete. It loses the work history, and a
  linked external issue stays in the provider pointing at nothing.
- **B. Keep the ticket as a work item, and strip what came from or describes
  the person.**
  - *Kept:* status, priority, category, labels, assignee, SLA outcome,
    timestamps and the external issue link.
  - *Cleared or replaced:* the description, `metadata.visitor_context`, the
    support code, note and comment bodies in its audit events (§2), and the
    subject, which becomes `Ticket #812 (requester erased)`.
  - *Deleted:* its `ticket_external_comment_deliveries`. Each holds an
    encrypted copy of a note posted, or about to be posted, to the provider,
    and cascades only with the ticket. Deleting them also stops a pending one
    from posting the note after the erasure.
  - The external issue keeps whatever it already holds (§6).
- **C. Ask per ticket at erasure time,** defaulting to B.

**Why B.** A bug a visitor reported is still a bug after they leave,
and the engineering work is usually in the external tracker anyway. Replacing
the subject is blunt, since an agent-written subject is often harmless. But
Wayfindr cannot tell the two apart, and an erasure that depends on an operator
spotting the person's name in a subject is not one. C adds a decision per
ticket at the moment an operator most wants the task done. It can come later if
B proves too blunt.

A ticket created through API v1 with this visitor as requester and no
conversation gets the same treatment.

### 4. What else is kept

- **Break-glass grants** keep their operator-written reason. That is the record
  of a platform operator reading customer data, and its accountability purpose
  outweighs its incidental content.
- **Bulk-action runs** keep their identifier lists. The `return_query` search
  text of a run is not attributable to one person and expires with the run.
- **Queued jobs** carry identifiers only. A job whose row has gone must no-op,
  and the implementation will prove it for every job that loads a conversation,
  message, ticket or attachment.

### 5. Who can erase, and how

- **A new delegable permission, `handle_data_requests`,** covers erasure and the
  export in §7. It is granted to Owner and Admin, and assignable to custom roles
  ([ADR 0023](0023-account-owned-custom-roles.md)). It is separate from
  `manage_contacts`: a team that merges duplicates and writes notes should not
  thereby be able to destroy records. The visitor must also be on a site the
  actor supports.
- **Before anything is deleted, the operator sees a summary:**
  - what will be deleted: counts of conversations, messages, attachments and
    notes;
  - what will be stripped: the tickets, by number;
  - what Wayfindr cannot reach: linked external issues by URL, and the note
    that backups taken before now still hold the data (§6).
- **The operator confirms** by typing `ERASE` and their current password, the
  way the dashboard's other sensitive actions ask for it. Visitors have no
  public reference, so there is nothing more specific to type back. An agent
  who signs in only through single sign-on has no password they know, and sets
  one through the password reset link first.
- **The erasure runs in one transaction,** with the lock order the contact
  merge already uses: account, actor, site (exclusive, so widget writes for
  the site wait), then the visitor and its aliases. The permission and site
  visibility are re-checked under the lock. Binaries are deleted after commit.
- **Live dashboards drop the visitor** through the existing
  `VisitorPresenceUpdated` removal path. That event has to be allowed to
  broadcast a removal when no visitor remains, which after an erasure is
  always the case: it declined for a missing visitor, which left the erased
  contact on screen until the board's next resync. A widget still holding the erased
  visitor's token bootstraps a new, empty visitor next time it loads, exactly
  as a new browser would.

### 6. What erasure cannot reach, said out loud

The summary before confirmation, the receipt after it, and the privacy docs all
say the same thing: erasure covers what this installation stores and nothing
else. It does not reach:
- backups (except as §8 describes);
- external issue trackers;
- mail already sent;
- the AI provider;
- API consumers;
- logs, or the operator's infrastructure.

**It does not stop future collection either.** A person who comes back is a
new visitor. A host page that identifies them by external ID recreates the
record, because that host is still sending it. Honouring an objection to
processing is the host's change to make, not Wayfindr's.

### 7. Export: everything held about one person

The same permission downloads a ZIP for one visitor:
- `visitor.json`: identity fields, attribute values, known browser IDs, contact
  notes;
- one file per conversation: messages, ratings, attachment metadata, the
  conversation's cobrowse sessions (page state, snapshots and mutations
  still held, which retention may already have pruned), and its copilot
  output (summary, reply draft, suggested ticket title and suggested
  articles);
- `tickets/`: requested tickets with their notes;
- `alerts.json`: the agent alerts that quote them. Each holds the
  conversation or ticket subject, and a message alert also a preview of their
  message and their browser ID. Erasure deletes these (§1), so they are data
  held about the person;
- `attachments/`: the binaries;
- `README.txt`: what is included, and the §6 list of what is not.

Contact notes are included. They are about the person and are usually
disclosable, so the operator reviews the export before sending it and removes
anything their law lets them withhold. The export writes an audit event with
counts only, and it is capped and streamed so a large history cannot exhaust
memory.

### 8. Erasures survive a restore

Restoring an archive taken before an erasure would silently undo it, and an
operator restoring after an incident is not going to remember last month's
requests. So each erasure is also written to a ledger. It holds only internal
IDs, the site, a timestamp and the receipt reference: no name, email or content.
The ledger is kept in two places:
- a `visitor_erasures` table, so later backups carry it;
- a file on the volume. This follows the precedent of the release state, which
  lives there so a restore cannot roll it back.

After the restore imports its dump, it re-applies every ledger entry whose
visitor exists in the restored data, and reports how many it re-applied.
Re-applying is idempotent.

A restore onto a **fresh** volume, such as disaster recovery onto new hardware,
has no ledger file. Restore then warns that erasures recorded after the archive
was taken cannot be re-applied. The operator runbook says to keep the ledger
file alongside the backups.

### 9. Receipt

Every erasure produces a receipt reference, and an audit event
`visitor.erased` with counts only. The operator can quote the reference in
their reply to the requester. It proves the erasure happened without the record
holding who was erased.

## Consequences

- **A contract test holds the erasure map to the schema.** It enumerates every
  foreign key and polymorphic column that can reach a visitor, conversation,
  message or ticket, and fails when one is not classified as deleted, stripped
  or kept-with-reason. This is how the gaps in the Context table would have
  been caught. It is also the only thing that keeps the next migration from
  quietly adding one.
- **The role matrix grows by one permission,** so `rbac-waypoints.md` and its
  `RbacDocumentationTest` change with it.
- **The privacy docs change:** the data inventory's retention posture, a
  section of data-responsibility on handling requests, and the backup/restore
  runbook (§8), which `RestoreCommandTest` reads.
- **The restore command gains a step,** and its failure mode has to be designed
  like the rest of restore. The dump is already replaced when re-application
  runs, so a failure there is partial, not harmless, and must say so.
- **Erasure is irreversible by design,** which is the reason for the summary,
  the typed confirmation and the password prompt. There is no undo, and there
  is no soft-deleted copy to undo from.
- **No new webhook event.** A thin `visitor.erased` event would let subscribers
  propagate erasures. It is a reasonable follow-up, but it extends ADR 0020's
  contract and is not needed to make erasure correct inside Wayfindr.

## Delivery

In order, each shippable alone:

1. **Erasure.** The permission, the service, the summary and confirmation, the
   ledger table, the receipt, the schema contract test, and the privacy docs.
2. **Restore re-application**, and the runbook change.
3. **Per-visitor export.**

## Decisions recorded

The owner settled the four open questions on 2026-09-30, each as recommended:

1. **Tickets:** option B, keep and strip (§3).
2. **Permission:** a new `handle_data_requests`, not `manage_contacts` (§5).
3. **Restore:** re-application is built (§8), not only documented.
4. **Export:** contact notes are included (§7).
