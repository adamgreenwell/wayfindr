# Engineering Handoff & Roadmap

*Living document — last updated August 11, 2026. For an agent (or engineer) picking up
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
  `https://wayfindr.on-forge.com`. Treat every stage or fork observation in this
  file as dated evidence, not proof of the current deployed state.

---

## 2. Current state (August 2026)

The **MVP support loop works end to end**: a visitor chats via the widget → the
agent sees it live and replies → tickets capture durable work → cobrowse gives a
consented, masked view of the visitor's page.

**The current Forge stage is the initial controlled dogfood instance.** The
owner explicitly chose it instead of creating a separate production install
first. The July 14 readiness pass confirmed the then-current runtime contract live:
`APP_DEBUG=false`, public HTTPS, current migrations, PostgreSQL, Redis, outbound
mail, the scheduler, durable queue worker, Reverb, writable storage, deploy
restarts, and no failed queue jobs. A manual database export and isolated
restore drill also succeeded. Since then, operator settings, GUI backup/restore,
release manifests, upgrade guards, advisory notices, pull-request CI, branch
protection, Dependabot, the GitHub Wiki, and the disposable-VM evidence contract
have landed. Do not treat the July 14 stage pass, the deployment fork, or any
later dated smoke as current deployment proof without re-checking the target.

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
The stage box was resized to 2 GB to host clamd comfortably. **The S3 surface
went live on Cloudflare R2 (July 18)**: stage now stores new attachments in
the `wayfindr-attachments` bucket and streams them back through both the
visitor and agent routes; older attachments keep serving from local disk
per-row.

**The agent UI density epic is complete and owner-validated (July 16).**
PRs #602–#605 trimmed every agent route to what the day-to-day support task
needs: coaching copy cut outright, session/state diagnostics collapsed in place
behind the native `<x-details-disclosure>` component with state-bearing
summaries, install-wide constants moved to readiness pages, duplication killed.
The disclosure pattern is approved for reuse on new surfaces.

**The break-glass epic is code-complete (July 16).** The full
[ADR 0008](../decisions/0008-platform-operator-break-glass.md) contract shipped
across PRs #606–#611: the grant model and lifecycle service (scoped, reasoned,
time-bound, read-only, row-locked, audited), the operator request/self-approve
console and the account approve/deny/revoke page, the scoped read-only viewers
(transcripts, tickets, attachment metadata only), the always-on dashboard
transparency banner while a grant is live, and a hardening suite that pins the
hosted shape end to end. 69 tests across five files. **Live-validated on
stage July 17**: a full drill — request by support code, self-approval on the
sole-admin account, read-only transcript with metadata-only attachments, the
dashboard banner, the account-homed searchable audit trail, revoke, and the
stale viewer URL dying at 403.

**Unattended alerts shipped (July 17).** A third email cadence — "Email only
when a visitor waits unseen" (#613) — closes the gap between
immediate-per-message email and the hourly digest without presence tracking:
the unread `ConversationNeedsReply` notification is the seen-signal. The
episode invariant that ten review rounds distilled: the stamp and clock
belong to a waiting episode; an episode ends when an agent replies or anyone
sees the conversation (read states first, strictly-after comparisons); each
new episode gets its own full threshold and exactly one metadata-only email.

