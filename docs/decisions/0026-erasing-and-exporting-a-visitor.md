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
  deliveries (all cascade today). A copilot request that is already running
  may be sending the transcript to the AI provider. Its job claims the row,
  then calls out outside any lock, bounded only by its own timeout. So erasure
  locks those rows and refuses while one started within twice that timeout,
  as it does for a note post (§3). A request that started earlier has ended,
  and whatever reached the provider is out of reach (§6);
- **the attachment binaries**. Their disks and keys are written onto the
  erasure's ledger row inside the transaction, then removed after the commit,
  which is the pattern `SitePurge` uses, so no live row points at a missing
  file. Each is struck off as it goes. A crash after the commit, or storage
  that will not delete, leaves them listed, and a scheduled
  `wayfindr:finish-erasures` retries until none remain;
- **proactive message deliveries**: rows naming the visitor, and detached rows
  whose `visitor_key` matches the key derived from the browser IDs being erased.
  The keys can be computed before the row goes;
- **agent notifications** whose data names one of the erased conversations, or
  a ticket stripped under §3. A ticket's alerts (assignment, SLA, automation)
  store its subject, which may be the person's words. Deleting them also stops
  alert email that a worker has claimed but not yet sent. Every alert mail
  checks, immediately before SMTP, that its alert still exists and its
  delivery is still live. A stripped ticket keeps its SLA clocks, so erasure
  cancels their unsent SLA deliveries for that check to refuse. The same
  check re-reads the ticket or conversation each alert mail names: mail about
  a deleted conversation, or built from a ticket before it was stripped, is
  refused even when its alert was never claimed, and a retry builds it again
  from what is left. A stripped ticket goes on alerting, under its stripped
  subject. The check's lock ends before the transport runs, so a mail that
  passes is recorded as in flight under it, and erasure refuses while one
  about the person's work is fresh, as it does for a note or a copilot call.
  Once the mail server has it, it is mail already sent (§6);
- **SLA clocks and automation executions** whose subject is an erased
  conversation. A ticket stripped under §3 keeps its own as the work item's
  history: identifiers, outcomes and a copy of the rule's own text. The
  exception is an execution's error message, which is raw exception text,
  and a failed query quotes its values, so it can hold the ticket's or a
  message's content. Erasure clears it on a stripped ticket's executions;
- **failed queue jobs** whose payload or exception names the person by
  email address or support code. A job that exhausted its retries is kept
  with the exception it died on, and a mail server's rejection quotes the
  address it refused. They are free text that says nothing of the site or
  account a job was for, so only identifiers that name this person wherever
  they appear find them: an address is one mailbox, and a support code is
  unique across the install. A host or browser ID is unique only within its
  site and would find other sites' visitors, so it is not used. Each must
  appear whole, not inside a longer address or code. They are searched in
  whichever store the operator configured: a table on any connection, a
  file, or DynamoDB. Other failed-job text is diagnostics, like logs (§6);
- **outbound webhook deliveries** about erased conversations or stripped
  tickets. Pending ones for erased conversations are cancelled. Every one
  keeps its payload, which carries only identifiers (ADR 0020), and stays as
  delivery history. Its stored response sample is cleared: up to 4 KB of
  whatever the subscriber replied, which can echo what it fetched about the
  person. This covers delivered and failed rows as well as pending ones.

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
    and cascades only with the ticket.
  - *No note is posted after the erasure completes.* A delivery that has not
    started is deleted under the row lock the worker claims it with, so it
    can never post. One that has started may be mid-post: the worker commits
    its start, then calls the provider outside any lock, and only the job's
    own timeout bounds that call. So erasure refuses while a post on one of
    the tickets started within that timeout, and asks the agent to try again
    in a couple of minutes. A post that started earlier has ended: its note
    is in the tracker or nowhere, like any note already posted (§6).
  - The external issue keeps whatever it already holds (§6).
  - *Mirroring stops.* The link stays, but the tracker's later comments are
    no longer copied into the ticket's history. Each would be a new copy of
    whatever the tracker says, which may be about the person. Mirroring locks
    the ticket before writing, which is the lock erasure strips it under. So
    a comment arriving mid-erasure is either recorded first and scrubbed
    with the rest, or finds the ticket stripped and is dropped.
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
  outweighs its incidental content. The grant's audit trail keeps the reason
  and who acted, but its stored labels name a conversation by its support code.
  For an erased conversation those labels become `Conversation (deleted)`,
  which is what the grant itself reads once the conversation is gone.
