# Release Manifest

Every Wayfindr release publishes a machine-readable declaration of what it
requires of an operator. This is the field reference for authoring one.

The rules it encodes come from [ADR 0012](../decisions/0012-platform-versioning.md)
(what a release declares) and
[ADR 0013](../decisions/0013-upgrade-preflight-and-release-requirements.md)
(how it is enforced). This page is the practical companion to both.

## Two files, and why

**`release.json`** at the repository root is authored by hand, like the
CHANGELOG's `Unreleased` section. It describes the release being prepared.

**The published manifest** is generated from it at build time by
`scripts/release/build-manifest.php`. Three fields are *derived* and must never
be authored:

| field | derived from |
| --- | --- |
| `version`, `commit` | the release identity (`/etc/wayfindr/version`, ADR 0012) |
| `requires_operator_action` | whether `actions` is empty |
| each action's `release` | the version being built |

`requires_operator_action` is derived because a hand-maintained boolean beside
the list it summarises drifts, and it drifts in the dangerous direction: `false`
while actions exist.

The generated manifest goes to two places, from the same builder so they cannot
disagree:

- **baked into the image** (`/etc/wayfindr/release.json`, plus
  `/etc/wayfindr/release-history.json`) — read by the guard, offline, before
  migrations run
- **published as a release asset** — read by the installer preflight, which must
  evaluate releases it never pulls

## Authoring `release.json`

```json
{
  "minimum_upgrade_from": "0.1.0-alpha.1",
  "actions": [
    {
      "id": "backups-queue-worker",
      "summary": "Run a second queue worker on the dedicated backups connection.",
      "detail": "Longer explanation, including the exact command.",
      "phase": "after-start",
      "depends_on_release": "code",
      "applicability": { "type": "always" },
      "verification": { "type": "check", "check": "backups-queue-consumer" }
    }
  ]
}
```

Keys beginning with `_` are treated as comments and stripped before publication.

An empty `actions` list is the normal case and means the release is safe to take
unattended.

### `minimum_upgrade_from`

The oldest version that may upgrade **directly** to this one. Older installs must
step through an intermediate release. This is what lets an old migration path be
retired without stranding anyone silently, and it is what bounds the history
baked into the image.

### `id`

A lowercase slug, unique within the release. Operators type these by hand into
`WAYFINDR_ACKNOWLEDGED_ACTIONS`, so they are validated as `a-z0-9` with single
hyphens — no spaces, underscores, or capitals.

### `summary` and `detail`

`summary` is one line, shown wherever space is tight. `detail` carries the exact
command or step.

Write these for someone whose upgrade has just stopped. The guard refuses to
start rather than half-upgrading, so this text *is* the recovery path — a halt
without a clear instruction is a worse outcome than no guard at all.

### `phase`

When the action must be performed, relative to the upgrade:

| phase | the moment | available |
| --- | --- | --- |
| `before-pull` | while the old release is still live | old code, old schema |
| `after-pull` | new code present, **migrations not yet run** | new code, old schema |
| `after-start` | new release live, migrations done | everything |

`after-pull` exists for a manual data migration that needs the new code but must
precede the schema change.

**`before-pull` is only detected, never prevented.** The artifact guard first
runs when the new image starts, which is after the pull — the phase has already
passed. Only the installer preflight can stop it being missed, and that is the
component which may not exist on an older install. What the artifact guarantees
is no *silent* progression: it refuses to migrate and tells the operator to roll
back, act, and upgrade again. So use `before-pull` sparingly, and only when that
rollback recovery genuinely exists.

### `depends_on_release`

What the action needs *from the release it belongs to* in order to be performed:
`none`, `code`, or `schema`.

This decides whether a direct jump may skip past it. An action needing `v2`'s
code is unperformable in a `v1 -> v3` upgrade at any phase, because `v2`'s code
is never present.

Some combinations are impossible and are rejected at build time:

| phase | may depend on |
| --- | --- |
| `before-pull` | `none` |
| `after-pull` | `none`, `code` |
| `after-start` | `none`, `code`, `schema` |

A `before-pull` action cannot need the new release's code or schema — neither
exists yet. An `after-pull` action cannot need the new schema, since that is
precisely what has not been migrated.

### `applicability`

Which upgrades the action actually applies to.

| type | meaning |
| --- | --- |
| `always` | applies to every upgrade reaching this release |
| `upgrade-from` | applies only above a `min` starting version |
| `state` | applies only when an observable `check` says so |

This exists because a pointer at an earlier action cannot express **retirement**.
If `v2` adds a worker and `v3` removes the need for it, `v3` must tell an install
that *ran* `v2` to remove it, while telling a direct `v1 -> v3` upgrade nothing —
that install never created it. One action cannot mean both, so a retirement
stands alone as its own entry, conditioned on where the upgrade started or on
observable state.

### `verification`

How the artifact decides whether the action has been done.

| type | meaning |
| --- | --- |
| `check` | a machine-evaluable condition, named by `check` |
| `attest` | the operator acknowledges it explicitly |

