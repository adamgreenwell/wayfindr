# Managed-update qualification evidence

## October 9, 2026: published-artifact gate blocked

The read-only preflight checked the actual public `v1.1.0` → `v1.1.1` patch
span. Both release/tag identities, release-manifest bytes and published image
digest assets verified. Neither immutable source tree contains the required
managed updater application/helper contracts. Both roles refused with
`helper_not_published` and exit status `2`.

- [Public declaration receipt](2026-10-09-publication-gate.json)
- [Blocked evidence scaffold](2026-10-09-blocked-evidence.json)

Commands executed from the repository root:

```bash
python3 scripts/smoke/managed-update-vm.py preflight --source v1.1.0 --target v1.1.1 --output /tmp/wayfindr-u8-public-gate.json --evidence-output /tmp/wayfindr-u8-blocked-evidence.json
python3 scripts/self-host/update_vm_evidence.py /tmp/wayfindr-u8-blocked-evidence.json
```

Both commands exited `2`; the validator classified **blocked**, with **zero
executed scenarios** and `qualified: false`. Choose fresh output paths for a
new run: the driver refuses to overwrite receipts.

No image was pulled, VM was provisioned, helper was enrolled/interrupted,
update was started, guest was rebooted, or archive was restored. These receipts
are public-declaration gate evidence, not VM, baked-image, update, backup,
recovery or restore qualification. They contain public artifact identities and
an observer-generated report UUID, without installation credentials or content.

The next published run needs compatible exact source/target releases and fresh
dedicated Ubuntu VMs. Use the [qualification matrix](../../managed-update-qualification.md);
retain independent backup creation and separate-VM restore evidence. U8/#1115
and the epic completion gates remain open.


## October 9, 2026: development candidate rehearsed on two VMs

The [sanitized candidate receipt](2026-10-09-candidate-rehearsal.json) records
actual observations from two independent Ubuntu 24.04.5 ARM64 Parallels guests.
The source checkout was clean at
`3b79fc5c76c37e4756fc11698ae731f0f3243575`. Its image retained the development
identity `1.2.0-dev+3b79fc5c76c37e4756fc11698ae731f0f3243575`; no stable build
version was supplied, and nothing was pushed to a registry.

On both guests, all five application roles used the same actual image ID.
PostgreSQL, the actual production Redis readiness guard, 80 applied migrations,
zero failed jobs, ordinary serving, signed private-nonce delivery through Caddy
and the authenticated API support loop passed. Independent readback confirmed
the current container states, baked identity and image linkage on both guests.
Linux updater fixtures passed 331 tests without skips, including socket peer
credentials. The checked-in real-UID lease fixture also passed all nine checks
against the candidate image's actual baked lease/gate classes.

An ordinary 19,642-byte archive was transferred with matching checksums and
restored on the separate VM using the retained effective keys. The retained
logical snapshots matched; one synthetic local attachment's bytes and one
encrypted operator setting verified. The erasure ledger was empty, so this
establishes no post-archive erasure replay. The source resumed ordinary serving,
and the restored application independently passed realtime and support-loop
checks. The restore VM's app-free baseline was snapshotted before this work;
the snapshot has not yet been reverted or qualified.

This rehearsal exposed and motivated the real Predis reply and cross-UID lease
fixes before publication. Build-network DNS delay, source-clone permissions,
process-list formatting and archive discovery were corrected in the private lab
harness; they are recorded separately from product defects. Raw private logs,
effective keys and synthetic content remain outside this repository.

The procedure used the source-build Compose files, the production
`ManagedRealtimeProbe` and `ManagedApplyCommand::verifyRedis` methods, the
checked-in `scripts/smoke/support-loop.sh`, and the ordinary backup/restore
commands described in [backup-restore.md](../../backup-restore.md). The private
harness supplied synthetic setup and readback assertions; it is not a second
managed-update driver. Reproduce on dedicated disposable guests, preserve the
actual development identity and record fresh guest, image, archive and snapshot
receipts. Later integration commits containing documentation or fixture changes
are separate source identities from this exact rehearsed candidate.