- **Bulk-action runs** keep their identifier lists and before-and-after
  values, so they can still be undone. Runs never expire, so erasure clears
  the queue search saved in `return_query` on every run that selected an
  erased conversation or a stripped ticket, whether it changed it or not: a
  run lists its whole selection in `item_ids`. A run made before that column
  lists only what it changed, so one that skipped anything loses its search
  too, since it cannot say what it skipped. That search is what the agent
  typed to find the work, which may be the person's name or email. Undoing
  such a run then returns to the unfiltered queue. A review not yet confirmed
  keeps no search at all: the search travels in the confirm form, not the
  agent's session, so it never sits in session storage, which erasure cannot
  reach.
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
- logs, failed-job text that does not name them directly, or the operator's
  infrastructure. The same goes for the last page address in an agent's
  session, which a later page load replaces.

**It does not stop future collection either.** A person who comes back is a
new visitor. A host page that identifies them by external ID recreates the
record, because that host is still sending it. Honouring an objection to
processing is the host's change to make, not Wayfindr's.

### 7. Export: everything held about one person

The export is the read side of the erasure map, under one rule. Whatever
erasure removes because it can hold the person's data is exported, and so are
the audit events §2 keeps. Bookkeeping that erasure removes only because its
parent row goes is not. The contract test that holds the map to the schema
also makes each entry say whether the export includes it, and why not when it
doesn't. So a table that erasure reaches but the export skips fails the same
test. The export's own test works per column: it compares each exported row
with its table's columns. A column added later then fails until the export
includes it, or the map says why not.

The same permission downloads a ZIP for one visitor:
- `visitor.json`: the whole visitor row. That is the identity fields, every
  attribute and metadata value (including the last page address and host
  context), and first and last seen times, plus known browser IDs and
  contact notes;
- one file per conversation: messages, ratings, attachment metadata, the
  conversation's cobrowse sessions (page state, snapshots and mutations
  still held, which retention may already have pruned), its copilot
  output (summary, reply draft, suggested ticket title and suggested
  articles), and its email reply deliveries (the address each reply went
  to, and its attempts and outcome);
- `proactive.json`: the proactive messages shown to them, and when each was
  shown, engaged with or dismissed;
- `tickets/`: requested tickets with their notes, and each note's deliveries
  to an external tracker (where it went, when, and the outcome);
- `alerts.json`: the agent alerts that quote them. Each holds the
  conversation or ticket subject, and a message alert also a preview of their
  message and their browser ID. Erasure deletes these (§1), so they are data
  held about the person;
- `audit.json`: the audit events about them, their conversations and their
  tickets (§2): what happened, when, and its metadata. Each names whether the
  person, an agent or the system acted, but not which agent: the agent's
  identity is data about the agent, and the operator adds it where their law
  requires naming who saw the data. The same goes for agents named inside the
  metadata, such as an assignment's old and new assignee: their names and IDs
  become the role. A list of identity-bearing keys does it, and the export's
  test holds that list to every action that writes a user into audit
  metadata;
- `incidental.json`: text Wayfindr kept in passing that can quote them. That
  means the response samples of webhook deliveries about their conversations
  and tickets, the error messages of automation runs on them, the queue
  searches saved by bulk actions that selected their work, and the failed jobs
  that name them (§1). A failed job is given as its job name, when it failed,
  and the first line of its exception. The first line is the message that
  quotes them. The stack trace below it is the installation's own code paths,
  not data about the person;
- `break_glass.json`: platform-operator access to their data. That covers
  grants scoped to one of their conversations, and views of their
  conversations or tickets under any grant: when, what scope, the reason
  and how long. The operator is named by role, as in `audit.json`;
- `attachments/`: the binaries;
- `README.txt`: what is included, and the §6 list of what is not.

