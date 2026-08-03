# 0012: Platform Versioning

Date: 2026-07-29

## Context

Wayfindr is self-hosted. Operators run it on their own infrastructure and
upgrade on their own schedule, which means the version number is not a
developer convenience — it is the primary signal an operator has for answering
one question: **can I just pull this, or do I have to do something first?**

Today there is a de facto scheme and no policy. `release-image.yml` publishes on
`v*` tags and passes `WAYFINDR_VERSION=${{ github.ref_name }}` into the build, so
**the git tag is the version**; the Dockerfile bakes it to `/etc/wayfindr/version`
alongside the commit, and `ReleaseIdentity` reads env-then-file. Three tags exist
(`v0.1.0-alpha.1` … `alpha.3`), so the shape is already SemVer-ish. But nothing
says what a bump *means*, and two gaps make that concrete:

**The upgrade path is version-blind.** `install.sh --upgrade` pulls the newest
image, restarts, and runs migrations. It never asks what version the install is
on, what version it is moving to, or whether that jump needs anything from the
operator. An install several releases behind takes every intervening change in
one step, silently.

**We have already shipped a change that needed the operator, in a minor.** The
operator backups GUI (ADR 0011 slice 3a) introduced a dedicated `backups` queue
connection, which requires a *second* worker process. Installs that pulled it and
restarted got a GUI button that queued jobs no one would ever run; the symptom
was a backup stuck at "Running" forever. Nothing in the version number, and
nothing in the upgrade flow, said "this one needs you." That is exactly the class
of change a self-hosting version must communicate.

