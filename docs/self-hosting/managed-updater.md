# Enrolling the independent host updater

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

These changes are development slices awaiting release and VM qualification.
They have not been qualified as a production update mechanism.

## Operator action contract

U6 adds JSON endpoints under `/operator/updates`. The review/progress interface
is a separate U7 slice; these endpoints alone do not add an Update button.

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
errors are JSON with `Cache-Control: no-store`. An unavailable or incompatible
helper and explicit external/hosting ownership cannot enable managed execution.
Old preparation/status helper versions remain observable without gaining start
authority. New enrollment probes the application's static protocol contract
before writing host credentials or services. The staged target must satisfy
that same fixed PHP-only protocol probe before migration admission, so an older
application cannot complete an update and silently lose its operator controls.

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
Python 3.11 or newer at `/usr/bin/python3`, Docker Engine at `/usr/bin/docker`, and Docker Compose
installed as a system CLI plugin. User-directory Docker plugins are not loaded
from the helper's isolated Docker configuration.
The host and image architecture must agree and be `amd64` or `arm64`.
Rootless Docker, user namespace remapping, custom Compose files, floating images,
custom images, and prereleases are refused by enrollment.

The installed application must contain `wayfindr:update-plan`,
`wayfindr:upgrade-window`, `wayfindr:protective-backup`, and
`wayfindr:managed-apply`. Enrollment checks
that these fixed commands exist before installing credentials or the service.
Fetching the helper cannot add them to an older image.
The draft changes need to reach an application release before an existing
published installation can use them.

Root must deliberately adopt and review the installation first. The directory,
`.env`, `compose.yml`, `install.sh`, and every ancestor must be root-owned, must not be
symlinks, and must not be writable by a group or another user. A typical managed
location is `/opt/wayfindr`. Home directories and helper-owned directories cannot
be used as installation paths. Enrollment does not change ownership or copy
your application files into another location.
New managed paths accept ASCII letters, numbers, slashes, dots, underscores and
hyphens. Whitespace, control characters, Unicode, quoting, expansion syntax and
the root directory are refused before enrollment writes.

Use a reviewed root-owned distribution of these helper files, with the same
official `docker/self-hosting/compose.yml` and guarded `scripts/self-host/install.sh`
bytes as the installed release. The script refuses a customized or different
Compose file or an older unguarded terminal controller. It never silently
replaces `install.sh` to make an installation eligible. The distribution's
paths and ancestors have the same ownership and write restrictions; a reviewed
distribution staged under `/root` is suitable. These checks protect the inputs
used by a privileged host process. No automatic download or helper self-update
is performed.

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
`APP_KEY`, helper code, a systemd unit, an installation ownership marker, and a
`compose.updater.yml` overlay. It enables and starts the helper after verifying
its authenticated socket and durable startup generation. It does not recreate
an application container or alter `.env`, the base `compose.yml`, or `install.sh`.

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
resets `/run`; Docker's re-established binds require separate VM qualification.
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
Redis, live worker/scheduler processes, the Reverb listener, and a fresh HMAC
challenge at the configured origin. It verifies the held 503 and released 200
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
upgrades for unenrolled installations behave as before. This foundation has no
helper replacement, credential rotation, or unenrollment command;
those operations require a separate ownership-aware implementation. Do not
delete an enrollment marker or journal to clear an interrupted operation.

Existing `0.1` preparation, `0.2` protection and `0.3` apply journal records remain readable.
New enrollment requires the additional application commands and a recorded hash
of the reviewed overlay. Re-running enrollment cannot upgrade an older enrollment in place or
replace its helper files. Its separate replacement/unenrollment workflow has
not been implemented.

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
