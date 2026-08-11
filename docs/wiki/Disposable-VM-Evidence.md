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

## Public-Artifact Evidence Workflow

The repository has a manual
[Disposable VM evidence workflow](https://github.com/adamgreenwell/wayfindr/actions/workflows/disposable-vm-evidence.yml)
for fresh GitHub-hosted Ubuntu 24.04 x64 runs. Use it to collect repeatable
public-artifact evidence for clean install and supported upgrade scenarios, then
copy the sanitized result summary into the relevant issue.

That workflow is helpful but deliberately bounded: it does not replace a
bare-metal VM reboot, rollback drill, real DNS/TLS, mail delivery, offsite
backup, or production restore posture. If the workflow proves only part of a
claim, record it as partial and keep the issue open.

## Related Pages

- [Installation](Installation)
- [Upgrading](Upgrading)
- [Backup, Restore, and Rollback](Backup-Restore-and-Rollback)
- [Runtime and Operations](Runtime-and-Operations)
- [Security and Privacy](Security-and-Privacy)
