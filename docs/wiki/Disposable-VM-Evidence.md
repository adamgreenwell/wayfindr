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

## Current Hosted Evidence

- August 11, 2026:
  [`clean-install-latest` passed](https://github.com/adamgreenwell/wayfindr/actions/runs/31535388323)
  against the latest public release, `v0.3.2`. The run installed from public
  artifacts, resolved image digest
  `sha256:3fe112ca3d3f83efb1f4d00c401b8bf43cc706ec5bfddb05244be01b2fd8e660`,
  verified healthy services, ran migrations, completed the support loop, took
  and restored a backup, completed the support loop again after restore, and
  restarted the stack.
- This is still hosted-runner evidence. Use it as partial proof for the clean
  install and local backup/restore path, not as proof of a bare-metal VM reboot,
  rollback drill, DNS/TLS, mail delivery, offsite backups, or production restore
  posture.

## Related Pages

- [Installation](Installation)
- [Upgrading](Upgrading)
- [Backup, Restore, and Rollback](Backup-Restore-and-Rollback)
- [Runtime and Operations](Runtime-and-Operations)
- [Security and Privacy](Security-and-Privacy)