What the export leaves out is bookkeeping that erasure removes only because its
parent row goes: which agent last read a thread, SLA clocks, an automation
run's copy of its rule, and the identifiers of webhook deliveries. Each
exclusion is written in the map with its reason, where the review above can see
it.

Contact notes are included. They are about the person and are usually
disclosable, so the operator reviews the export before sending it and removes
anything their law lets them withhold. The export writes an audit event with
counts only, and it is capped and streamed so a large history cannot exhaust
memory.

**One consistent moment.** The export reads its rows in a single read-only
transaction at repeatable read, so every file in the ZIP describes the same
moment. It holds a shared lock on the site row throughout. Erasure takes that
lock exclusively, so it waits for the export to end and cannot remove rows or
binaries mid-download. Widget writes take the lock shared, so conversations
carry on. Binaries are read inside the same window. One that retention prunes
between the snapshot and its read is listed in `README.txt` as pruned, not
silently left out.

Delivery 3 settled these details:
- **Repeatable read, not read-only.** PostgreSQL refuses `SELECT … FOR SHARE`
  in a read-only transaction, so the export cannot be both. It runs at
  repeatable read, takes the shared site lock as its first statement, and
  writes nothing inside the transaction. Its audit event is written after. A
  site row changed while the lock was awaited fails the snapshot, so the
  export takes it again, up to three times.
- **Built, then served.** The ZIP is built into a temporary file inside the
  snapshot and sent after the transaction ends. The site lock lasts as long as
  building takes, not as long as the agent's download does, and a refusal is
  an ordinary page rather than a broken download.
- **Erased while it waited.** The snapshot is taken when the lock is
  requested, so it can predate an erasure, or a merge into another contact,
  that held the lock first. After the transaction the export checks that the
  contact still exists, and if not, discards the archive and says so.
- **No zip extension.** The runtime does not require one, so the archive is
  written by `StoredZipWriter`: stored entries, each one's checksum written
  back into its header, and no ZIP64. A history past 65,535 files or about
  3.5 GB of attachments is refused before anything is written.
- **Every column decided.** `VisitorExporter::COLUMNS` lists every column of
  every table the export reads, as exported or with the reason it is not.
  Its test holds the list to the live schema, so a column added later is left
  out, and fails, until someone decides. Columns and metadata keys that name a
  user are replaced by a role: `visitor`, `agent`, `platform operator`,
  `integration` or `system`. `VisitorExporter::IDENTITY_KEYS` lists the keys,
  and a test holds it to every identity-shaped key the code writes.
- **A POST, and the same permission as erasure.** Building the archive reads
  the whole history and is audited, which a link another site can embed must
  not start. It is throttled to six a minute.
- **A file the download path would not serve is not exported either.** One the
  malware scanner holds, or one that never finished uploading, keeps its
  details in its conversation's file, and `README.txt` lists it as withheld.
  A file an agent has uploaded but not yet sent is a draft of a reply, which
  the download path shows only to its uploader, so it is left out entirely;
  the person's own unsent upload is theirs and is included.
- **Only what can be tied to the person is handed to them.** Erasure clears
  a bulk-action run's saved search when the run may have found the person,
  which for a run from before runs recorded their selection includes any that
  skipped an item. Export includes only runs that provably selected their
  work: the rest may be a search about someone else.
- **A cobrowse session on someone else's conversation** goes in
  `cobrowse.json`. Every session belongs to a conversation, so this is rare.
- **The README is in the exporting agent's dashboard language.** The operator
  reviews the archive before it goes to the person.

### 8. Erasures survive a restore

Restoring an archive taken before an erasure would silently undo it, and an
operator restoring after an incident is not going to remember last month's
requests. So each erasure is also written to a ledger. An entry holds only
internal IDs, the site, a timestamp, the receipt reference, and the storage
disk and key of any attachment binary not yet removed (§1): no name, email or
content.

