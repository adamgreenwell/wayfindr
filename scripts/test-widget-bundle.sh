#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENDOR_DIR="$ROOT_DIR/packages/widget-js/vendor"
VENDOR_FILE="$VENDOR_DIR/pusher.min.js"
README="$VENDOR_DIR/README.md"
DOCKERFILE="$ROOT_DIR/docker/self-hosting/server.Dockerfile"

fail() {
    echo "$1" >&2
    exit 1
}

[ -f "$VENDOR_FILE" ] || fail "The bundled realtime client is missing: $VENDOR_FILE"
[ -f "$VENDOR_DIR/pusher-js-LICENCE" ] || fail "The vendored library's licence must ship with it."

# The recorded hash is the provenance. A file swapped without updating the
# table in README.md is exactly what this catches -- vendored third-party
# bytes are only trustworthy while something checks they are the bytes that
# were reviewed.
recorded="$(grep -oE '\| SHA-256 \| `[0-9a-f]{64}` \|' "$README" | grep -oE '[0-9a-f]{64}')"
[ -n "$recorded" ] || fail "No SHA-256 recorded in $README."

if command -v shasum >/dev/null 2>&1; then
    actual="$(shasum -a 256 "$VENDOR_FILE" | cut -d' ' -f1)"
else
    actual="$(sha256sum "$VENDOR_FILE" | cut -d' ' -f1)"
fi

if [ "$recorded" != "$actual" ]; then
    fail "The vendored realtime client does not match the hash recorded in $README.
  recorded: $recorded
  actual:   $actual
Update the file and the table together, or restore the reviewed bytes."
fi

# The image must stage vendor/ as well as src/. Omitting it does not fail a
# build: the widget is simply served without realtime on an install whose
# configuration says realtime is on, which is the silent degradation this
# bundle exists to end.
grep -q 'COPY packages/widget-js/vendor' "$DOCKERFILE" \
    || fail "server.Dockerfile does not stage packages/widget-js/vendor, so the released image would serve the widget without its realtime client."

# ...and the context must actually contain it. `**/vendor` in .dockerignore
# exists to keep composer trees out, and also catches this directory: with it
# ignored, BuildKit cannot checksum the COPY source and EVERY image build
# fails with "packages/widget-js/vendor: not found".
#
# Checking the COPY line alone was not enough -- that check passed while the
# build was broken. Order matters too, because a later .dockerignore rule wins,
# so the negation has to come after the pattern it is overriding.
DOCKERIGNORE="$ROOT_DIR/.dockerignore"
[ -f "$DOCKERIGNORE" ] || fail "No .dockerignore at $DOCKERIGNORE."

ignore_line="$(grep -nxF '**/vendor' "$DOCKERIGNORE" | head -1 | cut -d: -f1 || true)"
unignore_line="$(grep -nxF '!packages/widget-js/vendor' "$DOCKERIGNORE" | head -1 | cut -d: -f1 || true)"

if [ -n "$ignore_line" ]; then
    [ -n "$unignore_line" ] || fail ".dockerignore excludes packages/widget-js/vendor via '**/vendor' and never unignores it, so every self-hosting image build fails on the COPY."
    [ "$unignore_line" -gt "$ignore_line" ] \
        || fail ".dockerignore unignores packages/widget-js/vendor on line $unignore_line, BEFORE the '**/vendor' rule on line $ignore_line. A later rule wins, so the directory is still excluded."
fi

# No path may reintroduce the third-party fetch.
if grep -rn 'js\.pusher\.com' \
    "$ROOT_DIR/apps/server/app" \
    "$ROOT_DIR/apps/server/resources" \
    "$ROOT_DIR/docs" \
    "$ROOT_DIR/packages/widget-js/README.md" 2>/dev/null | grep -vE 'WidgetScriptController\.php|vendor/README\.md' | grep -q .; then
    echo "A CDN script reference has come back:" >&2
    grep -rn 'js\.pusher\.com' \
        "$ROOT_DIR/apps/server/app" \
        "$ROOT_DIR/apps/server/resources" \
        "$ROOT_DIR/docs" \
        "$ROOT_DIR/packages/widget-js/README.md" 2>/dev/null | grep -vE 'WidgetScriptController\.php|vendor/README\.md' >&2
    exit 1
fi

# A size budget on what a visitor's browser actually downloads (#955).
#
# Every visitor of every page of every install fetches this, so growth here is
# multiplied by more than anything else in the product. Until this check existed
# there was no budget anywhere and nothing would have reported a doubling.
#
# The yardstick is GZIPPED bytes, because that is what crosses the wire; `gzip -9`
# is used so the number is reproducible rather than dependent on whatever level a
# given nginx is configured for. Raw size is reported alongside for context but is
# not what the budget is set against.
#
# These ceilings are deliberately close to today's figures. Raising one is a fine
# thing to do -- it just has to be a decision somebody took, rather than a drift
# nobody saw.
WIDGET_SRC="$ROOT_DIR/packages/widget-js/src/wayfindr-widget.js"
WIDGET_SRC_GZIP_BUDGET=85000

[ -f "$WIDGET_SRC" ] || fail "The widget source is missing: $WIDGET_SRC"

src_raw="$(wc -c < "$WIDGET_SRC" | tr -d ' ')"
src_gzip="$(gzip -9 -c "$WIDGET_SRC" | wc -c | tr -d ' ')"

if [ "$src_gzip" -gt "$WIDGET_SRC_GZIP_BUDGET" ]; then
    fail "The widget source is over its size budget.
  gzipped: $src_gzip bytes (budget $WIDGET_SRC_GZIP_BUDGET, raw $src_raw)
Every visitor of every page of every install downloads this. Either bring it back
under the budget, or raise WIDGET_SRC_GZIP_BUDGET in this script deliberately and
say why in the commit message. The source is unminified today, so there is room."
fi

# The SERVED payload is budgeted in PHP, not here -- see
# WidgetScriptBundleTest, 'the served widget payload stays within its size
# budget'. This script can read the source files but it cannot produce the
# response: bundledRealtime() wraps the vendored client in a globals-restoring
# IIFE and joins it to the widget with a newline, so concatenating the two
# files under-reports the real body and makes every future byte of that wrapper
# invisible. Near a ceiling that is the difference between a guard and a
# decoration, so the measurement was moved to where the bytes actually exist.

echo "Widget source within budget: ${src_gzip}B gzipped (raw ${src_raw}B)."
echo "Widget bundles its realtime client, with recorded provenance, and the image ships it."
