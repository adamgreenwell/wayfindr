# Reviewing an update

`wayfindr:update-plan` reviews a published stable release without changing the
installation. It fetches release metadata, evaluates the existing upgrade guards,
and reports the update method and outstanding work. It does not pull images, run
migrations, write release state, acknowledge actions, enroll a helper, or start an
update.

From an installer-managed installation directory:

```bash
docker compose exec -T web php artisan wayfindr:update-plan
docker compose exec -T web php artisan wayfindr:update-plan --json
```

On a host PHP deployment, run `php artisan wayfindr:update-plan` from the Laravel
application directory. Add `--ref=<stable-release-tag>` to review a specific
published release instead of GitHub's latest stable release. Branches, floating
image aliases, development versions, and prerelease targets are refused.

The installed application must contain the command; older published images do
not gain it by fetching metadata.

## Reading the result

The JSON receipt has schema `1` and a `plan_id` derived from all reviewed facts.
The same facts produce the same ID; a changed source identity, release artifact,
history, check outcome, acknowledgement, notice, or helper report changes it.
The receipt is a metadata review snapshot, not an approved host-bound plan.
Managed preparation and execution perform their own checks against the exact
host and release artifacts before making changes.

| Field | Meaning |
| --- | --- |
| `source` | Running identity, recorded release, retained requirements debt, runtime profile, and installation claims |
| `target` | Exact tag, commit, published image digest, release notes, and available platform evidence |
| `provenance` / `declarations` | Published metadata hashes and the commit-bound manifest/history used for assessment |
| `release_requirements` | Upgrade floor, outstanding actions across every phase, check outcomes, and existing attestations |
| `advisory_notices` | Advice that does not block migration or enable execution |
| `installation` / `managed` | Deployment ownership, helper capability claims, authentication boundary, and managed eligibility blockers |
| `manual` | Guidance for the installation's existing update path |
| `effects` / `recovery` | Expected interruption, unknown migration details, and limits on recovery after schema changes |

`update_available` and `up_to_date` exit successfully. `up_to_date` requires an
exact known running version and matching commit, coherent recorded identity, and
clear release guards. Unknown identity, conflicting recorded identity, a downgrade,
or unmet requirements return exit `78`. Missing, corrupt, unavailable, forbidden,
or rate-limited metadata returns exit `1` with `status: failed`; it never means
the installation is current.

The planner reuses ADR 0013's guard assessment, including skipped releases,
unknown legacy origins, profile changes, and requirements retained after a prior
migration. A `NOW` action must be completed while its release is still running;
a `STEP` action requires stopping at the release that owns it. Existing
acknowledgement and machine-check rules still apply. The planner does not add an
acknowledgement or invent a route around an upgrade floor.

## Ownership and managed capabilities

An `image` or `host` runtime profile selects release requirements. It does not
establish who owns the deployment. Ownership is reported separately as
`installer-managed`, `external-docker`, `source-build`, `host-php`,
`deployment-platform`, `hosting-managed`, or `unknown`.

`WAYFINDR_INSTALLATION_OWNERSHIP` and `WAYFINDR_INSTALLATION_ID` can describe an
installation for review. These values are claims: configuration and serialized
reports cannot authenticate or enroll a helper or establish managed eligibility.
When the helper is enabled, the command authenticates a capability read against
the enrolled helper. It still does not prepare or start a host operation, and
its receipt always reports `managed.execution_available: false`. Existing
terminal, Compose, host PHP, and platform updates acquire no helper requirement
or new backup setup task.

The authenticated helper establishes installation identity, enrollment, helper
protocol `1`, and its supported capabilities. The managed path also requires
Linux, `amd64` or `arm64`, an exact official release image, coherent source
identity, and verified target platform evidence. Floating/custom images and
unsupported or unverified platforms cannot enable it. The public metadata
client reports target platform evidence as unavailable; the separate host
preparation and execution workflow verifies the image index and exact platform
image. An authenticated capability response alone does not approve that
host-bound plan or enable execution through this CLI command.

Optional managed policy claims (`require_remote_backup` and
`require_restore_proof`) are separate from release-required actions and are not
assessed by this metadata-only command. A managed plan's capability eligibility
does not mean backup policy, protective backup, draining, or execution is ready.

## Artifact and recovery limits

The catalog resolves the release tag to a full source commit, checks the
manifest and image-digest assets against GitHub's published SHA256 checksums,
and reads `releases/history.json` at that exact commit. It validates history
schema, entries, uniqueness, supported coverage, and the target declaration's
agreement with the published manifest. The committed target's empty commit
field is expected: history is authored before its release commit exists.

The current release format does not declare a precise migration list or an
estimated duration, so both remain unknown. Updates can interrupt services and
jobs. After schema changes begin, switching images does not restore the
database; changed or uncertain schema state requires explicit recovery in
maintenance. This receipt promises neither automatic database restore nor
automatic rollback after migration.

Tracked delivery: [#1109](https://github.com/adamgreenwell/wayfindr/issues/1109)
in [the managed-update epic](https://github.com/adamgreenwell/wayfindr/issues/1107).
The independent host helper is [#1110](https://github.com/adamgreenwell/wayfindr/issues/1110).
The implemented operator review, start, and reconnecting progress workflow is
documented in the [managed updater guide](managed-updater.md). Optional managed
updates remain under disposable-VM qualification and are not qualified for
production use.
