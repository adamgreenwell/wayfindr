# Disposable VM Evidence

[Back to Home](Home)

Use disposable VM evidence when a clean install, upgrade, backup/restore,
rollback, or reboot claim needs proof from a fresh self-hosted environment.

The authoritative contract lives in
[Disposable VM Evidence Contract](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/disposable-vm-evidence.md).
It defines the minimum VM matrix, isolation rules, public-artifact rules,
scenario checklists, redaction rules, and report template.

## Short Version

- Use at least two fresh Ubuntu Server 24.04 LTS VMs for the current cycle:
  one clean install VM and one upgrade VM.
- Prove public artifacts, not local checkouts or leftover Docker state.
- Do not reuse volumes, generated `.env` files, images, backups, or databases
  between evidence runs.
- Record release tag, manifest URL, commit SHA, image tag, image digest,
  commands, exit statuses, and dated observations.
- Redact secrets, provider identifiers, private infrastructure names, and real
  support data.
- Mark partial evidence as partial; do not stretch a clean-install pass into an
  upgrade or restore claim.

## Related Pages

- [Installation](Installation)
- [Upgrading](Upgrading)
- [Backup, Restore, and Rollback](Backup-Restore-and-Rollback)
- [Runtime and Operations](Runtime-and-Operations)
- [Security and Privacy](Security-and-Privacy)
