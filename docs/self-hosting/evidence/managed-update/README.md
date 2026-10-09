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
