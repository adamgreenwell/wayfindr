# 0007: Conversation Message Attachments

Date: 2026-07-14

## Decision

The first real mobile dogfood conversation established a genuine need for file
attachments. Wayfindr will add attachments as a security, storage, retention,
and workflow feature — not a file-input tweak — under the following contract.

### Scope and ownership

- Attachments are owned by **conversation messages**. Both a visitor and an
  agent can attach files to a message they send.
- A ticket linked to a conversation **surfaces** that conversation's
  attachments as read-only supporting context. It does **not** copy the binary.
- **Direct ticket attachments and internal-note attachments are deferred** until
  real use proves they need their own lifecycle. Message ownership is the
  smallest correct scope, and it is the only scope in this contract.

### Storage and access

- Binaries live on a **private filesystem disk** with **opaque, non-guessable
  storage keys**. Never the public disk; never a directly reachable or guessable
  URL.
- The self-hosted default is the **local private disk**. An **S3-compatible
  object-storage disk** is a configuration choice. Wayfindr verifies only what
  it can honestly check (the disk is readable/writable, surfaced in readiness)
  and does **not** guarantee object-storage durability or backup — that stays
  operator-owned, consistent with the existing backup posture.
- All access is through **authorized endpoints**. Upload and download are scoped
  by the **signed visitor session** (a visitor may only touch its own
  conversation) or **agent site access** (an agent must support the site).
  Downloads stream with `Content-Disposition: attachment`, the **server-detected**
  content type, and `X-Content-Type-Options: nosniff`. No storage URL is ever
  handed to the client.

### Validation

- The server **sniffs the file's bytes** to detect its MIME type. The
  client-supplied filename extension and `Content-Type` are display hints only
  and are **never trusted** for authorization or safety.
- Files are **allowlisted by detected type**. The default allowlist is **images
  (PNG, JPEG, GIF, WebP), PDF, and plain text/log**. **SVG, HTML, archives, and
  executables are excluded by default** — SVG and HTML are active-content/XSS
  vectors, and archives hide their contents and enable decompression bombs. The
  allowlist is **configurable per install**; an operator may enable office
  documents knowingly.
- The server enforces, independent of the client: **per-file size (default
  10 MB)**, **per-message file count (default 5)**, and a **per-conversation
  total cap**. Account/site storage quotas are a follow-up once real usage shows
  they are needed. Upload endpoints are **rate-limited**.
- Images render as **inline previews** (constrained size, lazy-loaded) in the
  agent transcript and the widget. Server-generated thumbnails are a later
  optimization, not part of the first contract.

### Retention and lifecycle

- An attachment's lifecycle **follows its message**. Deleting a message,
  conversation, visitor, or account deletes the associated binaries (cascade). A
  **scheduled sweep** removes orphaned storage objects (abandoned or failed
  uploads with no owning message), mirroring `wayfindr:expire-idle-cobrowse-sessions`.
  Retention beyond that follows the operator's documented data-responsibility
  posture.

### Malware scanning

- Scanning is a **pluggable adapter** (e.g. ClamAV or an external service)
  selected by configuration. When a scanner is configured, an upload is held in
  a **pending/quarantined** state and is neither deliverable nor downloadable
  until it passes.
- **Default (no scanner configured): accept, with defense in depth.** The strict
  detected-MIME allowlist (no executables, HTML, or SVG), private storage, the
  forced `attachment` disposition, and `nosniff` are the standing protections,
  and **readiness surfaces that no scanner is configured** so the operator
  chooses knowingly. Refusing all uploads without a scanner was rejected: it
  would break the common self-host case, so the risk is made **visible** rather
  than hidden.
  - *Owner-confirmed (July 14, 2026): accept-with-defense-in-depth is the shipped
    default. An operator who prefers "refuse unscanned" flips a configuration
    switch; the adapter supports either policy.*

### External export

- Attachment **binaries and storage URLs never leave Wayfindr** for GitHub,
  GitLab, or Jira **by default.** This extends the existing conservative-export
  rule (transcripts, cobrowse snapshots, and internal notes are already omitted
  from outbound issue/comment payloads). Any future need to reference an
  attachment in an external issue will be an **explicit, opt-in, separately
  designed** capability — and even then a link back to an authorized Wayfindr
  download, not the binary.

## Rationale

- Support attachments are almost always **screenshots and small documents that
  belong to a conversation moment**, so message ownership is the natural,
  smallest correct scope. Ticket and internal-note attachments carry different
  lifecycles and can wait for demonstrated demand.
- Wayfindr's security and privacy posture is **browser-first masking,
  conservative export, and operator-owned data responsibility**. Private storage
  + authorized-only access + a byte-sniffed allowlist + forced-download
  disposition keep an uploaded file from becoming an XSS, SSRF, or exfiltration
  surface, and keep visitor-supplied binaries inside the trust boundary.
- **Self-hostability** means Wayfindr cannot assume object storage or a malware
  scanner exists. The defaults must be safe on a bare local disk with no
  scanner, while making the stronger options available and their **absence
  visible**.

## Consequences

- A `conversation_message_attachments` table and model: message, denormalized
  site/account (for scoping), storage disk + opaque key, sanitized display
  filename, detected MIME, size, checksum, status
  (`pending`/`ready`/`failed`/`quarantined`), scan result, and timestamps.
  Upload, download, and delete are audited.
- New authorized upload/download endpoints (visitor-session- and
  agent-site-scoped) with server-side size/MIME/quota/rate-limit enforcement and
  safe response headers.
- The widget gains an accessible file picker (with mobile camera/photo capture),
  progress, cancel/remove, retry, and **idempotent message send** so a retried
  upload cannot double-post.
- The agent transcript and linked-ticket context render safe attachment
  rows/previews; the reply composer gains agent→visitor attachments.
- Readiness / `/operator` gains an attachment-storage check and a **"no malware
  scanner configured"** signal; self-hosting docs cover private storage,
  retention, and the optional object-storage/scanning adapters.
- Delivery is sliced dependency-first per the roadmap in
  `docs/development/handoff.md` §6 (contract → model/storage → authorized
  endpoints → visitor upload → agent render/compose → retention/adapters →
  hardening).
- The conservative export rule is unchanged and now **explicitly** includes
  attachment binaries and storage URLs.

## Owner decisions (resolved 2026-07-14)

The three defaults the owner signed off on, now part of the contract above:

- **Scanner default**: **accept with defense-in-depth** (not refuse-unscanned).
  A configured scanner still quarantines until pass; the accept default keeps the
  common self-host case working with the allowlist/private-storage/forced-download
  protections and a readiness signal.
- **Office documents** (`.docx`/`.xlsx`): **excluded from the shipped default
  allowlist**; an operator may opt them in knowingly.
- **Default limits**: **10 MB per file, 5 files per message, 100 MB per
  conversation** (all server-enforced and configurable per install).

With these settled, delivery slice 2 (model + private storage) is ready to build.
