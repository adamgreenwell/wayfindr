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
public-artifact evidence for clean install, supported upgrade, and recovery
skew-restore scenarios, then copy the sanitized result summary into the relevant
issue. The hosted recovery path uses
`recovery-latest-synthetic-skew-restore`: latest public release install, real
backup archive, synthetic older manifest identity, forced restore, migration,
and post-restore support-loop proof.

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
- August 11, 2026:
  [`upgrade-v0.2.0-latest-custom-backup-queue` passed](https://github.com/adamgreenwell/wayfindr/actions/runs/31535924025).
  The run started from `v0.2.0`, set `BACKUP_QUEUE=evidence-backups`, upgraded
  to `v0.3.2`, verified the support loop before and after upgrade, observed the
  backups-queue advisory during upgrade guidance, confirmed the advisory retired
  after the worker was observed, and completed backup/restore plus post-restore
  smoke checks.
- August 11, 2026:
  [`upgrade-v0.1.0-latest` passed](https://github.com/adamgreenwell/wayfindr/actions/runs/31536143352).
  The run upgraded directly from `v0.1.0` to `v0.3.2`, then completed runtime,
  support-loop, backup/restore, post-restore smoke, and restart checks.
- August 11, 2026:
  [`upgrade-v0.1.0-v0.2.0-latest` passed](https://github.com/adamgreenwell/wayfindr/actions/runs/31536145475).
  The run upgraded from `v0.1.0` to `v0.2.0`, then to `v0.3.2`, with runtime
  verification and support-loop smoke after each upgrade, followed by
  backup/restore, post-restore smoke, and restart checks.
- August 11, 2026:
  [`recovery-latest-synthetic-skew-restore` passed](https://github.com/adamgreenwell/wayfindr/actions/runs/31537984956).
  The run installed the latest public release, `v0.3.2`, resolved image digest
  `sha256:3fe112ca3d3f83efb1f4d00c401b8bf43cc706ec5bfddb05244be01b2fd8e660`,
  took a real backup, rewrote a copy of the backup manifest to simulate an
  archive from `v0.2.0`, asserted the `Version skew:` restore warning, ran
  migrations after restore, completed the support loop after recovery, then
  completed the normal backup/restore and restart checks. This proves the hosted
  warning/recovery path, not arbitrary cross-version archive compatibility.
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