**Managed qualification remains false, with zero executed managed scenarios.**
The read-only admission check classified the development image as ineligible;
enrollment was not invoked. There was no published
source-to-target update, protective custody, helper fault/recovery or reboot
during an upgrade. Public DNS/TLS, browser acceptance, user-session
`/broadcasting/auth`, remote attachments and post-archive erasure replay remain
unverified. U8/#1115 and all applicable matrix gates remain open.


## October 9, 2026: required-bind startup proved across actual guest reboots

The [boot-order receipt](2026-10-09-boot-order-rehearsal.json) records a separate
isolated service/container probe on the same dedicated Ubuntu ARM64 VM. It uses
an independent socket responder and a PHP-only container from the exact
candidate image, with application UID:GID 1000:1000, a required read-only
`/run` directory bind and Docker's `unless-stopped` restart policy. No
application volumes, network access, enrollment credentials or updater
operation participate.

The original ordering passed its initial socket round trip, then failed after
an actual guest reboot. Docker attempted the bind at 4.800656 seconds, before
the helper service could start after Docker and create its runtime directory.
The container remained stopped with the original mount error through 167.38
seconds of uptime, although the helper and socket had become available. No
manual start or recreation occurred during that observation.

With the reviewed tmpfiles rule, normal boot setup created the empty socket
directory before Docker started. Only the rule's runtime pathname was changed
to keep the probe isolated. After a second actual guest reboot, the same
persisted container autonomously started, observed its empty bind before the
helper populated it, and completed a fresh socket nonce round trip before any
intervention. Subsequent helper stop/start preserved the directory's device and
inode and the container's identity/start time. There was no Docker override or
extra ordering unit. Initial boot timestamps were retained separately from the
later helper restart.

The matching enrollment fix installs only the exact reviewed fixed rule,
refuses preexisting rule/runtime artifacts, verifies root:1000 ownership and
mode 0750 before helper enable, and rechecks the directory identity after
authenticated startup. Interrupted enrollment retains its evidence for explicit
recovery. The probe establishes this boot bind prerequisite on normal systemd
ordering; it does not execute the actual enrolled helper/application protocol.
**Managed qualification remains false with zero scenarios.** Published-source
enrollment, idle helper/guest reboot and target-bound interrupted upgrades still
require their own observations. The original development candidate receipt
remains bound to its earlier source/image identity; this host enrollment fix is
validated separately.


## October 9 EDT / October 10 UTC, 2026: first compatible source published

