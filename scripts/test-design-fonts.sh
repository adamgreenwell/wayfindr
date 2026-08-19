#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FONT_DIR="$ROOT_DIR/apps/server/public/fonts"
README="$ROOT_DIR/docs/development/typography.md"
LAYOUT="$ROOT_DIR/apps/server/resources/views/components/layouts/app.blade.php"
DOCKERFILE="$ROOT_DIR/docker/self-hosting/server.Dockerfile"
DOCKERIGNORE="$ROOT_DIR/.dockerignore"

fail() {
    echo "$1" >&2
    exit 1
}

[ -d "$FONT_DIR" ] || fail "The vendored typefaces are missing: $FONT_DIR"
[ -f "$README" ] || fail "The typeface provenance record is missing: $README"

# Nothing but fonts and the licence may live under public/fonts: it is all
# web-served, and an install has no reason to publish its own provenance.
for stray in "$FONT_DIR"/*; do
    case "$(basename "$stray")" in
        *.woff2|OFL.txt) ;;
        *) fail "Only fonts and OFL.txt belong in public/fonts (it is web-served): $(basename "$stray")" ;;
    esac
done

# The licence requires the licence to travel with the fonts. Shipping the bytes
# without it is the one failure here that is a legal problem, not a visual one.
[ -f "$FONT_DIR/OFL.txt" ] || fail "OFL.txt must ship alongside the fonts: SIL OFL 1.1 requires it."

if command -v shasum >/dev/null 2>&1; then
    sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
else
    sha() { sha256sum "$1" | cut -d' ' -f1; }
fi

# Provenance. A typeface swapped without updating the table is exactly what this
# catches -- vendored third-party bytes are only trustworthy while something
# checks they are the bytes that were reviewed.
for file in "$FONT_DIR"/*.woff2; do
    name="$(basename "$file")"
    recorded="$(grep -F "\`$name\`" "$README" | grep -oE '[0-9a-f]{64}' | head -1)"

    [ -n "$recorded" ] || fail "No SHA-256 recorded in ${README#"$ROOT_DIR/"} for $name."

    actual="$(sha "$file")"

    if [ "$recorded" != "$actual" ]; then
        fail "$name does not match the hash recorded in ${README#"$ROOT_DIR/"}.
  recorded: $recorded
  actual:   $actual
Update the file and the table together, or restore the reviewed bytes."
    fi
done

# Every @font-face must resolve. A face declared for a file that is not there
# does not error: the browser silently falls back to the next stack entry, so
# the dashboard renders in system sans looking approximately right. That is the
# whole failure mode vendoring exists to prevent, so it is worth asserting.
declared=0

while read -r url; do
    declared=$((declared + 1))
    file="$ROOT_DIR/apps/server/public${url}"
    [ -f "$file" ] || fail "The layout declares a font that is not vendored: $url"
done < <(grep -oE "asset\('fonts/[^']+\.woff2'\)" "$LAYOUT" | grep -oE "fonts/[^']+\.woff2" | sed 's|^|/|' | sort -u)

[ "$declared" -gt 0 ] || fail "No @font-face declarations found in ${LAYOUT#"$ROOT_DIR/"}."

if grep -q "url('/fonts/" "$LAYOUT"; then
    fail "A font is referenced at the origin root. An install mounted below a path (APP_URL=http://host/base) 404s on it and silently falls back to the system stack; use asset()."
fi

# ...and every vendored face must be declared. An orphan is dead weight every
# operator ships and every browser could be asked for.
for file in "$FONT_DIR"/*.woff2; do
    name="$(basename "$file")"
    grep -q "fonts/$name" "$LAYOUT" \
        || fail "$name is vendored but no @font-face references it. Declare it or remove it."
done

# The image must carry public/, and the context must not exclude the fonts.
# Checking only the COPY was not enough for the widget's vendor directory -- a
# .dockerignore rule made that COPY fail while the grep still passed.
grep -q 'COPY apps/server' "$DOCKERFILE" \
    || fail "server.Dockerfile does not stage apps/server, so the image would ship no fonts."

# Anchored to the directory: a bare prefix also matches
# apps/server/public/fonts-manifest.dev.json, which is a different file and is
# excluded on purpose.
if grep -qE '^apps/server/public/fonts(/|$)' "$DOCKERIGNORE"; then
    fail ".dockerignore excludes apps/server/public/fonts, so the released image would serve the dashboard without its typeface."
fi

# No path may reintroduce a third-party font fetch. An install on localhost or
# behind a firewall does not fail on one -- it renders the system stack and
# looks almost right, on the deployments least likely to notice.
if grep -rn 'fonts\.googleapis\.com\|fonts\.gstatic\.com\|fonts\.bunny\.net' \
    "$ROOT_DIR/apps/server/app" \
    "$ROOT_DIR/apps/server/resources" \
    "$ROOT_DIR/packages/widget-js/src" 2>/dev/null | grep -q .; then
    echo "A third-party font reference has come back:" >&2
    grep -rn 'fonts\.googleapis\.com\|fonts\.gstatic\.com\|fonts\.bunny\.net' \
        "$ROOT_DIR/apps/server/app" \
        "$ROOT_DIR/apps/server/resources" \
        "$ROOT_DIR/packages/widget-js/src" 2>/dev/null >&2
    exit 1
fi

echo "Typefaces are vendored with recorded provenance, every face resolves, and the image ships them."
