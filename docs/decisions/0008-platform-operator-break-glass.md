# 0008: Platform Operator Break-Glass Access

Date: 2026-07-16

## Decision

Platform operators sometimes have a legitimate, exceptional need to see
customer support content — debugging a corrupted conversation, investigating
abuse, verifying a data-recovery — without operator status ever becoming a
quiet superuser. Wayfindr adds a **break-glass workflow**: explicit, scoped,
time-bound, read-only, audited, and visible to the account it touches. This
implements waypoint 7 of the
[platform operator boundary](../product/platform-operator-boundary.md).

### The grant

Access happens only through a **grant** — a first-class record, never an
ambient capability:

- **Scoped, narrowest by default.** Each grant names exactly what it opens:
  one **conversation** (by support code), one **site**, or one **account**.
  The request form defaults to the narrowest scope; the reason should justify
  anything wider.
- **Reasoned.** A written justification is mandatory. It is stored on the
  grant, shown to the approver, and visible to the affected account.
- **Time-bound.** Grants auto-expire: **60 minutes by default**, requestable
  up to a **24-hour maximum** when the reason justifies it. A grant can be
  **closed early** but never extended — continuing work means a fresh grant
  with a fresh audit trail.
- **Read-only.** An active grant lets the operator *view* the scoped
  conversations, tickets, and **attachment metadata** (filename, type, size,
  status — never the binary; break-glass attachment downloads are excluded
  from v1, keeping file contents inside the existing agent/visitor
  authorization boundary) through dedicated operator surfaces. It never
  permits replying, editing, deleting, or any other mutation. If a finding
  requires action, it routes to an account agent (or a future, separately
  designed repair capability). There is **no agent impersonation**.

### Approval

- When the target account has an **owner/admin who is not the requester**,
  that person must approve the grant before it opens (two-party control).
- On single-human installs — where the operator and the account admin are the
  same person — the request **degrades to self-approval**: the mandatory
  reason still applies, the grant is marked self-approved, and the
  account-visible record makes the access impossible to miss. Ceremony is not
  security when both hats are one head; visibility is.

### Transparency and audit

- **The account can always see it.** Active and past grants for an account are
  visible to its owners/admins (who requested, why, what scope, when it opened
  and expired). An active grant is surfaced prominently, not buried.
- **Every step is audited**: `break_glass.requested`, `.approved` /
  `.self_approved`, `.opened`, `.resource_viewed` (deduped per grant+resource,
  following the `attachment.downloaded` pattern), `.closed`, `.expired`.
  Audit metadata names the scope and resources, never copies content.
- Grant records are retained independently of the content they exposed.

### Enforcement shape

- Break-glass views are **separate operator surfaces** (under `/operator`),
  gated by policies that check for an *active, in-scope grant* on every
  request — scope re-derived per resource, the same defense-in-depth posture
  as attachments (ADR 0007).
- Platform authority continues to grant **nothing** on ordinary `/dashboard`
  routes. A platform operator without a grant sees exactly what they see
  today: instance health, no content.

## Rationale

- The boundary doc's promise — "operational visibility without
  customer-content visibility" — only holds if the exception path is *more*
  accountable than the rule, not less. A grant object with reason, scope,
  expiry, and an account-visible trail makes the exception auditable and
  socially expensive, which is the point.
- Self-hosted reality: most installs are one person. A design that *requires*
  two parties would be theater there; a design with only self-approval would
  be weak for hosted Wayfindr later. Degrading gracefully between the two
  keeps one model honest in both worlds.
- Read-only keeps the abuse surface and the audit story small. Mutations by
  operators inside customer accounts are a different, harder contract — out of
  scope until real need is proven.

## Consequences

- A `break_glass_grants` table: requester, account, scope
  (conversation/site/account + reference), reason, status
  (requested/active/denied/closed/expired), approver, requested/approved/
  expires/closed timestamps.
- Policies/gates keyed on active grants; read-only operator views for
  conversations, tickets, and attachment metadata within scope (attachment
  *downloads* through break-glass are deliberately excluded from v1 — viewing
  metadata suffices for diagnosis and keeps binaries inside the existing
  agent/visitor authorization boundary).
- `/operator` gains a break-glass section: request form, active/past grants,
  and the scoped read-only viewers.
- Account owners/admins gain a "platform access" view of grants touching their
  account, plus a prominent notice while one is active.
- Audit events as listed above; readiness/docs updated
  (`docs/product/platform-operator-boundary.md` gains the shipped mechanics).
- Delivery is sliced: (1) grant model + policies + audit, (2) request/approval
  flow + `/operator` UI, (3) scoped read-only viewers, (4) account-visible
  transparency surfaces, (5) hardening tests (cross-account, expired/closed
  grants, scope escalation attempts).

## Owner decisions (resolved 2026-07-16)

- **Scope**: chooseable per grant — conversation / site / account — with the
  narrowest as the form default.
- **Approval**: account admin approves when one exists besides the requester;
  otherwise self-approval with mandatory reason and account-visible notice.
- **Access level**: read-only, v1. Repair verbs are a possible later
  extension, separately designed.
- **Expiry**: 60-minute default, 24-hour maximum, early close, no extension.
