# Engineering Handoff & Roadmap

*Living document — last updated July 16, 2026. For an agent (or engineer) picking up
Wayfindr development. Read this, then `docs/product/roadmap.md` and
`docs/self-hosting/` for depth.*

---

## 1. What Wayfindr is

An open-source, self-hostable customer-support platform: **live chat**,
**cobrowsing**, and **ticketing**. Laravel-first monorepo. Cobrowse (an agent
watching a consented, masked replay of the visitor's page) is the strategic
differentiator.

- **Server app**: `apps/server` (Laravel 13, PHP). This is where almost
  everything lives.
- **Widget**: `packages/widget-js/src/wayfindr-widget.js` (vanilla JS, no build
  framework — it is embedded on customer sites).
- **Deploy target**: Laravel Forge (first-class). Stage:
  `https://wayfindr.on-forge.com`.

---

## 2. Current state (July 2026)

The **MVP support loop works end to end**: a visitor chats via the widget → the
agent sees it live and replies → tickets capture durable work → cobrowse gives a
consented, masked view of the visitor's page.

**The current Forge stage is the initial controlled dogfood instance.** The
owner explicitly chose it instead of creating a separate production install
first. The July 14 readiness pass confirmed the full runtime contract live:
`APP_DEBUG=false`, public HTTPS, current migrations, PostgreSQL, Redis, outbound
mail, the scheduler, durable queue worker, Reverb, writable storage, deploy
restarts, and no failed queue jobs. A manual database export and isolated
restore drill also succeeded. Automated backup orchestration is intentionally
not an initial launch requirement; the self-hosting operator owns the manual
backup process until productized archive/object-storage tooling exists.

**Controlled dogfooding is open.** The first real mobile support session on
`wayfindr.cc` completed the visitor/agent chat loop, produced a durable Wayfindr
ticket, and created GitHub issue #587 through the live provider connection. It
also surfaced a real mobile usability bug: iOS zoomed the page when the 14px chat
composer received focus. Commit `abdbb7f` raised the composer to the mobile-safe
16px threshold, added regression coverage, deployed to Forge, and passed the
owner's phone retest.

**The GitHub provider round trip is proven live.** The Wayfindr Public Site is
mapped to `adamgreenwell/wayfindr`; issue creation, inbound state reflection,
outbound comment relay, inbound comment relay, and the echo-loop guard all
worked against real GitHub deliveries. PR #586 then made provider capabilities
editable, made the save-before-webhook setup order explicit, and distinguished
configured inbound sync from a connection verified by a signed delivery.

**The attachments epic is 100% complete (July 16).** Every item in
[ADR 0007](../decisions/0007-conversation-message-attachments.md) is built,
Codex-reviewed, and shipped across PRs #589–#600: private local storage with an
airtight access boundary, two-step uploads with bind-on-send, retention/orphan
sweep, delete-on-remove quota reclaim, the visitor widget UI (including
first-message and mobile-camera attach), the agent dashboard UI with a deduped
per-download audit, a pluggable **malware scanner** (ClamAV over clamd INSTREAM,
synchronous, fail-closed — **live on stage**, EICAR-validated end to end), and
the **S3-compatible remote storage surface** (per-row `storage_disk`,
migration-free coexistence, dedicated-disk + private-ACL validation). Both UIs
were live-validated on stage, including the owner's own phone/desktop testing.
The stage box was resized to 2 GB to host clamd comfortably.

**Epics closed this cycle**: #4 (Chat UX Polish), #5 (Cobrowse Transport
Discipline), #490 (Cobrowse Observe-Mode Fidelity), attachments (ADR 0007).

**Issue housekeeping is current.** #564 (launch proof) was reconciled and
closed. #22's comment relay shipped and was live-validated; the issue remains
open solely for demand-gated field mapping. The longer-lived open product areas
are #58 (Platform Operator Boundary), #492 (live in-place cobrowse replay —
recommended against as specced), and the **agent UI density reduction** now at
the top of §6.

---

## 3. Major additions this cycle (what & where)