**Wayfindr distributes itself: `v0.1.0-alpha.1` is released (July 21).** The
self-hosting epic (#614–#616) replaced the compose prototype with the real
thing — a FrankenPHP production image (automatic HTTPS via `SERVER_NAME`,
always-on loopback ops site, Reverb proxied under the app's own hostname), a
pull-only official compose stack, a curl-able one-line installer with a
release-pinned upgrade path, and the repository's only CI: a tag-triggered
workflow that builds multi-arch images to GHCR and publishes the (prerelease-
marked) GitHub Release. The published pipeline was clean-room validated: the
real one-liner on an empty directory resolved and pinned the alpha, pulled
from the public registry, booted healthy, and served `/setup` and
`/widget.js`.

**Operators configure Wayfindr in a browser, not a text editor (ADR 0011,
July 28).** `/operator` now carries mail, attachment storage, malware scanning,
and backups as GUI settings stored in the database, overriding env with no
restart, plus a guided onboarding checklist as the landing page after `/setup`.
The architecture fork the owner resolved is worth knowing: **DB-backed settings
that override env**, not a GUI that rewrites `.env`. Backups got the full
treatment — destination, retention, per-install prefix, run-on-demand, run
history, and a confirmed in-GUI restore gated on a durability preflight that
names the specific unmet prerequisite instead of failing vaguely. Live on stage,
including a GUI-triggered backup completing end to end to real S3.

**Releases now declare what they require of an operator, and the artifact
enforces it (ADR 0012 + ADR 0013, August 3–5).** Versioning is SemVer where
"major" means *the operator must do something beyond pulling the image* — pre-1.0
the minor slot carries that role. Every install has a real release identity
(`0.2.0-dev+<sha>` for source builds, baked for official images), versions
compare in order rather than only for equality, and a release publishes a
manifest naming each required action, its phase, and whether it is machine-
checkable or must be attested. An install with unmet requirements **refuses to
migrate** (exits before touching the schema, so the previous image still runs) or
**refuses to serve** while keeping `/up` answering, depending on the phase. The
finding that shaped it: enforcement cannot live in the installer, because an
operator upgrading from an older release runs *their* installer, which has no
preflight and cannot be given one — so the guarantee has to live in the artifact
the upgrade actually fetches.

**`v0.1.0` is released (August 5)** — the first stable release, and the first to
publish a manifest. See §8.

**Epics closed this cycle**: #4 (Chat UX Polish), #5 (Cobrowse Transport
Discipline), #490 (Cobrowse Observe-Mode Fidelity), attachments (ADR 0007),
agent UI density (#602–#605), break-glass (ADR 0008, #606–#611), unattended
alerts (#613), self-hosting packaging + first release (#614–#616), operator
settings + guided onboarding (ADR 0011, #627–#634), backup remote-push
(ADR 0010, #623/#624), external issue integration foundations, platform
versioning + upgrade enforcement (ADR 0012/0013, #635–#656 — completed August
11 with the advisory response; see §12), repository CI/config hardening, and the
repo-authored GitHub Wiki.

**Issue housekeeping is current.** #564 (launch proof) was reconciled and
closed. External issue creation, state sync, and comment relay shipped and were
live-validated; demand-gated follow-up now lives in #626 rather than the original
integration umbrella. The break-glass half of the platform-operator boundary is
done; any future platform-action audit inventory should start from
`docs/product/platform-operator-boundary.md` instead of reopening the shipped
grant contract. Other long-lived product work is deliberately demand-gated:
#626 for richer external ticket follow-ups and #492 for live in-place cobrowse
replay, which remains recommended against as originally specced.

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

### Break-glass cycle (July 16)

| Piece | What it does | Where | PRs |
| --- | --- | --- | --- |
| **Contract** | ADR 0008: operator access to customer content only via a first-class grant — scoped (conversation/site/account, narrowest default), reasoned, time-bound (60 min default / 24 h max, close-early, never extend), read-only, two-party approval with standing-gated self-approve fallback, account-visible. | `docs/decisions/0008-platform-operator-break-glass.md` | #606 |
| **Grant model + lifecycle** | `BreakGlassGrant` (live-expiry `isActive()`, resource-side `covers*` re-derivation, `scopeLabel()` names references only when account-owned) + `BreakGlassGrants` service (request/approve/deny/close/expire, row-locked, every transition audited atomically, account-homed events); grants outlive the content they exposed (`nullOnDelete`). Five-minute expiry sweep command. | `app/Models/BreakGlassGrant.php`, `app/Support/BreakGlass/BreakGlassGrants.php` | #607 |
| **Request/approval surfaces** | `/operator/break-glass` (request form, self-approve only when it can succeed, close-early) and `/dashboard/account/operator-access` (approve/deny/revoke + history). Open grants are never capped behind history on either surface. | `OperatorBreakGlassController.php`, `AgentAccountBreakGlassController.php`, both blade views | #608 |
| **Read-only viewers** | Requester-only, live-active-only, coverage re-derived per request AND per listed row; transcripts + ticket records as text; attachment metadata only (rows verify their own scope columns); content renders only where that resource's view is audited (`.opened`/`.resource_viewed`, deduped); account audit page surfaces and searches the labels. | `OperatorBreakGlassViewerController.php`, `operator/break-glass-*.blade.php`, `AgentAccountAuditController.php` | #609 |
| **Transparency + hardening** | Dashboard banner for every agent of the account while any grant is live (admins get revoke); hardening suite: hosted shape end-to-end, no dashboard escalation, deactivated actors, stray scope columns. | `agent/dashboard.blade.php`, `tests/Feature/BreakGlass*` (69 tests, 5 files) | #610/#611 |

### Alerts + storage cycle (July 17–18)

| Piece | What it does | Where | PRs |
| --- | --- | --- | --- |
| **Unattended alert cadence** | Third cadence: email only when a visitor message waits unseen past 5 minutes. Episode-bounded (reply-or-seen), one aggregated metadata-only email per episode, five-minute sweep, cadence surfaced on profile/alert-center/account roster. No JSON-path SQL against the TEXT `notifications.data` column (Postgres). | `app/Support/UnattendedConversationAlertCollector.php`, `SendUnattendedConversationAlertsCommand.php`, `NotifyAgentsOfVisitorMessage.php`, `UnattendedConversationAlertMessage.php` | #613 |
| **S3 surface proven + R2 live** | Full MinIO drill of the real pipeline (safety gate accept/refuse, upload service, byte-identical round-trip, streaming, anonymous refusal, delete); then Cloudflare R2 wired on stage (`region=auto`, `ACL=private`, deploy needed for config:cache) and a real widget upload stored + served through the bucket. | `docs/self-hosting/attachment-storage.md`, stage env | validation only |

### Self-hosting release cycle (July 18–21)

| Piece | What it does | Where | PRs |
| --- | --- | --- | --- |
| **FrankenPHP image** | Production web server; `SERVER_NAME` is the TLS knob (hostname = automatic Let's Encrypt, `:80` default); **always-on loopback ops site `:8000`** so health probes and proxy upstreams work in every TLS mode; monorepo layout preserved (widget serves from `packages/widget-js`); non-root with bind capability; entrypoint storage prep + gated auto-migrate; `package-lock.json` committed. | `docker/self-hosting/server.Dockerfile`, `Caddyfile`, `docker-entrypoint.sh` | #614 |
| **Official compose stack** | Pull-only `compose.yml` (source builds via `compose.build.yml` overlay): web/queue/scheduler/reverb/postgres/redis + optional clamav profile; Reverb proxied under the app hostname (no published reverb port); split-horizon reverb env (services post to `reverb:8080`, browsers get `REVERB_CLIENT_*` with read-time fallback); ACME state in volumes; legacy prototype envs upgrade without collisions. | `docker/self-hosting/compose.yml`, `compose.build.yml`, `config/broadcasting.php` | #614 |
| **One-line installer** | `curl \| bash` → checks Docker, resolves and pins the latest release (stack files AND image; tags-API fallback; installs with **no published release at all** fail early with guidance — alpha releases resolve and pin normally), mints secrets, boots, waits on the ops site, prints `/setup`; `--behind-proxy` decouples TLS termination from scheme (loopback binds + `TRUSTED_PROXIES`); `--upgrade` re-resolves, refreshes, pulls, restarts. | `scripts/self-host/install.sh`, `scripts/self-host/generate-env.sh` | #614 |
| **Release workflow + docs** | The repository's only CI: `v*` tags → multi-arch GHCR build (`{version}`, `{major.minor}` for stables, `latest`) + GitHub Release (dash-suffixed tags marked prerelease, so stables win `releases/latest` over newer alphas). Install doc rewritten as the front door (requirements, three paths, data/backup/`down -v` warnings). | `.github/workflows/release-image.yml`, `docs/self-hosting/install.md`, `docker/self-hosting/README.md` | #614/#615/#616 |
| **`v0.1.0-alpha.1`** | First public release: multi-arch image on GHCR (publicly pullable), prerelease-marked GitHub Release, clean-room one-liner validated against the published artifacts. | `ghcr.io/adamgreenwell/wayfindr` | tag `v0.1.0-alpha.1` |

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
- **Operator readiness** (`/operator`, platform operator only) is the app's own
  ops checklist — trust it over guessing.

---

## 5. Working conventions (please keep these)

- **PR flow**: branch → open PR → wait ~5 min → check for **Codex bot** review
  comments when requested → address / reply / resolve threads → **merge when
  green**. Pull requests now have required CI: PHP application, widget/assets,
  repository contracts, and self-hosting contracts. Tag-triggered release-image
  publishing remains separate and only runs for `v*` tags.
- **Commits under the owner's creds only** — **no** `Co-Authored-By` trailers,
  **no** "Generated with Claude Code" footers. (The repo's `attribution` config
  already suppresses the harness trailer; just don't hand-type one.)
- **Fork sync is the owner's action.** Stage deploys from the
  `northcoastmedia/wayfindr` fork's `main`, which the owner syncs from upstream
  `main` to trigger a Forge deploy. **Do not sync it yourself.** A synced fork
  proves only that source was moved; it is not runtime proof until the target
  deployment is checked.
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

1. **Operate `v0.3.0` while preparing the `0.4.0` reliability cycle.** Three
   releases are out and the upgrade path is proven in both directions (see §11
   and §12). Cut freely: the upgrade is one command, installs pin to releases,
   and the guard now has a proportionate response for requirements that are worth
   reporting without being worth an outage.

   **The condition this document set in July is still unmet, and still matters**:
   nobody but the owner has run an install *or* an upgrade. `0.1.0 → 0.2.0` and
   `0.2.0 → 0.3.0` have both been proven end to end in a clean room against real
   published artifacts, so the mechanism is no longer the open question — the
   open question is whether it survives contact with someone else's environment.
   That is the next genuinely new evidence, and it cannot be manufactured here.

2. **Operate the real dogfood loop.** Route Wayfindr support through Wayfindr,
   keep synthetic smoke records distinguishable from real work, and let actual
   conversations choose the next branch-sized slice. Watch `/operator`,
   `/operator`, failed queue jobs, mail, and realtime after deploys, but do not
   turn routine observation into a new ceremony layer.

3. **Attachments: done, including remote storage in production.** The full
   contract shipped and the S3 surface runs live on Cloudflare R2 (see §2/§3).
   Future attachment work is demand-gated:
   direct-ticket/internal-note attachments, office-document opt-ins, pre-signed
   URL opt-in — all deliberately excluded from ADR 0007's v1.

4. **#626 — external ticket follow-ups, only after live demand.** Syncing labels,
   assignee, or priority needs a product decision about fields, direction, and
   conflict handling. Do not guess that contract before dogfood traffic shows
   which provider metadata agents actually need. Inbound-comment notifications
   and richer presentation belong to the same demand-gated polish lane.

5. **Platform operator boundary: break-glass is done.** The design-heavy grant
   contract shipped (ADR 0008, PRs #606–#611; see §3). Any later
   platform-action audit inventory is demand-gated, and much of its foundation
   (account-homed `break_glass.*` events, the operator dashboard's safe-activity
   feed) now exists. Possible later extensions the ADR deliberately excluded
   from v1: repair verbs, attachment binary access. Start from
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
  the referenced issue on merge. This already caused avoidable issue housekeeping
  on an older integration epic — mind epic references in PR text.
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
- **`notifications.data` is a TEXT column** — JSON-path where clauses
  (`data->x`) pass on SQLite and crash on Postgres. Narrow by plain columns in
  SQL and match JSON in PHP. (`audit_events.metadata` and friends are real
  `json()` columns — safe.)
- **Cloudflare R2 as the attachment store**: region must be `auto`, ACL must
  be `private` (R2 rejects everything else); the S3 key/secret come from
  R2 → Manage API Tokens (NOT My Profile → API Tokens), and the secret is the
  SHA-256 of the token value if the confirmation screen is missed. Forge env
  edits need a deploy to take effect (`config:cache`).
- **Codex delivers review findings as INLINE comments** — read
  `gh api repos/OWNER/REPO/pulls/N/comments`, not `issues/N/comments` or
  reactions, which stay empty while findings exist. Filter on
  `original_commit_id`; `commit_id` is re-anchored to the branch tip for older
  comments, so already-fixed findings resurface as if new. A `COMMENTED` review
  with an empty-looking body means the findings are inline.
- **A test that prints `FAIL` may still exit 0.** `assertFailed()` executes a
  pending Artisan command immediately, so any `expectsOutputToContain()`
  registered after it never runs; and shell checks appended after a script's
  final `[ "$fail" -eq 0 ]` cannot affect its exit status. Both happened here.
  After writing a test for a fix, mutate the fix and confirm the test *fails*.
- **Compose interpolates `${...}` from the invoking SHELL ahead of `--env-file`**
  (`install.sh:412` documents it). Anything the container must resolve from its
  own environment needs `$${...}` so the container shell expands it. Both forms
  look right in the YAML; `docker compose config` is the only thing that tells
  them apart.
- **At a release cut, ask what the CHANGELOG covers, not whether it is stale.**
  The commit-since-last-touch check passes when a later PR touched the file
  without covering earlier ones — which is exactly what happened at the 0.3.0
  cut.
- **The self-hosting stack's health story**: the loopback ops site on `:8000`
  exists in every TLS mode — probe that, never the public vhost. `compose.yml`
  is pull-only; source builds need the `compose.build.yml` overlay.

---

## 8. End-of-session snapshot — July 21, 2026 (release day)

- **`v0.1.0-alpha.1` is published**: multi-arch image at
  `ghcr.io/adamgreenwell/wayfindr` (publicly pullable), prerelease-marked
  GitHub Release with generated notes, and the clean-room one-liner validated
  against the published artifacts (resolve → pin → pull → boot → healthy →
  `/setup` + `/widget.js`). Upstream `main` is at the #616 merge; the stage
  fork is synced through the alerts slice (#613) and runs attachments on R2.
- Full server suite: **1,076 tests / 7,997 assertions**. Widget suite: **157
  tests**. Pint clean.
- Live on stage: the full break-glass drill (July 17), the unattended alert
  cadence (flip Profile → Email cadence to use it), and R2 attachment storage
  (July 18). Stage test conversations: WF-GZDZRBTE, WF-L6IVPTR1, WF-GYJ34VPJ.
- The self-hosting review (10 Codex rounds on #614) distilled rules worth
  keeping: health never depends on the public vhost (the `:8000` ops site
  exists in every TLS mode); stack files and image must describe the same
  release (pin both, fall back through the tags API, fail early pre-release);
  who terminates TLS is separate from the public scheme (`--behind-proxy`);
  split-horizon service addressing (servers post internally, browsers get
  client values with read-time fallback); and scripted edits must be verified
  by reading the result — one silent no-op nearly shipped a crash-loop.
- The unattended-alerts review (10 rounds on #613) produced the episode
  invariant (§2) and the TEXT-column landmine (§7). One deliberate
  won't-fix: a live viewer with the conversation open gets no read-state
  update (the transcript refresh is a pure read by design), so they may
  receive one redundant email — revisit only if dogfood shows it matters.
- Next: **operate the alpha** (§6.1) — dogfood on stage, watch for the first
  external self-hoster, cut `alpha.N` tags freely, and hold `v0.1.0` stable
  until an install + upgrade has succeeded in hands other than the owner's.
  *(Superseded — see §9. `v0.1.0` was cut on August 5 without that condition
  being met, deliberately and for stated reasons.)*

---

## 9. End-of-session snapshot — August 5, 2026 (v0.1.0 release day)

- **`v0.1.0` is published** — the first *stable* release and the first to carry
  a release manifest. Multi-arch image on GHCR (`0.1.0`, `0.1`, `latest`),
  `release-manifest.json` attached to the GitHub Release, built from `121e62a`.
- **`releases/latest` resolves for the first time.** Every prior release was
  prerelease-marked, so that endpoint 404'd and installs reached the tag through
  the installer's tags-API fallback. New installs now take the primary path.
- **Clean-room validated against the published artifacts**: the real one-liner,
  fetched fresh from `main`, in an empty directory — resolve → pin `v0.1.0` →
  pull from the public registry → boot → healthy in 10s → `/setup` and
  `/widget.js` both 200. The compose stack now brings up the `backup-queue`
  worker itself, so the operator action in the changelog applies to host-managed
  installs (Forge and similar), not to compose.
- **The guard ran for real and recorded the right thing.** The image bakes
  `v0.1.0` (the tag verbatim), and the install recorded
  `{"version": "0.1.0", "satisfied_through": "0.1.0", "fresh_install": true}` —
  canonicalised, and correctly classified as a fresh install rather than an
  upgrade. Worth knowing: **the `v` prefix survives into `/etc/wayfindr/version`
  and is canonicalised at every comparison point**, not at the source. That is
  deliberate (`ReleaseManifest::build`, `ReleaseState::recordedVersion`,
  `UpgradeGuard::declaredOrigin`), and `ReleaseManifestTest` pins it. Anything
  new that compares versions must canonicalise too — raw `ReleaseIdentity::version()`
  is a display string, not a comparison key.
- Full server suite at the release commit: **1,620 tests / 9,198 assertions**,
  1,620 collected and 1,620 executed (the counts are compared deliberately — an
  `exit()` reachable from a test kills the runner and silently shrinks the run).
- **Stage re-validated the same day at `7304c57`**: all four operator settings
  surfaces, a GUI-triggered backup completing to real S3 in ~44s, ClamAV
  reachability probe green, readiness 13/0/2.
- **What is still unproven**: nobody outside the owner has run an install *or*
  an upgrade. The clean room proves the artifacts are coherent; it does not prove
  the upgrade path across a release boundary, because `0.1.0` is the first
  release with a manifest and there is nothing above it yet. The first genuinely
  new evidence is `0.1.0 → 0.2.0` (see §6.1).
- Next: cut `0.2.0` when there is something to put in it, and use it to exercise
  the guard's first *declared* requirement — the backups-queue-consumer check,
  which `release.json`'s `_bootstrap` note describes and which is the reason that
  requirement is still changelog prose rather than an enforced action.

---

## 10. Session snapshot — August 10, 2026 (upgrade-guard debt paid)

**#647's structural debt is paid (PR #651).** The rule that had to be agreed by
six sites now has one name and one voice:
`App\Support\Release\ActionDisposition` (the three states) and `ActionAdvice`
(what the operator is told). Both message sites render one ordered list, so their
agreement is structural rather than a convention each file remembers.

**The find that explained the churn**: the three states existed in both
implementations all along — only their *names* differed, and one name meant two
things. `install.sh` clears its local `$stranded` for the middle case (so it
means *unacknowledgeable*); `UpgradeRequirements::stranded()` stays true there.

| Installer | PHP before | Meaning |
|---|---|---|
| `DO` | `!stranded()` | performable after the upgrade |
| `NOW` | `stranded() && reached()` | own release still running — possible until the pull |
| `STEP` | `stranded() && !reached()` | release skipped past — unreachable |

The enum's **values are the installer's codes**, so the two can be compared
directly rather than through a translation layer anyone has to maintain.

**The rule that replaced "delete the duplicate": pin, don't merge.** #647
proposed having the installer call the artifact's helpers. Neither duplicate can
actually be deleted:

- the preflight runs **inside the image being upgraded from**, so it cannot call
  a helper the release being installed introduced;
- `php_in_current_image()` needs `INSTALLED_IMAGE`, which comes from
  `env_value WAYFINDR_IMAGE` — **you cannot shell out to the artifact to learn
  which artifact to shell out to**. Only two of six `env_value` call sites run
  after the image is known, so delegating would leave *two* parsers where there
  is one, with the most consequential key still on the bash side.

So two differential tests lift the real functions out of `install.sh` and run
them against the authority they must match. **`install.sh` is byte-for-byte
unchanged** — no probe, no hook, no test-only branch. `make self-host-test` runs
them alongside the two pre-existing self-host scripts, which were undocumented
until now; see `testing.md`.

**Compose is the oracle for the dotenv half, not phpdotenv** — the file is
Compose's `--env-file`, and phpdotenv *rejects* the `export` spelling outright
with a fatal, while both Compose and `install.sh` accept it.

**Also fixed**: `UpgradeGuard` passed a third argument to the two-parameter
`stranded()`, which PHP silently discarded — behaviour was correct, but it read
as though the migration-blocking filter weighed the current release when it never
did. And the CHANGELOG had drifted again exactly as at the 0.1.0 cut: `#650`'s
operator-visible change (the backups page reporting worker presence) had no entry.

Suite 1,661/1,661, collected == executed. Both harnesses mutation-checked against
the real historical bugs from #648.

**Where the release sequencing stands** (owner's call, unchanged by this work):
`0.1.0` and `0.2.0` are stepping stones to a fully validated install/upgrade
process — there are no known third-party installs, so compatibility shims for
`0.1.0`-origin upgrades are deliberately *not* being built. Note that
RELEASING.md's own rule means a **patch** release cannot carry a declared
operator action pre-1.0, so the sequence is: `0.1.0 → 0.2.0` tests the plumbing
(manifest published, span read, floor honoured, nothing declared), `0.2.1` is the
recovery vehicle if something breaks, and **`0.3.0` is the first release that can
declare a real requirement** and exercise the guard end to end.

Anything added to this area — an advisory severity, a new phase, a third message
site — should extend `ActionDisposition` and `ActionAdvice` rather than re-derive
the rule.

---

## 11. `v0.2.0` released, and the upgrade path is proven — August 10, 2026

**`v0.2.0` is published** (release commit `564afa0`): stable, `release-manifest.json`
attached, `releases/latest` resolving to it, images `0.2.0` / `0.2` / `latest` on
GHCR. It declares nothing, deliberately — the point was to exercise the mechanism
on a release where being wrong costs nothing.

**The first cross-release-boundary upgrade has been run end to end.** A clean room
installed `0.1.0` using **0.1.0's own installer** (`--ref v0.1.0` pins stack files
and image; there is no `--version` flag), then ran `--upgrade`:

```
==> Handing off to the refreshed installer.                    <- the ADR 0013 re-exec, live
==> Preflight: nothing outstanding between 0.1.0 and 0.2.0.    <- read the published manifest
==> Upgrade complete.
```

Afterwards the install records
`{"version": "0.2.0", "satisfied_through": "0.2.0", "fresh_install": false}`.
**`fresh_install: false` is the new evidence** — every previous validation could
only ever produce `true`, because there was nothing above `0.1.0` to upgrade to.

**What is still unproven is now narrower**: the path works, but nobody outside the
owner has walked it.

**A real bug came out of the negative test, not the happy path.** Deleting the
release state file to check the guard could still *refuse* produced exit 78
correctly, with the wrong message: a perfectly current `0.2.0` install was told it
was *"older than 0.2.0 allows to upgrade directly"* and sent to reinstall
`0.1.0-alpha.1`. `minimum_upgrade_from` yields **two** refusals through one field —
*below the floor* (step through; no acknowledgement helps) and *floor unverifiable*
(the remedy is to state where you are, with `WAYFINDR_UPGRADE_FROM`). The report
command distinguished them and carried a comment describing the hazard; its twin,
the migration refusal, did not — and the twin is the one operators actually meet.
`FloorAdvice` now answers it once for both (PR #652).

**Three drifted twins surfaced in this one cycle**: the action-message pair (#651),
the smoke script's hand-copied partition (which predated the `NOW` classification
and answered `PROCEED` where the installer answers `BLOCK`, while its own comment
claimed it "cannot drift"), and the floor pair (#652). The standing rule for this
area is now **extract, never copy — and when fixing a message, go looking for its
twin.**

**Next**: `0.3.0` is the first release that can legitimately declare an operator
action (pre-1.0, RELEASING.md puts any action-bearing release on a minor bump). The
`backups-queue-consumer` check exists and works; declaring it still waits on a
third, non-blocking *advisory* severity, per ADR 0013 and `release.json`'s
`_bootstrap` note.

---

## 12. `v0.3.0` released — advisory notices, and ADR 0013 completed (August 11, 2026)

**`v0.3.0` is published**, and with it ADR 0013 has all three of its responses for
the first time. Every one has now been exercised against real published artifacts
rather than fixtures.

### Advisory notices: the third response

The guard could only halt the migration or refuse traffic. ADR 0013 recorded that
as a gap — *"some requirements are worth reporting without being worth an
outage"* — and the backups queue worker had been sitting in it since #650: its
check built and working, and deliberately undeclared, because the only phase it
could take was `after-start` and an unmet `after-start` **action gates serving**.
An install missing a backups worker would have refused all traffic over a backup
feature.

Advisory requirements are now declared under a separate top-level **`notices`**
list, reported on the operator console, in `wayfindr:upgrade-guard`, and in the
installer's upgrade output. They block nothing.

**The decision that matters is that it is a separate list, not a `severity` flag
on an action.** An advisory must be honoured at three independent gates — the
migration filter, the serving filter, the installer's partition — and a flag means
each has to *remember* to check it. A gate that forgets turns advice into an
outage, which is exactly what the response exists to prevent. The gates read
`actions` and cannot see `notices`.

It is also the only backward-compatible shape, which was not obvious until
checked against 0.2.0's shipped reader: older readers ignore an unknown top-level
key, so a notice-carrying release upgrades cleanly from every release predating
them, with **no schema bump** (a bump makes older images reject the manifest
outright). A `severity` flag inside `actions` would be read by old code as
*required*, so a `before-pull` advisory would have made the *old* installer refuse
the pull.

Three constraints follow, all enforced by the validator:

- A notice takes **no `phase`, no `depends_on_release`, no `upgrade-from`**. All
  three only mean something to work owed by a particular hop.
- **An action belongs to a hop; a notice belongs to the install.** Notices are
  read from the running release's own manifest — no span, no origin, no freshness
  reading. Getting this wrong caused two of the three P2s on #656.
- **`detail` is static.** A manifest is published once and rendered verbatim by
  three surfaces, none of which can substitute configuration. Where the right step
  depends on how an install is configured, name the requirement and point at the
  surface that computes the command.

`requires_operator_action` counts actions only, so a notice-only release stays
honestly marked safe to take unattended. Notices **carry over** between releases
(the post-release reset clears `actions` and leaves `notices`); removing one is a
deliberate edit.

### Proven end to end, in both directions

`0.2.0 -> 0.3.0` in a clean room, run with **0.2.0's own installer**, which has no
notices support at all:

```
==> Handing off to the refreshed installer.                    <- the re-exec
==> Preflight: nothing outstanding between 0.2.0 and 0.3.0.    <- nothing blocks
  This release advises:                                        <- the advisory
    0.3.0/backups-queue-consumer - Run a queue worker on the backups connection.
  Nothing here blocks the upgrade.
==> Pulling the release image.
```

The advisory could only appear because the hand-off replaced the installer before
the preflight ran. Post-upgrade: `fresh_install: false`, all endpoints 200, and
`notices: []` — the compose worker was running, so the check retired it instantly.

| state | result |
| --- | --- |
| worker running | silent |
| worker stopped, heartbeat cleared | advisory appears, **exit 0**, still serving |
| worker restarted | retires itself, no operator action |
| acknowledged while stopped | silenced |

The exit code is load-bearing: advice that fails a command costs automation
exactly what a refusal costs, which would defeat the point.

Stage confirmed the same independently — notice declared, `check: true`, nothing
reported, everything serving. Host-managed installs are the only class where it
can fire.

### A real bug the notice uncovered

`compose.yml` ran the backups worker with a hard-coded `--queue=backups` while the
app dispatches to `env('BACKUP_QUEUE', 'backups')`. Any Compose install that set
`BACKUP_QUEUE` had **every GUI backup stuck at *Running*, silently**, with the
check correctly reporting a worker that visibly existed.

**Declaring a requirement forced someone to check the platform actually met it,
and it did not.** That is an argument for the notices mechanism nobody
anticipated. Fixed twice: a host-side `${BACKUP_QUEUE:-backups}` is resolved by
Compose from the *invoking shell* ahead of `--env-file` (`install.sh:412`
documents this), so it diverged again; the correct form is `$${...}` so the
**container** shell expands it from the same env_file Laravel reads. Both broken
versions look right in the YAML — `docker compose config` is the only thing that
tells them apart, and the compose template test now renders with a poisoned shell
value to keep it that way.

### Process lessons worth more than the code

**Codex delivers findings as inline review comments — read `pulls/N/comments`.**
Watching `issues/N/comments` and reactions instead produced three false "clean"
reports and a merge past a **P1 I had introduced**: the floor refusal printed
`WAYFINDR_UPGRADE_FROM=<the floor>`, and since `declaredOrigin()` trusts that
value and the floor refuses only a definite *below*, an install genuinely too old
could follow the refusal's own advice and migrate on a retired path. Filter on
`original_commit_id`; `commit_id` is re-anchored to the tip for older comments.

**Thirteen findings this session. Ten were in operator-facing prose, two were
tests of mine that asserted nothing, one was the Compose bug. None were in a
predicate, a gate, or an evaluation rule.** In a subsystem whose only output is
instructions to humans, the prose *is* the feature —
`docs/self-hosting/release-manifest.md` already says *"this text IS the recovery
path"*. Review it as carefully as the logic behind it.

**Check tests by exit code, not by output.** Two of mine printed `FAIL` and exited
0 — one registered `expectsOutputToContain()` after `assertFailed()` (which
executes immediately), one appended checks after the script's final assertion.
After writing a test for a fix, mutate the fix and confirm the test *fails*.

**At a cut, ask what the changelog COVERS, not whether it is stale.** The
commit-since-last-touch check passed at this cut while `[Unreleased]` was missing
both floor fixes, because a later PR had touched the file without covering the
earlier ones.

### Next

- **Operate `v0.3.0`; prepare `0.4.0`.** Cut freely; the upgrade path is proven
  in both directions.
- **Next proof is disposable-VM evidence.** Nobody outside the owner has run an
  install or upgrade, so use `docs/self-hosting/disposable-vm-evidence.md` to
  make the owner's throwaway-VM runs repeatable and sanitized.
- The advisory response is available for any future requirement worth reporting
  without an outage. Extend `ActionDisposition`/`ActionAdvice`/`FloorAdvice`
  rather than re-deriving their rules — that coupling is what #647 was about.

## 13. Bare-metal readiness evidence completed — August 12, 2026

The `v0.3.2` public artifact matrix now has external-style evidence from
owner-operated disposable Hyper-V guests, not only GitHub-hosted runners. Two
independently created Ubuntu 24.04.4 clean guests and one separate upgrade guest
covered clean install, first-run setup, release identity, every Compose process,
migrations, zero failed jobs, the schedule, upgrade guard, synthetic support
loop, database-plus-attachment backup/restore, full service restart, and real VM
reboot/reverify. The upgrade guest proved public `v0.2.0 → v0.3.2` with a custom
`BACKUP_QUEUE`, advisory appearance/retirement, exact restore markers, and
post-reboot support. Reports were copied off each guest before destruction.

The matrix paid for itself by finding two Docker-only portability bugs. The
first guest reached the installer preflight only after host PHP was installed;
PR #707 moved that check into the application container. The independent repeat
started with no host PHP, then found `support-loop.sh` still required it for
parsing; PR #708 added a PHP adapter that streams host temp data to container
PHP. The final clean run used a fresh public `main` checkout after both merges,
empty Docker state, and no host PHP. Install and reboot reverify both exited `0`.

Recovery evidence is now explicit rather than implied. A forced release-
discovery outage made the upgrade installer refuse with exit `78` before schema
or container mutation; web container ID, image digest, migration-status digest,
`/up`, zero failed jobs, and the support loop all stayed unchanged on the old
release. The hosted recovery jobs separately cover a version-skew restore
warning and the current schema-compatible `0.3.2 → 0.3.1 → 0.3.2` image
rollback/retry. Neither result promises arbitrary downgrade safety.

The deployment fork and source matched at `b8be095` with green CI on both repos,
and Forge deployed that revision successfully. Authenticated operator evidence
showed PHP 8.4, ready `13 / 0 / 2` posture, SMTP, Redis queueing, Reverb, storage,
private S3-compatible attachments, and ClamAV. Forge showed the queue, backup
queue, Reverb, per-minute scheduler, and three-region HTTP 200 checks. The manual
scheduler proof note was stale and backup/restore proof note missing; do not
rewrite those as runtime failures or as current production restore proof.

### Next

- Do not cut `0.4.0` for evidence tooling and documentation alone. Keep
  `v0.3.2` as the latest public artifact until an operator-facing or reliability
  change merits a release.
- Operate the controlled Forge dogfood instance. Refresh the scheduler and
  backup/restore proof notes only when current operational evidence exists, and
  let real support conversations choose the next branch-sized product slice.
- Rerun the same disposable matrix for any later release candidate; the August
  12 results prove only the exact public artifacts and dated environments above.