A third gap surfaced while validating the restore: an install with no release
identity reports `unknown`, and the restore's skew check compared two `unknown`s
as *equal* — silently losing its schema-mismatch guard (fixed in #635, which now
treats an unverifiable pair as indeterminate). That fix closed the fail-open but
underlined the root problem: **a meaningful fraction of installs carried no
identity at all**, because only the official image baked one and source/Forge
deploys set nothing. That gap is now closed in the baseline — slice 2 (#637)
shipped the `VERSION` anchor, the source-build derivation, and the host fallback
— so what follows records the contract those rules answer to, not work still
outstanding.

## Decision

Adopt **Semantic Versioning, with MAJOR defined in operator terms**, make every
install carry an identity, and require every release to declare its upgrade
impact in a form both humans and code can read.

### MAJOR means "you must act", not "the API changed"

Classic SemVer scopes breakage to a consumed API. That is the wrong axis for
software people *operate*. Wayfindr's public surface (the widget, the REST API)
matters, but the change most likely to break an operator's install is an
infrastructure or procedural one. So:

- **MAJOR** — the operator must do something beyond pulling the image and
  restarting. New required process or service, a config key that must be set,
  manual data migration, a dropped dependency version, or a breaking change to
  the widget/public API.
- **MINOR** — new features, additive schema. Pull, restart; migrations run
  automatically and nothing else is required.
- **PATCH** — fixes only, same guarantee as minor.

The test for MAJOR is deliberately behavioural: *if an operator pulls this and
restarts, and does nothing else, is anything worse than before?* The backups
worker fails that test. An additive table does not.

**Pre-1.0 caveat.** SemVer says 0.x carries no compatibility guarantee, and
Wayfindr is at `0.1.0-alpha.x`. Until 1.0, the **0.MINOR** slot carries the role
MAJOR will take later: `0.2.0 → 0.3.0` may require operator action, and a patch
may not. This is a real weakness of the number alone pre-1.0 — which is why the
per-release declaration below, not the digit, is authoritative until 1.0.

### Every install carries an identity

`unknown` must become the rare exception, not the norm:

- **Official image** — unchanged: the tag is baked at build.
- **Source and Compose builds** — stamp `<next-version>-dev+<sha>` instead of
  today's literal `source`, so a development install still identifies its code.
  The sha is not abbreviated: build metadata is what pins the build for
  comparison, and a colliding abbreviation would make two different commits read
  as the same build. A sha pins the build **only from a clean checkout** (see
  below); a dirty tree stamps no commit at all, because the alternative is an
  identity that is confidently wrong.

  Even then a commit pins the **source, not the artifact**. The Dockerfile
  consumes mutable inputs — `node:24-alpine`, `dunglas/frankenphp:1-php8.4`,
  `composer:2`, live apt repositories — so rebuilding one clean commit months
  apart can yield different runtimes under an identical `<version>-dev+<sha>`.
  A published release is *less* exposed but not exempt, and it is worth being
  precise about why: the registry does give the image an immutable digest, but
  that digest appears in neither the declared identity nor the backup manifest,
  so nothing that compares versions can see it. Re-running the release workflow
  for an existing tag would publish a different image under the same version and
  commit, and a restore would read the two as the same build. A published tag is
  therefore treated as **immutable — never rebuilt** — which is a process rule
  standing in for a technical one. Pinning the base images by digest, or carrying
  the artifact digest in the identity, are the durable fixes. For source builds
  the sha is the best available approximation, and the honest reading of a
  matching pair is "same source", not "same image".
- **Deploys that build from source on the host** (Forge, and ADR 0003's path) —
  `WAYFINDR_VERSION` is part of the expected environment, since nothing bakes it
  for them. It must be **derived from the deployed commit by the deploy
  pipeline**, not typed in once: a hand-entered value survives across every
  subsequent deploy, so a continuously-deployed branch would run many commits
  under one identity — and because a non-`-dev` value is eligible for
  full-identity equality, backups taken from different code would compare as the
  same build. The version itself must therefore be derived per deploy. A stale
  version with a refreshed commit *is* caught by the commit rule below, but that
  is a backstop, not a substitute: the version is what operators read, what
  precedence is computed from, and what appears in every archive, so leaving it
  wrong to be rescued by a second field is a poor trade.
- **`wayfindr_commit` is the fallback identity.** It resolves independently of
  the version and is already captured in every backup manifest, so an archive
  whose version is unset can still be traced to its code.

**A trailing `-dev` is reserved.** It is the generated development identity, and
it must never be used as a release tag. Consumers distinguish a development build
by that suffix, so without the reservation they would be inferring a semantic
property from a naming convention an operator could legitimately collide with —
a release tagged `v0.2.0-dev` would be misread as an unpinned development build.
Reserving it in the scheme is what makes the inference sound. (`-dev.1` or
`-devpreview` are ordinary prerelease identifiers and stay available.)

**A dirty tree cannot pin a build.** `git rev-parse HEAD` still reports the last
commit when the working tree carries uncommitted edits **or untracked files**, so
stamping it would name code that is not what was built — and a clean checkout plus any number of
differently-modified ones would all claim the *same* identity, which is a
fail-open in the restore's skew check. A build from a dirty tree therefore omits
the commit and identifies by lineage only, which consumers correctly treat as
unverifiable.

"Dirty" here means tracked modifications *and* non-ignored untracked files: a
brand-new migration is invisible to a tracked-changes check but goes straight
into the build context, which is the likeliest way to stamp a sha onto code that
commit does not contain.

Even that is a **proxy**, because git-cleanliness is not build-context
cleanliness — `.gitignore` and `.dockerignore` are different sets, so a file can
be invisible to git and still enter the image. `apps/server/public/hot` was
exactly this: gitignored, not Docker-excluded, and copied in wholesale. The
remedy is to keep the two sets aligned for anything the build would carry (done
for the known cases), while recognising that only a content digest over the
effective build context closes the class rather than the instances.

This one is a **discipline, not an enforced invariant**, and the ADR says so
rather than implying a guarantee it cannot make: `.git` is excluded from the
Docker build context, so the build genuinely cannot inspect the tree it was
handed — it can only trust the commit the builder passes. The documented build
commands therefore check the tree *before* invoking the build, and the honest
framing for anyone automating their own path is that supplying a sha is an
assertion of cleanliness. A content digest over the build context would make it
self-verifying, and is the natural upgrade if development builds ever start
circulating beyond the machine that made them.

An unidentified install is not an error, but every feature that compares
versions must treat "no identity" as *indeterminate* — never as agreement. That
principle is already implemented in the restore (#635) and applies to everything
built on this ADR.

### Every release declares its upgrade impact

The number is a summary; the declaration is the contract. Each release carries,
in a machine-readable form published with it:

- `version` and `commit`
- `requires_operator_action` (bool) and, when true, **what** the operator must do
- `minimum_upgrade_from` — the oldest version that may upgrade *directly* to this
  one, so old migration paths can be retired without stranding anyone silently

Alongside it, a human `CHANGELOG.md` (the repository has none today). The
declaration is what makes `--upgrade` able to stop and say "this jump needs you
first" instead of discovering it afterwards, and it is the input an
update-in-place mechanism will consume.

**A declaration describes its own release, so upgrades must be evaluated over the
whole span.** An install on `v1` upgrading to `v3` gets every change in `v2` as
well; if `v2` required an action and `v3` did not, reading only `v3`'s
declaration would miss it — precisely the several-releases-behind case this ADR
exists to fix, and `minimum_upgrade_from` does not save it (the jump is
*supported*, it just has a step in the middle). The contract is therefore on the
consumer: **anything evaluating an upgrade must collect the declarations of every
release in `(current, target]` and present the union of their required actions.**
A release manifest is never read in isolation.

**A union is not enough on its own, because actions are ordered.** An action
belonging to `v2` may need `v2`'s code or schema to exist — it can be runnable
neither on `v1` before pulling, nor on `v3` afterwards. Collapsing the span into
an undifferentiated list would present such a step as though it could be done at
either end, which is worse than not listing it. So each declared action keeps:

- the **release it belongs to**,
- whether it **depends on that release's own code or schema** (which decides
  whether a direct jump may skip past it — see below),
- its **applicability** — which upgrades it actually applies to. A pointer at an
  earlier action is not enough to express retirement: if `v2` adds a worker and
  `v3` removes the need, then `v3` must tell an install that *ran* `v2` to remove
  it while telling a direct `v1 → v3` upgrade nothing at all, since that install
  never created it. One action cannot mean both. Applicability is therefore
  conditioned on where the upgrade started, or better on observable state ("if
  the worker exists"), and a retirement can stand alone as a tombstone rather
  than only as an annotation on something else, and
- an **execution phase**, of which there are three, because the upgrade has three
  distinct moments (`install.sh --upgrade` pulls, then runs `compose up -d`,
  which starts the stack *and* runs migrations):
  - **`before-pull`** — runs on the old release, while it is still the live code.
  - **`after-pull`** — the new code is present but nothing has started, so the
    new CLI is available and **automatic migrations have not run yet**. This is
    where a manual data migration that needs the new code but must precede the
    schema change belongs.
  - **`after-start`** — the new release is live and migrations have run.

The phases describe where an action sits relative to *the upgrade being
performed*, and that is only the whole story for the **target** release. For an
**intermediate** release the phases do not save you, because in a `v1 → v3` jump
`v2`'s code is never present at any point: `before-pull` has `v1`, and both
`after-pull` and `after-start` have `v3`. A `v2` action that invokes a `v2`
command is therefore unexecutable at every phase — and the command may have
changed or been removed by `v3`, so attempting it is not merely awkward but
potentially destructive.

So the rule is about **dependence, not timing**. An intermediate release's action
survives a direct jump only when it does not depend on that release's own code or
schema — "run a second queue worker" or "set this env var" are infrastructure
changes that hold regardless of which version is installed, which is why the
already-shipped backups-worker action would be safe to carry across a skipped
release. Anything that runs *its release's* code must declare that dependence,
and any such action in the span makes the jump **non-direct**: the operator steps
through that release rather than being handed a sequence that cannot be executed.

**Later releases can also retire earlier requirements, and the union must respect
that.** If `v2` requires a second worker and `v3` folds that queue back into the
default one, the `v2` action is code-independent — so the dependence rule lets it
cross a skipped release — yet presenting it to someone jumping `v1 → v3` tells
them to build infrastructure the target does not want. An obsolete instruction is
arguably worse than a missing one: it is confidently actionable and wrong.

So a release may declare that it **supersedes** an earlier action, and anything
evaluating a span resolves supersession *before* presenting the union. Where
supersession cannot be determined — an undeclared release in the span, say — the
span falls back to the same conservative answer as everywhere else in this ADR:
step through rather than assert.

In other words `minimum_upgrade_from` bounds how far back a jump may start; the
declared dependence of intermediate actions decides whether a supported span can
be crossed in one step; and supersession decides which of the surviving actions
are still real by the time the operator arrives at the target.

**A missing declaration means unknown, not "nothing required".** Every release
published before this ADR carries no manifest, and reading that absence as
silence would repeat the mistake this ADR exists to correct — two `unknown`
versions are not a match. It is not hypothetical: the already-shipped
backups-worker action sits inside exactly such a span. So a span containing any
release without a declaration cannot be certified, and the preflight **fails
closed** — it declines to call the jump unattended-safe, names the releases it
could not read, and sends the operator to the changelog for that range.

Rather than backfill manifests onto tags cut before the contract existed, the
first release under this ADR records a **baseline**: the last version that
predates declarations. Everything at or below it is then *known* to be
unreadable rather than mysteriously absent — a fact the preflight can state
plainly instead of guessing at.

### Comparison answers two questions, not one

Comparisons use an ordered SemVer comparator rather than string equality, so the
restore can tell an operator whether an archive is *older or newer* instead of
hedging both remedies, and the upgrade path can evaluate `minimum_upgrade_from`
and refuse an unsupported jump.

**Direction alone is not a remedy, though.** Knowing an archive is older says
only which way the gap runs, not that it can be closed: the span it crosses may
contain a release whose action is phase-bound, or start below the running
release's `minimum_upgrade_from`, in which case "just migrate forward" is
confidently wrong advice about an unsupported move. So restore guidance is
direction-aware only once the intervening declarations and the upgrade floor have
been consulted; where they are unavailable or say the span cannot be crossed
directly, it stays neutral and says why. Precedence narrows the guidance — it
does not license it.

But SemVer §10 says **build metadata is ignored when determining precedence** —
`0.1.0-dev+aaaaaaa` and `0.1.0-dev+bbbbbbb` are *equal* to a conforming
comparator, even though they are different code. Relying on precedence alone
would therefore hand back the fail-open this whole effort exists to remove.

So the system asks two separate questions, and must not conflate them:

1. **"Are these the same build?"** — compared over the **full** identity,
   including build metadata. This is what a restore's skew check uses. For a
   **development identity that carries no build metadata**, the answer is always
   *indeterminate* — never "same", even against a byte-identical string. A dirty
   build deliberately omits the commit, so every dirty checkout sharing a
   `VERSION` reports the same bare `<version>-dev` while containing different
   code; equating those would walk straight back into the fail-open this ADR
   exists to close. Equality for a development identity therefore *requires* the
   commit.

   **A differing commit defeats equality even when the versions match.** The
   backup manifest already records `wayfindr_commit` independently, so when both
   sides carry one and they disagree, that is decisive whatever the version
   strings say. It costs nothing — the data is already captured — and it removes
   the guarantee's dependence on every operator deriving their version correctly:
   a fixed `WAYFINDR_VERSION` with a refreshed commit is caught anyway. Version
   equality is necessary, not sufficient.
2. **"Which is newer?"** — SemVer precedence, which ignores build metadata and is
   therefore **undefined** between two builds of the same version.

Two dev builds with different shas are consequently *different, direction
unknown*: skew is real, and guidance for that case must stay direction-neutral.
Putting the sha in the prerelease portion (`-dev.abc1234`) would make it sort,
but a commit hash has no meaningful order, so that would only manufacture
confident-sounding nonsense. Admitting the direction is unknown is the honest
answer, and callers must be written to accept it.

**A development version has no precedence at all.** The same trap applies to the
`-dev` suffix itself, not just the sha, and it is worse because the ordering
*looks* meaningful:

- `0.1.0-alpha.3 < 0.1.0-dev` — "alpha" sorts before "dev", so a source checkout
  that **predates** alpha.3 still reads as newer than it.
- `0.2.0-dev < 0.2.0` — a prerelease sorts below its stable version, so a
  checkout taken *after* `v0.2.0` reads as older than the release it follows.

A development build sits at an unknown point in history; nothing in its identity
says where. So precedence is **undefined whenever either side is a development
version**, and callers must treat that as direction-unknown rather than trusting
the comparator's answer. Only the identity question ("same build?") remains
meaningful. Keeping `VERSION` moving to the *next* development version after each
release (see `RELEASING.md`) avoids publishing an identity that is wrong on its
face, but it does not make the ordering trustworthy — that is why the rule is
stated here rather than left to release hygiene.

**The canonical form carries no `v` prefix.** Git tags keep it by convention, and
the release workflow bakes the tag verbatim, so official installs identify as
`v0.1.0-alpha.3` while a source build produces `0.1.0-dev`. SemVer has no `v` in
it: a strict parser rejects every official release outright, and a permissive one
still reports `v0.1.0` and `0.1.0` as different builds. So a leading `v` is
stripped wherever a version is **read or compared** — including versions read
from the manifests of archives taken before this ADR — and the unprefixed form is
what gets stored, compared, and displayed. Tag naming does not change.

Today's string comparison is merely imprecise here rather than unsafe: without
normalization more things compare *unequal*, so the failure direction is a false
skew, which fails safe. It becomes a correctness problem the moment a parser is
introduced, which is why it is settled here rather than in slice 3.

## What stays out of scope

- **The update-in-place mechanism itself.** This ADR defines the version contract
  it will need; the mechanism is its own decision.
- **Retro-tagging.** The three existing alpha tags stand as they are.
- **Versioning the widget separately** from the platform. One version for now;
  if the embedded widget ever needs its own compatibility window, that is a
  later ADR.
- **Support windows / EOL policy.** A CalVer-style "supported for N months"
  commitment is a product decision, not a numbering one, and can be layered on
  later without changing this scheme.

## Delivery slices

1. **This ADR.** Records the contract before anything depends on it.
   - **`CHANGELOG.md` and `RELEASING.md` are deliberately not part of this
     slice.** Both need choices this ADR does not make: which changelog format
     to follow, and whether to backfill the three existing alpha tags or start
     the history at the next release. They are created in slice 1b, and until
     they land the human-facing half of the declaration described above is
     undefined — so they must ship before any release claims to follow this ADR.
2. **Identity everywhere — delivered (#637).** Creates the repository `VERSION` file — the
   authoritative source for `<next-version>` that the derivation and the release
   procedure both read. Source/Compose builds stamp `<VERSION>-dev+<sha>`;
   `WAYFINDR_VERSION` documented for host-build deploys; `source`/null retired as
   normal outcomes.
3. **Ordered comparator** (delivered), keeping identity and precedence separate,
   normalizing the `v` prefix on every version it reads, and returning *no
   direction* when either side is a development version (§"Comparison answers two
   questions"). Slice 2's guarantee that two differing dev builds count as skew
   survives, guarded by the regression test that slice added. Note this slice does
   **not** by itself make the restore's guidance direction-aware — direction is
   necessary but not sufficient, and the declarations that make it safe to act on
   arrive in slice 4. Until then the guidance stays neutral.
4. **Release manifest** — `requires_operator_action` carrying **every field
   listed in §"Every release declares its upgrade impact"** for each action, plus
   `minimum_upgrade_from`. The fields are deliberately not re-enumerated here:
   this list has drifted from that section three times during review, and each
   drift silently narrowed what an implementation would build. Every field is
   load-bearing — drop dependence and the preflight cannot tell when an
   intermediate release must not be skipped; drop applicability and it cannot
   distinguish an upgrade that must undo a retired requirement from one that
   never applied it. Published with each release, and
   `install.sh --upgrade` gains a preflight that collects the declarations across
   `(current, target]` — not just the target's — refuses or warns before pulling,
   and requires stepping when an action cannot be performed outside its own
   release. Restore guidance becomes direction-aware only once it can read these;
   until then it stays neutral (see §"Comparison answers two questions").

   **This slice must also bootstrap itself, or it protects nobody's first
   upgrade.** Existing installs run *their* copy of `install.sh`, and the upgrade
   path today refreshes the on-disk script and then keeps going in the
   already-parsed process — it fetches the new `install.sh`, then proceeds
   straight to `compose pull` and `compose up -d` without ever executing it. So a
   preflight added to the shipped script would sit on disk, unrun, while the very
   first release requiring operator action was pulled and started silently. The
   refreshed installer must therefore **take over before any pull** (re-exec with
   the original arguments, guarded against re-exec loops). Overwriting a script
   that bash is still reading is its own hazard, so the hand-off is worth doing
   regardless.

   **The hand-off cannot protect its own arrival.** An upgrade launched by any
   pre-slice-4 installer runs a process that contains no re-exec instruction, so
   the replacement it downloads is never given control no matter what that
   replacement says. The capability can only take effect from the *next* upgrade
   onward. The release that introduces the preflight must therefore itself
   **require no operator action** — safe to take unprotected — so that every
   install gets one harmless hop that leaves the hand-off in place.

   **And a bootstrap release cannot be made mandatory.** Publishing a harmless
   release does not make anyone traverse it: an operator who sits on a pre-slice-4
   version until an action-required release exists resolves straight to that
   release, and their old installer pulls and starts it in the old process. No
   installer-side guard binds an installer that already exists. So enforcement
   cannot live in the installer at all — it has to live in the artifact being
   installed, which is the one thing the upgrade *does* fetch. **A release
   requiring operator action must be able to detect its own unmet requirement at
   runtime and say so loudly** — refusing to serve, or surfacing it unmissably —
   rather than assuming a preflight ran. The installer preflight is then what it
   honestly is: a good experience for installs that have it, not the guarantee.

   **And that check must run before the automatic migration, not at serve time.**
   `docker-entrypoint.sh` runs `php artisan migrate --force` and only then execs
   the server, so a guard that refuses to *serve* has already let the schema
   change happen — which is precisely what an `after-pull` action exists to
   precede. The database would be altered before the required manual step, and
   for the class of action that must run against the old schema, refusing to
   serve afterwards is not a safeguard but an epitaph. The artifact's check
   therefore belongs ahead of the migration in the entrypoint, halting there, or
   the release must offer a migration-suppressed start for the operator to
   complete the prerequisite from.

   **The guard is phase-aware, or it deadlocks.** Only `before-pull` and
   `after-pull` prerequisites may block the migration — those genuinely must
   precede it. An unmet `after-start` action needs the migrated schema and the
   running code in order to be performed at all, so blocking migration on it
   would withhold the very state it requires and the requirement could never be
   satisfied. Unmet `after-start` requirements therefore gate *serving*, after
   migration, not migration itself.

   (This layering question, along with the manifest shape below, grew past what a
   versioning ADR should settle and is now
   [ADR 0013](0013-upgrade-preflight-and-release-requirements.md), which also
   absorbs slice 5. What belongs *here* is the constraint that the guarantee
   cannot rest on installer-side code.)
5. **Floor enforcement** — reject a direct upgrade from below
   `minimum_upgrade_from`, with the supported stepping path.

## Consequences

**The version answers the operator's real question.** "Can I pull this?" becomes
readable off the number, with the declaration as the authoritative detail. That
is the whole point, and it is what the backups-worker release lacked.

**Releases cost more.** Every release now needs a deliberate impact judgement and
a declaration. That discipline is the price of the guarantee; a *wrong*
declaration is worse than none, because it manufactures false confidence.

**Pre-1.0 the number alone is not sufficient.** Until we reach 1.0, operators
must read the declaration, not just the digits. That argues for reaching 1.0 once
the surface stabilises, rather than living in 0.x indefinitely.

**Existing behaviour gets better for free.** The restore's hedged version
guidance collapses to one accurate instruction, and `unknown` stops being the
normal state on non-image deploys.

**Update-in-place becomes tractable.** Ordered comparison, an operator-action
flag, and an upgrade floor are exactly the primitives it needs; without them it
would have to be built on guesswork.
