# 0.8.0 pre-publication rehearsal — September 13, 2026

This is local candidate evidence, not a published release or the human
non-author acceptance required by [#797](https://github.com/adamgreenwell/wayfindr/issues/797).
The owner selected 0.8.0 as the next release target. Account/site-settings
restructuring remains later work.

## Environment and artifact identity

The rehearsal used Docker Desktop on macOS, with Docker Engine 29.7.2 and
Linux ARM64 containers.
Clean-install and upgrade scenarios had separate Compose projects and fresh
application, database, Redis, and Caddy volumes. They shared the local Docker
engine and image cache. All published host ports were bound to loopback;
there was no production traffic or deployment.

| Artifact | Source commit | Resolved local image index |
| --- | --- | --- |
| Published 0.7.0 baseline, freshly pulled | `8c72ee6e40aeeae3a5a841f27171b2cc47a1eeca` | `sha256:70f23dab5c4ef6a5439134520cca260ea5c61c16b597ee712c781e77dce33c0b` |
| Initial local 0.8.0 candidate | `31d8a38121ff1e6b643c817a4c858267b4fdce80` | `sha256:602640df96614ef5bd7a4be6ac5c6450891ed09c45dc113ea40a61721966053d` |
| First fixed local 0.8.0 candidate | `d7533583fac2f7996be32e36b4aec7c0dde3d5cb` | `sha256:ec0651c4139c210f63bdf8e278d6fdce826f85179fc54f5ebdaa06099dd3b0c8` |
| Final local 0.8.0 candidate, including direct-exec fallback | `62901da43dc03da40e22e411ea41d4ab87277f07` | `sha256:138ec244613206867f8e8caf9f2e24347ba95c8d9eef30e035007b098fefd8f5` |

The candidate images used the repository Dockerfile with version `0.8.0` and
the listed commit supplied as build arguments. They were tagged only in the
local engine. The fixed builds used `git archive` contexts, excluding local
dependencies, browser output, environment files, and uncommitted documentation.
The final candidate's PHP runtime was 8.4.25 with curl, gd, and intl, and libcurl 8.14.1.
The baked version, commit, and action-bearing manifest were checked directly.

## Defect found and corrected

The initial clean install passed, but upgrading a used 0.7.0 installation to
the initial candidate produced HTTP 500 after agent sign-in. The persisted
compiled dashboard template still referenced `$activeBreakGlassGrants`, which
the current controller no longer supplied. Its newer cache timestamp prevented
Laravel from recompiling the current source. Clearing compiled views manually
restored the support loop, confirming the cause.

The image now defaults compiled views to container-local
`bootstrap/cache/views`. Startup creates the directory, including when an empty
override needs the default; explicit custom paths are respected. An image-only
view configuration applies the same fallback to direct `docker exec` PHP
commands, which inherit the original container environment, including blank
values, rather than the entrypoint's exports. Host-managed view configuration
is unchanged.
Local compiled output is excluded from the Docker build context.

A four-case regression executes the entrypoint and real Laravel view compiler
against a newer cached template from an older release. Before the fix, the
default/image cases rendered the previous template instead of current source.
Review also caught the blank direct-exec case failing with an invalid cache
path before the image-only configuration was added. Afterward, all four cases
passed with 30 assertions, exercising both entrypoint and direct-exec rendering
with unset, blank, image-default, and custom paths. The old shared cache is left
untouched.

## Runtime results

The final candidate repeated the clean-install, upgrade, backup/restore, and
restart scenarios. Its upgrade started from a newly recreated 0.7.0 installation,
without manually clearing its views. The obsolete shared dashboard template
remained on disk and was ignored successfully.

| Check | Result |
| --- | --- |
| Fresh fixed-candidate install | CLI bootstrap, agent sign-in, visitor API intake, conversation, and linked ticket passed. |
| Blank view-cache override | The final clean stack had an explicitly blank container environment value. A direct-exec PHP probe confirmed that value remained blank, resolved the container-local cache, and rendered a Blade view successfully. |
| Used 0.7.0 → fixed candidate | Installer refreshed and handed off; 35 migration records became 72, applying all 37 new migrations. |
| Upgrade data preservation | Seeded user identity, message content, and ticket content matched by hash; record counts were preserved before adding new smoke records. |
| Persistent files and keys | Unlinked marker files on the local attachment disk retained their bytes; a value encrypted under 0.7.0 still decrypted after upgrade. |
| Post-upgrade support loop | Visitor intake, authenticated conversation, and linked ticket passed. |
| Backup/restore | A real PostgreSQL backup recovered a deliberately deleted user marker and local file. The data hash, file bytes, and encrypted value matched after restore. |
| Stack restart | Services returned and the support loop passed again. This was a container restart, not a VM reboot. |
| Runtime checks | Web, queues, scheduler, Reverb, PostgreSQL, and Redis ran; `/up`, `/widget.js`, migration status, upgrade guard, and failed-job checks passed. A custom backup queue was retained. |

The local candidate was selected using the installer's custom-image override
and local stack-file source. Its public-manifest preflight therefore explicitly
skipped the unpublished custom image; the candidate's baked guard enforced its
own declaration. The attempted registry pull failed as expected for the local
tag, and the existing local image was used. This does not prove the future
published 0.8.0 manifest-download or registry-upgrade path.

The initial, first fixed, and final (`62901da4`) clean candidates passed a headed
Chromium check: the actual
Site Settings snippet was pasted unchanged into a separate local page and
auto-initialized. Visitor messages and agent replies arrived in both directions
without refresh, verified with unchanged navigation counts and time origins.
The operator console showed each candidate's exact version and commit. The
fixed candidates' agent and visitor consoles had no errors or warnings. The
final check used the clean stack with the explicitly blank cache-path setting.

## Host-managed PHP and repository checks

The local host/profile lane passed 287 release/guard tests with 523 assertions;
the test inventory independently confirmed that all 287 ran. PHP-version,
host-manifest, and self-hosting shell contracts passed.

The real extracted Forge preflight accepted PHP 8.5.10 and Composer using that
runtime. Repeating the check with the unsupported `8.3.33` runtime for either
application PHP or Composer stopped with exit 78 before the mutation sentinel.
Separately injected missing curl,
gd, or intl checks also stopped with exit 78; those missing-extension cases were
fault injection, not a claim that the Mac lacked the modules.

The current declaration remains globally action-bearing: host upgrades require
`0.8.0/php-runtime-extensions`, images are exempt, unknown profiles fail closed,
and acknowledgement cannot override a definitely failed runtime check. Tests
also cover proven-fresh exemptions and retained historical debt. All thirteen
historical declarations match their 0.7.0 counterparts semantically.

This is contract verification, not evidence that a deployed host's PHP-FPM,
workers, scheduler, and Reverb were checked or upgraded.

## Remaining release work

- Apply and read back the owner-controlled `v*` creation/update/deletion ruleset.
  The live ruleset list was still empty during this rehearsal.
- Repeat [#970's run audit](https://github.com/adamgreenwell/wayfindr/issues/970)
  after **2026-09-24 12:53:21 UTC / 08:53:21 EDT**. Four old publisher runs were
  still inside their rerun windows on September 13. Waiting is the selected
  route; the checkpoint is not automatic clearance.
- On the final release commit, update the changelog date, retain the operator
  action declaration, pass publishing-mode validation, and wait for main CI on
  that exact commit before the tag.
- Verify the published tag, commit, manifest, image digest and architectures,
  then repeat clean-install and 0.7.0 → 0.8.0 upgrade checks against the public
  artifacts. Update public-site release claims after publication.
- Refresh #797's artifact baseline and give the protocol to a human non-author.

This rehearsal does not cover clean Ubuntu AMD64 VMs, public DNS/TLS, local-CA
trust, external email/providers, VM reboot, or human acceptance. Do not reuse
the historical latest-to-0.3.1 image-rollback scenario across the new schema
without first establishing that downgrade is supported.

## Re-rehearsal at `e7edc0e3` (2026-09-21)

The rehearsal above validated `62901da4`. Twenty commits landed after it — the
seven advisory fixes and their findings — including two schema migrations, a new
post-activation command wired into both deploy scripts, and widget changes. A
candidate that has not been rehearsed is not a candidate, so the scenarios were
repeated against current `main`.

| Artifact | Source commit | Resolved local image index |
| --- | --- | --- |
| Published 0.7.0 baseline, freshly pulled | `8c72ee6e40aeeae3a5a841f27171b2cc47a1eeca` | `sha256:70f23dab5c4ef6a5439134520cca260ea5c61c16b597ee712c781e77dce33c0b` |
| 0.8.0 re-rehearsal candidate | `e7edc0e33ce2073c99294e2bed78183c1807a022` | `sha256:de2a678caa87cf8f8ac704f6be10e736f2c1bcca151606cce161531a5420d5ac` |

The 0.7.0 digest is identical to the one the September 13 rehearsal used, so the
upgrade comparison is against the same baseline. The candidate was built from a
clean checkout with the guarded command in `docs/self-hosting/install.md`, which
pins the commit only when `git diff`, the index, and `git ls-files --others` are
all empty. It was tagged in the local engine only. Its baked manifest declares
the `php-runtime-extensions` action for `host` profiles and the
`backups-queue-consumer` notice.

### Results

| Check | Result |
| --- | --- |
| Clean install | CLI bootstrap, visitor intake and conversation passed on a fresh stack; 74 migrations applied. |
| New-site default | A site created by `wayfindr:bootstrap` on a fresh 0.8.0 install carries `identity_verification=required`. |
| Used 0.7.0 → candidate | 35 migration records became 74, applying all 39 new migrations. |
| Upgrade data preservation | User identity, conversation, message and site-public-key hashes were identical before and after; record counts preserved. |
| Existing-site default | The upgraded site carries `identity_verification=off`. New sites verify and existing ones do not — the asymmetry holds across a real upgrade, not only in tests. |
| Legacy conversation ownership | The conversation opened under 0.7.0 carries `owner_session_id='~legacy'` after upgrade, is reachable by its own visitor with both its pre-upgrade token and a re-bootstrapped one (200), and is refused to a different visitor (404). |
| Key survival | A value encrypted under 0.7.0 decrypted after the upgrade. |
| Backup/restore | A real backup recovered a deliberately deleted conversation with its subject and `~legacy` owner intact; it was reachable by its visitor again afterwards. |
| Stack restart | All seven services returned; `/up` and `/widget.js` served 200. |
| Runtime checks | 74 migrations ran with none pending, no failed jobs, and the upgrade guard reported not blocked. |

The same-day fork sync put stage on this exact commit, which validated the two
riskiest changes against real data rather than fixtures: both pre-existing sites
came out `identity_verification=off`, and the new offboarding sweep revoked
nothing, because stage has no deactivated agents. The one revoked API token there
was revoked by hand before this release.

### Not covered

Everything the first rehearsal excluded still applies: clean Ubuntu AMD64 VMs,
public DNS/TLS, local-CA trust, external email or providers, VM reboot, and human
acceptance. The candidate was selected by local tag, so the published-registry
download and upgrade path is again unproven — that can only be exercised after
publication. This pass also did not repeat the headed Chromium widget check or
the blank view-cache probe from the first rehearsal; the view-cache defect that
prompted that probe is covered by the four-case regression, which runs in CI.

## Re-rehearsal at `166b3706` as 0.9.0 (2026-09-22)

The re-rehearsal above validated `e7edc0e3` as 0.8.0. Nineteen commits landed
after it and the candidate was renumbered to 0.9.0, so the scenarios were
repeated. **No migration landed in those nineteen commits** — 74 files at both
`e7edc0e3` and `166b3706` — so the upgrade's schema shape is the one already
rehearsed. What is new is the installer's reserved-hostname guard, four
dependency bumps, the widget single-instance guard, the site-settings split, and
the 0.9.0 identity itself.

| Artifact | Source commit | Resolved local image id |
| --- | --- | --- |
| Published 0.7.0 baseline, freshly pulled | `8c72ee6e` | `sha256:70f23dab5c4ef6a5439134520cca260ea5c61c16b597ee712c781e77dce33c0b` |
| 0.9.0 candidate | `166b3706` | `sha256:062938b19c234e819e500e7e2f9960b14ee69f8bd0d184a0fb39fdeafbfcfdf4` |

The 0.7.0 digest is **identical** to the one the September 13 and September 21
rehearsals used, so all three measure the upgrade from the same baseline.

**The candidate was built twice, and the second build is the one used.** The
guarded command in `docs/self-hosting/install.md` passes no
`WAYFINDR_BUILD_VERSION`, so the first build baked
`0.9.0-dev+166b3706da08ab08e2cf0ce31c2e04cc808e2697` — a source identity, not a
release one. `.github/workflows/release-image.yml:320` passes
`WAYFINDR_VERSION=${{ github.ref_name }}`, so the artifact that actually ships
bakes the tag. The rehearsal was rebuilt with `WAYFINDR_BUILD_VERSION=v0.9.0`
to exercise the path operators will take; the app normalises it and reports
`Release 0.9.0`. Both builds pinned the commit, from a checkout where
`git diff`, the index and `git ls-files --others --exclude-standard` were all
empty.

### Results

| Check | Result |
| --- | --- |
| Clean install | Seven services up, four reporting healthy (queue, backup-queue and scheduler set `healthcheck: disable: true`). 74 migrations applied, none pending. |
| Release identity | The candidate reports `Release 0.9.0` from a baked `v0.9.0`, so the tag normalises as the guard expects. |
| New-site default | A site created by `wayfindr:bootstrap` on a fresh 0.9.0 install carries `identity_verification='required'`. |
| Public surfaces | `/up` and `/widget.js` served 200; `/setup` redirected once an account existed. The widget payload was 413,239 bytes, matching the figure the release notes quote. |
| Used 0.7.0 → candidate | 35 migration records became 74, applying all 39 new migrations in place. |
| Upgrade data preservation | Content **hashes** — not counts — were identical either side for users, sites, visitors, conversations and messages. A count alone survives a migration that rewrites values. |
| Existing-site default | The upgraded site carries `identity_verification='off'` while the fresh install carries `'required'`. The asymmetry holds across a real upgrade, not only in tests. |
| Legacy conversation ownership | The conversation opened under 0.7.0 carries `owner_session_id='~legacy'` after upgrade. |
| Credential survival | A password set under 0.7.0 still validates after the upgrade. |
| Runtime checks | No pending migrations, no failed jobs, upgrade guard reports nothing outstanding, `/up` and `/widget.js` 200 on the upgraded stack. |

### Not covered

Everything the earlier passes excluded still applies: clean Ubuntu AMD64 VMs,
public DNS/TLS, local-CA trust, external email or providers, VM reboot, and
human acceptance. The candidate was selected by local tag, so the
published-registry download and upgrade path is again unproven — that can only
be exercised after publication.

This pass did not repeat the backup/restore drill or the visitor-resume
endpoint checks from the previous rehearsals. Neither surface changed in the
nineteen commits, and the schema did not move, so the earlier evidence still
describes the same code; a reader wanting those results should read the
September 21 pass above rather than assume this one repeated them.

The installer's reserved-hostname guard was exercised separately, against the
published `main` rather than this image, on a clean `ubuntu:24.04` with a real
Docker daemon: the README one-liner with `--app-url https://support.example.com`
is refused before anything is fetched, and a real hostname proceeds. That is
recorded on #797.
