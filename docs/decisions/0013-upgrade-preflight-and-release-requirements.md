# 0013: Upgrade Preflight and Release Requirement Enforcement

Date: 2026-08-03

## Context

[ADR 0012](0012-platform-versioning.md) established what a release *declares*:
`requires_operator_action`, `minimum_upgrade_from`, and for each action the
release it belongs to, whether it depends on that release's own code or schema,
its applicability, and its execution phase. Slices 1–3 delivered the identity and
the comparator that reads it.

Slice 4 is where declarations start being *enforced*, and ADR 0012 says plainly
that the layering question outgrew a versioning record. This is that record.

It exists because of one finding, which inverts where this work would naturally
go: **the guarantee cannot live in the installer.**

An operator sitting on a pre-enforcement version until a release requiring action
exists resolves straight to that release, and *their* installer — which contains
no preflight, and cannot be made to — pulls and starts it. No installer-side
guard binds an installer that already exists. Enforcement has to live in the one
thing an upgrade actually fetches: the artifact.

Two facts about the current code shape everything below.

**The upgrade never runs the installer it downloads.** `install.sh --upgrade`
fetches its own replacement and then continues in the same already-parsed
process:

```sh
fetch scripts/self-host/install.sh "$TARGET_DIR/install.sh"   # new script, on disk
chmod +x "$TARGET_DIR/install.sh"
migrate_env
pin_image
compose pull web                                              # still the old process
compose up -d                                                 # migrations run here
```

A preflight added to the shipped script would sit on disk, unrun, while the first
release requiring operator action was pulled and started. (Overwriting a script
bash is still reading is its own hazard — bash reads incrementally — so this is
worth fixing regardless of preflight.)

**Migrations run in the entrypoint, before the app serves.**
`docker-entrypoint.sh` runs `php artisan migrate --force` and only then
`exec "$@"`. A guard that refuses to *serve* has already let the schema change,
which is precisely what an `after-pull` action exists to precede.

## Decision

### The artifact refuses to start, halting before migration

The check runs **ahead of the migration**, prints what is required, and exits
non-zero. The schema is untouched and the previous image still runs, so the
operator's recovery is to complete the action or to restart the old tag. It runs
inside the migration path itself rather than in front of each caller, so every
deployment style is covered by one enforcement point
(see §"Migration itself enforces the guard").

The alternative — starting with migrations suppressed so the operator has a live
shell to work from — was considered and rejected for now. It means maintaining a
second start mode, and a running application on an un-migrated schema is its own
hazard class. The cost of refusing is that the operator meets a container that
will not start, so the **message is part of the feature**: it must name the
release, the action, and the exact command or step, and the docs must carry the
recovery. A guard that halts without saying why is a worse outcome than no guard.

### Enforcement is phase-aware, or it deadlocks

Only `before-pull` and `after-pull` prerequisites may block the migration. Those
genuinely must precede it.

An unmet `after-start` action needs the migrated schema and the running code in
order to be performed at all. Blocking migration on it would withhold the very
state the action requires, and the requirement could never be satisfied. Unmet
`after-start` requirements therefore gate **serving**, after migration — the app
starts, refuses traffic, and says what is outstanding.

**`before-pull` is detected by the artifact, not prevented by it.** The artifact
guard runs when the new image starts, which is after `compose pull` has already
completed. A `before-pull` action must be performed while the *old* release is
live, so by the time any artifact-side code can look, the moment has passed.
Blocking the migration does not give it back.

This is a real limit and the record states it rather than implying otherwise:

- Only the **installer preflight** can stop a `before-pull` requirement from
  being missed, and the preflight is the part that may not exist.
- What the artifact still provides is **no silent progression**. It detects the
  unmet requirement, refuses to migrate, and names the recovery — restart the
  previous release, perform the action there, then upgrade again. The pull is
  reversible; a migration past a missed prerequisite frequently is not.

So `before-pull` should be chosen sparingly, and only where that recovery is
genuinely available. An action that cannot be performed after a rollback does not
belong in this phase.

### Requirements are verified where possible and attested where not

Each declared action carries a `verification`:

- **`check`** — a machine-evaluable condition the artifact can test itself
  ("the backups queue has a consumer"). Preferred wherever the condition is
  expressible. This is real verification: the operator cannot be wrong about it.
- **`attest`** — an explicit operator acknowledgement, for actions whose
  completion the artifact cannot observe ("you have taken a backup you trust").

A declaration states which kind it uses, and the artifact reports it honestly:
a verified requirement says *verified*, an attested one says *acknowledged by the
operator*. Conflating the two would let an attestation read as a guarantee.

