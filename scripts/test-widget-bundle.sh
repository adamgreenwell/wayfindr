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

# No path may reintroduce the third-party fetch.
if grep -rn 'js\.pusher\.com' \
    "$ROOT_DIR/apps/server/app" \
    "$ROOT_DIR/apps/server/resources" \
    "$ROOT_DIR/docs" 2>/dev/null | grep -vE 'WidgetScriptController\.php|vendor/README\.md' | grep -q .; then
    echo "A CDN script reference has come back:" >&2
    grep -rn 'js\.pusher\.com' \
        "$ROOT_DIR/apps/server/app" \
        "$ROOT_DIR/apps/server/resources" \
        "$ROOT_DIR/docs" 2>/dev/null | grep -vE 'WidgetScriptController\.php|vendor/README\.md' >&2
    exit 1
fi

echo "Widget bundles its realtime client, with recorded provenance, and the image ships it."
