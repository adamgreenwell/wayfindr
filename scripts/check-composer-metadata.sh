#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVER_DIR="$ROOT_DIR/apps/server"
PINNED_CLASSIFIER='5.3.1'
ALLOWED_WARNING='- require.patrickschur/language-detection : exact version constraints (5.3.1) should be avoided if the package follows semantic versioning'

fail() {
    printf '%s\n' "Composer metadata check failed: $1" >&2
    exit 1
}

classifier_constraint="$({
    php -r '
        $composer = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        echo $composer["require"]["patrickschur/language-detection"] ?? "";
    ' "$SERVER_DIR/composer.json"
} 2>/dev/null)" || fail 'composer.json is not readable JSON.'

if [[ "$classifier_constraint" != "$PINNED_CLASSIFIER" ]]; then
    fail "the evaluation classifier must be pinned to $PINNED_CLASSIFIER."
fi

if ! validation_output="$(cd "$SERVER_DIR" && composer validate --no-check-publish 2>&1)"; then
    printf '%s\n' "$validation_output" >&2
    fail 'Composer validation reported an error.'
fi

printf '%s\n' "$validation_output"

constraint_warnings="$({
    printf '%s\n' "$validation_output" \
        | grep -E '^- (require|require-dev)\..*: (exact|unbound) version constraints' \
        || true
})"
unexpected_warnings="$({
    printf '%s\n' "$constraint_warnings" \
        | grep -Fvx -- "$ALLOWED_WARNING" \
        || true
})"

if [[ -n "$unexpected_warnings" ]]; then
    printf '%s\n' "$unexpected_warnings" >&2
    fail 'an unapproved dependency-constraint warning was reported.'
fi

if ! strict_output="$(cd "$SERVER_DIR" && composer validate --strict --no-check-publish --no-check-all 2>&1)"; then
    printf '%s\n' "$strict_output" >&2
    fail 'strict validation reported a warning outside the approved constraint exception.'
fi

printf '%s\n' 'Composer metadata check passed with only the intentional classifier pin exempted.'
