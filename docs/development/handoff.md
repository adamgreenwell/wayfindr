# Engineering Handoff & Roadmap

*Living document — last updated July 2026. For an agent (or engineer) picking up
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

**Stage has passed the core support-loop rehearsal.** As of July 10, outbound
**mail** (Google Workspace SMTP relay), the **scheduler** (hourly digests +
cobrowse pruning), **Reverb** (realtime), and the **queue worker** (digests +
`ShouldQueue` notifications) had each been validated live. Before declaring
dogfooding open after a deploy, refresh `/dashboard/readiness` and `/operator`,
confirm the deployed revision, and recheck the full runtime contract in
`docs/product/mvp-dogfood-readiness.md`, including backups and restore.

**Epics closed this cycle**: #4 (Chat UX Polish), #5 (Cobrowse Transport
Discipline), #490 (Cobrowse Observe-Mode Fidelity).

**Open issues**: #22 (External Ticket Integrations — bidirectional issue sync +
comment relay done; *field mapping* remains), #58 (Platform Operator Boundary —
break-glass + platform-action audit remain), #492 (live in-place cobrowse
replay — see §6, recommended against as specced), #564 (declare dogfooding open
— a product call for the owner).

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
- **Stage validation** uses Claude-in-Chrome: the agent side at
  `wayfindr.on-forge.com`, the visitor widget at `wayfindr.cc`. Drive typing /
  messages via DOM events; verify via DOM/`srcdoc`, **not** screenshots (the
  transform-scaled cobrowse preview rasterizes unreliably under CDP).
- **Steer at epic boundaries and genuine forks**; otherwise strong autonomy.
  Surface (don't silently override) anything that contradicts an existing
  deliberate decision — e.g. a test that encodes intent.

---

## 6. Prospective roadmap (next slices)

Ordered roughly by readiness-to-build. Each has a starting pointer.

1. **#564 — refresh stage and declare dogfooding open.** Sync the deployment
   fork through the owner's normal workflow, confirm the deployed revision,
   refresh the full readiness checklist, and make the stage-versus-production
   product call. Once open, use the clean support queues as the real-user
   feedback loop.

2. **Comment relay live-validation + polish**. The relay is thoroughly unit /
   HTTP-mocked but has **not** been exercised against a live provider (needs a
   real GitHub/GitLab/Jira connection + a linked issue on stage). When one
   exists, confirm the round trip and the echo-loop guard end to end. Polish
   ideas: notify assigned agents on an inbound comment; render received comments
   more richly than a plain internal note.

3. **#22 — richer field mapping, after live demand.** Syncing labels, assignee,
   or priority needs a product decision about fields, direction, and conflict
   handling. Do not guess that contract before dogfood traffic shows which
   provider metadata agents actually need.

4. **#58 — Platform Operator Boundary, next slices**. The boundary exists
   (`/operator`, readiness, security-posture check, docs). Remaining, design-heavy
   work: **break-glass** customer-data access (explicit, scoped, time-bound,
   audited) and a comprehensive **platform-action audit** trail. Start from
   `docs/product/platform-operator-boundary.md`.

5. **#492 — live in-place cobrowse replay (incremental DOM patching).**
   **Recommended against as currently specced**: it would require weakening the
   inert sandboxed preview, a security-posture regression. Only revisit with a
   design that keeps the preview inert (no script execution, no external-resource
   or `url()` exfiltration path).

6. **Scale-driven realtime hardening** (only if needed). Broadcasts are
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
