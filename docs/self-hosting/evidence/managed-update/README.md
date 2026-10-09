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
The development image refused supported enrollment. There was no published
source-to-target update, protective custody, helper fault/recovery or reboot
during an upgrade. Public DNS/TLS, browser acceptance, user-session
`/broadcasting/auth`, remote attachments and post-archive erasure replay remain
unverified. U8/#1115 and all applicable matrix gates remain open.
