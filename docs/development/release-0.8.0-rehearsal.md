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
| Fixed local 0.8.0 candidate | `d7533583fac2f7996be32e36b4aec7c0dde3d5cb` | `sha256:ec0651c4139c210f63bdf8e278d6fdce826f85179fc54f5ebdaa06099dd3b0c8` |

The candidate images used the repository Dockerfile with version `0.8.0` and
the listed commit supplied as build arguments. They were tagged only in the
local engine. The fixed build used a `git archive` context, excluding local
dependencies, browser output, environment files, and uncommitted documentation.
Its PHP runtime was 8.4.25 with curl, gd, and intl, and libcurl 8.14.1.
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
override needs the default; explicit custom paths are respected. The image
environment also supplies the default to direct `docker exec` PHP commands.
Local compiled output is excluded from the Docker build context.

A four-case regression executes the entrypoint and real Laravel view compiler
against a newer cached template from an older release. Before the fix, the
default/image cases rendered the previous template instead of current source.
Afterward, all four cases passed with 19 assertions, including custom paths and
the direct-exec default. The old shared cache is left untouched.

## Runtime results

The fixed upgrade was repeated from a newly recreated 0.7.0 installation,
without manually clearing its views. The obsolete shared dashboard template
remained on disk and was ignored successfully.

| Check | Result |
| --- | --- |
| Fresh fixed-candidate install | CLI bootstrap, agent sign-in, visitor API intake, conversation, and linked ticket passed. |
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

Both the initial and fixed clean candidates passed a headed Chromium check: the actual
Site Settings snippet was pasted unchanged into a separate local page and
auto-initialized. Visitor messages and agent replies arrived in both directions
without refresh, verified with unchanged navigation counts and time origins.
The operator console showed each candidate's exact version and commit. The
fixed candidate's agent and visitor consoles had no errors or warnings.

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