**Attestation cannot live in the database.** The guard runs before migration, so
the schema is whatever the *old* release left. Acknowledgement is therefore an
environment value — the one pre-migration channel compose already manages, and
where operators already set configuration:

```dotenv
WAYFINDR_ACKNOWLEDGED_ACTIONS=0.2.0/backups-worker,0.3.0/reindex-conversations
```

Each entry is `<release>/<action-id>`, so an acknowledgement is specific to the
action that required it and cannot be a blanket opt-out. It is deliberately
verbose to type: this is the operator asserting something the platform cannot
check, and it should read like an assertion.

A `check` sees whatever exists **when it runs**, which follows from its action's
phase rather than from a blanket rule:

- `before-pull` and `after-pull` checks are evaluated *before* migration, so they
  may consult infrastructure and the **old** schema but must not assume the new
  one. A check querying a table the pending migration creates would fail on every
  upgrade it was meant to guard.
- `after-start` checks are evaluated *after* migration, at the serving gate, so
  the **new** schema is present and is often exactly what they need to inspect —
  which is why `after-start` may depend on `schema` at all. Forbidding it there
  would push genuinely verifiable requirements into unverifiable attestations.

### The artifact carries the history, not just its own declaration

An artifact that knows only its own requirements cannot keep the promise this
record is built on. A `v1 -> v3` jump made by a pre-enforcement installer fetches
the `v3` image, and if `v2` declared the action then nothing in `v3`'s own
manifest mentions it. Assigning span collection solely to the installer preflight
puts it in the component that, by assumption, may not exist. The skipped-release
case is precisely the one the artifact guard is for.

So the image carries **every declaration from `minimum_upgrade_from` up to its
own release**, not a single manifest. The floor bounds the history: an upgrade
from below it is refused outright, so declarations older than the floor can never
be needed.

Evaluating a span also needs its start. Nothing records that today, so **the
running release writes its identity to the persistent volume** once it has
started successfully. The next image reads that file before migrating to learn
where the upgrade is coming from. It lives on the volume rather than in the
database because the guard runs pre-migration, and it is written after a
successful start rather than before, so a release that never came up does not
claim to have been installed.

**An absent file does not mean a fresh install.** Every install predating this
mechanism has no state file, which is most of them, and reading absence as "fresh"
would evaluate no span at all — recreating the pre-enforcement bypass the history
was added to close. The bootstrap release cannot rescue this either, since nothing
makes an operator traverse it.

Absence is therefore disambiguated against state that already exists: a populated
`migrations` table means an install that has been running, whatever the file says.
That is readable pre-migration, because it belongs to the *old* schema.

- No file, no prior schema -> genuinely fresh. No span, no requirements.
- No file, prior schema -> a **legacy upgrade** from an unknown starting point.
  The start cannot be recovered, so the conservative reading is the only honest
  one: evaluate the entire baked history and require every unmet requirement in
  it to be satisfied or acknowledged. That is louder than necessary for an
  operator who was already current, and it is the safe direction — the
  alternative is silence for precisely the installs with the furthest to travel.

### Published twice, for two different readers

- **Baked into the image** (alongside `/etc/wayfindr/version`, ADR 0012) — the
  history above, read by the artifact guard. It must work without network access,
  or a guard that cannot fetch its own rules has to choose between failing every
  offline start and skipping the check.
- **Published as a release asset** — one manifest per release, read by the
  installer preflight, which must evaluate releases it never pulls.

Both come from one builder so they cannot disagree.

### Migration itself enforces the guard

The container entrypoint is not the only way Wayfindr migrates. ADR 0012 treats
**host builds** as first-class, and a repo-wide search finds thirteen
`artisan migrate` invocations across the shipped deploy scripts, the entrypoint,
the smoke test, and the operator documentation — including the restore flow and
the Forge guide:

```
deploy/forge/zero-downtime-deploy.forge:59
deploy/forge/standard-deploy.sh:71
docker/self-hosting/docker-entrypoint.sh:22
docs/self-hosting/runtime-requirements.md:223
docs/self-hosting/backup-restore.md:260
docs/self-hosting/setup-templates.md:86
...
```

The first draft asked every one of those to call a guard first. That is a rule
each call site must remember, and the set grows: a new runbook, a support reply,
an operator's own script. Any of them silently bypasses the guarantee, and the
bypass looks exactly like the documented command.

So the guard runs **inside the migration path**, not in front of its callers. One
enforcement point, no call sites to maintain, and using a different documented
command cannot route around it. The mechanism belongs to slice 2, with one
binding requirement: it must abort **before any schema change is applied**, not
merely before the command returns.

Two consequences fall out of putting it there, both wanted:

