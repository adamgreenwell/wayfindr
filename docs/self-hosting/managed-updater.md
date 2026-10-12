# Maintaining the independent host updater

The host helper is an explicit opt-in for an official installer-managed Linux
VM. It runs independently of Wayfindr's web and queue processes, so its durable
operation record remains available when the application is unavailable.

The helper supports authenticated preparation, durable status, and an
explicit root-only protection rehearsal: hold new intake, drain the existing
writers, take and independently verify a protective backup, retain private
recovery material, then check and resume the same services. The `0.3` helper also
supports root-only `apply` and `recover-apply` for a prepared release, with
independent artifact verification, a fresh continuous protective hold, explicit
migrations, and verified serving evidence. The `0.4` helper adds bounded
operator `start`, `history`, and `cancel` requests, with exact approved-plan,
request, and numeric actor identities retained in its journal. Recovery remains
an explicit root-only terminal action.

Public `v1.3.0` contains helper `0.5.0`, which binds Docker classic and
containerd image identities separately. Public `v1.4.0` contains helper `0.6.0`,
which keeps protocol `1`, corrects Docker process listings and supports a separate root-only
replacement of either exact published `0.4.0` or `0.5.0` bundle. Pulling an
application image does not install or replace host helper code.

This guide describes the published 1.4.0 helper distribution, independently
verified against its exact public commit and Release/image contracts. The
[current publication receipt](evidence/managed-update/2026-10-10-v1.4.0-publication.json)
records those identities. Use a reviewed immutable source distribution for each
administrator command. The dated
[ordinary candidate observations](helper-0.6-candidate.md#bounded-native-candidate-observations-2026-10-10)
and separate [active-operation bridge observation](helper-active-recovery-bridge.md#bounded-native-preservation-observation-2026-10-10)
retain their original source identities and limits.

Public `v1.4.1` contains helper `0.7.0`, including the supported Compose
replacement-creation command. The 0.6 procedure and 0.7 candidate section below
retain their original distribution boundaries. The separate
[public v1.4.2/helper 0.8 contract](helper-0.8-candidate.md) adds the runtime
image-default correction and exact public 0.7 idle-source compatibility. Its
subsequent application upgrade failed; the
[qualification record](managed-update-qualification.md) preserves that result.
The next [v1.4.3/helper 0.9 source candidate](helper-0.9-candidate.md) corrects
Compose JSON dollar escaping and adds exact public 0.8 idle-source compatibility.
It remains unpublished. Pulling an application image does not replace the host
helper or repair an interrupted application operation.

Optional managed updates remain under disposable-VM qualification and are not
qualified for production use. Publishing compatible application and helper
contracts does not by itself complete that qualification.

The [U8 qualification matrix](managed-update-qualification.md) distinguishes
synthetic harness checks from published-artifact, reboot and separate-VM restore
evidence. The initial recorded public-artifact gate for `v1.1.0` → `v1.1.1`
is blocked for that span: those older images lack the application/helper
contracts needed for enrollment. Target-bound managed execution requires a
compatible published source release and a newer compatible published target.
Actual VM runs provide separate qualification evidence; publication alone cannot
establish it.

## Reviewing and following an update

Open **Operator → Updates** (`/operator/updates/console`). The page checks the
latest published stable release without starting a host operation. It shows
the current and proposed releases, required actions, prerequisites, backup
policy, interruption and recovery limits. Docker, source, host-PHP, deployment
platform and hosting-managed installations receive their own update guidance.

For an eligible enrolled installation, confirm your password and second factor
when enabled, then choose **Review release**. Preparation records one exact
release and plan without applying it. Read the host-bound review, select the
confirmation checkbox, then choose **Start update**. **Recheck** prepares a new
immutable review; it does not change an earlier operation.

The dialog follows **Prepare → Download → Protect data → Apply → Restart →
Verify**, with elapsed time, the last confirmed host state and redacted details.
It does not invent percentages or a completion time. During the expected HTTP
503 hold it keeps the last confirmed stage and explains reconnection. Status
reads use one bounded request at a time, a ten-second deadline and backoff up
to thirty seconds, without depending on Reverb.

Closing the dialog or refreshing does not cancel an update. The browser retains
only an actor/installation-bound bookmark, release/plan identifiers, last-known
stage and pending request identity; it stores no password, second-factor code
or execution authority. Reopening or returning to the operator console reads
the existing host operation. If storage is unavailable, the host's active run
is still discoverable. An uncertain write is reconciled by reads; **Try again**
is an explicit retry of the same request, never an automatic new Start.

Success requires the host's requested-release, service, origin, migration and
configuration verification, not merely a returning HTTP response. Safe failure,
cancellation, recovery required and uncertain status remain distinct. This
executor does not perform automatic post-migration rollback; a returning old
release is not labelled as a completed database rollback.

Cancellation is available only before migration intent. Once the HTTP hold
blocks the application, terminal inspection/cancellation remains the independent
path described below. An expired session stops browser polling and offers a
sign-in link; expired action confirmation can be renewed without losing the
operation. Review/progress copy is available in English, German and Italian,
with native-dialog keyboard/focus behavior and reduced-motion styling.

## Operator action contract

JSON endpoints under `/operator/updates` support the review/progress page and
the read-only latest-stable candidate check. Managed execution remains subject
to the publication and qualification boundaries above.

Every endpoint requires a current, active platform operator, the existing
authenticated-session and MFA policy checks, and the normal web CSRF protection
for POSTs. Tenant owner/admin/agent permissions cannot authorize an instance
update. Mutations require a password proof from the previous five minutes,
plus a fresh TOTP or recovery code when MFA is enrolled. The proof belongs to
the current operator and password/MFA version; credential or MFA changes
invalidate it. Accounts without a usable local password need a local platform
operator for this first contract.

| Method | Path after `/operator/updates` | Input and behavior |
| --- | --- | --- |
| GET | `/` | Observe ownership, authenticated helper capabilities, execution eligibility, and manual guidance. |
| GET | `/console` | Render the operator review/progress page with local identity and fixed endpoint URLs. |
| GET | `/candidate` | Read and assess latest-stable public declarations; do not prepare, start, or change host state. |
| POST | `/reauthenticate` | `current_password`, and `one_time_code` when MFA is enrolled; return a short-lived session proof. |
| POST | `/plan`, `/recheck` | Canonical stable `release_tag` and UUID `request_id`; prepare asynchronously. Recheck creates a fresh operation without changing earlier history. |
| GET | `/{operation}/review` | Build the full public release review and local guard evidence; its fingerprint must match the prepared host plan. |
| POST | `/{operation}/start` | Lowercase 64-hex `plan_id` and UUID `request_id`; the server supplies the operator ID and rechecks the reviewed plan before host admission. |
| POST | `/{operation}/cancel` | The same plan/request binding; request cancellation only while the host can still prove no schema intent. |
| GET | `/status` | Optional UUID `operation_id`; reconcile the authoritative host snapshot. |
| GET | `/history` | Bounded `cursor`/`limit` operation page with journal revision. Restart pagination if that revision changes. |
| GET | `/{operation}/events` | Bounded `cursor`/`limit` redacted host events. |

Unknown executable inputs, paths, image selectors, actor claims, and extra
fields are refused. POSTs accept the normal CSRF `_token`, never an authority
claim. Requests use exact stable tags rather than `latest`. Responses and
errors are JSON with `Cache-Control: no-store`; `/console` uses normal HTML and
login redirects with the same no-store policy. An unavailable or incompatible
helper and explicit external/hosting ownership cannot enable managed execution.
Old preparation/status helper versions remain observable without gaining start
authority. New enrollment probes the application's static protocol contract
before writing host credentials or services. The staged target must satisfy
that same fixed PHP-only protocol probe before fencing or draining the source,
and again before migration admission, so an older
application cannot complete an update and silently lose its operator controls.

An incompatible or unsettled early target probe records `recovery_required`
with no owned maintenance hold and no schema intent. The helper leaves the
source services and configuration untouched, retains operation ownership for
explicit root recovery, and makes no claim that source serving was independently
verified. Inspect the recorded hold and services before acting; this early
manual-attention state does not mean the application was placed in maintenance.
Later failures retain any actual owned hold until explicit recovery verifies
the appropriate serving state.

Start and cancellation admission receipts bind operation, reviewed plan,
request UUID, numeric actor ID, timestamp, and host event revision. Identical
retries reconcile the same admission; changing the target, actor, operation, or
action under an already used request UUID is a conflict. The helper commits
admission before scheduling execution. A duplicate start does not rerun a plan
against an already replaced source.

Cancellation is a cooperative request, not an immediate success. Download and
protection stop at safe seams; migration intent and cancellation compete under
the same journal mutex. Once migration intent wins, cancellation is refused and
only explicit root recovery is available. A `cancelled` outcome requires the
old services, release, configured origin, and released hold to be verified.
Interruption or unknown settlement retains `recovery_required` instead of
claiming cancellation completed.

The global managed-update hold still returns HTTP 503 before session or route
writers, including these endpoints. During that window the browser retains its
last observed state and reconnects after the app returns. Root can inspect the
helper and request pre-migration cancellation from the terminal; a browser
disconnect never cancels an update.

Inspect the root-owned history even while the app is unavailable:

```bash
sudo wayfindr-updater history --cursor 0 --limit 20 --json
```

For an active operation before migration intent, root can request cancellation
using its retained plan and a fresh request UUID. Supply the local operator's
numeric ID for attribution:

```bash
sudo wayfindr-updater cancel --operation <operation-uuid> --plan-id <approved-plan-id> \
  --request-id <fresh-request-uuid> --actor-id <operator-id>
sudo wayfindr-updater status --operation <operation-uuid> --json
```

Acceptance only means the request was recorded. Observe `cancelled` and the
verified source evidence separately; `recovery_required` needs the existing
explicit recovery workflow.

The host journal is authoritative even if application audit records disappear.
When available, status/history reads mirror validated host events into global
`audit_events` with a unique installation/operation/revision key. The mirror
records only numeric actors, release identities, approved plan, redacted
evidence, phases and outcome. It includes no passwords, keys, operator names or
customer support content, and no tenant account/site ownership. Failed mirror
writes are reported separately and never repeat a host admission. Reads after
the web process returns reconcile from the same retained host operation.

Existing terminal updates and Docker, source, host PHP, and deployment-platform
installations acquire no systemd or helper requirement. The base Compose file
has no helper mounts. A Docker container never receives the Docker daemon socket.

## Supported enrollment

The initial helper supports one installation per Linux systemd host, with
Python 3.11 or newer at `/usr/bin/python3`, Docker Engine at `/usr/bin/docker`,
systemd-tmpfiles at `/usr/bin/systemd-tmpfiles` with its normal boot setup, and
Docker Compose installed as a system CLI plugin. User-directory Docker plugins
are not loaded
from the helper's isolated Docker configuration.
The host and image architecture must agree and be `amd64` or `arm64`.
Rootless Docker, user namespace remapping, custom Compose files, floating images,
custom images, and prereleases are refused by enrollment.

Enrollment requires an official published stable application image containing
`wayfindr:update-plan`,
`wayfindr:upgrade-window`, `wayfindr:protective-backup`, and
`wayfindr:managed-apply`, plus the compatible
`wayfindr:updater-status --protocol-contract` probe. It also requires the
matching reviewed source distribution described below. Enrollment verifies
these fixed commands and the protocol before installing credentials or the
service. Fetching the helper cannot add them to an older application image or
make that image eligible.

Root must deliberately adopt and review the installation first. The directory,
`.env`, `compose.yml`, `install.sh`, and every ancestor must be root-owned, must not be
symlinks, and must not be writable by a group or another user. A typical managed
location is `/opt/wayfindr`. Home directories and helper-owned directories cannot
be used as installation paths. Enrollment does not change ownership or copy
your application files into another location.
New managed paths accept ASCII letters, numbers, slashes, dots, underscores and
hyphens. Whitespace, control characters, Unicode, quoting, expansion syntax and
the root directory are refused before enrollment writes.

Use a reviewed root-owned distribution of the installed official source
release's helper files, with the same official
`docker/self-hosting/compose.yml` and guarded `scripts/self-host/install.sh`
bytes as the installation. The script refuses a customized or different
Compose file or an older unguarded terminal controller. It never silently
replaces `install.sh` to make an installation eligible. The distribution's
paths and ancestors have the same ownership and write restrictions; a reviewed
distribution staged under `/root` is suitable. These checks protect the inputs
used by a privileged host process. No automatic download or helper self-update
is performed.

The enrollment script’s `status` command reports static host identity and
credentials. It does not establish overlay activation, live helper connectivity
or eligibility for a particular update. Use authenticated application status
and the exact target plan for those checks.

Enrollment reads Docker metadata through the local Engine socket with an empty
root-owned Docker configuration. It checks the official project and all five
running application services, their identical image identity, and their direct
host process mapping to UID:GID `1000:1000`. Docker contexts and credentials from
the invoking environment are not inherited.

## Explicit enrollment

From the reviewed distribution, after preparing a compatible root-owned
installation:

```bash
sudo /usr/bin/python3 scripts/self-host/enroll-updater.py enroll --install-dir /opt/wayfindr
```

Enrollment creates host-owned identity, a random credential independent of
`APP_KEY`, helper code, a systemd unit, a fixed tmpfiles rule, an installation
ownership marker, and a `compose.updater.yml` overlay. It prepares and verifies
the socket directory, enables and starts the helper, then verifies its
authenticated socket and durable startup generation. It does not recreate an
application container or alter `.env`, the base `compose.yml`, or `install.sh`.

Review the generated overlay, then activate its web-only mounts explicitly:

```bash
cd /opt/wayfindr
sudo docker --host unix:///var/run/docker.sock --config /etc/wayfindr-updater/docker \
  compose --env-file .env -f compose.yml -f compose.updater.yml up -d web
```

This explicit step recreates the web container. Choose the time you activate it.
The overlay mounts the helper's socket directory and credential file read-only.
It gives web the installation identity and fixed socket and credential paths;
workers receive no helper mount. Mounting the socket directory keeps the
connection path valid when the helper recreates its socket after a restart.
The systemd unit preserves that directory across stop/start as well, so an
existing container bind does not point at a removed directory. Host reboot
resets `/run`. The reviewed tmpfiles rule creates the empty root-owned socket
directory before Docker restores container binds during normal systemd boot;
the helper starts after Docker and creates its authenticated socket inside that
same directory. Enrollment verifies directory ownership and permissions before
starting the helper. Existing nodes at `/run/wayfindr-updater` or
`/etc/tmpfiles.d/wayfindr-updater.conf` refuse first enrollment; interrupted
setup retains its evidence for explicit recovery. Custom or conflicting host
tmpfiles rules and altered Docker boot dependencies remain outside this bounded
proof.
Actual enrolled application recovery after guest reboot still requires its own
published-artifact VM qualification.
The generated unit retains `ProtectSystem=strict` and allows writes to the fixed
helper configuration directory and the reviewed installation directory for
atomic configuration promotion. State and runtime directories remain confined
to their existing systemd-managed paths. This uses systemd's
[file access settings](https://github.com/systemd/systemd/blob/main/man/systemd.exec.xml).

## Host-owned paths

| Path | Purpose and access |
| --- | --- |
| `/etc/wayfindr-updater/installation.json` | Root-only identity, fixed installation path, official image, and reviewed file hashes |
| `/etc/wayfindr-updater/credential.json` | Random installation credential; root-owned, readable only by root and application GID 1000 |
| `/etc/wayfindr-updater/docker/` | Empty root-owned Docker configuration; no invoking-user contexts or credentials |
| `/etc/tmpfiles.d/wayfindr-updater.conf` | Fixed rule file, root:root, mode 0644; creates the empty socket directory root:1000, mode 0750 before Docker's boot restore |
| `/usr/local/lib/wayfindr-updater/updater.py` | Root-owned helper code |
| `/usr/local/lib/wayfindr-updater/update_protection.py` | Fixed protection and old-service recovery coordinator |
| `/usr/local/lib/wayfindr-updater/update_apply.py` | Fixed release application and interrupted-apply recovery coordinator |
| `/usr/local/lib/wayfindr-updater/update_artifacts.py` | Independent published release and platform-specific OCI verifier |
| `/usr/local/lib/wayfindr-updater/protection_archive.py` | Independent streaming archive verifier; never extracts files |
| `/usr/local/bin/wayfindr-updater` | Root-owned host CLI wrapper |
| `/var/lib/wayfindr-updater/` | Root-only lock, durable journal, and operation state |
| `/var/lib/wayfindr-updater/protection/<operation-uuid>/` | Root-only archive, receipt, recovery context, configuration and separate erasure-ledger custody |
| `/var/lib/wayfindr-updater/apply/<operation-uuid>/` | Root-only apply intent, source/target state, and immutable migration/runtime receipts |
| `/run/wayfindr-updater/updater.sock` | Local authenticated protocol; root-owned socket accessible to application GID 1000 |
| `<installation>/.updater-enrolled` | Root-only ownership marker preventing an unsynchronized terminal upgrade |
| `<installation>/compose.updater.yml` | Reviewed web-only helper mounts |

The helper accepts a fixed request vocabulary. Request input cannot choose an
installation path, command, Compose file, Docker context, or arbitrary image.
The host installation record supplies those facts. Status and logs expose
operation metadata and classified errors, rather than environment files,
credentials, command output, or customer content.

The `plan_reported` checkpoint records selected facts from the application's
read-only planner. Protection separately checks the exact running source image,
container identities, enrolled file hashes, and application fence. This is not
permission to apply the target release. A root-only apply request reruns the
complete planner on the fixed source image and requires the same plan identity,
then independently verifies the published target provenance and platform image
before replacing an image or changing the schema.

Before fencing, the helper compares the original web environment with the
reviewed Compose environment and captured image defaults, and checks that all
five application containers share the same local storage volume. The backup
oneoff repeats the environment check before booting PHP and binds its effective
database, attachment, backup, erasure, and key configuration to the original
release before invoking the backup runner. An edited `.env` that has not reached
the original containers therefore refuses protection instead of capturing a
different database or storage location. Environment values remain private.

Ordinary backups, restores and managed hold transitions share a lifetime file
lease under `storage/framework`. The empty lock uses that directory's group so
authorized terminal and application users can coordinate without changing an
existing lock's owner or permissions. Existing locks are opened read-only and
remain the same inode; local exclusive `flock` semantics are required. Do not
remove or replace the lock while work is active. The private managed marker
continues to deny admission independently of the live lease.

## Protection rehearsal

Use the operation UUID from a completed preparation that reached
`plan_reported` with `execution_not_available`. Root can request protection
through the running authenticated helper:

```bash
sudo wayfindr-updater protect --operation <operation-uuid>
sudo wayfindr-updater status --operation <operation-uuid>
sudo wayfindr-updater logs --operation <operation-uuid>
```

The first command accepts the operation and returns its durable status. Work
continues independently of that CLI or browser connection. Status and classified
events show the subsequent fence, drain, backup, custody, and service recovery.
Repeating `protect` for the same operation never starts a second snapshot.
This is an intentional service interruption; it is not an update application.

Starting protection requires no ordinary maintenance hold, all five original
application services running the same verified source image, healthy Postgres
and Redis, and the reviewed activated updater overlay. A pre-existing operator
maintenance hold refuses protection before the service interruption. Protection
never removes or overwrites ordinary maintenance files or their bypass options.

The application has a separate operation-owned marker in shared framework
storage. Its HTTP gate has no bypass cookie or excluded route: widget writes,
inbound mail, integration webhooks, API writes, broadcast authorization, and
health requests receive a generic 503. Laravel queue workers and scheduled
tasks also observe the hold. Reverb's separate transport is included in the
host drain. `artisan up` cannot remove the managed hold. There is no automatic
expiry.

The host freezes the original container identities, records drain intent, and
requests a graceful stop with a bounded wait. A timeout never escalates to a
forced kill or treats an unfinished request/job as drained. Docker can still
have a pending graceful stop after the CLI wait ends; that uncertain window
retains the hold and requires checked recovery. Only verified stopped original
writers permit the backup to begin.

An unfinished Docker exec or fence command also blocks further fence changes
and release. The helper checks outstanding exec identities and private process
state, plus named one-shot container state; a created, paused, or restarting
container is not terminal. A CLI timeout cannot authorize a second command that
races a delayed first command. Once the command has settled, explicit recovery
can recheck ownership and the original serving state.

The protective backup runs through a PHP-only one-shot container pinned to the
old source image, with no dependencies started and no image pull. Its entrypoint
does not run migrations. During recovery a PHP-only old-image fence is verified
before any original container restarts. The web entrypoint suppresses automatic
migrations while **any** managed marker exists, including corrupt marker state.
Original image/container identities, service readiness, and the held HTTP
response are checked before releasing this operation's hold. Serving health
must then be observed before reporting recovery complete.

Before invoking the backup runner, the PHP-only container waits up to 120 seconds
for other PostgreSQL client sessions in the application database to leave active
or open-transaction states. This covers a database query still finishing after
its application process stopped. A busy, unreadable, or unsupported database
refuses the backup; it does not authorize schema work. The session fields come
from PostgreSQL's [activity statistics](https://www.postgresql.org/docs/17/monitoring-stats.html#MONITORING-PG-STAT-ACTIVITY-VIEW).

A successful rehearsal has `operation.protection.phase = verified`,
`custody_verified = true`, `services_recovered = true`, and `hold_owned = false`.
The overall rehearsal operation ends as `blocked` with `protection_verified`.
That rehearsed operation cannot subsequently be applied: writes resumed after
its backup, so applying a release requires a fresh prepared operation and a new
backup whose hold remains continuous through the upgrade.

## Root-only release application

Use a fresh operation UUID from completed preparation that reached
`plan_reported` with `execution_not_available` and has no previous protection or
apply evidence. Request application through the running helper:

```bash
sudo wayfindr-updater apply --operation <operation-uuid>
sudo wayfindr-updater status --operation <operation-uuid>
sudo wayfindr-updater logs --operation <operation-uuid>
```

The request contains only an operation UUID. It cannot supply a target image,
release tag, filesystem path, backup path, shell command or force option. The
helper durably claims the prepared operation before dispatching its worker.
Disconnecting the CLI does not cancel it, and repeating `apply` does not start a
second execution. Application UID 1000 cannot invoke either mutating apply RPC,
even with the installation credential.

The helper independently verifies the official release metadata and complete
migration history, resolves the published OCI index to the host's platform
manifest and image configuration digest, and checks the downloaded image's
identity. Those distinct digests are retained in public apply evidence. Download
and artifact verification precede the service interruption.

This first executor supports application-image changes with byte-identical
published base Compose configuration. It preserves `.env`, `compose.yml`,
`install.sh`, storage and certificate volumes. A changed base Compose file or
changed effective database/storage/key binding refuses before migration;
infrastructure and installer transitions need a separately reviewed path.
Artifact checks verify published hashes and image identity; they do not claim
independent signing-key provenance.

It then repeats source/configuration binding and captures a fresh protective
backup with all application writers stopped. The protective record reaches
`captured`: archive custody is verified, this operation still owns the hold, and
the old services have not resumed. A previous rehearsal or backup is not reused.

Before dispatching explicit migrations on the pinned target, the journal
commits `migration_intent`, `mutation_started = true`, and
`apply.migration_started = true`. These flags never reset after an interrupted
command. A failed command or unchanged migration table alone cannot prove that
DDL did not run. Migrations and target services run under the same operation's
hold; normal startup cannot implicitly run migrations through the entrypoint.

Before starting each replacement, the helper checks its operation label,
fresh container identity, image, environment, command, entrypoint and original
mounts. Runtime verification includes every application service, PostgreSQL and
Redis, live worker/scheduler processes, the Reverb listener, a fresh HMAC
challenge at the configured origin, and authenticated private-event delivery
over the browser's configured WebSocket endpoint. The transport probe uses a
temporary channel and nonce while this operation owns the hold. It verifies
TLS, subscription authentication and exact event delivery within a bounded
deadline; it writes no application records, sessions or queued jobs. A failed
transport proof prevents target completion or source fallback release.
The probe does not exercise Laravel user-session `/broadcasting/auth`, which
stays fenced during maintenance; verify that path and the full support loop
separately after release. The helper verifies the held 503 and released 200
separately; cached health responses cannot establish success. Queue workers
remain paused during the hold, so these checks establish fresh process/runtime
identity without claiming that a queued customer job ran during maintenance.

After target migration and runtime receipts are verified, the helper promotes
the reviewed overlay and enrollment image/hash using the existing configuration
schema. The durable `configuration_commit_intent` permits narrowly checked
old/new configuration states during recovery of an interrupted two-file
promotion. An unrelated configuration edit remains a refusal.

Success requires immutable migration and runtime receipt hashes, every target
artifact digest, verified migrations and application services, committed
configuration, release of this operation's hold, and a verified serving origin.
The terminal phase is `succeeded` with `apply.phase = verified` and no error.
The protective record becomes `retained`, with `custody_verified = true`,
`hold_owned = false`, and `services_recovered = false`: the retained backup
belongs to the old release while the new release is serving.

A failure before migration intent can become `failed_safe` only after the
helper proves that the previous source services and origin are serving again
and the owned hold is released. It retains the failure reason and a runtime
receipt. Once schema mutation is possible, or command settlement, ownership or
serving state is uncertain, the operation remains active as `recovery_required`.
The helper does not automatically downgrade the image or restore the database.

A failed final origin check attempts to reacquire this operation's hold on
the appropriate release. Interrupted creation is reconciled from recorded
intent and observed containers; a missing result never authorizes another
force-recreate or migration invocation. Recovery rechecks retained artifact
bytes and local image identity without fetching replacement metadata.

## Protective archive and recovery material

The application captures a nonempty SQL dump and local attachment binaries,
records each member's size and SHA-256 in its manifest, and checks captured local
attachment rows against the staged binaries. The host copies the archive into
its private operation directory and independently verifies the compressed
archive hash, exact manifest bytes, source and operation identity, and every
regular member's size and hash. Verification streams gzip/USTAR bytes without
extracting anything. Unknown members, duplicate paths, traversal, links,
extended headers and special files refuse protection.

Private configuration is retained separately in root-only custody. Before the
drain, the host reads the effective current and previous decryption keys from
all five original running application containers and requires their key sets to
agree. The original values are saved privately in `keys.json` with mode 0600;
the archive's fingerprint set must match that frozen running-key set. This
preserves the effective release keys independently of later `.env` edits.
Fingerprints and actual keys never appear in public status/logs. The default
`storage/app/erasure-ledger` is copied separately when present. Custom ledger
locations are unsupported for this protection slice. The ledger is a record of
later erasures and must **never** be rolled back from an older snapshot.

Protective backups suppress ordinary retention pruning. A configured mirror
uses a separate `protective/<operation-uuid>` object prefix. Its receipt reports
only existence and matching byte size after upload; it does not prove geographic
separation, independent custody, or a successful restore drill. Attachment
binaries on external/retired disks are counted as dependencies and are neither
included in the archive nor verified by this operation. Retain those stores and
the separate recovery material.

The supported archive format has at most 32,768 regular data members and an
8 MiB manifest, and names must fit USTAR's name/prefix limits. Separate ledger
custody is bounded at 64 MiB. Archive verification also has a one-hour deadline
checked throughout hashing and streamed decompression. Capacity, malformed input,
or verification failure blocks successful protection. The helper attempts checked recovery of the old
services; if it cannot prove that recovery is safe, the durable hold remains.
Byte verification does not replace a restore drill on a disposable database.

## Status and interrupted operations

Enrollment identity can be inspected without changing files:

```bash
sudo /usr/bin/python3 scripts/self-host/enroll-updater.py status
```

Root CLI status and operation logs remain available if web or the daemon is
unavailable:

```bash
sudo wayfindr-updater status
sudo wayfindr-updater logs --operation <operation-uuid>
sudo systemctl status wayfindr-updater.service
```

An operation ID and request identity are retained in the host journal. Retries
refer to that operation rather than starting a second operation. The helper
retains interrupted ownership across a helper or VM restart; a stale heartbeat
does not release it. For a preparation-only interrupted operation, root can
request the restricted reconciliation path:

```bash
sudo systemctl stop wayfindr-updater.service
sudo wayfindr-updater reconcile --operation <operation-uuid>
sudo systemctl start wayfindr-updater.service
```

The root CLI requires the helper's exclusive host lock, so the daemon must be
stopped for reconciliation. The journal validates the known preparation-only
checkpoint before releasing interrupted ownership.

An interrupted **protection** operation uses a different recovery path. Keep
or restart the daemon, inspect the retained operation, and explicitly request
checked old-service recovery:

```bash
sudo systemctl start wayfindr-updater.service
sudo wayfindr-updater status --operation <operation-uuid>
sudo wayfindr-updater recover-protection --operation <operation-uuid>
sudo wayfindr-updater logs --operation <operation-uuid>
```

This request is asynchronous and authenticated as root. It does not run a second
backup or change an image/schema. A helper/VM restart records
`recovery_required`; it never silently resumes a protection operation. Recovery
refuses while a backup or pending graceful stop could still be writing, or when
source identity, enrollment files, custody context, or ownership cannot be
verified. Status/logs remain readable even if recovery cannot proceed. Never
clear the marker or journal, run `artisan up`, force-kill a writer, or use the
preparation-only `reconcile` command to bypass that hold.

An interrupted **apply** operation has its own explicit root-only recovery:

```bash
sudo systemctl start wayfindr-updater.service
sudo wayfindr-updater status --operation <operation-uuid>
sudo wayfindr-updater recover-apply --operation <operation-uuid>
sudo wayfindr-updater logs --operation <operation-uuid>
```

Restarting the helper records `recovery_required` and preserves the last
checkpoint, custody and possible-migration flags. Recovery checks command
settlement, fixed source/target identities, receipts, enrollment transition and
hold ownership before proceeding. It cannot use `recover-protection` or
preparation-only `reconcile` to close an apply operation. A proven completed
target can finish verification; ambiguous migration execution remains held for
operator investigation instead of automatically rerunning the migration command
or restoring an older schema.

While the application is running with the overlay, its authenticated status
command can inspect the same host record:

```bash
sudo docker --host unix:///var/run/docker.sock --config /etc/wayfindr-updater/docker \
  compose --env-file .env -f compose.yml -f compose.updater.yml exec -T web \
  php artisan wayfindr:updater-status --json
```

Add an operation UUID and `--logs` to include its first bounded event page.

## Preserving ownership

Re-running enrollment refuses to overwrite an existing identity, token, helper
code, service, marker, or journal. Partial enrollment is also retained for
operator inspection. An error after host files are created can leave enrollment
incomplete; inspect the service and host files before proceeding. The command
does not repair that condition by replacing files behind an active helper.

The terminal installer refuses `--upgrade` while `.updater-enrolled` exists.
This prevents a separate terminal controller from replacing an installation
owned by the helper without sharing its operation lock and journal. Terminal
upgrades for unenrolled installations behave as before. Credential rotation and
unenrollment remain separate, unimplemented workflows. Do not
delete an enrollment marker or journal to clear an interrupted operation.

Existing `0.1` preparation, `0.2` protection, `0.3` apply and `0.4` operator journal records remain readable.
New enrollment requires the additional application commands and a recorded hash
of the reviewed overlay. Re-running enrollment cannot upgrade an older enrollment in place or
replace its helper files. Use the separate helper upgrade procedure below.

## Upgrading the installed helper

Application updates and helper replacement are separate operations. Managed
apply changes the application image and overlay; it does not replace privileged
host code. The public normal `0.6.0` replacement accepts only the complete,
unmodified five-module helper `0.4.0` from `v1.2.0` or `0.5.0` from `v1.3.0`.
Modified, mixed and unknown installed bundles refuse. Protocol remains `1`,
compatible with the published `v1.2.0` application contract. Enrollment cannot
upgrade an existing helper, and neither pulling an image nor restarting the
application activates this command.

### Review the complete distribution first

Before executing downloaded source, independently verify the complete public
source archive for the selected stable release against its exact published tag
and full commit. Review the five helper modules, normal upgrade CLI, frozen
legacy recovery CLI, separate bridge CLI, installer, base and updater Compose
files, systemd unit and tmpfiles rule. Retain the individual file hashes and the
target bundle hash. The two Release manifest/image-digest assets do not by
themselves verify this source distribution. A moving branch, a candidate archive
or a reported helper version cannot substitute for that public source proof.
Public v1.4.0/helper 0.6 is verified in the publication receipt above; its
complete immutable source distribution must still be independently verified
before execution. Public v1.4.1/helper 0.7 and v1.4.2/helper 0.8 cannot substitute
for the original destination distribution when recovering an older transaction.
The v1.4.3/helper 0.9 source candidate remains unpublished.

Stage the reviewed complete distribution in a canonical, root-owned directory
such as `/root/reviewed-wayfindr-v1.4.0` for the public 0.6 commands below. Every
ancestor and required file must be root-owned, without unsafe write permissions
or symlinks. The tool does
not download, select or execute remote code. Independently retain the installed
source/runtime baseline, enrollment authority, journal, credentials and recovery
material before replacement; keep credential-bearing evidence private.

The host must support Python 3.11+, systemd 252+, unified cgroup v2 with freezing
and `cgroup.kill`, and Linux `renameat2` atomic directory exchange, with trusted
`/usr/bin/busctl` and `/usr/bin/test` binaries. The installed source module
hashes, wrapper, service unit and tmpfiles rule must match the fixed published
allowlist. Missing platform/API prerequisites refuse before installing a
transaction gate. A later exchange failure retains the stopped transaction for
explicit recovery; there is no file-by-file or ordinary-stop fallback.

### Replace an idle helper with public 0.6

Use a root shell for the following commands from the exact reviewed public
v1.4.0 tree, commit `0f4312f8f7bfae7dae556ea547fccda2cf66f43e`:

```bash
cd /root/reviewed-wayfindr-v1.4.0
/usr/bin/python3 scripts/self-host/upgrade-updater.py inspect
```

Require `helper_version = 0.6.0`, `protocol = 1` and the independently reviewed
five-module bundle SHA-256 below. `inspect` reads the staged target; it does not
establish that the installed host is idle or authorize changed source bytes.

```bash
/usr/bin/python3 scripts/self-host/upgrade-updater.py upgrade \
  --expected-bundle-sha256=eb86ed370721250d0e22492f665b0fc116f0e292454150761014e5f6ae9d24df
```

The command serializes helper upgrades, refuses active or held application
operations and checks retained recovery ownership. An existing helper transaction
requires its own recovery; it cannot be retargeted to 0.6. A refused, partial or
uncertain outcome must be inspected with its retained evidence before any
continuation. Do not delete enrollment, qualification, journal, transaction or
stop-gate state to make the normal path admit it.

The command installs an exact systemd
gate with both `ConditionPathExists` and `ExecCondition`, verifies the effective
condition's executable and arguments through typed D-Bus metadata, freezes the
helper's entire cgroup, then rereads the durable journal. `ExecCondition` also
guards automatic restarts, which can skip unit start conditions
([systemd 255 unit startup](https://github.com/systemd/systemd/blob/v255/src/core/unit.c),
[service conditions](https://github.com/systemd/systemd/blob/v255/src/core/service.c)). The canonical
`Restart=on-failure` policy is preserved.
If Start won the race, the old helper is thawed and replacement refuses. Only a
confirmed idle frozen helper is killed; its cgroup must empty before the service
is stopped and the existing lifetime lock is acquired. There is no ordinary-stop
fallback for hosts without this admission protection.

The complete code directory switches atomically, preserving its existing safe
mode (`0700`, `0750` or `0755`) even under a restrictive root `umask`. The command
preserves the installation UUID, credential, configuration, application files, runtime
directory and lifetime-lock inode, journal operations and retained recovery
bytes. It starts the complete new helper with application mutations blocked,
checks an authenticated root-peer/HMAC response, a new executor generation and
the preservation invariants. It verifies the same live process and unfrozen
kernel cgroup, reconciles a stale systemd freezer cache when required and verifies
again before clearing the transaction barrier. Failed verification retains
recovery ownership. A private success receipt and
the complete old code generation remain retained. It does not restart application
containers, migrate a database or rotate credentials.

### Recover the same 0.6 transaction

An interrupted replacement keeps its private transaction and prevents unsafe
startup or mutation. Set `HELPER_TRANSACTION` to the exact reported canonical
transaction UUID, then recover from the complete reviewed public v1.4.0
distribution, with its original normal CLI and the same bundle hash. The 0.7
candidate reports `public_06_helper_recovery_required` for an interrupted
normal 0.4-to-0.6 or 0.5-to-0.6 transaction; it cannot retarget that transaction.
Verify the original CLI's hash before executing it:

```bash
cd /root/reviewed-wayfindr-v1.4.0
printf '%s  %s\n' \
  53e33e9361129201855964b2e7f696de42cc92ee1b85b287a91fc4b16ba8222f \
  scripts/self-host/upgrade-updater.py \
  | sha256sum --check --strict
/usr/bin/python3 scripts/self-host/upgrade-updater.py recover \
  --transaction="$HELPER_TRANSACTION" \
  --expected-bundle-sha256=eb86ed370721250d0e22492f665b0fc116f0e292454150761014e5f6ae9d24df
```

Recovery accepts only the recorded old/new code generations and exact preserved
state. It resumes the same transaction; it does not reenroll or infer a safe
state from a missing file. A truncated first transaction record or partial
systemd gate written before an authoritative transaction exists refuses for
operator inspection; recovery cannot invent the missing identity or byte proof.
Keep `/var/lib/wayfindr-updater/helper-upgrade.json`,
the systemd stop gate and the retained generation intact for this procedure.
`status`, `history` and `logs` remain observation-only while a new helper is
running behind the transaction gate. An existing active or interrupted `0.4`
application update must be settled with its original helper before replacement.
Historical terminal schema-1 recovery material is retained and validated with
the pinned original code; it is never converted into an executable schema-2
operation.

Require a completed receipt for the original UUID, its recorded 0.4.0 or 0.5.0
source and 0.6.0 destination, `preserved = true` and
`application_changed = false`. Retain both code generations and all original
recovery evidence. Authenticate the resulting helper and independently verify
that the host is idle before considering a distinct 0.7 candidate transaction.
The normal recovery procedure does not recover a schema-2 bridge transaction.

### Finish an existing 0.4-to-0.5 transaction first

For an exact retained legacy transaction, the 0.6 and 0.7 normal CLIs return
`legacy_helper_recovery_required` without changing code, journal, gate or
transaction bytes. Finish it into its original public `0.5.0` destination with
the original UUID, using the included frozen compatibility CLI; do not recover
it as a 0.6 transaction or start a new one.

Independently verify the public `v1.3.0` source at commit
`1bae329c4e349017716827dd0ce26b9ba7f9f2ac` in a separate root-owned staging tree,
`/root/reviewed-wayfindr-v1.3.0`. Its five helper modules must remain the exact
public `0.5.0` bytes. After reviewing both distributions, verify the frozen CLI
and replace only the CLI in that staging tree. Set `LEGACY_HELPER_TRANSACTION`
to the original retained transaction UUID before recovery. The commands below
use the frozen compatibility CLI from the reviewed public v1.4.0 tree:

```bash
cd /root/reviewed-wayfindr-v1.3.0
printf '%s  %s\n' \
  fe494f93552246677ee8a97c55a819ab748de4910bf300123dbb6afe408d4616 \
  /root/reviewed-wayfindr-v1.4.0/scripts/self-host/upgrade-updater-0.5-recovery.py \
  | sha256sum --check --strict
install -m 0644 \
  /root/reviewed-wayfindr-v1.4.0/scripts/self-host/upgrade-updater-0.5-recovery.py \
  scripts/self-host/upgrade-updater.py
/usr/bin/python3 scripts/self-host/upgrade-updater.py inspect
/usr/bin/python3 scripts/self-host/upgrade-updater.py recover \
  --transaction="$LEGACY_HELPER_TRANSACTION" \
  --expected-bundle-sha256=e8523f24327188c36e6a352cd4366977afdb96b02f56d17760d7bd8063cada29
```

Require the inspected `0.5.0`/protocol `1` bundle and the completed receipt's
original UUID, source `0.4.0`, target `0.5.0`, `preserved = true` and
`application_changed = false`. Retain that receipt and both original recovery
evidence and code generations. Authenticated status must verify the resulting
idle 0.5 helper before a distinct normal upgrade, using the public 0.6 procedure
above or another independently reviewed, eligible public successor distribution.
If recovery refuses or an application operation remains active, stop this
procedure and retain its evidence. The compatibility CLI has no 0.6 or 0.7 destination.
The [legacy procedure and dated native observation](helper-0.6-candidate.md#finish-an-interrupted-04-to-05-helper-transaction-first)
retain their original reviewed source identities.

### Historical 0.7 candidate procedure on an idle qualification VM

This section preserves the original pre-publication candidate procedure.
v1.4.1/helper 0.7 is now public; its actual public distribution and source
archive must be verified separately before use. The paths and candidate hashes
below still refer to the original reviewed candidate, not the 0.8 successor.

The v1.4.1/helper 0.7 candidate accepts only complete, exact public five-module
source bundles: 0.4.0 from v1.2.0, 0.5.0 from v1.3.0 or 0.6.0 from v1.4.0.
A version string, mixed module tree or an earlier candidate transaction is not
proof of an eligible installed source. All platform, enrollment, idle-journal,
authority and preservation requirements above still apply. Active or held
application operations refuse, including a mutated 0.6 operation awaiting
application recovery. Installing this candidate is not an application recovery
procedure.

Before any disposable-VM execution, independently review and freeze the
complete immutable candidate distribution, every required file and its target
bundle digest. Preserve the public source/runtime baseline and recovery material.
Use a separate root-owned staging tree; retain the public v1.4.0 tree for pending
0.6 recovery and historical bridge work. A future public v1.4.1 procedure also
requires its own completed publication and complete public source-archive
verification; this candidate section does not establish either gate.

Only after that separate review, set `CANDIDATE_HELPER_BUNDLE_SHA256` to the
independently retained candidate bundle hash. In a root shell:

```bash
cd /root/reviewed-wayfindr-0.7-candidate
/usr/bin/python3 scripts/self-host/upgrade-updater.py inspect
```

Require `helper_version = 0.7.0`, `protocol = 1` and the exact reviewed bundle
hash. After any earlier normal transaction has completed into its original
destination and the helper is independently verified idle, start a new
transaction:

```bash
/usr/bin/python3 scripts/self-host/upgrade-updater.py upgrade \
  --expected-bundle-sha256="$CANDIDATE_HELPER_BUNDLE_SHA256"
```

If this new 0.7 transaction is interrupted, retain its own reported UUID in
`CANDIDATE_HELPER_TRANSACTION` and use only the same immutable candidate tree
and bundle:

```bash
/usr/bin/python3 scripts/self-host/upgrade-updater.py recover \
  --transaction="$CANDIDATE_HELPER_TRANSACTION" \
  --expected-bundle-sha256="$CANDIDATE_HELPER_BUNDLE_SHA256"
```

Do not use these commands to recover a pending 0.6 or legacy 0.5 transaction.
Do not clear a journal, transaction, gate, qualification marker or retained
generation to make a new transaction eligible. Candidate helper lifecycle
checks do not qualify managed application upgrades or prove successful recovery
of an already mutated application.

### Keep the active-operation bridge separate

Normal replacement continues to refuse active or interrupted application work.
The separate [active-operation bridge](helper-active-recovery-bridge.md) admits
only the exact published 0.5 process-listing failure during the initial read-only
baseline of a public v1.2.0-to-v1.3.0 apply: `recovery_required`, checkpoint
`apply_started`, exact seven-event prepare/start/failure history and receipts,
with no schema mutation, owned hold, archive or executor custody. It reruns the
corrected complete read-only baseline before any helper exchange. Its only
destination is public 0.6, using the complete immutable public v1.4.0 tree above,
including its original normal CLI. The bridge is deliberately unavailable from
the 0.7, 0.8 or 0.9 distribution and cannot replace a mutated, held helper or repair
that application's state. Other active operations remain refused.

Use only that guide's root-only `inspect-apply-bridge`, `bridge-apply-recovery`
and same-transaction `recover-apply-bridge` procedure, with the exact retained
operation UUID, reviewed journal-state SHA-256 and bundle binding. Inspection
explicitly reports `baseline_verified = false`; it cannot replace the full
baseline. Its schema-2 `active_apply_helper_bridge` transaction is distinct from
the normal schema-1 helper transaction. Neither CLI adopts the other's receipt.

An existing supported VM attestation is preserved only as its exact private
root-owned `qualification/disposable.json` subtree: complete historical public
source/target identities, raw bytes and directory/file identities must match.
The marker does not authorize replacement and is never rewritten, deleted or
re-adopted. The full failed application journal and original helper generation
remain retained. A completed bridge leaves the application `recovery_required`;
application recovery needs a separate explicit operator action and preservation
proof. It does not start or retry the application operation.

### Native evidence limits

The dated [ordinary 0.6 candidate observations](helper-0.6-candidate.md#bounded-native-candidate-observations-2026-10-10)
cover one 0.4-to-0.6 AMD64/classic replacement and one 0.5-to-0.6 ARM64/classic
replacement, including the retained legacy startup transaction before the latter.
The separate [ARM64/containerd bridge observation](helper-active-recovery-bridge.md#bounded-native-preservation-observation-2026-10-10)
preserved one failed operation, its marker and the fresh repaired S3 baseline;
historical pre-loss S3 continuity remains invalidated. The earlier
[0.5 lifecycle rehearsal](evidence/managed-update/2026-10-10-helper-lifecycle-rehearsal.json)
records a separate same-kernel post-exchange process-crash recovery. These dated
observations do not establish native power-loss/reboot durability, application
recovery, managed upgrade, protective backup, ordinary restore or publication.
**Zero of 100 managed application-upgrade scenarios are qualified by these
helper-only observations.** Public source delivery and the remaining native
architecture/store/interruption cases remain separate gates.

## Docker image identity

Artifact receipt schema `2` records the public index, selected platform manifest
and raw config digests separately from Docker's observed local image ID and
descriptor. Classic stores normally use the config digest as the local ID;
Docker 29 containerd stores can use the index or selected manifest. The helper
requires a verified mapping, complete image Config and RootFS comparison, the
native platform and the exact official repository digest. Only known typed
empty defaults from older classic inspect representations are normalized;
unknown fields and nondefault changes refuse.

All target execution uses the exact official `version@sha256:index` reference
and explicit native Linux platform, with pulling disabled after verification.
The never-started, network-disabled artifact probe verifies baked release bytes
without installation mounts. Containerd probes, oneoffs and all five application
roles must also identify the selected platform manifest. Protocol checks,
migration, replacement, promotion and recovery revalidate the retained binding.
The public operation's `config_digest` remains a public content digest; it is
never fabricated from a container's `.Image`. Old private schema-1 apply state
is preserved and refuses execution by the new helper.

Synthetic tests cover protocol, journal, enrollment, application holds, archive
verification, and failure/recovery ordering. They do not
qualify this systemd service, Unix-socket mounts, host UID mapping, or VM reboot
behavior on a real VM. That independent qualification belongs to
[#1115](https://github.com/adamgreenwell/wayfindr/issues/1115).

Tracked delivery: [#1110](https://github.com/adamgreenwell/wayfindr/issues/1110),
[#1111](https://github.com/adamgreenwell/wayfindr/issues/1111),
[#1112](https://github.com/adamgreenwell/wayfindr/issues/1112) and
[#1113](https://github.com/adamgreenwell/wayfindr/issues/1113) in
[the managed-update epic](https://github.com/adamgreenwell/wayfindr/issues/1107).
