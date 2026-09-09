#!/usr/bin/env bash
#
# Hermetic contract test for install.sh release discovery.
#
# The installer has to tell two very different truths apart before it fetches
# stack files or pulls an image: GitHub authoritatively has no release, or
# GitHub could not answer. This lifts the shipped functions verbatim and gives
# their curl boundary deterministic responses, so CI never spends rate limit or
# depends on the public network to hold that distinction down.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALLER="$ROOT_DIR/scripts/self-host/install.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

say() { printf 'SAY|%s\n' "$*"; }
die() { printf 'ERROR|%s\n' "$*" >&2; exit 1; }

FUNCTION_FILE="$TMP_DIR/release-resolution.sh"

awk '
    /^github_json_tags\(\) \{$/ { capture = 1 }
    /^# Rewrite WAYFINDR_IMAGE/ { exit }
    capture { print }
' "$INSTALLER" > "$FUNCTION_FILE"

grep -q '^github_json_tags() {' "$FUNCTION_FILE" \
    || fail "could not lift github_json_tags() from install.sh"
grep -q '^github_api_get() {' "$FUNCTION_FILE" \
    || fail "could not lift github_api_get() from install.sh"
grep -q '^is_release_tag() {' "$FUNCTION_FILE" \
    || fail "could not lift is_release_tag() from install.sh"
grep -q '^resolve_release() {' "$FUNCTION_FILE" \
    || fail "could not lift resolve_release() from install.sh"
grep -q 'No published release found' "$FUNCTION_FILE" \
    || fail "lifted resolver is incomplete"

# shellcheck source=/dev/null
. "$FUNCTION_FILE"

RELEASES_API="https://api.github.test/repos/wayfindr/releases/latest"
TAGS_API="https://api.github.test/repos/wayfindr/tags?per_page=100"
CALL_LOG="$TMP_DIR/calls"

FAKE_RELEASE_STATUS=200
FAKE_RELEASE_BODY='{"tag_name":"v0.7.0"}'
FAKE_RELEASE_EXIT=0
FAKE_TAGS_STATUS=200
FAKE_TAGS_BODY='[]'
FAKE_TAGS_EXIT=0
FAKE_TAGS_PAGE_2_STATUS=200
FAKE_TAGS_PAGE_2_BODY='[]'
FAKE_TAGS_PAGE_2_EXIT=0
FAKE_TAGS_LATER_STATUS=200
FAKE_TAGS_LATER_BODY='[]'
FAKE_TAGS_LATER_EXIT=0
RUN_UPGRADE=0

# The production helper asks curl for the body followed by a newline and the
# final HTTP status. This fake implements that interface rather than adding a
# test-only branch to the installer.
curl() {
    local url="${!#}" body status exit_code

    printf '%s\n' "$url" >> "$CALL_LOG"

    case "$url" in
        "$RELEASES_API")
            body="$FAKE_RELEASE_BODY"
            status="$FAKE_RELEASE_STATUS"
            exit_code="$FAKE_RELEASE_EXIT"
            ;;
        "${TAGS_API}&page=1")
            body="$FAKE_TAGS_BODY"
            status="$FAKE_TAGS_STATUS"
            exit_code="$FAKE_TAGS_EXIT"
            ;;
        "${TAGS_API}&page=2")
            body="$FAKE_TAGS_PAGE_2_BODY"
            status="$FAKE_TAGS_PAGE_2_STATUS"
            exit_code="$FAKE_TAGS_PAGE_2_EXIT"
            ;;
        "${TAGS_API}&page="*)
            body="$FAKE_TAGS_LATER_BODY"
            status="$FAKE_TAGS_LATER_STATUS"
            exit_code="$FAKE_TAGS_LATER_EXIT"
            ;;
        *)
            fail "unexpected curl URL: $url"
            ;;
    esac

    printf '%s\n%s' "$body" "$status"
    return "$exit_code"
}

assert_contains() {
    case "$1" in
        *"$2"*) ;;
        *) fail "$3: output did not contain '$2'\n$1" ;;
    esac
}

