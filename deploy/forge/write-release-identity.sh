#!/usr/bin/env bash
#
# Derive this release identity and write it into the Forge environment file.
#
# Called by the deploy script from apps/server, after the release is checked
# out and before the artisan cache steps - the identity is resolved in a config
# file, so it must be written before config:cache bakes it.
#
# This lives in the repository rather than inline in the Forge deploy script
# editor. A 200-line block pasted into that editor proved unreliable, and
# keeping it here means it is version-controlled, reviewable, and testable.
set -euo pipefail

# Insert before the artisan cache steps. Both scripts have changed into
# `apps/server` by that point, so nothing here may assume the repository root is
# the working directory: `VERSION` lives at the root, and the clean-tree gate
# below would otherwise inspect only `apps/server` and miss a change anywhere
# else in the repo. Resolve the root once and run every path-sensitive command
# through it.
#
# `git ls-files` is scoped to the current directory on its own, and the gate's
# `git diff` calls take a `.` pathspec so that the shared path can be excluded -
# which makes them cwd-sensitive too. Do not drop the `-C "$root"` from either on
# the grounds that a bare `git diff` is repo-wide; these are not bare.
# `git tag` and `git rev-parse` need no help.
root=$(git rev-parse --show-toplevel)

# A checkout sitting exactly on a tag reports that tag's canonical version;
# anything else is a development build. Deriving this rather than hand-setting it is what keeps a
# tag-pinned site honest when it later moves off the tag.
# Pick the highest release-version tag pointing at HEAD.
#
# Enumerate rather than use `git describe --exact-match`, which returns only ONE
# tag: with both `v1.2.3` and a movable `production` on the same commit it may
# return the alias, and adding an alias would then change the identity of an
# unchanged release.
#
# The grammar is anchored and explicit about digits, because shell globs are not
# (`v[0-9]*.[0-9]*.[0-9]*` also accepts `v1-production.2.3`, `1a.2b.3c`,
# `v1.2.3.4`). Matching the whole string doubles as the safety check: git permits
# `&` and `|` in ref names and both are hostile in the sed below - `&` expands to
# the matched text, `|` closes the expression.
#
# It is the real SemVer grammar rather than a digits-and-dots approximation,
# assembled from named parts because one anchored expression would be unreadable.
# ADR 0012 adopts SemVer and plans a parser, so a tag this accepts must be one
# that parser will accept: `01.2.3`, `1.2.3-01` and `1.2.3-alpha..1` all look
# like versions and are none of them valid, and stamping one as an exact release
# identity would record a version nothing downstream can parse.
#
# A trailing `-dev` is reserved for development identities (ADR 0012); a tag
# using it would be recorded as exact here while the restore treats it as
# unverifiable, so it is excluded too.
#
# Anything rejected falls back to the SHA-qualified development identity, which
# is the honest description of a checkout that is not on a release.
# Both Forge deploy scripts run `set -euo pipefail`, so a `grep` that matches
# nothing would abort the deploy - precisely on the untagged branch deploy this
# fallback exists to serve. `|| true` keeps the no-match path successful.
# Canonicalize once, here, rather than at each place that reads this list. ADR
# 0012 makes the unprefixed form canonical, and folding `v1.2.3` into `1.2.3` at
# the boundary means `1.2.3` and its `v1.2.3` alias are one entry rather than
# two - so adding an alias cannot change what is selected, how many candidates
# there appear to be, or the identity of unchanged code. Every step below can
# then assume unprefixed input and compare like with like.
# No leading zeroes in the three core numbers.
semver_core='(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)'
# A prerelease identifier is numeric without leading zeroes, or contains a
# non-digit. Dot-separated, and none of them may be empty.
semver_pre_id='(0|[1-9][0-9]*|[0-9]*[a-zA-Z-][0-9a-zA-Z-]*)'
semver_pre="(-${semver_pre_id}(\.${semver_pre_id})*)?"
# Build metadata is looser: any non-empty alphanumeric-or-hyphen identifiers.
semver_build='(\+[0-9a-zA-Z-]+(\.[0-9a-zA-Z-]+)*)?'

release_tags=$(git tag --points-at HEAD 2>/dev/null \
  | grep -E "^v?${semver_core}${semver_pre}${semver_build}$" \
  | grep -vEi -- '-dev$' \
  | sed -E 's/^v//' \
  | sort -u || true)

