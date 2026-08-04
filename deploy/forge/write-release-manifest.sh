#!/usr/bin/env bash
#
# Generate this release's manifest into the checkout, for the upgrade guard.
#
# Called by the deploy scripts from apps/server, after the release is checked
# out and BEFORE `artisan migrate` — the guard runs inside migrate and reads
# this file to decide whether the upgrade may proceed (ADR 0013).
#
# Only the image build writes /etc/wayfindr, so without this a host deployment
# has no declaration at all and the guard is silently inert: it finds no
# manifest, enforces nothing, and every requirement a release declares passes
# unnoticed on exactly the installs the container entrypoint never touches.
#
# The other half of the pair, `releases/history.json`, is committed with each
# release and so is already present in the checkout. Only the manifest for the
# release being deployed has to be produced here.
set -euo pipefail

root=$(git rev-parse --show-toplevel)

# The release this declaration belongs to is VERSION, not the identity derived
# by write-release-identity.sh.
#
# That identity is `<version>-dev+<sha>` on anything not sitting on a tag, and it
# changes with every commit. Acknowledgements are keyed `<release>/<action-id>`
# and typed by hand into the environment file, so stamping the manifest with it
# would invalidate every acknowledgement on the next deploy — the operator would
# be asked to redo work they had already done and already attested to, forever.
#
# VERSION is the release being prepared, it is stable across a cycle, and it is
# the same value the tagged build will stamp. So an acknowledgement made against
# a branch deploy still holds once that release is cut.
version=$(cat "$root/VERSION")

# Informational only — the guard decides on version. Recorded straight from HEAD
# rather than from the identity file: a dirty tree makes the identity decline to
# name a commit, which is the right answer for "what code is running" and an
# unhelpful one for "which commit was this declaration built from".
commit=$(git -C "$root" rev-parse HEAD)

# A malformed declaration aborts the deploy here, before anything migrates. That
# is the same failure the image build takes, and it is the point of the builder
# validating at all: shipping a manifest that under-declares what an operator has
# to do is worse than a deploy that stops and says so.
"${FORGE_PHP:-php}" "$root/scripts/release/build-manifest.php" \
    --version="$version" \
    --commit="$commit" \
    --out="$root/release-manifest.json"