Prefer `check` wherever the condition is expressible: it is real verification and
the operator cannot be wrong about it. A `check` without a named condition is
rejected — that is an attestation wearing a verification's label.

Use `attest` only when the artifact genuinely cannot observe completion ("you
have taken a backup you trust"). The operator satisfies it with:

```dotenv
WAYFINDR_ACKNOWLEDGED_ACTIONS=0.2.0/backups-queue-worker
```

Each entry is `<release>/<action-id>`, so an acknowledgement is specific to the
action that required it and can never become a blanket opt-out.

The release part is the **canonical** version — no leading `v`. The release
workflow passes the git tag verbatim, so the same release would otherwise arrive
as `v0.2.0` from an official build and `0.2.0` from a source one, and an
acknowledgement typed for one would satisfy nothing on the other. The builder
canonicalises it, so `v0.2.0` and `0.2.0` produce the same key.

What a `check` may inspect follows its phase, because that is when it runs:

- `before-pull` and `after-pull` checks run **before** migration, so they may read
  infrastructure and the *old* schema but must not assume the new one. A check
  querying a table the pending migration creates would fail on exactly the
  upgrades it exists to guard.
- `after-start` checks run **after** migration, so the new schema is present and
  is often what they need.

## What the guard does at upgrade time

The declaration is not advice — the release enforces it against itself.

**Before migrating.** Any unmet `before-pull` or `after-pull` requirement stops
`artisan migrate` before a single schema change is applied, prints what is
needed, and exits **78**. The container entrypoint treats that code as a refusal
rather than a transient failure, so it stops immediately instead of retrying.
Nothing has been changed, and the previous release still runs.

**After migrating.** An unmet `after-start` requirement cannot block migration —
the action needs the migrated schema to be performed at all — so it gates
serving instead. The release starts, returns `503` with the outstanding
requirements on every route, and keeps `/up` answering so an orchestrator does
not restart it in a loop.

To see where an install stands without attempting an upgrade:

```bash
php artisan wayfindr:upgrade-guard          # human readable
php artisan wayfindr:upgrade-guard --json   # for tooling
```

It exits non-zero when something is outstanding.

### Where the upgrade is measured from

After a successful migration the release records its identity to
`storage/app/release-state.json`, so the next upgrade knows where it started and
which declarations its span covers.

An install with no such file is **not** assumed to be fresh — every install
predating this mechanism has none. If the database already carries migrations,
it is treated as a legacy upgrade from an unknown starting point, and the whole
published history is evaluated. That is louder than necessary for someone who
was already current, and it is the safe direction: the alternative is silence for
the installs with the furthest to travel.

### The first enforcing release declares nothing

The release that introduces the guard must require nothing of an operator, or it
refuses traffic on every install the moment it lands — including those already
doing the right thing. `release.json` is therefore empty for that release, and
`tests/Feature/BootstrapSelfEnforcementTest.php` fails if an action is added back
without the constraint being reconsidered.

## At release time

`RELEASING.md` carries the step. The declaration is appended to
`releases/history.json`, which is committed with the release:

```bash
php scripts/release/build-manifest.php --version=0.2.0 --history=releases/history.json
```

The declaration is **not** cleared at this point. The tagged tree is what the
release workflow and the image build both read, and both regenerate this
release's manifest from `release.json` — so emptying it before the tag would
publish an asset, and bake a history entry, declaring that no operator action is
required. That would overwrite the entry this very command just recorded.

Clearing happens after the tag, alongside advancing `VERSION` for the next cycle:

```bash
php scripts/release/build-manifest.php --version=0.3.0 --reset-declaration
```

Without it the next release rebuilds the same actions under its own version, and
an operator who already acknowledged `0.2.0/thing` is asked again for
`0.3.0/thing` — work they have demonstrably already done. The floor and any
comment keys are preserved; only `actions` is cleared.

That file is the record of what published releases required, and the image bakes
it. It is how a later release knows what an intermediate one asked for: a
`v1 -> v3` upgrade must learn `v2`'s requirements from somewhere, and at build
time they exist nowhere else.

No `--commit` is passed there — the release commit does not exist yet, and
recording its parent would be wrong. The image build stamps the real identity
into the copy it bakes.

## Checking your work

A malformed declaration fails the build rather than shipping a manifest that
under-declares what an operator must do. To check before committing:

```bash
php scripts/release/build-manifest.php --version=0.0.0-test
```

It prints the manifest it would publish, or exits non-zero naming the problem.

What it checks, specifically:

- every required field is present, and `summary` and `detail` are non-empty
  strings — the guard halts an upgrade with that text, so an empty one is a halt
  with no recovery in it
- `id` is a unique lowercase slug
- `phase`, `depends_on_release`, and the applicability and verification types are
  known values, and the phase/dependency pair is one that can actually be
  performed
- a `check` names a condition to run
- `minimum_upgrade_from` and an `upgrade-from` `min` parse as versions, since
  they are compared against the upgrade's starting point — a value that does not
  parse would silently make an action apply to every upgrade, including the ones
  it was written to exclude

What it does **not** check is whether the `detail` you wrote is *useful*, or
whether a named `check` is implemented. Those are review's job.
