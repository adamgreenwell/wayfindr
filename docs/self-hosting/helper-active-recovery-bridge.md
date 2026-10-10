# Candidate bridge for the published helper's process-listing failure

This is **unpublished development source** under
[#1140](https://github.com/adamgreenwell/wayfindr/issues/1140). Offline checks do
not establish native acceptance, a release, application recovery or completed
managed-update qualification. Do not use this candidate on a production host.
The [normal helper replacement](helper-0.6-candidate.md) remains idle-only.

An ARM64/containerd rehearsal completed two read-only preservation baselines,
then refused during inspection with `partial_state`, before a bridge transaction,
helper kill or code exchange. The original candidate did not recognize the
qualification marker already created by the supported VM runner. The correction
below has offline coverage; native bridge acceptance is still pending and managed
qualification remains **0/100**.

## One narrowly admitted state

`scripts/self-host/bridge-updater.py` provides a separate root-only action for
the exact public helper 0.5.0 bundle, with protocol 1, when an application apply
stopped at its initial read-only baseline. The published Docker process-listing
command can fail there before creating executor custody. Ordinary helper
replacement refuses this active operation; ordinary application recovery can
encounter the same baseline failure.

The bridge admits only a single operation already in `recovery_required`, at
checkpoint `apply_started`, with executor 0.5.0 and the exact seven-event
prepare/start/failure history. It requires the public v1.2.0 source and v1.3.0
target bindings, both operator prepare/start receipts, no cancellation, no
schema mutation, no owned hold, no archive and no apply/protection executor
directory. Missing directories alone do not authorize replacement. Changed or
unknown helper bytes, a different checkpoint/history, pending commands,
additional helper processes, maintenance and an unverified source refuse.

The action invokes the corrected candidate's full `Protector.baseline` through
a fixed read-only command boundary. It verifies the source image, rendered and
effective environment, keys, storage binding, upgrade-window status, five
application roles, PostgreSQL/Redis and the completed storage-init container.
Full container configuration, mounts and stable process facts are bound
privately; mount ordering alone is normalized. It never invokes the executor's
initial/recovery methods, pulls an image, stops an application container,
creates a backup, changes application configuration or runs a migration.

## Review and retain the binding before replacement

Use an independently reviewed immutable successor distribution with canonical
root-owned ancestors and files. Review the bridge CLI and its sibling normal
lifecycle CLI as well as the complete five-module 0.6 bundle. A branch name,
reported version or digest freshly supplied by an untrusted tree is insufficient.
The service must retain its canonical owned helper-upgrade gate. The same Linux,
systemd, unified-cgroup freeze/kill and atomic-exchange requirements as the
normal lifecycle apply; there is no stop/thaw fallback for an actually frozen
unverified helper.

From that reviewed tree, the read-only inspection command is:

```bash
/usr/bin/python3 scripts/self-host/bridge-updater.py inspect-apply-bridge \
  --operation="$BRIDGE_OPERATION"
```

`BRIDGE_OPERATION` must already contain the exact retained application operation
UUID. Inspection returns its canonical `journal_state_sha256`; it explicitly
reports `baseline_verified = false` and `helper_replacement_started = false`.
It is an eligibility observation, not authorization to skip the full baseline.
Retain the original private journal and independently review the source/runtime
preservation evidence before proceeding on a disposable qualification host.

After setting `BRIDGE_JOURNAL_SHA256` to that reviewed journal binding and
`BRIDGE_BUNDLE_SHA256` to the independently reviewed 0.6 bundle digest:

```bash
/usr/bin/python3 scripts/self-host/bridge-updater.py bridge-apply-recovery \
  --operation="$BRIDGE_OPERATION" \
  --expected-journal-state-sha256="$BRIDGE_JOURNAL_SHA256" \
  --expected-bundle-sha256="$BRIDGE_BUNDLE_SHA256"
```

The bridge creates a separate schema-2 `active_apply_helper_bridge` transaction
at the existing admission-barrier path. Schema-1 normal helper transactions
cannot be adopted by this action, and the normal CLI cannot adopt a bridge.
An interrupted 0.4-to-0.5 transaction must first finish through the separately
pinned [legacy procedure](helper-0.6-candidate.md#finish-an-interrupted-04-to-05-helper-transaction-first).

## What completion preserves

The first authenticated source PID/start identity remains bound through the
frozen check. After confirmed whole-cgroup freeze, the bridge rereads the exact
journal and all source/runtime facts, proves no helper child or pending Docker
command remains, kills the frozen cgroup, observes it empty, stops the service
and acquires the original lifetime-lock inode. It saves and fsyncs the exact raw
journal before exchanging complete code generations. The journal must remain
byte-identical across that exchange.

The new helper boots behind the admission barrier. Only top-level `generation`
and `heartbeat_at` may change at boot; the entire remaining journal, including
global revision, every event, operator receipt and historical executor metadata,
must remain exact. New daemon authentication binds root socket peer credentials,
PID/start identity, HMAC, canonical service and unfrozen kernel state. The same
process is verified again before writing completion and immediately before
removing the transaction barrier. A failure retains the transaction even if an
earlier completion receipt exists.

Private retained evidence lives under
`/var/lib/wayfindr-updater/helper-upgrades/active-bridge-<transaction-uuid>/`:
the original raw journal, completed schema-2 receipt and recognized interrupted
write bytes. Every existing receipt/history entry, enrollment/configuration and
credential byte, runtime/state/lifetime-lock identity and application runtime
binding is preserved. The complete original 0.5 code generation also remains
retained. Ordinary idle inspection tolerates this custody after a separately
completed application recovery without adopting it as a normal upgrade receipt.

An existing VM runner attestation may be retained only as the exact root-owned
private `qualification/disposable.json` subtree with schema 2, a valid canonical
UTC date and the matching installation. This narrowly supported bridge case
binds the complete reviewed public v1.2.0/v1.3.0 release declarations and their
historical 0.4.0/0.5.0 helper declarations, including every source blob, image,
manifest, provenance file and historical upgrade CLI. Unknown nested fields or
substituted identity values refuse. These fixed public identities describe the
original supported runner adoption; they do not select the newly installed 0.6
helper or admit future release shapes. Its complete bytes and directory/file
identities must remain unchanged, including its historical helper and boot
bindings. The bridge does not use the marker as authorization, rewrite it or
re-adopt the VM. Missing, partial, unknown or changed entries refuse. An interrupted
record from the earlier candidate, which rejected this subtree, can recover only
while the subtree remains absent. The normal idle CLI is unchanged; do not delete
qualification state to bypass its own state checks.

A successful receipt reports `active_operation_preserved = true`,
`application_changed = false` and the freshly verified helper generation. The
application operation remains `recovery_required`. The bridge does not start or
complete application recovery; that needs its own explicit operator action and
preservation proof.

## Interrupted bridge recovery

Retain the transaction UUID and all private files. Do not delete markers, edit
the journal, reenroll, overwrite installed modules or rerun normal replacement.
After reviewing the retained state, use only the same distribution, operation,
journal binding, bundle digest and bridge transaction:

```bash
/usr/bin/python3 scripts/self-host/bridge-updater.py recover-apply-bridge \
  --transaction="$BRIDGE_TRANSACTION" \
  --operation="$BRIDGE_OPERATION" \
  --expected-journal-state-sha256="$BRIDGE_JOURNAL_SHA256" \
  --expected-bundle-sha256="$BRIDGE_BUNDLE_SHA256"
```

Recovery validates the authoritative transaction and exact complete old/new
generations. It never exchanges an already installed new generation back to the
old one. Recognized partial module, stop, journal-copy and receipt writes are
retained before repair; unrelated bytes, metadata or entries refuse. A fresh
verified boot updates the completion generation and retains any prior receipt.
An incomplete first transaction scratch with no complete authoritative record
remains an inspection boundary: recovery cannot infer authority from truncated
JSON. Preserve it; no helper kill or exchange has occurred at that boundary.

Offline modeled process-crash tests cover these transitions. They do not prove
native power-loss/reboot durability, qualify either architecture/store lane, or
repair a failed application's data. Existing historical qualification evidence
and its limits remain unchanged.