| Area | What | Key files | PRs |
|---|---|---|---|
| **Ops / Forge-first** | Root `artisan` shim so Forge's Commands panel, scheduler cron, and queue worker resolve in the monorepo (`current/` is the repo root; the app is under `apps/server`). | `/artisan`, `docs/self-hosting/laravel-forge.md` | #573 |
| **Ops docs** | Outbound email delivery wiki page (Workspace SMTP relay worked example + SPF/DKIM + troubleshooting). | `docs/self-hosting/email-delivery.md` | #572 |
| **Cobrowse retention** | `wayfindr:expire-idle-cobrowse-sessions` (every 5 min) ends abandoned consented sessions so they stop reading "active" in readiness **and** become eligible for the 72h content pruner (was a real retention leak). | `app/Console/Commands/ExpireIdleCobrowseSessionsCommand.php`, `routes/console.php` | #574/#575 |
| **Agent live transcript** | New visitor messages append to the agent transcript with no reload; the realtime socket now reconnects with backoff and resyncs. | `resources/views/agent/conversations/show.blade.php` (inline realtime script), `AgentConversationController@messages`, `partials/message-list.blade.php` | #576 |
| **Typing indicators** | Both directions: widget shows "Support is typing…"; agent detail shows "Visitor is typing…". | `chat-workspace.blade.php`, `show.blade.php`; widget `renderAgentTyping` | #577 |
| **Comment relay (outbound)** | Opt-in per note: an agent internal note can also post as a comment on the linked issue. GitHub/GitLab/Jira. | `app/Support/ExternalIssues/{GitHub,GitLab,Jira}IssueCommenter.php`, `IssueCommenter` interface, `AgentTicketController` (`storeNote`, `commenterFor`, `COMMENT_PROVIDERS`) | #578/#579/#580 |
| **Comment relay (inbound)** | External issue comments mirror onto the ticket as internal notes, with an echo-loop guard. GitHub/GitLab/Jira. | `app/Support/ExternalIssues/InboundCommentSync.php`, `Integrations/{GitHub,GitLab,Jira}WebhookController.php` | #581/#582 |
| **Integration setup + verification** | Ordered setup guidance, editable capability flags, provider-specific webhook instructions, and safe signed-delivery verification metadata. | `resources/views/agent/account/integrations.blade.php`, `ExternalIssueProviderConnection.php`, provider webhook controllers | #586 |
| **Live GitHub dogfood** | Real issue creation, state reflection, two-way comment relay, and echo suppression proved against `adamgreenwell/wayfindr`; conservative exports omitted transcripts, cobrowse content, and internal notes. | Wayfindr ticket external workspace, GitHub webhook deliveries | #585/#587 |
| **Mobile composer focus** | Raised the visitor chat textarea from 14px to 16px so iOS does not zoom the host page on focus; covered by the full widget suite and confirmed on a real phone. | `packages/widget-js/src/wayfindr-widget.js`, `packages/widget-js/tests/wayfindr-widget.test.js` | #587 / `abdbb7f` |

### Attachments cycle (July 14–16)