# Prefer a stable tag over a prerelease on the same commit. `sort -V` is natural
# ordering, not SemVer precedence: it ranks `1.2.3-alpha` ABOVE `1.2.3`, so a
# promoted release would otherwise be stamped with its prerelease name.
#
# A prerelease is a hyphen immediately after the version core - NOT any hyphen,
# which would misread `1.2.3+build-1` (a stable release with a hyphen in its
# build metadata) as a prerelease and hand the identity back to the alpha tag.
# The `v?` is belt-and-braces: the list is unprefixed by construction, and this
# keeps the filter correct rather than silently wrong if that ever changes.
#
# Sorting is safe now that every candidate is unprefixed. On mixed input it is
# not - `sort -V` compares the surrounding text too, so a digit sorts before `v`
# and `1.3.0` alongside `v1.2.3` would select the OLDER tag. Canonicalizing at
# the boundary is what removes that hazard, rather than a decorated sort key here.
#
# Nothing normalizes the `v` on read yet - RestoreService still compares with a
# plain `!==` - so recording the canonical form is what keeps a site's own
# backups comparable across an alias being added. Official images are still
# stamped verbatim by release-image.yml, so an archive from one reads
# `v0.1.0-alpha.3` against a Forge install's `0.1.0-alpha.3`; that pair compares
# unequal, which warns rather than proceeds, and ADR 0012's read-side
# normalization (slice 3) is what reconciles the two writers for good.
tag=
stable=$(printf '%s\n' "$release_tags" | grep -vE '^v?[0-9]+\.[0-9]+\.[0-9]+-' || true)

# Precedence ignores build metadata (SemVer section 10), so `1.2.3` and `1.2.3+build.1`
# do not rank against each other - they tie. `sort -V | tail -1` would hand the
# identity to whichever sorts last, and adding a metadata alias to a released
# commit would then rename code that has not changed.
#
# Metadata cannot simply be stripped to break the tie: ADR 0012 treats two builds
# differing only in build metadata as different code, so folding them together
# would equate builds that are not the same - a fail-open, and a worse one than
# the problem it solves.
#
# So rank on the metadata-free key, then look at everything holding the top rank.
stable_ranked=$(printf '%s\n' "$stable" | sed -E 's/^([^+]*)(.*)$/\1 \1\2/')
top_precedence=$(printf '%s\n' "$stable_ranked" | cut -d' ' -f1 | sort -V | tail -1)
top_stable=$(printf '%s\n' "$stable_ranked" | awk -v k="$top_precedence" '$1 == k { print $2 }')

# One tag at the top rank is the release. Several means they differ only in build
# metadata, which SemVer does not order - the same position as two prereleases,
# answered the same way: decline, and let the development identity say so.
if [ "$(printf '%s\n' "$top_stable" | grep -c .)" = 1 ]; then
  tag=$top_stable
fi

# No stable tag: take a prerelease only when it is unambiguous. `sort -V` is not
# SemVer precedence for prerelease identifiers either - SemVer ranks `alpha.beta`
# above `alpha.1`, `sort -V` inverts it - and rather than implement that
# comparison here, decline to choose. Picking arbitrarily would let a later alias
# change the identity of unchanged code and report skew between identical builds.
# The count is over canonical, deduplicated versions, so `1.2.3-alpha.1` and its
# `v1.2.3-alpha.1` alias count once. Counting raw tags would read that pair as
# two candidates, call an unambiguous release ambiguous, and drop a site that had
# been identifying as `1.2.3-alpha.1` down to a development identity.
if [ -z "$tag" ] && [ "$(printf '%s\n' "$release_tags" | grep -c .)" = 1 ]; then
  tag=$release_tags
fi

# `tag` is empty here in three cases, all deliberate: no release tag at all, two
# or more stable tags tied on precedence, or two or more prereleases with no
# stable tag. The last two are declined answers rather than missing ones - if a
# future reader takes an empty `tag` for an oversight and resolves it by picking
# a candidate, that reintroduces an alias renaming unchanged code. The only thing
# below that may touch it is the dirty-tree gate, which clears it.