assert_not_contains() {
    case "$1" in
        *"$2"*) fail "$3: output unexpectedly contained '$2'\n$1" ;;
        *) ;;
    esac
}

call_count() {
    grep -cF "$1" "$CALL_LOG" 2>/dev/null || true
}

run_resolver() {
    local initial_ref="$1" output_file="$TMP_DIR/output"

    : > "$CALL_LOG"
    : > "$output_file"

    set +e
    (
        set -e
        REF="$initial_ref"
        IMAGE_TAG=""
        PRERELEASE=0
        UPGRADE="$RUN_UPGRADE"
        resolve_release
        printf 'STATE|%s|%s|%s\n' "$REF" "$IMAGE_TAG" "$PRERELEASE"
    ) > "$output_file" 2>&1
    LAST_STATUS=$?
    set -e

    LAST_OUTPUT="$(<"$output_file")"
}

expect_calls() {
    local name="$1" expected_releases="$2" expected_tags="$3"
    local actual_releases actual_tags

    actual_releases="$(call_count "$RELEASES_API")"
    actual_tags="$(call_count "${TAGS_API}&page=")"

    [ "$actual_releases" -eq "$expected_releases" ] \
        || fail "$name: expected $expected_releases release API calls, got $actual_releases"
    [ "$actual_tags" -eq "$expected_tags" ] \
        || fail "$name: expected $expected_tags tags API calls, got $actual_tags"
}

expect_success() {
    local name="$1" initial_ref="$2" expected_state="$3" expected_releases="$4" expected_tags="$5"

    run_resolver "$initial_ref"

    [ "$LAST_STATUS" -eq 0 ] || fail "$name: resolver failed\n$LAST_OUTPUT"
    assert_contains "$LAST_OUTPUT" "$expected_state" "$name"
    expect_calls "$name" "$expected_releases" "$expected_tags"
    printf '    ok  %s\n' "$name"
}

expect_failure() {
    local name="$1" expected="$2" expected_releases="$3" expected_tags="$4"

    run_resolver ""

    [ "$LAST_STATUS" -ne 0 ] || fail "$name: resolver unexpectedly succeeded\n$LAST_OUTPUT"
    assert_contains "$LAST_OUTPUT" "$expected" "$name"
    assert_contains "$LAST_OUTPUT" "--ref vX.Y.Z" "$name"
    assert_not_contains "$LAST_OUTPUT" "No published release found" "$name"
    expect_calls "$name" "$expected_releases" "$expected_tags"
    printf '    ok  %s\n' "$name"
}

expect_upgrade_failure() {
    local name="$1" expected="$2" expected_releases="$3" expected_tags="$4"

    RUN_UPGRADE=1
    run_resolver ""
    RUN_UPGRADE=0

    [ "$LAST_STATUS" -ne 0 ] || fail "$name: resolver unexpectedly succeeded\n$LAST_OUTPUT"
    assert_contains "$LAST_OUTPUT" "$expected" "$name"
    assert_contains "$LAST_OUTPUT" "Retry when GitHub's release API is reachable" "$name"
    assert_contains "$LAST_OUTPUT" "upgrade preflight still needs the published release history" "$name"
    assert_not_contains "$LAST_OUTPUT" "rerun with --ref vX.Y.Z" "$name"
    assert_not_contains "$LAST_OUTPUT" "No published release found" "$name"
    expect_calls "$name" "$expected_releases" "$expected_tags"
    printf '    ok  %s\n' "$name"
}

tag_page() {
    local count="$1" prefix="$2" page='[' index

    for ((index = 1; index <= count; index++)); do
        [ "$index" -eq 1 ] || page="${page},"
        page="${page}{\"name\":\"${prefix}-${index}\"}"
    done

    printf '%s]' "$page"
}

printf '\n  install.sh release discovery\n\n'

# An explicit ref is deterministic and never spends a network request.
FAKE_RELEASE_STATUS=403
FAKE_RELEASE_BODY='{"message":"forbidden"}'
expect_success "explicit release ref bypasses discovery" "v0.7.0" "STATE|v0.7.0|0.7.0|0" 0 0