**An entry names the person's whole merge history, not just the current
row.** A contact merge deletes the source visitor and moves everything onto
the target
([identity merge](../product/visitor-contact-management.md#identity-merge)).
Erasing that target and then restoring an archive from before the merge would
bring back the source row, under an ID the ledger has never seen. So the
entry records the erased visitor's ID and every visitor ID merged into it.
Erasure reads these, before §2 scrubs the audit metadata, from two places:
- the `previous_visitor_ids` on the person's browser aliases;
- the `source_visitor_id` of each `visitor.merged` event. A merge re-anchors
  the source's audit events onto the target, so a chain of merges ends with
  all of them on the erased visitor.

The aliases alone are not enough: each keeps only its last 50 IDs, and a
visitor merged in by external ID or email may have had no browser alias at
all.

The ledger is kept in two places:
- a `visitor_erasures` table, so later backups carry it;
- one file per entry under `storage/app/erasure-ledger/`. Backups archive the
  database and the attachment disks, not this directory, so a restore cannot
  roll it back. This follows the precedent of `release-state.json`.

**Writing both, crash-safely.** The database transaction and a file write
cannot commit together, so the order is fixed and every failure between them
ends safe:
1. Inside the transaction, erasure first takes the contact merge's locks
   (§5). Before deleting anything, it reads the lineage and writes the entry
   as `<receipt>.pending.json`: to a temporary file, flushed to disk, then
   renamed into place, and the directory flushed too. Holding the locks
   means no merge can add a source the entry misses. If the write fails, the
   transaction rolls back with nothing deleted, and erasure refuses, naming
   the storage problem.
2. The same transaction then deletes and strips, inserts the
   `visitor_erasures` row under the same receipt, and commits.
3. After the commit, erasure renames the file to `<receipt>.json`. If the
   rename fails, the erasure has still happened, so it is reported as done.
   The pending file already holds the whole entry, and reconciliation
   promotes it.

If the transaction reports a failure, erasure leaves its pending file for
reconciliation rather than deleting it. A commit whose acknowledgement was lost
reports the same error as a rollback, and only the database can tell them
apart. So a reported erasure always has a file on the volume, flushed before
the database committed, and so does one whose success was never reported. The
only inverse risk is a stale file from a failed erasure, and it lasts only
until reconciliation clears it.

**Reconciliation** settles pending files against the database. For each one
it first takes the lock on the entry's site. The erasure holds that lock from
before its file is written until its transaction ends, so getting it means the
transaction is over, however long it ran. Age is never taken as proof. Then a
file whose receipt has a row is promoted, and one whose receipt has no row is
deleted. A site that no longer exists has no lock to wait on, and nothing can
still be erasing on it. Reconciliation runs on the scheduler. It also runs at
the start of every restore, against the database about to be replaced,
before the dump is imported. That makes it the last moment the answer is
still there. If that
database cannot answer, because it is missing or older than the table,
restore applies pending entries as if committed and lists their receipts. An
unconfirmed entry is still one an operator confirmed, and applying one that
did not commit costs far less than skipping one that did.

**Binaries still to remove survive the restore too.** The dump restores the
ledger table as it was when the archive was taken, which can predate a
pending binary or its whole erasure. So the volume entry carries the same
pending list as the table row. The scheduled `wayfindr:finish-erasures`
strikes each binary off in both places, and after the import it works from
the volume entries. A remote object can therefore not drop out of tracking
when the database goes back in time. Reconciliation settles a pending entry
before it counts toward the list, so a failed erasure's stale list removes
nothing.

**Re-application.** After the dump is imported, restore moves the visitor ID
sequence past the highest ID the ledger holds. An imported dump resets the
sequence to the archive's value, so without this a new visitor could take an
erased person's ID and be erased in their place. Then it re-applies every
committed entry that names a visitor in the restored data, by any ID in its
lineage, and reports how many it re-applied. Re-applying is idempotent. It
puts the ledger row back under the same receipt when the restored database
predates it, so later backups carry it, and writes a `visitor.erasure_reapplied`
audit event with counts only.

Delivery 2 settled these details:
- **The entry also records the site's public key**, and re-application finds
  the restored site by it, and by the ID when there is one. An archive from
  another installation can hold a site, and visitors, under the same IDs. The
  ledger row keeps the key too, since a purged site nulls its `site_id`. An
  entry with no key cannot prove its site. It is not re-applied, and it holds
  re-application open, so nothing is served, until the operator checks the
  contacts it names. Then they either vouch for it
  (`wayfindr:finish-erasures --vouch=<receipt>`), which records the restored
  site's key, or remove its file when the archive is another
  installation's.
- **Re-application runs only for a restore.** The restore records on the
  volume that it is outstanding, before the load, and clears that once it
  succeeds. Nothing else starts it, so an ID reused by a dump loaded some
  other way, without the sequence move, is never erased.
- **An archive whose schema differs from the code waits.** Erasing works on
  the tables the running code knows. An older archive lacks some of them, and
  a newer release's archive may hold the person in tables this code cannot
  reach. So restore leaves re-application outstanding, and says so, until the
  database has run exactly the migrations the code ships. It runs when
  `migrate` finishes, after migrating an older archive or deploying a newer
  one's release, or failing that on the next scheduled
  `wayfindr:finish-erasures`. The in-app restore keeps the site in maintenance
  mode until it has.
- **A failure is reported as its own thing.** The restore says the people
  erased since the archive may be back, exits non-zero, and leaves
  re-application outstanding for the next run.
- **The restore stops new erasures before it reads the ledger.** It marks
  re-application outstanding first, which raises the serving gate below, and
  holds a lock on the ledger until it is over, which the scheduled and
  post-migrate re-applications take too: they cannot settle a restore that is
  still under way. Before the load, an entry the database being replaced
  cannot answer for stays pending, because a failed load leaves that database
  live. An
  erasure already past the gate may still write its entry after that first
  read and commit to the database the load replaces. So after the load, a
  pending entry without a row is kept as committed and listed, rather than
  discarded: the restored database cannot answer for it. The same holds for
  every reconciliation while a restore's re-application is outstanding, so a
  restore that fails after its load and before that step leaves nothing for
  a later run, or a later restore, to discard. An entry no run can settle
  yet, because the database will not answer, holds the work open as a
  failure until one can.
- **Nothing is served while re-application is outstanding.** A deploy cannot
  be trusted to hold maintenance mode: a standard Forge deploy restores the
  site when `migrate` fails, and the container's migration loop crash-loops on
  a failure that is not transient. So, like the release gate of ADR 0013,
  the app answers every request but its health check with a 503 until the
  ledger records the work as done.
- **A ledger row outlives its account,** as its file on the volume does. The
  rows are what fill a new volume's ledger. A row that went with its account
  would leave that volume knowing some erasures and not others, so it would
  not count as new and give no warning, while a restore from before the
  account was removed brings the account back, erased contacts and all. A row
  holds internal IDs, the site's key and counts, so keeping it keeps nothing
  about anyone.

A restore onto a **fresh** volume, such as disaster recovery onto new hardware,
has no ledger directory. Restore then warns that erasures recorded after the
archive was taken cannot be re-applied. The operator runbook says to keep the
ledger directory alongside the backups.

The first release with re-application backfills the directory from the
`visitor_erasures` table on first run, so erasures recorded before it are
covered from then on. Any row without a file gets one: a row exists only for
an erasure that committed. The scheduled run does this, and so does restore,
from the database it replaces and again from the one it loads. It cannot recover erasures that an earlier restore
already undid, and until it ships, restoring an older archive undoes the
erasures made since, as it does today. Delivery 1's docs say so, and tell the
operator to erase those people again.

### 9. Receipt

Every erasure produces a receipt reference, and an audit event
`visitor.erased` with counts only. The operator can quote the reference in
their reply to the requester. It proves the erasure happened without the record
holding who was erased. The database side is complete when the receipt is
issued. Until its ledger row lists no binaries left to remove (§1), the
storage side is not, and the scheduled run says how many remain.

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
   ledger table with each entry's merge lineage, the receipt, the schema
   contract test, and the privacy docs.
2. **Restore re-application.** The ledger directory with its write order and
   reconciliation, the backfill, re-application and the sequence move, and
   the runbook change.
3. **Per-visitor export**, with the map's export column and its test.

## Decisions recorded

The owner settled the four open questions on 2026-09-30, each as recommended:

1. **Tickets:** option B, keep and strip (§3).
2. **Permission:** a new `handle_data_requests`, not `manage_contacts` (§5).
3. **Restore:** re-application is built (§8), not only documented.
4. **Export:** contact notes are included (§7).