# A commit names the deployed code only when the tree matches it. Forge's
# zero-downtime path makes a fresh checkout per release, so this passes by
# construction - but the standard path runs `git pull` in a persistent checkout
# (deploy/forge/standard-deploy.sh) that can carry local edits, and a sha
# stamped from a modified tree names code that is not what is running. Worse,
# a clean checkout and any number of differently-modified ones would then all
# claim the SAME identity, which is a fail-open in the restore's skew check.
#
# "Dirty" means tracked modifications AND non-ignored untracked files: a
# brand-new migration is invisible to a tracked-changes check but is very much
# part of what is deployed.
#
# The shared path has to be excluded or this check fires on every healthy
# zero-downtime release. Forge's `$CREATE_RELEASE()` installs shared paths before
# this block runs, replacing `apps/server/storage` with a symlink - so git sees
# the ten tracked `.gitignore` files under it as deleted, and the symlink itself
# as untracked. That is the platform doing exactly what it was configured to do,
# and it says nothing about whether the deployed code matches HEAD. Left in, it
# would put every clean release on the unverifiable identity and print a warning
# each time, which is how operators learn to ignore warnings.
#
# Excluded by path rather than by ignoring deletions generally: this hides only
# the directory Forge is documented to share, and a modified migration or a new
# untracked file anywhere else is still caught.
shared_paths=':!apps/server/storage'

if git -C "$root" diff --quiet -- . "$shared_paths" \
   && git -C "$root" diff --cached --quiet -- . "$shared_paths" \
   && [ -z "$(git -C "$root" ls-files --others --exclude-standard -- . "$shared_paths")" ]; then
  commit=$(git rev-parse HEAD)
else
  # A tag names a commit, not a modified copy of one, so a dirty tree cannot
  # claim a release either.
  commit=
  tag=
  echo 'WARNING: working tree is not clean - recording an unverifiable identity.'
fi

# No commit means identify by lineage only. The bare `-dev` is deliberate: ADR
# 0012 makes it never compare equal to anything, so the restore treats it as
# indeterminate and warns rather than asserting a match it cannot support.
if [ -n "$commit" ]; then
  version=${tag:-"$(cat "$root/VERSION")-dev+$commit"}
else
  version=${tag:-"$(cat "$root/VERSION")-dev"}
fi

# Running from `apps/server`, this `.env` is the link both deploy scripts create
# to the Forge environment file at the repository root, so it is a symlink on
# every site type rather than only on zero-downtime ones. Record what it was
# rather than assuming, so the check below still fits if you relocate this block.
#
# A regular file here means the link was already broken before this deploy - most
# likely by an earlier version of this snippet, which used a bare `sed -i` and so
# let GNU sed replace the link with a copy. Everything below still writes the
# identity to the file the app reads, so the version stays correct; what is lost
# is the Environment panel, whose edits now go to a file nobody reads. Say so
# rather than repairing it: that copy may hold the only version of values that
# were edited into it, and a deploy script is the wrong place to silently discard
# an operator's configuration. See "Repairing a detached environment file".
if [ ! -L .env ]; then
  echo 'WARNING: .env is a regular file, not a link to the Forge environment file.'
  echo '         Environment panel edits are not reaching this site.'
fi

was_link=0; [ -L .env ] && was_link=1

# Make sure each key exists before substituting, because a substitution writes
# nothing when there is no matching line. An .env created before these two keys
# joined the template above has no such line, so the deploy would succeed, report
# nothing, and leave the identity on its fallback - the worst shape a failure can
# take, because it looks like it worked.
#
# The leading newline matters: an .env whose last line has no newline of its own
# would otherwise get the new key spliced onto the end of it. A blank line in an
# .env is harmless, and this only ever runs once per key.
#
# Deliberately written without a helper function. Forge substitutes its own
# `$CREATE_RELEASE()` macro into this script before running it, and a deploy that
# added a function definition here failed with the macro left unexpanded - bash
# then read `$CREATE_RELEASE()` as a malformed function definition and died on
# the next line. Whatever the mechanism, plain top-level commands are what this
# editor is known to survive.
grep -q '^WAYFINDR_VERSION=' .env || printf '\nWAYFINDR_VERSION=\n' >> .env
grep -q '^WAYFINDR_COMMIT=' .env || printf '\nWAYFINDR_COMMIT=\n' >> .env

sed -i --follow-symlinks "s|^WAYFINDR_VERSION=.*|WAYFINDR_VERSION=${version}|" .env
sed -i --follow-symlinks "s|^WAYFINDR_COMMIT=.*|WAYFINDR_COMMIT=${commit}|" .env

# If it was a shared symlink, it must still be one. Written as an `if` rather
# than an `&&` chain because this is the last line of the snippet: an `&&` chain
# whose warning does not fire exits non-zero, which is fine mid-script but fails
# the whole deploy for anyone who pastes this block at the end of theirs.
if [ "$was_link" = 1 ] && [ ! -L .env ]; then
  echo 'WARNING: .env is no longer a symlink - this release is detached from the Environment panel.'
fi
