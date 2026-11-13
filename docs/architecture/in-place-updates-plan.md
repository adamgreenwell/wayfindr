# In-place updates: proposed design

Status: the original design proposal, kept as written. Its first delivery has
since been built: the opt-in host helper and operator update flow
([#1108](https://github.com/adamgreenwell/wayfindr/issues/1108)–[#1114](https://github.com/adamgreenwell/wayfindr/issues/1114))
shipped from 1.2.0. Managed application upgrades remain unqualified (0/100 managed
scenarios as of 1.6.0), so this page describes intent, not current operating
procedure — see the [host updater guide](../self-hosting/managed-updater.md) and
the [qualification matrix](../self-hosting/managed-update-qualification.md).
Date: October 8, 2026. Status updated: November 13, 2026.

Delivery is tracked by [epic #1107](https://github.com/adamgreenwell/wayfindr/issues/1107)
and the [feature map](../product/in-place-updates-feature-map.md).

## Recommended direction

Give installer-managed Wayfindr installations a platform-operator **Update**
button backed by an updater that lives outside the application it replaces.
Keep the existing terminal upgrade entry point; on enrolled installations, both
interfaces use the same upgrade engine. Existing terminal and external-platform
upgrade paths remain usable without enrolling a helper. Start with Linux VMs
running the official Docker Compose stack.

The experience is an in-place update of the installation: its URL, data,
configuration, keys, and identity stay put. The application containers are
replaced with a published image. Files inside a running image are never patched.
This is also how Coolify's one-line installation and dashboard upgrade work.
See its [installation guide](https://coolify.io/docs/start-with-self-hosted)
and [upgrade script](https://github.com/coollabsio/coolify/blob/main/scripts/upgrade.sh).

## What Wayfindr already has

- A one-line installer and `./wayfindr/install.sh --upgrade`, which resolve a
  stable release, refresh the stack, run preflight, pull its image, restart,
  migrate, and wait for HTTP availability
  ([installation guide](../self-hosting/install.md),
  [installer](../../scripts/self-host/install.sh)).
- Release identity, per-release manifests, minimum upgrade floors, release-span
  requirements, phase-aware actions, runtime profiles, and guards enforced
  before migration and before serving
  ([ADR 0012](../decisions/0012-platform-versioning.md),
  [ADR 0013](../decisions/0013-upgrade-preflight-and-release-requirements.md)).
- Release publication of `release-manifest.json` and
  `release-image-digest.txt`, with image/release verification
  ([release workflow](../../.github/workflows/release-image.yml)).
- Operator identity/readiness, backups, guarded restore, and erasure-ledger
  preservation ([operator boundary](../product/platform-operator-boundary.md),
  [backup guide](../self-hosting/backup-restore.md)).
- Host deployment scripts covering dependencies, built assets, release
  identity/manifests, migrations, caches, and queue/Reverb restart
  ([Forge deployment](../../deploy/forge/standard-deploy.sh)).

Build on these contracts. Do not create a parallel release comparator or a
weaker migration path for the dashboard.

Historical work already covers one-command installation
([#137](https://github.com/adamgreenwell/wayfindr/issues/137)), upgrade guards
([#647](https://github.com/adamgreenwell/wayfindr/issues/647)), upgrade evidence
([#666](https://github.com/adamgreenwell/wayfindr/issues/666)), and VM recovery
([#664](https://github.com/adamgreenwell/wayfindr/issues/664)). The all-state
issue/PR review on October 8 found no open updater epic overlapping this proposal.
Independent installation acceptance is recorded in
[#797](https://github.com/adamgreenwell/wayfindr/issues/797); independent human
operation of this proposed upgrade flow remains a separate acceptance step.

Two existing installer behaviors need tightening before GUI orchestration:

1. It refreshes the active Compose file before upgrade preflight has completed.
   Download into a staging directory and preserve the active configuration
   until the entire plan has passed.
2. The upgrade branch tolerates an image-pull failure, then checks HTTP serving
   rather than the requested release identity. An official managed update must
   abort a failed pull and must never call the old running version a successful
   update. Custom/local image behavior must remain a separate explicit path.

## Installation ownership determines the controls

| Installation | Update behavior | Operator screen |
| --- | --- | --- |
| Official one-line installer on a Linux VM | Host updater replaces the official application images and managed stack files. | Check for updates, review, update, progress, history. |
| Docker/Compose managed by the user | User pulls and recreates containers with their own deployment configuration. | Release information, required actions, matching commands; offer explicit enrollment if the stack is compatible. |
| Docker image built from a Git clone | User builds a new image and redeploys. | Source-build instructions; no official-image update button. |
| Git checkout running host PHP | User runs a documented deployment wrapper or pipeline. | Release guidance and host requirements; managed host adapter is a later slice. |
| Forge, Coolify, Kubernetes, or another deployment platform | The platform owns deployment. | Release guidance and a link/instructions for that platform. |
| Hosted Wayfindr | The hosting control plane owns updates for the installation. | Hosting-managed status; customer roles cannot update the server. |

Docker is an artifact format, not evidence of who owns its lifecycle. Likewise,
the presence of `.git` is insufficient to enable automatic checkout changes.
Keep the existing `image`/`host` requirement profiles; add a separate, explicit
deployment-ownership capability reported by an enrolled updater. Without that
capability, show guidance.

For a host PHP checkout, `git pull` alone is insufficient. The deployment also
needs target runtime checks, Composer's locked dependencies, asset builds where
applicable, manifest/identity generation, migration, caches, and process
refresh. Prefer staging a clean tagged release in a new directory, then
activation, so a build failure does not mix new source with old dependencies.
Refuse dirty/custom checkouts rather than resetting the user's work. Laravel
also requires [long-running services to restart after deployment](https://laravel.com/framework/docs/deployment#reloading-services).

## Execution and trust boundary

For the first supported environment, install a small Linux host updater managed
by systemd. This is an operational helper for the Compose installation, not a
new customer-facing service. Its execution survives web, worker, and Reverb
replacement, browser closure, and loss of the initiating HTTP request.

The application submits a fixed operation request over a restricted local Unix
socket. The helper owns the stack path, Compose project, release source, and
allowed commands. Requests contain a release selection and operation ID, never
shell commands, paths, arbitrary image references, or user-provided Compose.
The helper independently resolves and validates the official release. Keep its
program/configuration and active release files unwritable by the web process.

Only the helper gets Docker-daemon access. Do not mount the Docker socket into
web or worker containers. Docker access grants broad host control; a fixed
command interface reduces what the application can request, but does not make
the helper itself unprivileged. Keep request authentication and per-instance
socket permissions separate from `APP_KEY`. See
[Docker's daemon security guidance](https://docs.docker.com/engine/security/#docker-daemon-attack-surface).

Only authenticated platform operators may start an update or request safe
cancellation, with CSRF protection and recent reauthentication. Cancellation is
available only before Apply/schema mutation and must resume and verify the old
release. Once mutation begins, offer status/recovery instead of Cancel. Tenant
owners/admins do not gain this
ability from account RBAC. Record the actor, old/target identities, approved
plan, required-action evidence, phase transitions, and outcome without customer
content or secrets. The host journal is authoritative; mirror events into the
application audit trail when it is available.

Persist an installation ID, ownership mode, helper version/protocol, managed
path/project, and current operation outside the application database. Take a
host-level exclusive lock, and coordinate updates with existing backup/restore
serialization. No upgrade may overlap a restore, backup, another upgrade, or a
managed deployment. A fixed-TTL application cache lock is insufficient for a
long update; the host lock and durable operation record must also apply.

## Release selection and preflight

Default to manual updates on the stable channel. Show current version, target
version, release notes, required actions, migration summary, expected service
interruption, backup coverage, and recovery limits before the operator starts.
Once reviewed, pin that exact target; a newer release appearing mid-run must
not change it.

Resolve from the canonical published release and verify tag, commit, manifest,
and published image digest consistently. Pin the image by digest, not a floating
`latest` tag. Preserve the previous digest and configuration. Download failure,
rate limiting, malformed metadata, missing assets, or unsupported architecture
are explicit failures, never "up to date" or reasons to guess another target.
This uses the current trusted HTTPS release source; a digest verifies content
identity and is not a separate publisher signature.

Reuse release-span evaluation across every skipped version, minimum-version
floors, and the existing distinction between machine verification and operator
attestation. Complete `before-pull` work while the old release is live;
`after-pull` work precedes migration; `after-start` work gates reopening service.
For the MVP, requirements needing manual infrastructure changes or environment
attestations stop with their existing exact instructions, then recheck. Do not
silently acknowledge them from the Update button.

Preserve ADR 0012's promise that minor/patch releases require only pulling and
restarting, with migrations handled automatically. Automatic protective backups
are part of the enrolled managed-updater workflow, not a new release action.
Do not make manual backup setup, offsite-copy proof, or an individual restore
drill prerequisites for an ordinary terminal/external update. Optional managed
policies can require stronger backup evidence, explicitly selected at enrollment;
the screen distinguishes those policies from release-required operator work.

Preflight also checks disk space for the new image plus backup and retained
previous image, Docker/Compose availability, configured storage access, helper
compatibility, valid running identity, and absence of conflicting operations.
Require a healthy serving baseline; an existing maintenance/recovery hold sends
the operator to recovery instead. Record whether this operation entered
maintenance, and only remove a hold it owns. A failed update must never lift a
pre-existing hold.
Unknown legacy identity uses the existing conservative guard behavior; it must
not be mistaken for a fresh installation.

## One operation, six visible stages

1. **Prepare.** Lock the installation, verify its current identity, validate
   the selected release, stage configuration, and satisfy prerequisites.
2. **Download.** Pull and verify the target image while the old installation
   continues serving. A failure leaves the running stack intact.
3. **Protect data.** Put the old application in maintenance, reject new writes,
   stop the scheduler and other sources of new work, stop fresh queue intake,
   and then drain in-flight requests/jobs. Run a synchronous backup from the
   old release. Verify its manifest/checksum
   and declared coverage. Do not enqueue it onto a worker about to be stopped.
4. **Apply.** Activate the staged managed configuration and assign migration
   ownership to one target-image invocation using the existing guarded migration
   path. Suppress automatic web-entrypoint migration for this managed start;
   unchanged web configuration otherwise runs migration again. Preserve the
   normal entrypoint behavior for ordinary external installs. Replace the
   application services with the pinned image, retaining durable data volumes
   and keys. Do not upgrade Postgres or Redis major versions as part of an
   ordinary application update.
5. **Restart.** Restart web, normal queue, backup queue, scheduler, and Reverb
   against the same target. Keep maintenance active while checking them.
6. **Verify.** Confirm target version/commit and actual service-image provenance,
   migration completion, serving guards, database/Redis connectivity,
   fresh worker/scheduler evidence, and a realtime probe. Reopen traffic and
   confirm HTTP through the configured origin before recording success.

For multi-architecture releases, verify the chain from the published image-index
digest to the host platform's manifest and its local image configuration. A
container's `.Image` configuration digest is not the index digest in
`release-image-digest.txt`; compare their verified linkage. All application
services must resolve to the same intended platform image.

Maintenance must cover HTTP/API/webhooks and all write-producing jobs, not just
the operator's browser. Draining has a bounded timeout: if work cannot settle,
stop before migration and return safely to the old release. Test maintenance
visibility across containers; do not assume a marker exists everywhere.
Existing chats/WebSockets can disconnect briefly. Preserve persisted messages
and show reconnecting; do not promise uninterrupted chat or zero downtime.

Use the backup runner and its locks, but first add an upgrade-backup mode that
skips its automatic local/remote retention pruning. Coordinate ownership or
handoff of the backup/restore lock; do not hold that cache lock and call a
runner that tries to acquire it again. Every backup/restore entry point must
honor the active update operation while its own protective backup remains
permitted. Account for the runner's actual coverage:
local attachment binaries are in the archive; remote attachment disks depend
on the retained bucket. Preserve `APP_KEY` and `APP_PREVIOUS_KEYS` separately
with restricted access, along with previous environment/configuration. Protect
the erasure ledger outside restorable application backups. A local backup
protects this upgrade's recovery, not loss of the whole VM; show offsite-copy
status accurately. Restore qualification requires a disposable restore drill,
not merely a backup command returning zero.

## Progress screen and reconnection

Use the attached Coolify screenshot as the interaction reference: a focused
dialog over the operator page, source → target version, a stage strip, elapsed
time, current message, and calm reconnect messaging. Wayfindr's six stages make
the data-protection and verification work visible. Include an optional details
drawer with redacted logs and a link to durable operation history.

Suggested restart copy: **"Wayfindr is restarting. A brief disconnect is
expected; this page will reconnect automatically."** Closing the dialog/browser
does not cancel the update. Returning to `/operator` resumes the active run.

Use bounded polling with backoff; progress must not depend on Reverb being up.
Keep the last known phase visible during application downtime and label it as
such. In the MVP there need not be a separately exposed public helper endpoint:
the already-loaded browser shows reconnection and resumes authoritative status
when the app returns. If it never returns, the host CLI remains usable. Do not
pretend fresh progress is available while the application cannot answer.

Distinguish reconnecting, failed, rolled back, and recovery required. A timeout
means status is uncertain, not that the operation necessarily failed. A healthy
old version, a login redirect, or a single successful `/up` response is not
upgrade success. After return, reconcile the durable operation with the actual
running identity. Announce stage changes accessibly, respect reduced motion,
manage dialog focus, and use the existing operator language catalogues. Do not
display fabricated percentages or a guaranteed completion time.

Coolify provides the useful host-status/reconnect pattern in its
[progress view](https://github.com/coollabsio/coolify/blob/main/resources/views/livewire/upgrade.blade.php)
and [status reader](https://github.com/coollabsio/coolify/blob/main/app/Services/CoolifyUpgradeStatus.php).
Its [update guide](https://coolify.io/docs/core/instance-management/update)
still asks for an instance backup, and its
[downgrade guide](https://coolify.io/docs/core/instance-management/downgrade)
warns about database incompatibility. The interface should not imply stronger
recovery guarantees than the implementation proves.

## Durable state and recovery

Store each run in a host-owned journal outside the database and replaceable
containers. Atomically publish a redacted status snapshot. Include operation
ID, actor, source/target version/commit/digest, staged/previous configuration
references, backup ID/coverage, phase/checkpoints, migration state, timestamps,
helper heartbeat, and a bounded error/recovery code. Do not store keys or raw
environment values in status. Keep the protocol versioned so old/new app and
helper versions can reconnect across releases.

Terminal states are `succeeded`, `failed`, `rolled_back`, and
`recovery_required`; unmet preconditions may end as `blocked` before mutation.
An uncertain interruption stays nonterminal until reconciled. Duplicate clicks
or retried HTTP submissions return the same operation. After helper/VM restart,
inspect the journal, actual containers, and migration records before continuing.
Do not blindly rerun interrupted migration or restore steps, or discard a run
just because its heartbeat is old. Update the helper itself separately, with
protocol compatibility; an active update must not replace its own executor.

| Failure point | Required behavior |
| --- | --- |
| Release lookup, preflight, pull, or staging fails | Preserve the current serving release and report the exact failure. |
| Drain or backup fails before schema mutation | Resume the old services, remove maintenance after checking them, and report failure. |
| New artifact guard refuses before migration | Restore previous application configuration/digest, verify the old release, and show the required action. |
| Migration starts, partially fails, or has an unknown outcome | Keep maintenance; require reconciliation/recovery. No blind image rollback or `migrate:rollback`. |
| New release fails after migrations | Allow automatic application rollback only if compatibility with the previous schema/old code is explicitly proven. Otherwise keep maintenance and provide recovery instructions. |
| Helper/app/browser loses contact | Keep the durable operation, prevent a second run, and reconcile actual state. |

The first version should conservatively prohibit automatic post-migration
rollback. A later release-manifest extension can declare and test compatibility
with the previous application, accounting for all migrations across a skipped
release span. A rollback must not claim success until the previous release has
been verified. Database restore is a separate explicit recovery action: it can
discard later writes and must preserve/reapply erasures. Never automatically
restore a database just to make the update dialog turn green.

Keep a terminal recovery command and redacted log path available even when the
web app cannot start. Design exact command names during the CLI slice; this
proposal does not present unimplemented commands as executable instructions.
Retain the previous image/configuration until the run succeeds and the chosen
retention period passes. Do not prune volumes or backups during an update.

## Delivery slices and acceptance

1. **Harden the existing CLI.** Stage changes, abort official-image pull
   failure, pin/verify digest and target identity, coordinate backup/restore,
   and define durable states. Keep `install.sh --upgrade` as the familiar entry.
2. **Add the host executor.** Implement the systemd helper, restricted local
   protocol, locking, journal, checkpoint reconciliation, and CLI status/logs.
   Existing installations need one explicit terminal enrollment; do not assume
   an old app already has host-control permission. Fresh supported installs can
   provision it through the installer. Preserve existing CLI/external update
   paths without enrollment or a new systemd runtime dependency.
3. **Add operator update UI.** Capability-aware release card, review screen,
   start action, progress/reconnect dialog, outcome and history. Unsupported
   install styles receive the correct guidance.
4. **Qualify recovery.** Run disposable-VM upgrades with persisted conversations,
   attachments, encrypted settings, queued work, and erasure ledger; exercise
   failures and recovery, then have a non-author operate the documented flow.
5. **Broaden deliberately.** Add a managed host-PHP adapter and platform hooks,
   then optional update scheduling/automation after the manual path is proven.

Acceptance must cover stable patch/minor updates, major required actions,
skipped versions/floors, legacy unknown identity, missing/malformed/rate-limited
release data, custom images and dirty Git work, absent/incompatible helper,
pull/disk/backup failures, concurrent backup/restore/deploy, long-running jobs,
lost browser/network, helper/VM restart, pre/post-migration failure, healthy old
image versus intended target, stale workers/scheduler, broken Reverb, and public
origin reachability. Check that target application services share the intended
platform image and its linkage to the published index digest,
and that data, keys, local/remote attachments, erasures, and instance identity
survive, including Caddy certificates and reverse-proxy configuration. Test
operator authorization, CSRF, local-interface command injection,
log redaction, keyboard behavior, translations, and protocol compatibility.

The first safe implementation slice is the CLI hardening. The Update button
comes after that engine can tell success, failure, and recovery apart reliably.