- **A development checkout is unaffected.** No baked history means nothing is
  declared, and nothing declared means nothing to enforce. `local-setup.md`'s
  plain `artisan migrate` keeps working without a special case.
- **The restore flow is covered.** `backup-restore.md` tells an operator to
  migrate forward after restoring an older archive; if the running release needs
  an action first, that is exactly a migration that should stop.

### The installer hands off to the version it downloaded

`install.sh --upgrade` re-execs the refreshed script with the original arguments
before any pull, guarded against re-exec loops by an environment marker. This is
what allows a preflight to exist at all, and it fixes the read-while-overwritten
hazard as a side effect.

### The hand-off cannot protect its own arrival

An upgrade launched by any pre-slice-4 installer runs a process containing no
re-exec instruction, so the replacement it downloads is never given control
whatever that replacement says. The capability takes effect only from the *next*
upgrade onward.

The release that introduces the preflight must therefore **require no operator
action itself** — safe to take unprotected — so every install gets one harmless
hop that leaves the hand-off in place.

**And that bootstrap release cannot be made mandatory.** Publishing a harmless
release does not make anyone traverse it. This is exactly why enforcement lives
in the artifact: the installer preflight is a good experience for installs that
have it, and the artifact guard is the guarantee for everyone else.

## Consequences

- A release requiring operator action will stop an unprepared install rather than
  half-upgrade it. The stack stays on the previous image with its schema intact.
- Operators who skip releases are covered, because the preflight evaluates the
  whole span and the artifact guard does not care how the image arrived.
- An action with no expressible check is honestly labelled as attested rather
  than silently treated as verified.
- Every declared action needs its verification written and tested. This is real
  cost per release, and the point: a declaration nobody can evaluate is a comment.
- The first enforcing release is unprotected by design. That is not a gap to fix
  but a property to schedule around — it must require nothing.
- Enforcement sits in the migration path, so every deployment style is covered on
  the same terms and no call site has to remember it — including scripts this
  project never wrote.
- Migration gains the ability to refuse. That is a significant behaviour change
  for a command operators trust to be mechanical, so its message must be
  unmistakable and its bypass documented for genuine emergencies.
- The image grows a bounded history rather than one manifest, and releases gain a
  state file on the volume. Both are small, and the floor keeps the history from
  growing without limit.
- **The preflight is a second implementation of the guard's decision, in another
  language, running a version behind it.** Every change to `UpgradeGuard` is a
  latent divergence until `upgrade_preflight` in `scripts/self-host/install.sh`
  is changed to match, and a divergence here is silent by construction: the
  preflight says "clear", the operator pulls, and the artifact refuses on a
  release that is already installed. This is the standing maintenance cost of
  wanting an answer *before* the pull, and it is not visible from either file.

  Two constraints follow, both learned the hard way:

  - The preflight may only use APIs the release being upgraded **from** already
    had. It runs inside that image, so a method added by the release being
    installed does not exist there — and a probe must confirm the classes are
    present at all, since anything cut before this ADR carries none of them.
  - It must predict what the artifact will do, not compute its own better
    answer. Where the artifact treats a value as unknown, the preflight has to
    as well, even when it can see more — an origin derived from the image tag is
    invisible to the artifact and cannot satisfy its floor.

  When changing the guard, the checklist is: which origin does this read, what
  does it do when that origin is unknown, and does the preflight now disagree?

  **The divergence is now held down by test rather than by attention.**
  `scripts/test-self-host-classification.sh` lifts the preflight's classification
  block out of `install.sh` verbatim and runs it against the same fixtures as
  `UpgradeRequirements::disposition()`, failing if the two ever answer
  differently. `scripts/test-self-host-env-value.sh` does the same for the
  installer's dotenv reading, against Docker Compose — the parser that actually
  resolves the image being pulled. Both are wired into `make self-host-test`.

  This does not remove the duplicate; it makes disagreement loud. Deleting the
  duplicate remains unavailable for the reason above, and for a second one worth
  recording: `php_in_current_image()` needs `INSTALLED_IMAGE`, which comes from
  `env_value WAYFINDR_IMAGE`, so the installer cannot shell out to the artifact
  to learn *which* artifact to shell out to. Of six `env_value` call sites only
  two run after the image is known, so delegating would leave two parsers where
  there is one — with the most consequential key still on the bash side.

