#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE="$ROOT_DIR/packages/design-tokens/tokens.json"
GENERATOR="$ROOT_DIR/scripts/generate-design-tokens.php"
BLADE="$ROOT_DIR/apps/server/resources/views/components/layouts/app.blade.php"
WIDGET="$ROOT_DIR/packages/widget-js/src/wayfindr-widget.js"

fail() {
    echo "$1" >&2
    exit 1
}

[ -f "$SOURCE" ] || fail "The token source is missing: $SOURCE"
[ -f "$GENERATOR" ] || fail "The token generator is missing: $GENERATOR"

php -l "$GENERATOR" >/dev/null || fail "The token generator does not parse."

# The markers are the contract. A consumer that loses them does not fail loudly
# on its own -- it simply stops receiving tokens while still looking styled,
# which is the drift this whole mechanism exists to prevent.
for marker in 'wayfindr:tokens:start' 'wayfindr:tokens:end'; do
    grep -q "$marker" "$BLADE" || fail "The dashboard layout has lost its '$marker' marker: $BLADE"
    grep -q "$marker" "$WIDGET" || fail "The widget has lost its '$marker' marker: $WIDGET"
done

# The generated blocks must match what the source renders today.
php "$GENERATOR" --check || fail "Run 'make design-tokens' and commit the regenerated blocks."

# The widget's tokens land inside a single-quoted JavaScript string literal, so
# a stray apostrophe in a value does not produce a bad rule -- it stops the
# entire file parsing, and the widget disappears from every customer's site.
# This is not hypothetical: the first generated run did exactly that, because
# font stacks carry quoted family names. Parse it every time.
if command -v node >/dev/null 2>&1; then
    node --check "$WIDGET" >/dev/null || fail "The widget no longer parses as JavaScript after token generation."
else
    echo "note: node is unavailable, so the widget parse check was skipped." >&2
fi

# Tokens may be USED anywhere (var(--wf-...)); they may only be DEFINED by the
# generator. A hand-written definition outside the block is invisible drift --
# it works locally and silently diverges from the source of truth.
for file in "$BLADE" "$WIDGET"; do
    stray="$(awk '/wayfindr:tokens:start/{inside=1} /wayfindr:tokens:end/{inside=0; next} !inside' "$file" \
        | grep -nE -- '--wf-[a-z0-9-]+[[:space:]]*:' || true)"

    if [ -n "$stray" ]; then
        echo "A design token is defined outside the generated block in ${file#"$ROOT_DIR/"}:" >&2
        echo "$stray" >&2
        fail "Add it to packages/design-tokens/tokens.json and regenerate instead."
    fi
done

# The widget must resolve every colour through a token, not repeat one. This is
# the drift that started ADR 0014: the dashboard and the widget shared five hex
# values as duplicated literals in two languages with nothing watching them.
#
# The generated block is excluded because it is where the values legitimately
# live; everything else in the injected stylesheet must reference them.
#
# box-shadow is excluded too: the widget floats over a page Wayfindr does not
# own, and its separation shadows are tuned to sit on an unknown background
# rather than to match either of our themes.
widget_css_hex="$(awk '/style\.textContent = \[/{inside=1} /\]\.join\(/{inside=0} inside' "$WIDGET" \
    | awk '/wayfindr:tokens:start/{skip=1} /wayfindr:tokens:end/{skip=0; next} !skip' \
    | grep -v 'box-shadow' \
    | grep -nE '#[0-9a-fA-F]{3,8}\\b|rgba?\\(' || true)"

if [ -n "$widget_css_hex" ]; then
    echo "The widget stylesheet hardcodes a colour instead of using a token:" >&2
    echo "$widget_css_hex" >&2
    fail "Add it to packages/design-tokens/tokens.json and reference it as var(--wf-...)."
fi

# The dead Tailwind pipeline is gone and must stay gone: it was configured,
# built on every pull request, and referenced by no view (ADR 0014). Left in
# place it tells the next contributor that utilities are available when they
# are not.
if [ -f "$ROOT_DIR/apps/server/vite.config.js" ] || [ -f "$ROOT_DIR/apps/server/resources/css/app.css" ]; then
    fail "The unused Vite/Tailwind pipeline has returned. ADR 0014 removed it; adopt it deliberately or leave it out."
fi

echo "Design tokens are generated from one source, both consumers are in sync, and the widget parses."