The final 1.2.0 source is `56374e9574ed84616aae430d06589cfe2f0b33a0`.
[Exact-main CI 38006329309](https://github.com/adamgreenwell/wayfindr/actions/runs/38006329309)
passed. [Guarded publisher 38007100329](https://github.com/adamgreenwell/wayfindr/actions/runs/38007100329)
passed. Independent public readback completed at 00:29 UTC on October 10. The
Release published at 00:26 UTC, October 9 at 20:26 EDT; the notes retain their
October 9 date. The public Release is stable and not a draft, GitHub latest is
`v1.2.0`, and `1.2.0`, `1.2` and `latest` image selectors resolve to the same
verified OCI index.

| Public artifact | Verified identity |
| --- | --- |
| OCI index | `sha256:052a2897b503ebfdec4cfba232d2893cd48f3cdadeb3478c4161627d38e01683` |
| amd64 platform manifest | `sha256:5039c0be6e4d0250aadbaf12e4d3df47c7f073d476bf90575a83b3b4aeece8fa` |
| amd64 configuration | `sha256:2766ddb5bcefd2bcb69e6c5e3220eb8f91a17f31bce30b2fb2696b1b8488ccd4` |
| arm64 platform manifest | `sha256:afa4730dd6ad38c2710f6a08258104a94f200eb9df8931b53b59899fc175e15e` |
| arm64 configuration | `sha256:4bd909bcdd9bff20a3842a036134ebf550c5d2951aa501d53424dac00820e1cb` |
| [Release manifest](https://github.com/adamgreenwell/wayfindr/releases/download/v1.2.0/release-manifest.json), 1,111 bytes | SHA256 `2f07858a050a4c602500247d9d27ac7ad01b09e612a9425d5148ae4442aa92f4` |

Both public OCI configuration documents declare raw `WAYFINDR_VERSION=v1.2.0`
and the exact release commit; the manifest and OCI version label are canonical
`1.2.0`. These public configuration reads do not prove the bytes under
`/etc/wayfindr` or execute an installed application.

ARM64 never-started-image verification passed for baked `v1.2.0`, full
`56374e9574ed84616aae430d06589cfe2f0b33a0` commit, the byte-identical
1,111-byte manifest and canonical builder history.
The probe was created without starting, used no network or mounts, and verified
the named user/group UID/GID 1000. Local Config/rootfs matched the public ARM64
configuration; RepoDigest/Descriptor bound the official OCI index.

[Actual source and ordinary-recovery receipt](2026-10-10-published-source-and-ordinary-restore.json): official
ARM64 installation, supported optional enrollment, idle helper restart and
autonomous idle guest reboot passed. The tagged installed distribution and
helper code matched. All five application roles used UID/GID 1000 on the exact
official image; PostgreSQL, production Redis, PHP 8.4.26, 80 applied migrations
and zero failed jobs were verified. Authenticated application/helper
capabilities/status/history used protocol 1/helper 0.4.0; only web had the
read-only runtime mount.

Helper restart preserved container IDs, runtime-directory inode and
credential/config/source fingerprints with a new generation. Real idle guest
reboot preserved all eight container IDs, with seven services running and the
completed storage-init container. Initial read-only boot observations preceded
intervention; tmpfiles completed before Docker and helper startup. Private
WebSocket nonce and authenticated API/operator/runtime checks passed again.
No operation or hold existed. Exact probe ID/name absence was read back after
boot. These installed-VM observations are ARM64/private HTTP only; they do not
establish TLS or user-session broadcasting authorization.

Ordinary archive/restore on a separate clean, unenrolled ARM64 guest also
**passed without force**. The fixed pre-erasure archive is 21,391 bytes, SHA256
`6aeae349566289fdc766471aa2e4b12315e576015c02f8e160dac95029d28539`.
The real latest nonempty ledger (one committed entry) was installed before
restore. The effective current key was installed before first application
start, retaining fresh DB credentials and restore origin; there were no
previous keys, so rotation was not exercised.

The exact returned erasure receipt bound the restored row/account/site/public
key/lineage and positive SYSTEM replay audit event. One contact, conversation,
message, attachment and ticket scrub were verified; the post-archive source
erasure event was absent from the dump. Survivor logical rows/local binary,
persisted setting and encrypted setting decryption matched the pre-erasure
archive binding. Target rows/local binary remained absent, archived ticket
personal fields were scrubbed, and pending/unreadable/outstanding ledger work
was clear. The read-only sequence query and a distinct post-up API contact
proved erased IDs were not reused. Five official runtime roles, PostgreSQL,
production Redis, private nonce and authenticated API/operator checks passed.
Erasure used the production service, not the dashboard confirmation flow.
No preparation or synthetic receipt stands in for either actual VM run.

The actual Docker 29/containerd guest reports the OCI index digest as local
image `.Id` and container `.Image`, while the verified public configuration
digest is a separate identity and not a usable local image address. The initial
private probe stopped at that identity mismatch; a bounded probe correction
verified source identity without changing the published image or product.
Published managed artifact verification/apply retain configuration-digest assumptions,
tracked as execution blocker [#1131](https://github.com/adamgreenwell/wayfindr/issues/1131).
This is not evidence of an attempted managed operation. Private controller
refusals were retained: initial image/User assumptions, JSON stdout parsing
after actual enrollment success, and a 12-character versus full candidate-ID
comparison. Bounded controller corrections resumed the existing installation
without rerunning enrollment or manually rescuing the rebooted stack. These
verification issues do not establish a product reboot failure.

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


This first source is distinct from the development-candidate and isolated
boot-order receipts above. Source installation/idle reboot and ordinary restore
do not qualify target-bound protection, apply, interrupted migration or reboot
during an active update. Those require a real newer compatible published target.
Managed qualification remains **false with zero scenarios**. U8/#1115,
U9/#1116 and epic #1107 remain open. Preserve all preceding dated evidence and
append the reviewed actual result rather than rewriting its artifact or scope.


## October 9 EDT / October 10 UTC, 2026: Docker 29 helper recovery candidate

The [sanitized helper-recovery receipt](2026-10-10-docker29-helper-recovery.json)
records an actual interrupted-CLI recovery and artifact-component rehearsal on
a new disposable Ubuntu ARM64 VM with Docker 29.1.3 and the containerd image
store. The running application remained the exact public `v1.2.0` image. The
five-module candidate bundle came from
`6a9b834eb7c1d77efc3caa2f4cef0e974cbefc74`; the receipt binds its exact bundle
hash and the separately frozen helper-upgrade CLI hash. Earlier lab VMs were
preserved.

An explicit `recover` of the retained candidate transaction freshly froze the
published `0.4.0` helper and persisted a new `frozen_idle` record for that same
transaction. The external fault controller killed that
frozen helper through `cgroup.kill` and paused the upgrade CLI for 6.5 seconds,
beyond the service's five-second restart delay. The service remained inactive
with MainPID zero and the same executor generation. Its canonical
`Restart=on-failure` policy stayed unchanged; typed systemd metadata recorded
the effective `ExecCondition` exiting `1`, blocking automatic restart.

The controller then killed the paused CLI. An ordinary upgrade attempt refused
with `upgrade_unavailable`, `recovery_required: true` and the unchanged
transaction identity. Explicit `recover` with that same transaction UUID and
reviewed bundle completed the `0.4.0` → `0.5.0` replacement. This records recovery
after an actual CLI interruption; an uninterrupted fresh upgrade remains a
separate observation.

The published application's real PHP `HostUpdaterClient` authenticated the new
helper through protocol `1` and HMAC. Enrollment identity, credential and
configuration, state/runtime/lifetime-lock identities and durable journal
operations were preserved. All eight application container IDs stayed the
same: seven services remained running and storage-init retained exit `0`. The
complete old `0.4.0` code generation remained retained; the transaction and stop
marker cleared through the supported procedure.

On that same candidate, actual `Artifacts.prepare` and read-only retained
`verify` passed against independently downloaded public `v1.2.0` release
metadata and image bytes. Artifact schema `2` kept index, selected platform,
raw configuration and observed local image identities distinct. Complete
Config/RootFS comparison, the selected container manifest and baked
version/commit/manifest/history passed through the never-started, network-disabled
probe without installation mounts. No newer application target or managed
operation was created.

Native observations exposed legitimate `0700` code directories under a
restrictive root umask and systemd 255 automatic restarts skipping
`ConditionPathExists`. The reviewed candidate preserves the safe directory mode
and uses the verified `ExecCondition` guard. A private observer assertion also
initially reported `pending_not_refused`: it expected a dedicated pending reason
instead of the actual safe `upgrade_unavailable` refusal. Only the harness
continuation was corrected before explicit recovery; the frozen CLI and
transaction stayed unchanged. Private logs and host authority remain outside
this repository.

This is ARM64/containerd candidate helper-lifecycle and current-release component
evidence. The `0.5.0` distribution is not published; the public `v1.2.0` helper
remains `0.4.0`. Managed qualification remains **false with zero managed
scenarios**. A real newer compatible application release and all 100 native
architecture/image-store scenario cases remain required. This run does not
establish managed protective custody, application apply/migration, an
active-update reboot, native interruption during directory exchange, historical
terminal apply recovery, or the other native/store combinations. U8/#1115,
U9/#1116 and epic #1107 remain open.