expect_success "explicit non-image ref bypasses discovery" "v0/feature" "STATE|v0/feature||0" 0 0

# The ordinary stable-release path does not consult tags after GitHub answers.
FAKE_RELEASE_STATUS=200
FAKE_RELEASE_BODY='{"id":7,"url":"https://api.github.test/releases/7","tag_name":"v0.7.0","draft":false,"prerelease":false,"body":"Line one\nLine two \"quoted\"","assets":[{"size":123,"label":null}],"author":{"login":"wayfindr-release"}}'
expect_success "latest stable release" "" "STATE|v0.7.0|0.7.0|0" 1 0

# A 404 means there is no GitHub Release, but a bare v* tag may still have
# published the image.
FAKE_RELEASE_STATUS=404
FAKE_RELEASE_BODY='{"message":"Not Found"}'
FAKE_TAGS_STATUS=200
FAKE_TAGS_BODY=$'[\n  {"name":"v0.6.0","commit":{"sha":"abc"}},\n  {"name":"v0.7.0","commit":{"sha":"def"}}\n]'
expect_success "bare release tag fallback" "" "STATE|v0.7.0|0.7.0|0" 1 1

FAKE_TAGS_BODY="$(tag_page 100 documentation)"
FAKE_TAGS_PAGE_2_BODY='[{"name":"v0.8.0","commit":{"sha":"page-two"}}]'
expect_success "release tag on the second tags page" "" "STATE|v0.8.0|0.8.0|0" 1 2

FAKE_TAGS_PAGE_2_BODY='[]'
expect_success "full page followed by authoritative empty page" "" "STATE|main||1" 1 2

FAKE_TAGS_PAGE_2_BODY="$(tag_page 100 documentation-page-two)"
FAKE_TAGS_LATER_BODY="$(tag_page 100 documentation-later)"
expect_failure "tags pagination safety cap" "tags API remained full after 20 pages" 1 20
FAKE_TAGS_PAGE_2_BODY='[]'
FAKE_TAGS_LATER_BODY='[]'

FAKE_TAGS_BODY='[]'
expect_success "authoritatively empty tag list" "" "STATE|main||1" 1 1

FAKE_TAGS_BODY='[{"name":"documentation"}]'
expect_success "valid tag list without a release tag" "" "STATE|main||1" 1 1

# A failure from releases/latest must not fall through to tags: the latest
# release endpoint deliberately excludes prereleases, while a sorted tag list
# does not.
FAKE_RELEASE_STATUS=403
FAKE_RELEASE_BODY='{"message":"rate limited"}'
expect_failure "release API HTTP 403" "release API returned HTTP 403" 1 0
expect_upgrade_failure "upgrade release API HTTP 403" "release API returned HTTP 403" 1 0

FAKE_RELEASE_STATUS=429
FAKE_RELEASE_BODY='{"message":"too many requests"}'
expect_failure "release API HTTP 429" "release API returned HTTP 429" 1 0

FAKE_RELEASE_STATUS=500
FAKE_RELEASE_BODY='{"message":"server error"}'
expect_failure "release API HTTP 500" "release API returned HTTP 500" 1 0

FAKE_RELEASE_STATUS=000
FAKE_RELEASE_BODY=''
FAKE_RELEASE_EXIT=6
expect_failure "release API transport failure" "release API request failed with curl exit 6" 1 0
FAKE_RELEASE_EXIT=0

FAKE_RELEASE_STATUS=200
FAKE_RELEASE_BODY=''
expect_failure "release API empty HTTP 200" "release API returned malformed or unexpected JSON with HTTP 200" 1 0

FAKE_RELEASE_BODY='{"name":"v0.7.0"}'
expect_failure "release API JSON without a release tag" "release API returned malformed or unexpected JSON with HTTP 200" 1 0

FAKE_RELEASE_BODY='not-json {"tag_name":"v9.9.9"}'
expect_failure "release API malformed prefix with tag-shaped text" "release API returned malformed or unexpected JSON with HTTP 200" 1 0

FAKE_RELEASE_BODY='{"tag_name":"v9.9.9" BROKEN'
expect_failure "release API malformed suffix with tag-shaped text" "release API returned malformed or unexpected JSON with HTTP 200" 1 0