| Area | What | Key files | PRs |
|---|---|---|---|
| **Contract** | ADR 0007 + local-first/scoping amendment; all owner defaults resolved (accept-with-defense scanner default, office docs excluded, 10 MB / 5 / 100 MB limits, local-first sequencing). | `docs/decisions/0007-conversation-message-attachments.md` | #589/#590 |
| **Storage + access boundary** | `conversation_message_attachments` model, private `attachments` disk, defense-in-depth authz (opaque id AND account/site-scoped lookup AND visitor-token / agent-`view`-policy), hardened streaming (forced attachment disposition, server-detected type, nosniff, no-store), 13-case isolation matrix. Shared `VisitorConversationResolver`. | `app/Models/ConversationMessageAttachment.php`, `app/Support/VisitorConversationResolver.php`, `app/Support/Attachments/AttachmentResponder.php` | #591 |
| **Two-step uploads** | Upload lands a pending (unbound) row; message send binds atomically (`AttachmentBinder`, row locks). Byte-sniffed MIME allowlist (finfo — never extension/Content-Type), size/count/conversation caps under a conversation lock. | `app/Support/Attachments/AttachmentUploadService.php`, `AttachmentBinder.php` | #592 |
| **Retention sweep** | Model delete removes the binary; hourly `wayfindr:sweep-orphaned-attachments` reaps abandoned/failed unbound uploads + FK-cascade-orphaned objects (grace-windowed, per-disk, failure-isolated, honest delete counting). | `app/Console/Commands/SweepOrphanedAttachmentsCommand.php` | #593 (+#600 hardening) |
| **Visitor widget UI** | Attach button + file picker (mobile camera via `accept=image/*`), upload-on-pick chips, attachment-only sends, inline image/file-row transcript rendering, unchanged-poll render-skip (no image refetch), live broadcast payloads carry attachments, first-message attach via local staging (no empty conversations). | `packages/widget-js/src/wayfindr-widget.js`, `tests/wayfindr-widget-attachments.test.js` | #594/#597 |
| **Agent dashboard UI** | Transcript + linked-ticket rendering (shared `message-list` partial covers page, live-refresh, and ticket views), composer attach via fetch-upload + hidden `attachment_ids[]`, deduped `attachment.downloaded` audit recorded only when the stream can serve. | `resources/views/agent/conversations/partials/*`, `agent/partials/reply-composer-script.blade.php`, `AgentConversationAttachmentController.php` | #595 |
| **Delete / quota reclaim** | Scoped DELETE (unbound + requester-owned only, `lockForUpdate` serialized with the binder) wired into both chip-removal paths, incl. remove-mid-upload orphan handling. | Both attachment controllers, widget + composer scripts | #596 |
| **Malware scanner** | Pluggable `AttachmentScanner` (Null default = accept-with-defense-in-depth, surfaced on readiness; ClamAV via clamd INSTREAM, dependency-free). Synchronous pre-store scan; infected → rejected + `attachment.quarantined`; unreachable → fail-closed reject + logged error + audit (fail-open opt-in). Hardened against silent clamd (socket-activation), early verdicts, and deadline overshoot. **Live on stage** (2 GB box), EICAR-proven. | `app/Support/Attachments/Scanning/*`, `docs/self-hosting/attachment-scanning.md` | #598/#599 |
| **S3 storage surface** | `WAYFINDR_ATTACHMENT_STORAGE_DISK=attachments-s3` routes NEW uploads to any S3-compatible store; per-row `storage_disk` = migration-free coexistence; stream-through downloads on both surfaces. `AttachmentStorage::assertSafeDisk()` is the single safety judgment (dedicated `attachments*` name, no exposure markers, ACL allowlist) shared by uploads and the sweep. Write ACL defaults `bucket-owner-full-control` (modern AWS owner-enforced buckets). | `config/filesystems.php`, `app/Support/Attachments/AttachmentStorage.php`, `docs/self-hosting/attachment-storage.md` | #600 |

---

## 4. Architecture notes worth knowing before you touch these areas

- **Agent realtime is a hand-rolled WebSocket** speaking the pusher protocol
  directly (inline in `conversations/show.blade.php`) — **not** laravel-echo, so
  `window.Echo` is absent by design. It reconnects with capped backoff and
  resyncs the transcript on (re)subscribe. All broadcast events are
  `ShouldBroadcastNow` (synchronous → Reverb), so **realtime needs no queue
  worker**. See `memory` / `docs` if confused by a missing `window.Echo`.
- **External issue integrations** follow a consistent shape:
  - *Outbound issue*: `{Provider}IssueCreator` (dispatched by
    `AgentTicketExternalIssueController`). Scoped summary only — never
    transcripts, cobrowse snapshots, or internal notes.
  - *Outbound comment*: `{Provider}IssueCommenter implements IssueCommenter`,
    resolved by `AgentTicketController::commenterFor()`.
  - *Inbound*: public per-connection webhook receivers →
    `InboundIssueStateSync` (state) / `InboundCommentSync` (comments). Auth per
    provider: GitHub & Jira `X-Hub-Signature(-256)` HMAC; GitLab `X-Gitlab-Token`
    compare. No secret configured ⇒ refuse. State is **reflected, never
    enforced** (Wayfindr tickets are never auto-closed).
  - *Echo-loop guard*: `InboundCommentSync` keeps a bounded per-link ledger
    (`metadata.synced_comment_ids`), written under a `lockForUpdate` row lock so
    concurrent deliveries are atomic. Outbound records every posted comment id;
    inbound skips ids it already knows (own echoes + retries).
  - *Capability flags*: `create_issue` gates outbound creation and `add_comment`
    gates the outbound note relay. `sync_status` is present but is not currently
    an inbound-webhook switch. Signed inbound deliveries instead require an
    enabled connection and valid configured webhook secret.
- **Cobrowse** is consent-gated; masking happens **in the browser before data
  leaves the page**, and the server re-sanitizes. The agent preview is an inert
  **sandboxed iframe** (opaque origin) — keep it inert. Transport budgets live in
  `CobrowsePayloadBudget`; content is pruned 72h after a session ends.
