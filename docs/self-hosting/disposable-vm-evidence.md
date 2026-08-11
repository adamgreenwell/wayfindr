# Disposable VM Evidence Contract

Use this contract when a clean install, upgrade, backup/restore, rollback, or
reboot claim needs evidence from a throwaway self-hosted Wayfindr environment.
The goal is repeatability: a second VM should be able to follow the same report
without inheriting state, volumes, images, credentials, shell history, or
assumptions from the first.

This contract is for release-readiness and issue evidence. It is not a
production hardening checklist; production still needs the broader
[runtime requirements](runtime-requirements.md), backup policy, monitoring, TLS,
mail, retention, and security review.

## Minimum Matrix

For the current reliability cycle, collect evidence from at least two
disposable Linux VMs:

| VM | Purpose | Base operating system | Artifact source |
| --- | --- | --- | --- |
| Clean install VM | Proves a new operator can install the current public artifact from nothing. | Ubuntu Server 24.04 LTS, x86_64/amd64, fresh package state. | Public GitHub release, release manifest, container image, and installer URL. |
| Upgrade VM | Proves an existing supported install can upgrade to the target artifact and preserve operator data. | Ubuntu Server 24.04 LTS, x86_64/amd64, fresh package state before the old install. | Start from the previous supported public artifact, then upgrade through the public installer. |

Optional expansion VMs are useful when a finding is OS-specific or destructive:

- a second clean install VM to confirm a repaired procedure;
- a destructive restore/rollback VM so backup drills do not pollute the upgrade
  evidence;
- a non-Ubuntu Docker host only after the Ubuntu path is boring.

## VM Isolation Rules

Every report must state how the VM was isolated:

- Start from a fresh OS image or a reset snapshot taken before Wayfindr existed
  on the VM.
- Do not reuse Docker volumes, bind mounts, local images, generated `.env`
  files, database dumps, backup archives, or `/opt/wayfindr` directories across
  reports.
- Do not mount a developer checkout into the VM when proving a public artifact.
- Do not copy local `composer.lock`, `package-lock.json`, `.env`, or built
  assets into the VM.
- Do not rely on a cached image as the source of truth. Pull the target image
  during the run and record the resolved image digest.
- Destroy the VM, or destroy all Wayfindr data volumes and images, before
  reusing the machine for another claim.

## Public Artifact Rules

Evidence must come from artifacts an operator can obtain without your local
machine:

- installer: the published `scripts/self-host/install.sh` URL or the exact
  release checkout documented for the run;
- release metadata: GitHub Release URL and `release-manifest.json`;
- image: GHCR image tag plus digest;
- source fallback, if intentionally testing source builds: the public commit SHA
  and the documented source-build Compose overlay.

If a local patch is being validated before publication, label the report
`pre-publication` and do not use it as release evidence until the same steps are
repeated against the public artifact.

## GitHub-Hosted Evidence Harness

