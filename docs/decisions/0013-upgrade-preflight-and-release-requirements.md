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

The check runs in `docker-entrypoint.sh` **ahead of the migration block**, prints
what is required, and exits non-zero. The schema is untouched and the previous
image still runs, so the operator's recovery is to complete the action or to
restart the old tag.

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

For the same reason, a `check` **may** consult infrastructure and the *old*
schema, but must not assume the new one. A check that queries a table the
pending migration creates would fail on every upgrade it was meant to guard.

### The release manifest is published twice, for two different readers

- **Baked into the image** (alongside `/etc/wayfindr/version`, ADR 0012) — this
  is what the entrypoint guard reads. It must be present without network access,
  because a guard that cannot fetch its own rules would have to choose between
  failing every offline start and skipping the check.
- **Published as a release asset** — this is what the installer preflight reads,
  and it must be fetchable *without pulling each image*, because the preflight
  collects declarations across `(current, target]` for releases it never pulls.

The two must agree; the release workflow publishes both from one source.

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
- `docker-entrypoint.sh` grows a responsibility beyond directory setup, and is
  shell rather than PHP. Keep the shell to reading the manifest and dispatching
  to an artisan command that can be tested.

## Delivery slices

1. **Release manifest** — the declaration format, generated at build, baked into
   the image and published as a release asset. No enforcement yet; publishing
   first means later slices have real data to read.
2. **Artifact guard** — the entrypoint check ahead of migration, phase-aware,
   with `check` and `attest` verification and the acknowledgement env value. This
   is the guarantee; it lands before the installer work because it is the part
   that does not depend on the operator having a current installer.
3. **Installer hand-off and preflight** — re-exec before pull, then the
   span-collecting preflight that refuses or warns and requires stepping where an
   action cannot be performed outside its own release.
4. **Floor enforcement** (ADR 0012 slice 5) — reject a direct upgrade from below
   `minimum_upgrade_from`, with the supported stepping path. Depends on the
   manifest, so it follows rather than leads.

The bootstrap constraint applies to whichever release first carries slice 2: it
must require no operator action.
