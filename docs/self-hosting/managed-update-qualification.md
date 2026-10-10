# Managed-update failure and recovery qualification

This is the execution plan and evidence boundary for [U8 / #1115](https://github.com/adamgreenwell/wayfindr/issues/1115).
It extends the [disposable-VM evidence contract](disposable-vm-evidence.md)
for the [independent host updater](managed-updater.md). It does not record a
completed managed VM qualification. The managed source-to-target matrix below
remains **proposed and unexecuted**. Actual source and ordinary-recovery
observations are recorded separately and cannot complete its rows.

## Publication and execution gates

There are three separate results:

1. **Local contract tests** exercise journal, protocol, backup and apply logic
   with controlled dependencies. They cannot prove systemd, Docker UID mapping,
   Unix-socket mounts, a guest reboot, or data survival on an installed VM.
2. **Pre-publication rehearsal** runs candidate code on an isolated VM and
   records the candidate commit and artifact identities. It can find integration
   defects before release. It does not qualify a published upgrade span.
3. **Published qualification** repeats the supported matrix with exact public
   release assets and images, then independently verifies the resulting data,
   services, origin, reboot and restore behavior.

Public `v1.2.0` is frozen at
`56374e9574ed84616aae430d06589cfe2f0b33a0`, and
[exact-main CI 38006329309](https://github.com/adamgreenwell/wayfindr/actions/runs/38006329309)
and [publisher 38007100329](https://github.com/adamgreenwell/wayfindr/actions/runs/38007100329)
passed. Independent public readback verified the Release, manifest, both
amd64/arm64 image chains and aliases on October 10 UTC (October 9 EDT).
ARM64 never-started-image baked version/commit/manifest/history verification
passed, including exact public Config/rootfs equality, no start or mounts, and
named UID/GID 1000. Actual ARM64 official installation, optional enrollment,
idle helper restart and autonomous idle guest reboot passed; the
[source/ordinary-recovery receipt](evidence/managed-update/2026-10-10-published-source-and-ordinary-restore.json)
binds the exact official image and installed distribution. Five application
roles passed PostgreSQL/production Redis/runtime checks, with private nonce and
authenticated API/operator checks repeated after reboot. Container identities
and credential/config/source fingerprints survived with a new helper generation
and no operations. Ordinary archive/restore on a separate clean, unenrolled
VM passed without force: the fixed archive predated a real erasure, its latest
nonempty settled ledger preceded restore, and exact positive SYSTEM replay,
survivor rows/local binary/settings/decryption and sequence/new API contact
checks passed. One current key was retained, with no previous keys; rotation
was not exercised. Erasure used the production service, not dashboard
confirmation. These ARM64/private HTTP runs do not establish TLS, user-session
broadcasting authorization, remote storage or managed protective custody. U8/#1115, U9/#1116 and epic #1107 remain open;
managed qualification remains false, with zero executed managed scenarios.
Public amd64 and arm64 manifest/configuration checks do not establish baked-file
or installed-VM observations for an untested architecture.

**Docker 29 execution blocker:** on the ARM64 source guest, the containerd image
store reports the OCI index digest as image `.Id` and container `.Image`; the
public configuration digest is separate and is not a local image address. The
source probe verified full public Config/rootfs equality and baked bytes, but
published managed artifact verification/apply assume the local ID equals the configuration
digest. [#1131](https://github.com/adamgreenwell/wayfindr/issues/1131) must be fixed
and exercised with exact published artifacts before managed execution can be
qualified. No managed operation was attempted. A newer compatible published
target is a separate prerequisite.

The affected code is installed in the host helper. Managed apply replaces the
application image/overlay and installation image/configuration binding; it does not replace helper
code. Enrollment refuses existing helper artifacts, and terminal upgrade refuses
enrolled hosts. A newer application image alone therefore cannot repair an
already-enrolled 1.2.0 Docker 29 source. Keep that span unqualified until a
supported, ownership-aware, byte-verified helper upgrade preserves enrollment
identity, credential, journal, locks and recovery state while refusing active or
partial work, or qualify a real newer published source freshly enrolled with the
fixed helper. Manual helper overwrite, marker deletion, store switching and
silent enrollment overwrite are not supported recovery paths.

The development `0.5.0` implementation addresses this image binding and adds the
separate [exact-byte helper upgrade](managed-updater.md#upgrading-the-installed-helper).
The published `v1.2.0` helper is still `0.4.0`. Implementation tests or a local
candidate lifecycle rehearsal do not publish the fix or qualify a managed
source-to-target span. Record actual installed-helper replacement, public target
identity and the expanded matrix separately.

The earlier [ARM64 Docker 29/containerd candidate receipt](evidence/managed-update/2026-10-10-docker29-helper-recovery.json)
records actual same-transaction recovery after interrupting the explicit
helper-upgrade `recover` command,
preserving the published `v1.2.0` application and its enrollment while completing
`0.4.0` → `0.5.0`. Current-public-release `Artifacts.prepare` and retained
read-only verification also passed. This is candidate helper-lifecycle and
artifact-component evidence; an uninterrupted fresh upgrade is a separate
observation. The fix remains unpublished, no newer application target or managed
operation was created, and all 100 managed scenario cases remain unexecuted.

The subsequent [merged-helper lifecycle receipt](evidence/managed-update/2026-10-10-helper-lifecycle-rehearsal.json)
records a clean uninterrupted `0.4.0` → `0.5.0` upgrade and explicit
same-transaction recovery after the unmodified CLI's successful atomic directory
exchange and SIGKILL. Both trials used one new ARM64/Docker 29/containerd VM,
reset between trials only through its own clean published-source snapshot.
The actual syscall-exit stop proved complete generations exchanged while the
record remained `staging`, before the parent-directory fsync. Both trials
preserved application containers, authority, journal and seeded data, including
binary bytes and fresh-process encrypted-setting decryption. This is
same-kernel process-crash evidence, without power-loss durability or reboot
recovery. Historical terminal managed-apply custody remains unexercised.
The helper remains unpublished; no newer application target, managed
protection/apply/migration or other native/store combination ran. Managed
qualification remains false with zero of 100 managed cases; #1131, U8/#1115,
U9/#1116 and epic #1107 remain open.


The earlier October 9 publication gate checked public `v1.1.0` → `v1.1.1`.
Neither immutable tag contains the managed-apply command required by enrollment;
the now-merged implementation cannot change those older artifacts. Retain that
[blocked receipt](evidence/managed-update/README.md) as dated evidence.
A managed success span needs **two compatible published releases**, with the
source and target satisfying the reviewed application/helper protocol and
byte-identical supported base Compose configuration. The first compatible
release can prove enrollment, idle helper reboot behavior, ordinary backups and
an independent restore. Target-bound protection and active-operation reboot
drills need an eligible newer published target; planning the running release
returns `no_update_required`. One release cannot supply both ends of a published
managed upgrade.

Release/tag creation, registry publication, implementation merges, production
changes and arbitrary image downgrades are separate actions. This qualification
plan authorizes none of them. Do not relabel a local image as an official public
release or bypass enrollment checks to turn a rehearsal into release evidence.

## Disposable installation boundary

Use fresh Ubuntu Server 24.04 LTS guests with systemd and rootful Docker Engine
and Compose. Record the guest architecture; an amd64 result does not qualify
arm64. Rootless Docker, user namespace remapping and external deployment owners
are refusal cases for this executor.

The managed installation must use the canonical `wayfindr-self-hosting` Compose
project, the reviewed unmodified base `compose.yml`, directly mapped host
UID:GID `1000:1000`, trusted root-owned installation paths, and the exact stable
official application image. Enrollment records private identity and file hashes.
After enrollment, use the activated `compose.updater.yml` alongside the base
file when inspecting or operating the application. The helper has one fixed
host service and state directory; use a separate VM for each independent
installation rather than several enrolled projects on one guest.

The existing public-artifact harness uses customizable `wayfindr-evidence-*`
projects and base Compose commands. Its successful install, restart or restore
does not prove managed enrollment. Its earlier reports remain evidence for the
commands and public artifacts they actually ran.

Before any injected interruption or reboot, bind the run to an explicit
disposable-installation confirmation, canonical installation directory,
installation UUID, boot ID, source identity, operation UUID and selected
checkpoint. Refuse absent or mismatched bindings. Retain an out-of-band guest
console, a bounded timeout and a way to reconnect. Record which actor requested
each mutation. A local developer Docker daemon, shared VM or production origin
must never become an implicit fallback target.

No procedure below requires a test-only bypass in the shipped helper. A fault
controller operates outside the helper, within the explicitly disposable guest,
and records the intervention and the resulting observations. Privileged
procedures are plans until the corresponding command has been exercised on that
guest; this document does not provide unexecuted privileged shell snippets.

## Evidence to freeze before the first operation

Record the run ID, guest/environment ID, UTC timestamps, OS, kernel,
architecture, Python/Docker/Compose versions, boot ID and helper version/protocol.
Record the published source and target tag, commit, release-manifest hash,
migration-history hash, OCI index digest, platform-manifest digest and image
configuration digest separately. A tag, image ID and multi-platform index digest
are different identities.

Freeze a synthetic used-install dataset before preparing the update:

- conversations, messages, tickets and their relationships;
- local attachment metadata and downloaded binary bytes;
- remote attachment metadata and downloaded binary bytes on a dedicated
  disposable S3-compatible dependency;
- encrypted settings plus values written under the effective current and
  previous application keys;
- an erasure recorded after the archive's snapshot point, with the latest
  separate ledger preserved;
- installation identity, proxy configuration and certificate material.

Compare logical data and retrieved bytes rather than a raw database dump whose
encoding or row order can change. Use run-keyed logical digests for sensitive
values and configuration, with the digest key retained privately. Public reports
must not contain application keys, credential tokens, secret fingerprints,
cookies, sessions, customer content, private endpoints or full Docker inspect
output. Verify decryption through the application; equal encrypted database
bytes alone do not establish usability. Record local and remote attachment
checks separately; an external-storage count does not prove that a remote binary
is retrievable.

The evidence contract names these invariants `conversations`,
`local_attachments`, `remote_attachments`, `encrypted_settings`,
`application_key`, `erasure_tombstones`, `installation_identity`,
`tls_certificates` and `proxy_configuration`. Missing before/after/restored
observations leave the corresponding claim unproved.

Here, `installation_identity` and its run-keyed digest describe the preserved
logical application/site identity. Record the helper's host enrollment UUID
separately. A second restore VM must have its own host authority; matching
application identity does not require copying the original helper UUID,
credential, journal or enrollment marker onto another host.

## Required matrix

Evidence schema `2` repeats every scenario below for each supported native
combination: Linux amd64 and arm64, each with the classic and containerd image
store. These are 25 scenario identifiers across four combinations, or 100
separately observed cases. A containerd image index is not a config digest:
record the public index/platform/config digests, observed local ID and
descriptor, immutable version-plus-digest selector and explicit platform.
Containerd container observations must identify the selected platform manifest;
classic container observations may report null for `platform_manifest_digest`.
Successful schema-2 cases require helper `0.5.0` or later; the application protocol stays
`1`. Older schema-1 reports remain readable but cannot qualify this expanded
image-binding matrix. Synthetic fixtures exercise report consistency only.

The scenario identifiers below are the evidence contract identifiers. A grouped
row requires separate observations for each identifier; one successful operation
does not pass every scenario in the row. All rows are initially **unexecuted**.

| Group | Scenario identifiers | Procedure and required observation |
| --- | --- | --- |
| M01 | `stable_patch`, `stable_minor` | Run exact published supported patch and minor spans on used installations. Prove the requested tag/commit and all three OCI digests, completed guards and migrations, fresh target service IDs, configured-origin proof, synthetic-data survival and retained backup custody. |
| M02 | `major_actions`, `skipped_span`, `below_floor` | Use actual published release metadata that requires an operator action, an intermediate step or a minimum source. Prove the relevant refusal occurs before prohibited mutation and that the source remains usable. For `major_actions`, retain independent refusal evidence bound to the target manifest/actions, complete the real action, and then prove a verified target success; refusal alone is incomplete. Identify actual published intermediate artifacts for `skipped_span`. A metadata fixture is contract-test evidence only. |
| M03 | `legacy_identity` | Begin with an unsupported or unverified source identity. Prove preparation/start refuse and offer the appropriate external update guidance, without inferring identity from a displayed version or relabeling an image. |
| M04 | `unsupported_ownership` | Inspect external Docker, source/host-PHP and deployment-managed installations. Prove managed execution remains unavailable and that no enrollment/host mutation occurs. A single owner type does not cover the others. |
| M05 | `metadata_failure`, `pull_failure` | Deny metadata transport or an uncached target-image pull within the disposable guest. Record the exact denied transport and checkpoint. Prove no target migration/recreation occurred; report `failed_safe` only if the previous origin and services were independently verified. |
| M06 | `disk_failure`, `backup_failure` | Constrain a dedicated disposable filesystem/quota or deny the disposable backup dependency at a measured stage. Record the affected storage and actual error. Prove no migration admission without a verified fresh protective archive, and preserve either verified source serving or the explicit held recovery state. |
| M07 | `duplicate_requests`, `conflicting_operations` | Replay the same preparation/start/cancel request identity through the actual authenticated surface, and attempt another run with a different identity while ownership is active. Prove one accepted mutation, stable operation/plan binding, appropriate busy/conflict refusals and no duplicate migration or backup. |
| M08 | `long_jobs` | Keep a synthetic real job/request or PostgreSQL transaction active through drain. Prove new writes are fenced, no force-kill or premature snapshot occurs, and timeout retains uncertainty until the writer settles. After explicit recovery, independently verify the intended source or target serving state. |
| M09 | `browser_network_loss` | Disconnect the operator browser or drop its connection after an accepted request. Reconnect, reload and reopen from a saved operation. Prove the same host run continues, last-confirmed progress remains identifiable, and reconnection emits reads without replaying a mutation. |
| M10 | `helper_restart` | Interrupt the enrolled systemd helper at separately recorded preparation, drained/pre-migration and possible-migration checkpoints. Observe the changed executor generation and retained journal/hold. Use only the recovery action valid for that operation type; prove restart does not silently restart work. |
| M11 | `vm_reboot` | Reboot the actual guest with an owned operation and repeat post-reboot verification on that same persistent installation. Record the boot ID changing, systemd service startup, retained ownership/custody, Docker state and explicit recovery result. A stack restart or hosted-runner restart is insufficient. |
| M12 | `interrupted_migration` | Hold a synthetic migration at a deterministic database boundary and interrupt execution after durable migration intent. Observe the named migration container, immutable completion receipt if one exists, retained mutation flags and held origin. Prove ambiguous execution is not rerun or automatically downgraded; a proven completed target may finish verification through explicit recovery. |
| M13 | `stale_services`, `broken_origin`, `broken_realtime` | Separately leave a stale role/container, break the configured origin route, and break the actual realtime path. Prove neither a healthy web container nor a cached `/up` response grants success. The candidate executor requires authenticated private-channel delivery over the configured browser WebSocket endpoint while held, using a temporary channel and nonce. Independently test real delivery and user-session `/broadcasting/auth` after release; fixture checks do not prove the public transport or browser authorization on a VM. |
| M14 | `authorization`, `protocol_boundary` | Exercise actual operator sessions with valid/expired reauthentication, missing MFA where required, wrong role/actor, stale plan and cross-operation request IDs. Test socket peer UID, invalid credential/MAC, replay/expired nonce and forbidden path/image/command fields. Prove refusals precede dispatch and public output remains redacted. |
| M15 | `old_app_new_helper`, `new_app_old_helper` | Use actual compatible/incompatible published app/helper combinations. Prove unsupported combinations refuse before service interruption, and that supported old journal versions remain readable. Replacing helper files by hand is not an implemented upgrade workflow and cannot establish supported helper replacement. |
| M16 | All successful and recovered scenarios; independent restore | Repeat the logical-data and binary checks after every serving success or verified source recovery. On a second fresh disposable environment, restore the exact retained archive with privately retained effective keys and the latest erasure ledger, then independently verify every restored invariant. Backup byte verification and restore success are separate records. |

If no actual public span exercises a required floor/action/compatibility case,
record it as blocked with the missing artifact prerequisite. Do not manufacture
release metadata inside the independent artifact verifier and then call that a
public release test. A case intentionally outside the supported executor must
prove refusal, not an invented successful upgrade.

## Fault seams and checkpoint observations

### Transport and storage

Metadata transport, OCI pull and configured-origin routing are separate seams.
Use a guest-scoped transport rule or disposable dependency to deny only the seam
being tested. Record when it was enabled/removed and whether bytes were cached.
For a pull failure, confirm the selected target was not already locally
available. Never remove the running source image or broadly prune a Docker host
to create an unwarmed target.

Capacity tests use a dedicated disposable filesystem or quota with retained
guest boot/log headroom. Do not fill a shared root filesystem. Distinguish
application backup storage, host custody/state storage and Docker image storage;
an error in one does not establish behavior in the others. State-disk failure
can prevent further journal commits, so an unavailable status response must
remain unknown until independently inspected. Denying an offsite mirror is not
proof of local archive corruption, independent offsite custody or durability.

### Writers and migration settlement

Use a synthetic application job/request or a controlled PostgreSQL session to
create a real busy-writer condition. A paused Docker container may exercise
container-state refusal, but does not prove graceful completion of a running
application job. Record worker/process identity and the database session's
actual settlement; a closed client connection does not prove its server-side
command stopped.

Observe the actual public checkpoint and operation UUID before intervention.
For deterministic interrupted migration, a disposable database lock can hold
the target's real migration transaction while the journal records
`migration_intent`. Record both facts before the interruption; missing them
means that injection did not prove the intended window. A failure with
`mutation_started = true` remains schema-ambiguous even if the migration table
appears unchanged. After the hold is released, count target migration execution
and compare immutable receipts rather than inferring that it ran only once.

The helper names one-shot backup and migration containers by operation UUID.
Observe their actual running/created/paused/exited state before recovery. Killing
a Compose client, restarting systemd, or timing out an RPC does not establish
that the Docker daemon's command is terminal. Do not remove those containers to
make a recovery check pass.

### Helper interruption and guest reboot

Capture a checkpoint before a controller requests helper interruption or an
operator reboots the guest; capture the real post-intervention state independently. Report a
graceful helper restart, process crash, guest reboot and abrupt power loss as
different interventions. A helper restart or ordinary guest reboot does not
prove crash/power-loss durability. Checkpoints can advance between observation
and interruption: retain the last observed checkpoint and the first recovered
checkpoint, and mark the intended injection window inconclusive when they do
not support it.

For helper/VM restart observations, bind the before and after journey to the
same operation UUID, request UUID and plan ID, with advancing journal revision.
A changed global generation without the same operation's evidence cannot
establish reconciliation.

Restarting the helper preserves active ownership and records
`reconciliation_required` for preparation or `recovery_required` for an
operation with protection/apply evidence. Preparation reconciliation, protection
recovery and apply recovery are distinct host actions. Recovery must verify
command settlement, identities and ownership before changing services. Neither
the controller nor an operator may clear a journal/marker, reset migration flags,
use ordinary maintenance release, or force-kill a writer to force a terminal
success. The runner's bounded interruption controller is an evidence tool, not
an automatic recovery agent.

## Outcome checks independent of the dialog

A successful update requires the exact accepted target identity and
`serving_verified`, verified migration/runtime receipts, committed
configuration, retained protective custody and release of this operation's
hold. Independently observe all five application roles (`web`, `queue`,
`backup-queue`, `scheduler`, `reverb`), PostgreSQL and Redis; role-specific live
processes; fresh target container IDs; expected mounts; and the configured origin
under held and released conditions. Exercise a queued synthetic job and the
authenticated realtime/support path after release. A process list or open port
does not establish completed work or message delivery.

The held state must fence new writes from HTTP/API/widget, inbound integrations,
queue workers and scheduled work; Reverb belongs in the host drain. Check this
while preserving a deliberately active writer for the long-job case. The hold
has no bypass cookie or automatic expiry. A `failed_safe` or `cancelled` result
requires independently verified previous-source serving evidence with no
possible schema mutation. A `recovery_required` result is a valid conservative
failure posture, but it is not successful recovery or permission to begin a
second operation.

The HTML dialog, one HTTP status, an accepted asynchronous request, a changing
timer, archive creation, validator success and a passing local test are each
intermediate evidence. None independently establishes the complete outcome.

## Separate restore drill

Keep the original operation and retained archive intact. Transfer only to a
second fresh disposable environment, using private custody for configuration,
effective current/previous keys and the latest erasure ledger. Do not publish
these private files as CI artifacts. The protective archive excludes the keys
and the separate erasure ledger; an offsite existence/size receipt does not
replace their custody or verify remote attachment bytes.

The restore environment must have a different environment ID and retain a
receipt binding the exact archive SHA-256 to the source operation. Verify the
archive exists and was retained separately from proving that restore completed.
Restore onto a schema-compatible release, preserve/reapply the latest erasures,
then test decryption, support records, local/remote binaries, identity and
configuration. Keep the restore VM's host-helper enrollment and credential
separate from the original host. Include an erasure made after the snapshot so
an older archive cannot silently resurrect the erased subject. Document how certificate/proxy
configuration was privately transferred and which endpoint was actually tested.

Ordinary application restore refuses while the managed hold is active. The
independent restore drill must not bypass the original VM's owned maintenance
state, clear its marker, or overwrite its journal. No successful disposable
restore establishes automatic post-migration rollback, arbitrary downgrade
safety, production restore readiness, offsite durability or mail delivery.

## Recording results and remaining gates

The [managed-update VM runner](../../scripts/smoke/managed-update-vm.py) has six
commands. Its [synthetic tests](../../scripts/test_managed_update_vm.py) verify
control boundaries without exercising Linux privileges or a real VM.

| Command | Implemented effect |
| --- | --- |
| `preflight` | Read public declarations for the exact `--source`, `--target` and optional distinct `--helper-release`; write an exclusive `--output` receipt and optional `--evidence-output` scaffold. It does not access a VM or Docker. A ready declaration is still unqualified. |
| `adopt` | With `--ack-disposable`, verify the actual baked source identity, five distinct running canonical services on that image, no active operation and authenticated helper identity. Independently bind the exact published helper files and any completed supported helper-upgrade receipt before writing a root-private source/target/helper marker. Isolation remains an operator attestation. It does not provision or enroll the installation. |
| `snapshot` | Capture the validated journal, authenticated helper identity when available, and minimal identities/running states for the five application services. It does not execute origin, runtime or data-survival checks. |
| `interrupt-helper` | Wait within a bounded interval for the exact owned operation/checkpoint, retain an interruption-intent receipt, and signal only the fixed helper service. Existing systemd restart policy may restart the helper. This command does not recover it or terminate application containers. |
| `checkpoint-reboot` | Retain an owned operation observation before a separately requested guest reboot. It does not reboot the VM. |
| `resume-reboot` | Compare the retained checkpoint with the same installation after a real changed boot ID, executor generation and advanced journal. It does not perform recovery or claim data survival. |

The runner never enrolls, starts an update, reboots, restores, clears a hold or
reports qualification. Use its help from the repository root:

```bash
python3 scripts/smoke/managed-update-vm.py --help
python3 scripts/smoke/managed-update-vm.py preflight --help
python3 scripts/self-host/update_vm_evidence.py --help
```

The matrix does not imply that every fault is automated. Evidence collection
needs actual observations from additional procedures for cases beyond those
controls. Keep VM receipts in private trusted root-owned directories; the
portable public preflight receipt does not authorize a privileged action.
The running helper version comes from its authenticated local status response,
with a root socket peer, nonce and HMAC verification. The observer distribution
version is recorded separately. An offline journal remains readable with
unverified helper identity and cannot authorize a fault injection.

### Adopting a source with a separately upgraded helper

An application release and its installed helper can have different published
source tags. A `v1.2.0` application initially enrolls helper `0.4.0`; after the
separate supported replacement, the application still reports `v1.2.0` while
the authenticated helper reports `0.5.0`. Do not replace the application identity
or reenroll to make those versions appear identical.

After publishing and independently verifying the selected helper distribution,
pass its exact stable tag as `--helper-release` and the completed replacement
receipt UUID as `--helper-upgrade-transaction` to `adopt`. Both flags are required
for this distinct-helper case. Use the receipt from that installation's actual
root-only upgrade. The runner verifies the selected release, source-tree blobs,
complete installed module hashes, source enrollment inputs, completed receipt
and transaction-derived retained old generation. It requires no pending
replacement/stop gate and verifies the current root systemd process through the
authenticated socket peer PID.

The private adoption marker uses schema `2`. Subsequent VM controls recheck its
local generations and receipt without repeating public metadata requests. The
receipt's startup generation is historical; a legitimate helper restart creates
a new authenticated generation. A display version alone cannot admit the host.

Public metadata reads are bounded and unauthenticated. Guests behind one NAT
share GitHub's public API allowance; retain each adopted installation's own
snapshot between fault scenarios instead of repeatedly adopting it. A transport
or rate-limit refusal is a missing prerequisite, not permission to relax checks.

The runner accepts the actual `accepted` and `prepare_started` checkpoints before
source/target/plan resolution. Those observations bind the requested release,
operation/request UUIDs, executor generation and journal revision and require
null unresolved identities with no mutation. Protection checkpoints such as
`fenced`, `drained` and `backup_verified` require the resolved plan and protection
record. Observations do not constitute an atomic pause or prove that an
intervention hit its intended window.

### Validating the sanitized report

Validate the sanitized report with the
[managed-update evidence contract](../../scripts/self-host/update_vm_evidence.py)
before attaching it to #1115. The validator takes a report path and exits `0`
only for a complete qualified report, `2` for valid incomplete/blocked evidence,
and `1` for invalid input. Its
[tests](../../scripts/test_update_vm_evidence.py) contain synthetic fixtures,
which must never be submitted as actual VM observations.

The validator checks structure and consistency of supplied
observations. It cannot independently prove that a report's VM facts are true,
that a published image was pulled, or that a recorded restore really occurred.
Retain the run commands, exit codes, logs, hashes and independent observation
receipts needed to review those claims. A missing prerequisite, blocked
scaffold or incomplete matrix must remain visibly unqualified.

U8 gates managed-update qualification and rollout until the actual published
matrix, a real same-guest reboot and the second-environment restore have evidence.
The first compatible source must be published before that matrix can run; its
publication does not clear this gate. Follow the separate
[release-readiness sequence](managed-update-release-readiness.md). U9 acceptance,
human review and production use remain separate gates.