- **Attachments (ADR 0007)** in one breath: message-scoped rows with
  denormalized `conversation_id`/`account_id`/`site_id` and a per-row
  `storage_disk`; two-step upload (pending → bind-on-send, all races
  row-locked); every fetch re-derives message → conversation → site and passes
  the visitor-token or agent-`view` check; binaries only ever stream through
  the app. `AttachmentStorage::assertSafeDisk()` is the single storage-safety
  judgment (dedicated `attachments*` disks only, no exposure markers, ACL
  allowlist) — used by upload routing AND the sweep; never bypass it. Scanning
  runs synchronously pre-store via the `AttachmentScanner` binding.
- **Operator readiness** (`/dashboard/readiness` admin, `/operator` platform
  operator) is the app's own ops checklist — trust it over guessing.

---

## 5. Working conventions (please keep these)

- **PR flow**: branch → open PR → wait ~5 min → check for **Codex bot** review
  comments → address / reply / resolve threads → **merge when green**. There is
  **no GitHub Actions CI** in this repo; "green" means local tests pass **and**
  Codex has cleared (it often stays silent = no findings). Codex catches real
  bugs — take its P2s seriously.
- **Commits under the owner's creds only** — **no** `Co-Authored-By` trailers,
  **no** "Generated with Claude Code" footers. (The repo's `attribution` config
  already suppresses the harness trailer; just don't hand-type one.)
- **Fork sync is the owner's action.** Stage deploys from the
  `northcoastmedia/wayfindr` fork's `main`, which the owner syncs from upstream
  `main` to trigger a Forge deploy. **Do not sync it yourself.**
- **Test toolchain**: run server tests with the PHP 8.5 binary
  (`/opt/homebrew/opt/php/bin/php`); add `-d memory_limit=1G` for the full suite.
  Pest + Pint (`./vendor/bin/pint <files>`). Widget: `node --test` + jsdom. For
  inline Blade `<script>` changes, sanity-check JS with `node --check` on the
  extracted block.
- **Stage validation** uses an authenticated browser session: the agent side at
  `wayfindr.on-forge.com`, the visitor widget at `wayfindr.cc`. Drive typing /
  messages via DOM events; verify via DOM/`srcdoc`, **not** screenshots (the
  transform-scaled cobrowse preview rasterizes unreliably under automation).
- **Steer at epic boundaries and genuine forks**; otherwise strong autonomy.
  Surface (don't silently override) anything that contradicts an existing
  deliberate decision — e.g. a test that encodes intent.

---

## 6. Prospective roadmap (next slices)

Ordered by real dogfood value and dependency, not feature novelty.

1. **Agent UI density reduction — the active epic (owner-directed, July 16).**
   Every agent route presents a lot of *good* information, but the day-to-day
   agent doesn't need most of it to do their support task. Trim the agent UI to
   what's relevant for the task at hand; keep the depth reachable, not ambient.
   Guardrails already established by the prior UI epic (#511): unobtrusive UI,
   the agent finds what they need NOW, never make the customer wait; tabs over
   long scrolls; reuse the `x-tabs` component for new surfaces.

   Suggested shape (survey first, then slice): inventory each agent route's
   sections; classify each as *task-critical* (visible by default),
   *situational* (collapsed/behind a tab or toggle), or *diagnostic/ops*
   (move toward readiness/operator surfaces or a "details" drawer); then trim
   route by route — conversation detail first (it carries the most ambient
   diagnostics), then ticket detail, then the queues/home.

2. **Operate the real dogfood loop.** Route Wayfindr support through Wayfindr,
   keep synthetic smoke records distinguishable from real work, and let actual
   conversations choose the next branch-sized slice. Watch `/dashboard/readiness`,
   `/operator`, failed queue jobs, mail, and realtime after deploys, but do not
   turn routine observation into a new ceremony layer.

3. **Attachments: done.** The full contract shipped (see §2/§3). The only
   remaining optional item is exercising the S3 surface against a real bucket
   (stage runs local by design). Future attachment work is demand-gated:
   direct-ticket/internal-note attachments, office-document opt-ins, pre-signed
   URL opt-in — all deliberately excluded from ADR 0007's v1.

4. **#22 — richer field mapping, only after live demand.** Syncing labels,
   assignee, or priority needs a product decision about fields, direction, and
   conflict handling. Do not guess that contract before dogfood traffic shows
   which provider metadata agents actually need. Inbound-comment notifications
   and richer presentation belong to the same demand-gated polish lane.

5. **#58 — Platform Operator Boundary, next slices.** The boundary exists
   (`/operator`, readiness, security-posture check, docs). Remaining, design-heavy
   work: **break-glass** customer-data access (explicit, scoped, time-bound,
   audited) and a comprehensive **platform-action audit** trail. Start from
   `docs/product/platform-operator-boundary.md`.

6. **#492 — live in-place cobrowse replay (incremental DOM patching).**
   **Recommended against as currently specced**: it would require weakening the
   inert sandboxed preview, a security-posture regression. Only revisit with a
   design that keeps the preview inert (no script execution, no external-resource
   or `url()` exfiltration path).

7. **Scale-driven realtime hardening** (only if needed). Broadcasts are
   `ShouldBroadcastNow` (synchronous). If broadcast volume ever pressures request
   latency, consider moving to queued broadcasts + a dedicated worker — but that
   reintroduces a worker dependency for realtime, so measure first.

---

## 7. Known caveats / gotchas

- **Stage Reverb socket can drop right after a deploy.** The agent reconnect
  (#576) recovers it; the visitor widget stays current via polling regardless.
  If a live-update test fails immediately post-deploy, wait for reconnect.
- **Cobrowse preview + CDP screenshots**: the transform-scaled sandboxed iframe
  often captures blank under automation. Validate content via the
  `allow-same-origin` diagnostic-iframe trick or by reading `srcdoc`, not
  screenshots. (Real agents' browsers paint it fine.)
- **Blade `@php(...)` short form** can truncate on inner `()` — use
  `@php … @endphp` block form.
- **GitHub auto-close keywords in PR titles/bodies** (`closes #NN`) will close
  the referenced issue on merge. #22 was reopened after an accidental close this
  way — mind epic references in PR text.
- **Codex review triggers occasionally drop silently** (no 👀 reaction on the
  `@codex review` comment = it never started). Re-trigger once; when it *has*
  reviewed the substance and only a tiny, test-covered delta is unreviewed,
  merging on judgment is acceptable — say so explicitly. Codex also signals
  "clean" as a 👍 reaction on the PR body with no comment.
- **clamd on Ubuntu**: won't start until freshclam's first signature download
  completes; uses the unix socket `/var/run/clamav/clamd.ctl` (not TCP); needs
  ~1 GB RAM (stage was resized to 2 GB for it); and `systemctl stop
  clamav-daemon` alone doesn't stop intake — the socket unit re-triggers it
  (stop both). A socket-accepted-but-dead daemon is precisely the hang case
  #599 fixed.
- **`/operator` requires `platform_role = operator`** on the user — only the
  `wayfindr:bootstrap` user gets it automatically; grant via a tinker one-liner.
- **Faked `UploadedFile` guesses MIME from the extension** — byte-sniffing tests
  need a real temp-file upload (see `realUpload()` in the upload test).

---

## 8. End-of-session snapshot — July 16, 2026

- Upstream `main` is at `c9a4bd0` (S3 storage surface, #600). The stage fork was
  last synced at #599 (ClamAV hang fix); #600 ships defaulting to local storage,
  so stage needs no config change when it syncs.
- Full server suite: **1,010 tests / 7,887 assertions**. Widget suite: **157
  tests**. Pint clean.
- The attachments epic (ADR 0007) is **fully closed**: contract, storage +
  scoping, uploads, sweep, both UIs, first-message attach, ClamAV scanner, and
  the S3 surface — all Codex-reviewed. Codex raised ~24 findings across the
  epic's PRs (nine on #600 alone); every one was addressed with a regression
  test. Standouts worth knowing: the sweep-eats-shared-disk hazard (hence
  `assertSafeDisk`), the AWS ACL-disabled-bucket default (hence
  `bucket-owner-full-control`), and the clamd socket-activation hang.
- **ClamAV is live on stage** (2 GB box): readiness green, EICAR rejected
  end-to-end through the real widget with the `attachment.quarantined` audit
  naming `Eicar-Test-Signature`, clean uploads scanned and served.
- Both attachment UIs are live-validated (automated passes + the owner's own
  phone/desktop testing). Stage test conversations: WF-GZDZRBTE, WF-L6IVPTR1.
- The next epic is the **agent UI density reduction** (§6.1), owner-directed:
  survey → classify → trim, conversation detail first.
