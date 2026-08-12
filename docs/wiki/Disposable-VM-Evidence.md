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
skew-restore/image-rollback scenarios, then copy the sanitized result summary
into the relevant issue. The hosted skew-restore path uses
`recovery-latest-synthetic-skew-restore`: latest public release install, real
backup archive, synthetic older manifest identity, forced restore, migration,
and post-restore support-loop proof. The hosted image rollback/retry path uses
`recovery-latest-v0.3.1-image-rollback-retry`: latest public release install,
rollback to the previous schema-compatible `0.3.1` image, support-loop proof,
retry of the original image, and support-loop proof again.

For a real disposable VM reboot check, run the evidence installer with a
persistent `WAYFINDR_EVIDENCE_TARGET_DIR`, fixed synthetic credentials, and
`WAYFINDR_EVIDENCE_KEEP=1`; reboot the VM; then run
`scripts/smoke/public-artifact-reverify.sh` with the same target directory,
project name, app URL, site key, and synthetic agent credentials.

The repo also includes
[`scripts/smoke/disposable-vm-evidence-runner.sh`](https://github.com/adamgreenwell/wayfindr/blob/main/scripts/smoke/disposable-vm-evidence-runner.sh)
for bare-metal disposable VMs. It wraps the install and post-reboot reverify
scripts, keeps the stack running, stores synthetic reverify credentials on the
VM, tees a local command log, and writes a Markdown report skeleton that can be
reviewed and sanitized before posting to GitHub.

The bare-metal wrapper needs Bash, curl, Docker Engine with the Compose plugin,
grep, OpenSSL, sed, and tee. Host PHP is not a prerequisite because its PHP
checks execute inside the Wayfindr container. Generated synthetic addresses use
a bounded run-ID prefix, so even long upgrade scenario names remain valid.

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
- August 11, 2026:
  [`recovery-latest-v0.3.1-image-rollback-retry` passed](https://github.com/adamgreenwell/wayfindr/actions/runs/31539581605).
  The run installed the latest public release, `v0.3.2`, resolved image digest
  `sha256:3fe112ca3d3f83efb1f4d00c401b8bf43cc706ec5bfddb05244be01b2fd8e660`,
  rolled the stack back to `ghcr.io/adamgreenwell/wayfindr:0.3.1` at digest
  `sha256:36cdaf94f29372eab5b60a48eccc3ca40c3664afb9f3df01137a0a26a8941a8f`,
  verified runtime and support-loop health, retried the original `v0.3.2`
  image, verified runtime and support-loop health again, then completed the
  normal backup/restore and restart checks. This proves the current
  schema-compatible hosted image rollback/retry path, not arbitrary downgrade
  safety.
- The hosted matrix remains partial on its own. The owner-operated matrix below
  adds real bare-metal guest creation and reboot proof; neither matrix proves
  DNS/TLS, mail delivery, offsite backups, or production restore posture.

## Current Bare-Metal Evidence

On August 12, 2026, the owner ran the public-artifact contract on disposable
Ubuntu 24.04.4 x86_64 guests created on an owner-operated Hyper-V host. The
guests used fresh virtual disks, isolated synthetic identities, Docker Engine
29.1.3, Docker Compose 2.40.3, and a private local-only network. Reports were
copied off each guest before destruction; private addressing, secrets, support
codes, and host identifiers are not published.

- Clean installation was repeated on two independently created guests. Both
  pinned `v0.3.2` at commit `47a65ca330920630596bc84f08451d498894ae04`
  and image digest
  `sha256:3fe112ca3d3f83efb1f4d00c401b8bf43cc706ec5bfddb05244be01b2fd8e660`.
  The final clean run started with empty Docker state and no host PHP, completed
  setup, verified web, queue, backup queue, scheduler, Reverb, Postgres, Redis,
  migrations, the upgrade guard, and zero failed jobs, then passed the synthetic
  support loop, database-plus-attachment backup/restore, post-restore support
  loop, and full service restart.
- A real guest reboot changed the kernel boot ID. Docker restarted the entire
  stack without manual intervention; the public reverify runner then confirmed
  the same release/digest, healthy services, migrations, zero failed jobs,
  schedule, clear upgrade guard, and another complete support loop.
- The upgrade guest installed public `v0.2.0` with its own installer, used a
  non-default `BACKUP_QUEUE`, upgraded to public `v0.3.2`, observed the
  backups-worker advisory and its retirement, preserved the synthetic support
  path, restored the exact database marker and attachment bytes, and passed a
  real reboot reverify.
- A forced public-release discovery outage made the upgrade preflight refuse
  with exit `78` and HTTP `000` before mutation. The web container ID, image
  digest, migration-status digest, `/up` response, and zero-failed-job state
  stayed unchanged, and the previous release completed the support loop after
  refusal.
- The first clean attempt exposed an undocumented host-PHP dependency in the
  evidence preflight; [PR #707](https://github.com/adamgreenwell/wayfindr/pull/707)
  moved that work into the application container. The independent repeat then
  exposed the same assumption in the support-loop parser;
  [PR #708](https://github.com/adamgreenwell/wayfindr/pull/708) added the
  container PHP adapter. The passing final repeat used public `main` after both
  fixes, so these are closed findings rather than waived prerequisites.

The version-skew restore warning and `0.3.2` to `0.3.1` image rollback/retry are
covered by the hosted recovery runs above. The combined matrix does not claim a
destructive-schema downgrade, arbitrary archive compatibility, real DNS/TLS,
real mail delivery, offsite-backup durability, or a production restore.

## Related Pages

- [Installation](Installation)
- [Upgrading](Upgrading)
- [Backup, Restore, and Rollback](Backup-Restore-and-Rollback)
- [Runtime and Operations](Runtime-and-Operations)
- [Security and Privacy](Security-and-Privacy)
