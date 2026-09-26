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

# The image must stage vendor/ as well as the widget build. Omitting it does not
# fail a build: the widget is simply served without realtime on an install whose
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
# The yardstick is GZIPPED bytes, because that is what crosses the wire, at level
# 9 so the figure does not depend on whatever level a given nginx is configured
# for. Raw size is reported alongside for context but is not what the budget is
# set against.
#
# Measured with PHP's gzencode rather than the system gzip, and that matters more
# than it looks. Apple gzip, GNU gzip and zlib disagree by a hundred bytes or two
# on the same input, so shelling out made this number a property of whoever ran
# it: a figure read on a Mac was wrong in CI, and two successive attempts to
# write it down accurately were wrong for that reason rather than for want of
# care. gzencode gives the same answer everywhere.
#
# It is also the SAME compressor the served-payload budget uses, in
# apps/server/tests/Feature/WidgetScriptBundleTest.php. Those two numbers now
# measure one artifact the same way, which is what that test's comment had
# claimed before it was true.
#
# These ceilings are deliberately close to today's figures. Raising one is a fine
# thing to do -- it just has to be a decision somebody took, rather than a drift
# nobody saw.
WIDGET_BUILD="$ROOT_DIR/packages/widget-js/dist/wayfindr-widget.min.js"
# The budget is on the MINIFIED build, because that is the file a visitor
# downloads: WidgetScriptController serves dist/, not src/ (Sept 2026).
#
# The source budget before it was raised four times in a month (85000 -> 90000
# -> 92500 -> 93500), and the note on the last raise said the next change that
# needed room should minify instead -- the source is written to be read, and
# about half of it is comments. The widget-length-limit change needed room, the
# owner chose to minify, and the same source went from 93087 bytes gzipped to
# 31895 for the build. That headroom is the point of having done it; the
# ceiling below keeps the old discipline -- about 10% over today's figure, and
# raising it is a decision someone writes down, not a drift.
#
# The source itself is no longer budgeted: comments cost a visitor nothing now.
# Keeping the build honest about the source is `npm run check:build` in CI,
# which rebuilds and fails on any difference.
WIDGET_BUILD_GZIP_BUDGET=35000

[ -f "$WIDGET_BUILD" ] || fail "The minified widget is missing: $WIDGET_BUILD
Run \`npm run build\` in packages/widget-js and commit dist/."

# The image must stage the build, since that is what the controller reads.
grep -q 'COPY packages/widget-js/dist' "$DOCKERFILE" \
    || fail "server.Dockerfile does not stage packages/widget-js/dist, so the released image has no widget to serve."

command -v php >/dev/null 2>&1 || fail "php is required to measure the widget bundle.
This script pins the compressor to PHP's gzencode so the figure is the same on
every machine and matches the served-payload budget in
apps/server/tests/Feature/WidgetScriptBundleTest.php.

Install PHP and put it on PATH -- 'brew install php' on macOS, 'apt install
php-cli' on Debian or Ubuntu. This measurement needs only PHP with zlib, which
is on by default; the wider suite needs 8.4.1 or newer, which
docs/self-hosting/runtime-requirements.md covers."

build_raw="$(wc -c < "$WIDGET_BUILD" | tr -d ' ')"
build_gzip="$(php -r 'echo strlen(gzencode(file_get_contents($argv[1]), 9));' "$WIDGET_BUILD")"

if [ "$build_gzip" -gt "$WIDGET_BUILD_GZIP_BUDGET" ]; then
    fail "The minified widget is over its size budget.
  gzipped: $build_gzip bytes (budget $WIDGET_BUILD_GZIP_BUDGET, raw $build_raw)
Every visitor of every page of every install downloads this. Either bring it back
under the budget, or raise WIDGET_BUILD_GZIP_BUDGET in this script deliberately
and say why in the commit message."
fi

# The SERVED payload is budgeted in PHP, not here -- see
# WidgetScriptBundleTest, 'the served widget payload stays within its size
# budget'. This script can read the source files but it cannot produce the
# response: bundledRealtime() wraps the vendored client in a globals-restoring
# IIFE and joins it to the widget with a newline, so concatenating the two
# files under-reports the real body and makes every future byte of that wrapper
# invisible. Near a ceiling that is the difference between a guard and a
# decoration, so the measurement was moved to where the bytes actually exist.

echo "Minified widget within budget: ${build_gzip}B gzipped (raw ${build_raw}B)."
echo "Widget bundles its realtime client, with recorded provenance, and the image ships it."
