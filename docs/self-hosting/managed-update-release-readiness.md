# Managed-update release readiness

This records the release and qualification sequence prepared on October 9,
2026 for the U1–U8 managed update work in
[epic #1107](https://github.com/adamgreenwell/wayfindr/issues/1107).
It records current public/source/ordinary-recovery results and preserves the
original preparation sequence. It does not authorize a tag, publication, merge,
deployment or enrollment of a shared installation. Follow [RELEASING.md](../../RELEASING.md) for the
actual cut procedure and the [U8 matrix](managed-update-qualification.md) for
independent VM evidence.

## Current publication: v1.3.0 and helper 0.5.0

Public [`v1.3.0`](https://github.com/adamgreenwell/wayfindr/releases/tag/v1.3.0)
is frozen at `1bae329c4e349017716827dd0ce26b9ba7f9f2ac`.
[Exact-main CI 38056674067](https://github.com/adamgreenwell/wayfindr/actions/runs/38056674067)
and [guarded publisher 38057466650](https://github.com/adamgreenwell/wayfindr/actions/runs/38057466650)
passed. Independent readback verified the public Release, manifest/digest assets,
both native architecture OCI chains, and the `1.3.0`, `1.3` and `latest` aliases.
The [sanitized publication receipt](evidence/managed-update/2026-10-10-v1.3.0-publication.json)
records their exact identities and the separately published helper `0.5.0`,
protocol 1, five-module bundle and upgrade CLI bytes.

This supplies a real `v1.2.0` → `v1.3.0` minor-release pair and the published
Docker 29 image-identity correction. An enrolled 1.2.0 host must first use the
[supported helper upgrade](managed-updater.md#upgrading-the-installed-helper);
pulling the application image does not replace host helper code. Native
installation, helper replacement, managed protection/apply/fault recovery and
separate-VM restore remain independent gates. Publication alone qualifies zero
managed scenarios. U8/#1115, U9/#1116, #1131 and epic #1107 remain open.

No operator action or migration is required for 1.3.0. Its standing backup-queue
notice remains. Development `main` is now `1.4.0-dev` after
[PR #1136](https://github.com/adamgreenwell/wayfindr/pull/1136), merge
`a61445dcf5d67713f61d082cd389876b1d9f2e46`; all five
[exact-main CI jobs](https://github.com/adamgreenwell/wayfindr/actions/runs/38059773227)
passed. This version change did not publish another release.

## Previous v1.2.0 publication, ARM64 source and ordinary recovery

The final 1.2.0 source is `56374e9574ed84616aae430d06589cfe2f0b33a0`.
[Exact-main CI 38006329309](https://github.com/adamgreenwell/wayfindr/actions/runs/38006329309)
passed. [Guarded publisher 38007100329](https://github.com/adamgreenwell/wayfindr/actions/runs/38007100329)
passed. Independent public readback verified the Release, manifest, OCI index,
both amd64/arm64 platform/configuration chains and aliases. The Release
published October 10 at 00:26 UTC, October 9 at 20:26 EDT; its notes keep their
October 9 date. The U1–U8 implementation and follow-up fixes are merged;
publication remains separate from VM qualification.

| Independent result | Verified status |
| --- | --- |
| Public `v1.2.0` Release and attached manifest/digest assets | Verified; manifest 1,111 bytes, SHA256 `2f07858a050a4c602500247d9d27ac7ad01b09e612a9425d5148ae4442aa92f4` |
| OCI index and both amd64/arm64 platform manifest/config | Verified; [exact identities](evidence/managed-update/README.md) |
| Aliases and GitHub latest at the 1.2.0 publication | Verified index `sha256:052a2897b503ebfdec4cfba232d2893cd48f3cdadeb3478c4161627d38e01683`; GitHub latest `v1.2.0` |
| ARM64 never-started-image baked version/commit/manifest/history proof | Verified; exact public config/rootfs, no start or mounts, named UID/GID 1000 |
| Actual ARM64 published-source install, supported enrollment, idle restart/reboot | Passed; [source/ordinary-recovery receipt](evidence/managed-update/2026-10-10-published-source-and-ordinary-restore.json), private HTTP/nonce/API; same container IDs and autonomous idle reboot |
| ARM64 ordinary archive and separate-VM restore with a real post-archive erasure | Passed on a clean, unenrolled guest without force; exact positive SYSTEM replay, survivor/file/decryption/sequence checks |
| Target-bound matrix at this earlier observation | Blocked on a newer published target and installed Docker 29 fix; 1.3.0 publication is now verified above, but native qualification remains separate |
| Historical `1.3.0-dev` reset after 1.2.0 | Separate `VERSION` change `bcb5474a`; required actions empty and standing notices preserved |

No operator action is required for 1.2.0. The single additive migration
`2026_10_09_180000_add_managed_update_event_key_to_audit_events.php` adds a
nullable unique audit-event key and runs automatically. Unenrolled installs
retain their update path. Optional root-owned enrollment is a separate choice.
U1–U7/#1108–#1114 are implementation-delivered; actual managed VM and independent
operator acceptance remain in U8/#1115 and U9/#1116, with epic #1107 open.
The accepted ARM64 source receipt covers official installation, optional
enrollment, authenticated helper protocol 1/version 0.4.0, idle helper restart
and actual autonomous idle guest reboot. All five application roles passed
PostgreSQL/production Redis/runtime checks, with 80 migrations and zero failed
jobs; private WebSocket nonce and authenticated API/operator checks passed again
after reboot. The same eight container IDs and credential/config/source
fingerprints survived, with a new helper generation and zero operations.
Ordinary separate-VM restore also passed from the fixed pre-erasure archive,
with the real nonempty settled ledger installed before invocation, retained
effective key before first start and an empty unenrolled baseline without force.
Exact positive SYSTEM replay, surviving logical rows/local binary/settings and
decryption, ticket scrubbing and sequence/new API contact checks passed. There
was one current key and no previous keys; rotation was not exercised. Erasure
used the production service, not dashboard confirmation. These results do not
establish TLS, user-session broadcasting authorization, remote storage, managed
protective custody/apply or active-upgrade reboot.

The ARM64 baked probe observed Docker 29/containerd storing the OCI index digest
as local image `.Id` and container `.Image`. The separately verified public
configuration digest is not a usable local image address on that guest. Full
Config/rootfs equality and never-started baked bytes proved the source identity,
but the published managed artifact verification/apply paths assume a configuration-digest
local image identity. [#1131](https://github.com/adamgreenwell/wayfindr/issues/1131)
was a concrete execution blocker in helper 0.4.0. The correction and target
are now published in 1.3.0; actual installed-helper and managed execution
observations remain separate. No managed operation was attempted in this earlier run.

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

The now-published `0.5.0` helper implements distinct public/local image binding,
selected-container-manifest checks and a separate exact-byte `0.4.0` → `0.5.0`
[helper upgrade procedure](managed-updater.md#upgrading-the-installed-helper).
Its review and contract tests are implementation evidence. Actual native
ARM64/Docker 29/containerd
[earlier candidate helper recovery and current-release artifact-component checks](evidence/managed-update/2026-10-10-docker29-helper-recovery.json)
passed. The fault interrupted explicit recovery of a retained candidate
transaction; subsequent recovery of that same transaction completed replacement.
The separate [merged-helper lifecycle receipt](evidence/managed-update/2026-10-10-helper-lifecycle-rehearsal.json)
records a passed clean uninterrupted upgrade and same-transaction recovery after
the unmodified CLI's kernel-confirmed atomic exchange and SIGKILL. One new
ARM64/containerd VM was restored only from its own clean published-source
snapshot between those two trials. Application containers, authority, journal,
synthetic rows/binary and fresh-process decryption were preserved. The CLI was
killed at successful syscall exit before the parent-directory fsync; this
same-kernel process-crash result establishes neither power-loss nor reboot
durability. Historical terminal managed-apply custody remains unexercised.
The published `v1.2.0` helper remains `0.4.0`; a newer application image alone
does not upgrade it. The reviewed distribution and compatible application target are now published;
the 100-case native architecture/image-store qualification matrix remains a
separate gate. These helper trials created no managed operation;
managed qualification remains false with zero managed scenarios. #1131, U8/#1115,
U9/#1116 and epic #1107 remain open.


## Preparation record before the first compatible publication

The candidate was **1.2.0**, as recorded in `VERSION` at preparation. Adding an
optional host updater does not make an existing operator install a new service:
ordinary unenrolled Docker and host installations retain their update paths.
The proposed verdict is **No operator action required**, with the additive audit
event migration running automatically. Keep `release.json` actions empty unless
review discovers a genuine required change. Optional enrollment has its own
root-owned setup and recovery responsibilities; it is not a prerequisite for
upgrading Wayfindr. This follows [ADR 0012](../decisions/0012-platform-versioning.md).

The 1.2.0 notes were reconciled on October 9, 2026 against both
merge and non-merge commits after `v1.1.1`, the U1–U8 stack, and the follow-up
compatibility/realtime, lease, Redis and boot-order fixes, plus the final
development-only source-map patch. The frozen declaration and retained history
agree with the final release source. Draft branch contents are not
evidence that those changes reached `main` or a published image.

## What is already established

The [committed public-artifact gate](evidence/managed-update/README.md) checked
the real `v1.1.0` → `v1.1.1` span. Release/tag identities, manifest bytes and
published digest assets verified; neither source tree contains the updater
contracts. Its report is **blocked**, `qualified: false`, with **zero executed
VM scenarios**. Those releases cannot become managed-compatible by downloading
new helper code.

Synthetic tests and CI cover the new contracts, journal, holds, backup custody,
apply/recovery ordering, authorization, progress UI and evidence validator.
They do not establish systemd behavior, actual Unix-socket mounts, host UID
mapping, guest reboot, public WebSocket delivery or recovered data on an
installed VM. A successful candidate build likewise remains distinct from a
published artifact and an accepted installation.

## Artifact and enrollment boundary

| Component | Where it comes from | Required identity |
| --- | --- | --- |
| Laravel app, managed commands and operator UI | Official multi-architecture application image | Exact release tag and commit; OCI index, selected platform manifest and configuration digests recorded separately |
| Release manifest and retained history | Baked under `/etc/wayfindr`; public manifest/digest assets and committed history independently compared | Selected release declaration, commit and complete supported upgrade span agree |
| Host helper, enrollment script, systemd unit, tmpfiles rule and overlay template | A separately reviewed, root-owned distribution of the exact source release | Record source commit and file hashes; base `compose.yml` and guarded `install.sh` must match the installation byte-for-byte |
| Enrollment identity, credentials, journal and private custody | Created on the dedicated host by explicit enrollment | Host-owned authority for that installation; never copied from another VM to imitate enrollment |

The application image does **not** bake or install the host helper, systemd unit
or enrollment documentation. Enrollment does not download them automatically.
Stage the reviewed source distribution in a root-owned location such as `/root`
and the installation separately, typically `/opt/wayfindr`. All required files
and ancestors must meet the ownership, mode and symlink checks in
[managed-updater.md](managed-updater.md#supported-enrollment).

The first compatible source freezes **helper 0.4.0, protocol 1** behavior.
There is no need to invent a helper version bump merely
to create a test pair. Managed apply changes the application image and reviewed
overlay; it does not replace the installed helper. The published `v1.2.0`
distribution's `0.4.0` helper has no helper-replacement command. Credential
rotation and unenrollment remain unimplemented. A later app image cannot repair
a defect in an already enrolled host helper; helper replacement needs its own
reviewed distribution.

Enrollment requires Linux/systemd, Python 3.11+, fixed system Docker/Compose,
matching amd64 or arm64 architecture, the canonical Compose project and all five
running application services using the same official stable image with host
UID:GID 1000:1000. Custom, floating or development images, rootless Docker, user
namespace remapping, unsupported ownership and customized Compose remain
ineligible. Source-build and externally managed Docker update guidance must stay
available without silently enrolling those installations.

## Work before the first publication

Use fresh, dedicated Ubuntu 24.04 VMs with only synthetic data and disposable
dependencies. Record guest identity, boot ID, architecture, tool versions,
candidate commit and actual image digests. Keep a second fresh VM for restore;
a restart of the original stack is not a restore drill. A developer Docker
daemon or shared server is not a substitute.

Pre-publication rehearsal can run the Linux contract tests, including real
socket peer-credential checks, and exercise a candidate source-build image's
fresh-install runtime, application commands, support loop and ordinary
backup/restore behavior. Identify that image as a development build and record
its actual digest. These observations may reveal integration defects, but must
not be labelled published-artifact qualification.

Current enrollment deliberately refuses that development/custom image. Do not
give it an official stable identity, fabricate a public release, or weaken
enrollment to manufacture a passing run. Supported managed enrollment,
systemd/socket integration and managed source-to-target execution remain gated
on real compatible published releases.

These integration checks belong in the reviewed first source:

- Validate the independently verified immutable target's protocol before
  fencing or stopping source writers, then recheck before schema admission.
  An early incompatible or unsettled probe retains active manual attention as
  `recovery_required` without inventing a hold or a verified `failed_safe`
  outcome. The source remains untouched; explicit root recovery is separate.
- Verify authenticated private-event delivery over the configured public
  WebSocket transport before reporting target success or verified source
  recovery. A Reverb process or local listener is insufficient. This transport
  probe signs an ephemeral private channel directly while PHP intake is held;
  it does not prove a user's `/broadcasting/auth` session or the full support
  loop. Those require separate post-update acceptance on the VM.
- Prepare the socket directory before Docker restores web's required bind
  during normal systemd boot. A fixed root-owned tmpfiles rule creates only the
  empty directory; the helper remains after Docker and preserves that inode
  across service restarts. An isolated service/container probe on a real VM
  reproduced the missing-directory failure after an actual guest reboot.
  Corrected boot behavior and actual
  enrolled published-source recovery remain separate evidence gates.

These checks have no managed VM qualification merely because their synthetic regression
tests pass. Retain the observed failure/recovery behavior independently of the
operator dialog.

## Publication gates and remedies

This table is a future procedure. None of its publication or VM actions is
claimed as executed by this document.

| Gate | What must be true before advancing | Remedy for a missing gate |
| --- | --- | --- |
| Final implementation | Reviewed U1–U8 changes and follow-up fixes are merged in dependency order; required checks pass for each current head | Finish review and exact-head validation; do not publish a draft branch as the release |
| Candidate declaration | `VERSION`, frozen changelog verdict and `release.json` agree; release history includes the candidate's actual declaration | Reconcile all merged changes, freeze notes with the release commit's date and generate history without resetting actions before the tag |
| Local release contracts | Release contract, self-host, wiki and public-info checks pass; the publishing-mode contract passes after the release commit exists | Correct the candidate and rerun the affected checks before tagging |
| Protected release provenance | Live active `v*` tag rules restrict creation, update and deletion with owner-only bypass; no eligible pre-guard publisher can move aliases | Preserve the current audit; missing protection or a still-rerunnable unguarded publisher is a stop-before-tagging result |
| Main authorization | Full **Pull request CI** succeeded for the exact release commit on the main push event | Wait for that exact run or fix its failure; PR-only or an earlier SHA's green run is insufficient |
| Guarded publication | Stable `v$(VERSION)` identifies that exact main commit; the sole guarded publisher completes | Push only the authorized tag; a failed/partial publication stops next-cycle housekeeping |
| Public artifact readback | Manifest/digest assets, exact image, baked identity and public Release agree; mutable aliases are promoted only after verification | Investigate the failed hand-off; never rebuild or move a published immutable release identity |
| VM qualification | Actual install/enrollment/reboot/backup/restore and later upgrade observations satisfy their scoped claims | Keep missing scenarios blocked and U8 open; build/CI/publication success cannot stand in for VM results |

The guarded publisher is stable-only. It rejects a tag that differs from
`VERSION`, a commit outside `main`, missing publishing-mode release contracts,
or missing successful exact-SHA main CI before registry authentication or push.
It stages a draft manifest, publishes the exact image/digest, verifies the
public Release and then promotes aliases under serialized publication. There
is no supported prerelease publishing path to use as a rehearsal shortcut.
Use the detailed readback and immutable retry procedure in
[RELEASING.md](../../RELEASING.md), rather than a second publishing mechanism.

## First source, then a meaningful target

1. **Prepare the first source.** Finish the candidate and Linux rehearsals,
   preserve unresolved observations, review optional enrollment guidance and
   complete the publication gates above. Publish 1.2.0 only when its real
   contents justify that existing candidate and the release is authorized.
2. **Install the published source.** Use its exact public image and matching
   reviewed source distribution on a dedicated fresh VM. Independently verify
   baked identity, all services, configured origin and support loop. Enroll
   explicitly, activate the reviewed web-only overlay, and verify authenticated
   helper status, credential access and journal persistence.
3. **Exercise the source without an upgrade target.** Check idle helper restart
   and actual guest reboot, ordinary backup creation and a separate fresh-VM
   restore with the retained effective keys and latest erasure ledger. Preserve
   and verify synthetic conversations, settings, local/remote attachment bytes,
   logical installation identity, proxy configuration and certificates.
4. **Retain target-dependent blockers.** Preparing the current source release
   reports `no_update_required`; host protection requires a prepared newer
   eligible target. Therefore the first release alone cannot prove host
   protection rehearsal, reboot during an active managed upgrade or managed
   apply. Ordinary backups do not count as managed protective custody evidence.
5. **Select the next real release.** A genuine compatible application fix can
   justify a later patch; a genuine feature/schema addition can justify a later
   minor. Decide its number from its actual operator impact, retain the source
   helper compatibility and byte-identical supported base Compose configuration,
   and publish through the same independent gates. Do not publish an empty
   version or fabricate an operator action, floor or intermediate release to
   fill a matrix row.
6. **Qualify the actual span.** Freeze both public identities and all digests,
   run target-bound protection rehearsal on one prepared operation, then use a
   new operation for apply with a fresh continuous protective backup. Exercise
   the applicable transport/storage failures, browser disconnection,
   duplicate/conflicting requests, long writers, helper interruption, active
   guest reboot and migration uncertainty. Independently verify serving,
   authenticated realtime delivery, data survival and the exact retained
   archive's separate-VM restore. A completed protection rehearsal cannot be
   reused as the apply backup after writes resume.

## Partial qualification remains explicit

The U8 contract requires **25 distinct scenario identifiers**, not 25 aliases
for one successful update. One patch span cannot also establish a minor span;
two releases do not provide a skipped intermediate release. A major-action
scenario needs a real published action plus both the refusal and fulfilled
upgrade. Floor and old/new helper compatibility cases likewise need actual
published prerequisites; manually replacing helper files does not establish a
supported replacement workflow.

Record each real observation and leave unavailable scenarios blocked with the
missing prerequisite. The strict evidence validator must continue reporting
the overall run unqualified until the complete required evidence exists.
Do not invent a globally qualified report for a useful partial run, or close
[U8/#1115](https://github.com/adamgreenwell/wayfindr/issues/1115) because the
harness, first release or one span passed. U9 rollout policy and production
enablement remain separate decisions after the scoped results are reviewed.