FAKE_RELEASE_BODY='{"wrapper":{"tag_name":"v9.9.9"}}'
expect_failure "release API nested tag impostor" "release API returned malformed or unexpected JSON with HTTP 200" 1 0

FAKE_RELEASE_BODY='{"tag_name":"v0.7.0","tag_name":"v9.9.9"}'
expect_failure "release API duplicate tag field" "release API returned malformed or unexpected JSON with HTTP 200" 1 0

FAKE_RELEASE_BODY='{"tag_name":"v0.7.\\u0030"}'
expect_failure "release API escaped release tag" "release API returned malformed or unexpected JSON with HTTP 200" 1 0

FAKE_RELEASE_BODY=$'{"tag_name":"v0.7.0\037"}'
expect_failure "release API raw control byte" "release API returned malformed or unexpected JSON with HTTP 200" 1 0

FAKE_RELEASE_BODY=$'{"tag_name":"v0.7.0\177"}'
expect_failure "release API non-image tag character" "release API returned HTTP 200 without a usable v* release tag" 1 0

# Tags are consulted only after an authoritative latest-release 404, and have
# the same failure/shape boundary.
FAKE_RELEASE_STATUS=404
FAKE_RELEASE_BODY='{"message":"Not Found"}'
FAKE_TAGS_STATUS=403
FAKE_TAGS_BODY='{"message":"forbidden"}'
expect_failure "tags API HTTP 403" "tags API page 1 returned HTTP 403" 1 1

FAKE_TAGS_STATUS=429
FAKE_TAGS_BODY='{"message":"too many requests"}'
expect_failure "tags API HTTP 429" "tags API page 1 returned HTTP 429" 1 1

FAKE_TAGS_STATUS=200
FAKE_TAGS_BODY="$(tag_page 100 documentation)"
FAKE_TAGS_PAGE_2_STATUS=429
FAKE_TAGS_PAGE_2_BODY='{"message":"too many requests"}'
expect_failure "tags API page 2 HTTP 429" "tags API page 2 returned HTTP 429" 1 2
FAKE_TAGS_PAGE_2_STATUS=200
FAKE_TAGS_PAGE_2_BODY='[]'

FAKE_TAGS_STATUS=000
FAKE_TAGS_BODY=''
FAKE_TAGS_EXIT=7
expect_failure "tags API transport failure" "tags API page 1 request failed with curl exit 7" 1 1
FAKE_TAGS_EXIT=0

FAKE_TAGS_STATUS=200
FAKE_TAGS_BODY=''
expect_failure "tags API empty HTTP 200" "tags API page 1 returned malformed or unexpected JSON with HTTP 200" 1 1

FAKE_TAGS_BODY='[{}]'
expect_failure "tags API JSON without tag names" "tags API page 1 returned malformed or unexpected JSON with HTTP 200" 1 1

FAKE_TAGS_BODY='[garbage {"name":"v9.9.9"}]'
expect_failure "tags API malformed prefix with tag-shaped text" "tags API page 1 returned malformed or unexpected JSON with HTTP 200" 1 1

FAKE_TAGS_BODY='[{"name":"v9.9.9"} BROKEN]'
expect_failure "tags API malformed suffix with tag-shaped text" "tags API page 1 returned malformed or unexpected JSON with HTTP 200" 1 1

FAKE_TAGS_BODY='[{"wrapper":{"name":"v9.9.9"}}]'
expect_failure "tags API nested tag impostor" "tags API page 1 returned malformed or unexpected JSON with HTTP 200" 1 1

FAKE_TAGS_BODY='[{"name":"v0.7.0","name":"v9.9.9"}]'
expect_failure "tags API duplicate tag field" "tags API page 1 returned malformed or unexpected JSON with HTTP 200" 1 1

FAKE_TAGS_BODY='[{"name":"v0.7.0",}]'
expect_failure "tags API trailing comma" "tags API page 1 returned malformed or unexpected JSON with HTTP 200" 1 1

printf '\n  34 release-discovery outcomes preserve the truth boundary.\n\n'
