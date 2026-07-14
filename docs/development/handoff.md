# Engineering Handoff & Roadmap

*Living document — last updated July 14, 2026. For an agent (or engineer) picking up
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

**Epics closed this cycle**: #4 (Chat UX Polish), #5 (Cobrowse Transport
Discipline), #490 (Cobrowse Observe-Mode Fidelity).

**Issue housekeeping is now behind runtime truth.** #564 can be reconciled with
the completed launch and first-live-validation evidence, then closed. #22 should
mark its live-provider validation complete but remain open only if it continues
to own demand-gated field mapping and integration polish. #587 can close after
the shipped mobile fix is acknowledged. The longer-lived open product areas are
#58 (Platform Operator Boundary), #492 (live in-place cobrowse replay — see §6,
recommended against as specced), and the attachment surface described below.

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

1. **Reconcile and close the launch proof.** Update #564 with the deployed
   revision, readiness/restore evidence, stage-as-dogfood decision, live mobile
   support session, and GitHub handoff proof, then close it. Update #22's two
   live-validation checkboxes; keep that epic open only for explicitly retained
   integration work. Close #587 against `abdbb7f` after the phone retest is
   recorded. These are tracking updates, not new product gates.

2. **Operate the real dogfood loop.** Route Wayfindr support through Wayfindr,
   keep synthetic smoke records distinguishable from real work, and let actual
   conversations choose the next branch-sized slice. Watch `/dashboard/readiness`,
   `/operator`, failed queue jobs, mail, and realtime after deploys, but do not
   turn routine observation into a new ceremony layer.

3. **Attachment capability — define the contract before adding upload UI.** The
   first real mobile conversation raised a legitimate need for attachments on
   desktop and mobile. Treat this as a security, storage, retention, and workflow
   feature rather than a file-input tweak.

   **Recommended initial boundary:** attachments belong to conversation
   messages. Tickets linked to a conversation can surface those attachments as
   supporting context without copying the binary. Direct ticket attachments and
   internal-note attachments should wait until real use proves they need separate
   lifecycles.

   **Product decisions to write down first:**

   - allowed file classes, detected MIME types, per-file size, message count,
     account/site quotas, and whether images receive previews;
   - mobile photo/camera/file-picker behavior versus desktop drag/drop and picker;
   - retention and deletion semantics, including what happens when a message,
     conversation, visitor, or account is removed;
   - self-hosted local/private storage versus S3-compatible object storage, and
     which operational guarantees Wayfindr can honestly check;
   - malware scanning/quarantine expectations and the safe fallback when no
     scanner is configured; and
   - external export policy. Attachment binaries and storage URLs should **not**
     leave Wayfindr for GitHub/GitLab/Jira by default.

   **Dependency-ordered delivery slices:**

   1. Add an attachment contract and issue/ADR covering the decisions above.
   2. Add a message-scoped attachment model, private storage keys, sanitized
      display names, detected MIME/size metadata, upload state, and audit events.
   3. Add authorized upload/download endpoints scoped through the signed visitor
      session or agent site access. Enforce size, MIME allowlists, quotas, rate
      limits, safe `Content-Disposition`, and orphan cleanup server-side.
   4. Add visitor uploads to the widget with an accessible picker, mobile camera
      compatibility, progress, remove/cancel, retry, and idempotent message send.
   5. Render safe attachment rows/previews in the agent transcript and linked
      ticket context, then add agent-to-visitor attachments to the reply composer.
   6. Add retention/pruning behavior, private-storage readiness guidance,
      backup/restore documentation, and optional object-storage/scanning adapters.
   7. Harden with cross-account/site authorization, MIME spoofing, oversized and
      decompression-bomb cases, interrupted uploads, duplicate submits, expired
      visitor tokens, mobile Safari/Android, keyboard, and screen-reader tests.

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

---

## 8. End-of-session snapshot — July 14, 2026

- Local `main`, `origin/main`, `ncm/main`, and the active Forge release all point
  to `abdbb7fab0c611d5e2375eaebe9d82df485c9436`.
- Forge reports no failed queue jobs.
- The last full server verification for the integration guidance change passed
  919 tests / 7,622 assertions; its focused provider suite passed 60 tests / 253
  assertions.
- The mobile composer fix passed the full widget suite: 145 tests.
- Installation readiness was 12 ready / 0 attention / 1 intentional manual
  backup check. Dogfood gates had no blocked item.
- The stage instance is the owner-approved initial dogfood instance.
- Live GitHub handoff and the first real mobile support loop are proven.
- No attachment implementation exists yet; §6 defines the prospective surface
  and its recommended first boundary.
