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
underlined the root problem: **a meaningful fraction of installs carry no
identity at all**, because only the official image bakes one and source/Forge
deploys set nothing.

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
- **Source and Compose builds** — stamp `<next-version>-dev+<short-sha>` instead
  of today's literal `source`, so a development install still sorts and still
  identifies its code.
- **Deploys that build from source on the host** (Forge, and ADR 0003's path) —
  document `WAYFINDR_VERSION` as part of the expected environment, since nothing
  bakes it for them.
- **`wayfindr_commit` is the fallback identity.** It resolves independently of
  the version and is already captured in every backup manifest, so an archive
  whose version is unset can still be traced to its code.

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

### Version comparison is ordered and explicit

Comparisons use an ordered SemVer comparator, not string equality. Two
consequences follow immediately: the restore can tell an operator whether an
archive is *older or newer* rather than hedging both remedies, and the upgrade
path can evaluate `minimum_upgrade_from` and refuse an unsupported jump.

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

1. **This ADR, `CHANGELOG.md`, and the release-notes convention.** Docs only;
   establishes the contract before anything depends on it.
2. **Identity everywhere.** Source/Compose builds stamp `-dev+<sha>`;
   `WAYFINDR_VERSION` documented for host-build deploys; `source`/null retired as
   normal outcomes.
3. **Ordered comparator**, and the restore's skew guidance becomes
   direction-aware (replacing the current "if older … if newer …" hedge).
4. **Release manifest** (`requires_operator_action`, `minimum_upgrade_from`)
   published with each release, and `install.sh --upgrade` gains a preflight that
   reads it and refuses or warns before pulling.
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