The repository includes a manual workflow,
[`Disposable VM evidence`](https://github.com/adamgreenwell/wayfindr/actions/workflows/disposable-vm-evidence.yml),
that runs selected public-artifact scenarios on a fresh GitHub-hosted Ubuntu
24.04 x64 runner. It is not pull-request CI; trigger it when you need release
evidence for an install or upgrade claim.

Available scenarios:

- `clean-install-latest` — downloads the public one-line installer, installs the
  latest release image, completes synthetic setup, verifies runtime processes,
  runs the support-loop smoke, runs a backup/restore drill, and restarts the
  stack.
- `upgrade-v0.2.0-latest-custom-backup-queue` — starts from the `v0.2.0`
  installer and image, sets a custom `BACKUP_QUEUE`, upgrades to the latest
  release, verifies support-loop survival, and proves the backup-worker advisory
  retires once the upgraded worker is observed.
- `upgrade-v0.1.0-latest` — starts from `v0.1.0` and upgrades directly to the
  latest release.
- `upgrade-v0.1.0-v0.2.0-latest` — starts from `v0.1.0`, steps through `v0.2.0`,
  then upgrades to the latest release.

The workflow uses `scripts/smoke/public-artifact-install.sh`, which can also run
on a disposable VM:

```bash
WAYFINDR_EVIDENCE_SCENARIO=clean-install-latest \
  scripts/smoke/public-artifact-install.sh
```

Use the workflow log as evidence only for what it actually proves. A GitHub
runner is a fresh Ubuntu 24.04 x64 environment and is useful for public-artifact
install/upgrade checks, but it does **not** replace a bare-metal VM reboot,
operator-managed DNS/TLS, real mail delivery, offsite backups, rollback, or a
production restore posture. Record those separately.

## Evidence Rules

Capture facts, not vibes:

- Use UTC or local time with timezone for every dated observation.
- Record exact commands, exit statuses, and trimmed outputs for pass/fail
  evidence.
- Include artifact identities: release tag, release manifest URL, commit SHA,
  image tag, image digest, installer source, and VM OS.
- Include only sanitized hostnames and IPs. Use placeholders such as
  `vm-clean-01.example.invalid`, `203.0.113.10`, or `[redacted-public-ip]`.
- Exclude secrets, tokens, cookies, private repository URLs, real customer data,
  private DNS zones, SSH fingerprints, provider project IDs, and full paths that
  reveal private infrastructure naming.
- Prefer synthetic operator, agent, visitor, site, and ticket data.
- If a command output contains a secret, summarize the relevant pass/fail fact
  instead of pasting the output.

## Required Evidence by Scenario

### Host Baseline

Record this before Wayfindr is installed:

```bash
date -Is
lsb_release -a || cat /etc/os-release
uname -a
docker version
docker compose version
df -h
```

### Clean Install

Record:

- installer command and source URL;
- generated Wayfindr directory path;
- release tag and manifest URL selected by the installer;
- image tag and digest after pull;
- whether the generated env file was reviewed before boot;
- the first successful boot command;
- `/up` response;
- `/setup` reachability;
- successful first operator/account owner creation with synthetic data.

Useful commands:

```bash
docker compose --env-file .env -f compose.yml ps
docker compose --env-file .env -f compose.yml exec -T web php artisan about
docker compose --env-file .env -f compose.yml exec -T web php artisan migrate:status
curl -fsS https://support.example.invalid/up
```

### Process and Runtime Health

Record that the expected processes exist and stay healthy:

- web;
- queue worker;
- backup queue worker on the `backups` connection;
- scheduler;
- Reverb when realtime is enabled;
- Postgres;
- Redis;
- persistent storage volumes.

Useful commands:

```bash
docker compose --env-file .env -f compose.yml ps
docker compose --env-file .env -f compose.yml exec -T web php artisan queue:failed
docker compose --env-file .env -f compose.yml exec -T web php artisan schedule:list
docker compose --env-file .env -f compose.yml exec -T web php artisan wayfindr:upgrade-guard
```

### Support Loop

Use synthetic data and record:

- public widget bootstrap succeeds;
- visitor can create a conversation and send a message;
- agent can sign in, view the conversation, reply, and create or inspect a
  ticket;
- realtime path is either proven or explicitly marked not tested;
- no real support content or credentials appear in the report.

The repository smoke scripts document the expected environment variables in
[testing.md](../development/testing.md#smoke-scripts).

### Backup and Restore

Record:

- backup destination and storage mode with identifiers sanitized;
- operator-triggered backup starts and completes;
- backup queue worker processed the job;
- backup archive exists where expected;
- restore is tested against disposable data;
- post-restore smoke checks pass.

Do not treat a backup file existing as restore proof. A restore claim needs the
restore command, exit status, and at least one post-restore data check.

### Upgrade and Advisory Behavior

Start from the previous supported public artifact, not from a fresh target
install. Record:

- starting release tag, image digest, and manifest;
- synthetic data created before upgrade;
- installer upgrade command;
- preflight output, including required actions and advisory notices;
- target release tag, image digest, and manifest;
- migration result;
- post-upgrade support-loop and data-preservation checks;
- `wayfindr:upgrade-guard` output after upgrade.

If the expected behavior is refusal, record that the old release stayed running
and that the refusal text names the operator action.

### Rollback

A rollback claim needs:

- the release being rolled back from;
- the previous image tag/digest;
- database and storage state before rollback;
- exact rollback command or manual steps;
- whether migrations are forward-only for the tested span;
- post-rollback `/up`, queue, scheduler, and support-loop checks.

If rollback is not supported for a version span, say that directly and record
the safe recovery path instead.

### Reboot Recovery

After a clean install or upgrade, reboot the VM and record:

```bash
sudo reboot
```

After reconnecting:

```bash
date -Is
docker compose --env-file .env -f compose.yml ps
curl -fsS https://support.example.invalid/up
docker compose --env-file .env -f compose.yml exec -T web php artisan queue:failed
docker compose --env-file .env -f compose.yml exec -T web php artisan schedule:list
```

The report should state whether services restarted automatically and whether any
manual intervention was required.

## Report Template

Copy this template into an issue comment, pull request comment, or private
release-readiness note. Replace placeholders with sanitized values only.

```markdown
# Disposable VM Evidence — <scenario> — <YYYY-MM-DD>

## Summary

- Scenario:
- Result: pass / fail / partial
- Reported by:
- Date/time and timezone:
- Related issue(s):
- Public artifact tested:

## VM Baseline

- VM purpose: clean install / upgrade / backup-restore / rollback / reboot
- Base OS:
- CPU/RAM/disk class:
- Docker version:
- Docker Compose version:
- Network shape: public HTTPS / private LAN / tunnel / local-only
- Isolation statement:

## Artifact Identity

- Installer source:
- Starting release, if upgrade:
- Target release:
- Release manifest URL:
- Commit SHA:
- Image tag:
- Image digest:

## Steps and Evidence

| Step | Command or action | Expected | Observed | Result |
| --- | --- | --- | --- | --- |
| 1 |  |  |  | pass/fail |

## Scenario Checks

- [ ] Clean install completed from public artifact.
- [ ] `/setup` was reachable and first synthetic operator/account owner was created.
- [ ] `/up` returned success.
- [ ] Web, queue, backup queue, scheduler, Reverb, Postgres, Redis, and storage were checked.
- [ ] Support loop passed with synthetic visitor and agent data.
- [ ] Backup completed.
- [ ] Restore completed and post-restore data check passed.
- [ ] Upgrade preflight/advisory behavior matched expectation.
- [ ] Upgrade preserved synthetic data.
- [ ] Rollback behavior matched documented support for the tested span.
- [ ] Reboot recovery required no manual intervention, or intervention is documented.

## Findings

| ID | Severity | Finding | Evidence | Proposed follow-up |
| --- | --- | --- | --- | --- |
| VM-001 | blocker/high/medium/low |  |  |  |

## Redaction Confirmation

- [ ] No secrets, tokens, cookies, private DNS, private IP inventory, provider IDs, or real support data included.
- [ ] Hostnames/IPs are sanitized.
- [ ] Synthetic users/sites/conversations/tickets used.
```

## Pass/Fail Language

Use narrow claims:

- Good: "Clean install passed on Ubuntu Server 24.04 LTS using release
  `v0.4.0` image digest `sha256:<redacted-prefix>` on 2026-08-11."
- Good: "Backup archive creation passed; restore was not tested in this run."
- Bad: "Self-hosting works."
- Bad: "Upgrade is good" when only a fresh target install was tested.

When evidence is partial, leave it partial. A precise partial result is more
useful than a heroic full-pass story held together with vibes and duct tape.