- **One rule, six sites, is itself the hazard.** Whether an action can still be
  performed, whether an acknowledgement settles it, and what the operator should
  be told had to be agreed by the predicate, the settlement in `outstanding()`,
  the blocking filter in `UpgradeGuard`, the installer's partition, and two
  operator-facing messages. Nearly every round of #648 fixed a subset and left
  another, twice fixing a message in one file and not its twin.

  The three states now have one name each — `App\Support\Release\ActionDisposition`,
  whose enum values are the installer's own `STEP`/`NOW`/`DO` so the two can be
  compared directly — and everything a site needs to *say* comes from
  `ActionAdvice`, which both messages render verbatim. That makes their ordering
  a structural property rather than a convention each file has to remember.

  Anything added here — an advisory severity, a new phase, a third message site —
  should extend those two classes rather than re-derive the rule.

- **The only response this record defines is refusal, and some requirements are
  worth reporting without being worth an outage.** Phases say *when* an action
  can be performed, not how hard the answer should be, so declaring an action is
  a choice between halting the migration and refusing traffic. Nothing lighter
  exists.

  The first requirement to meet this was the backups queue worker. Its check
  (`backups-queue-consumer`) was built and works, and it was still deliberately
  left undeclared: the only phase it could take is `after-start`, so an install
  without a backups worker would have refused *all* traffic — the support
  platform down because a backup worker was missing, on host-managed installs
  only, whose operator would then have to edit `.env` and restart with their
  site already down. The requirement is real, but the response available was not
  proportionate to it.

  It is reported where the operator actually meets it instead — the backups page
  names the missing worker and what to run. That is the pattern to follow while
  this gap stands: **a check may exist, and be useful, without its action being
  declared.** The declaration is a separate decision about blast radius, not an
  automatic consequence of the check becoming possible.

  **The third response now exists.** Advisory requirements are declared under a
  separate top-level `notices` list, reported on the operator console, in
  `wayfindr:upgrade-guard`, and in the installer's upgrade output, and they block
  nothing. `requires_operator_action` counts actions only, so a notice-only
  release stays honestly marked as safe to take unattended.

  **It is a separate list rather than a severity on an action, and that is the
  load-bearing decision.** An advisory has to be honoured at three independent
  gates — the migration filter, the serving filter, the installer's partition —
  and a severity flag means each must remember to check it. A gate that forgets
  turns advice into an outage, which is precisely what the advisory response
  exists to prevent. This record already documents one rule needing six sites to
  agree and drifting at nearly every step; a second such rule, whose failure mode
  is refusing all traffic, was not worth authoring. The gates read `actions` and
  cannot see `notices`.

  It is also the only shape that is backward compatible. Older readers ignore an
  unknown top-level key — verified against 0.2.0's shipped reader — so a release
  carrying notices upgrades cleanly from every release predating them, with no
  schema bump (a bump would make older images reject the manifest outright). A
  severity flag inside `actions` would be read by older code with no concept of
  it and treated as required, so a `before-pull` advisory would have made the
  *old* installer refuse the pull.

  Two constraints fall out, both recorded in
  `docs/self-hosting/release-manifest.md`:

  - A notice takes **no `phase` and no `depends_on_release`**. A phase times a
    response it does not have; `depends_on_release` decides strandedness, which
    decides blocking.
  - Therefore **advisory work must be performable at any time**. Work that can
    only be done on a release the upgrade passes is not advisory — either it
    stops the upgrade, or telling an operator to do it is noise.

  The backups queue worker is this response's first user.

## Delivery slices

1. **Release manifest** — the declaration format and builder, generated at build,
   with this release's manifest published as an asset and the bounded history
   baked into the image. No enforcement yet; publishing first means later slices
   have real data to read.
2. **Artifact guard** — the artisan command, phase-aware, with `check` and
   `attest` verification and the acknowledgement env value; the release state
   file with legacy-install disambiguation; and enforcement inside the migration
   path, proven to abort before any schema change. This is the guarantee, and it
   lands before the installer work because it is the part that does not depend on
   the operator having a current installer.
3. **Installer hand-off and preflight** — re-exec before pull, then the
   span-collecting preflight that refuses or warns and requires stepping where an
   action cannot be performed outside its own release.
4. **Floor enforcement** (ADR 0012 slice 5) — reject a direct upgrade from below
   `minimum_upgrade_from`, with the supported stepping path. Depends on the
   manifest, so it follows rather than leads.

   Refused **before** requirements are evaluated, and reported differently: an
   unsupported jump is not a to-do list. No acknowledgement can make it safe,
   because the migrations that would carry the install forward no longer ship —
   the only remedy is to upgrade in steps. A comparison with *no answer* (a
   development version on either side) does not refuse: that is not evidence of
   an unsupported jump, and treating it as one would strand source installs that
   are perfectly current.

The bootstrap constraint applies to whichever release first carries slice 2: it
must require no operator action.
