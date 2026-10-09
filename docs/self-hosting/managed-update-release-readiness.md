# Managed-update release readiness

This is the proposed release and qualification sequence for the U1–U8 managed
update work in [epic #1107](https://github.com/adamgreenwell/wayfindr/issues/1107).
It is a preparation record, not authorization to tag, publish, merge, deploy or
enroll a shared installation. Follow [RELEASING.md](../../RELEASING.md) for the
actual cut procedure and the [U8 matrix](managed-update-qualification.md) for
independent VM evidence.

The candidate remains **1.2.0**, as already recorded in `VERSION`. Adding an
optional host updater does not make an existing operator install a new service:
ordinary unenrolled Docker and host installations retain their update paths.
The proposed verdict is **No operator action required**, with the additive audit
event migration running automatically. Keep `release.json` actions empty unless
review discovers a genuine required change. Optional enrollment has its own
root-owned setup and recovery responsibilities; it is not a prerequisite for
upgrading Wayfindr. This follows [ADR 0012](../decisions/0012-platform-versioning.md).

The proposed Unreleased notes were reconciled on October 9, 2026 against both
merge and non-merge commits after `v1.1.1`, the U1–U8 stack, and the follow-up
compatibility/realtime checks. They must be reconciled again against the final
merged tree immediately before the release cut. Draft branch contents are not
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

The first published source freezes the currently unpublished **helper 0.4.0,
protocol 1** behavior. There is no need to invent a helper version bump merely
to create a test pair. Managed apply changes the application image and reviewed
overlay; it does not replace the installed helper. Helper replacement,
credential rotation and unenrollment are not implemented. A later app image
cannot repair a defect in an already enrolled host helper, so finish and review
the helper integration fixes before first publication.

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
