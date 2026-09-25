#!/usr/bin/env bash
#
# The installer's --app-url refusals, and the promise that it never recommends
# a value it refuses.
#
# WHY THIS EXISTS
#
# A literal-compliance install run on a clean machine found that the README's
# own example -- `--app-url https://support.example.com` -- produced an install
# that exits 0, prints "Wayfindr is running.", brings the whole stack up, and
# answers only on the loopback probe the installer itself checks -- nothing on
# the hostname the operator was just told to visit. Let's Encrypt answers
# `rejectedIdentifier ... forbidden by policy` for an RFC 2606 name, so no
# certificate is ever coming and Caddy retries in the background for weeks
# while the operator has been told it worked (#797).
#
# `make self-host-test` runs `bash -n` over install.sh, which proves it parses
# and nothing else. install.sh is the file operators curl into bash, so its
# argument handling is interface: it is worth a test that actually runs it.
#
# WHAT IT DOES
#
# Drives the real script with real arguments and reads its exit status. Nothing
# here needs Docker or the network: every case is refused, or reaches the point
# where the release is resolved, before either is touched.
#
# The self-reference check at the end is the one that would have caught a
# mistake made while writing this guard: the installer's own "--app-url is
# required" message recommended https://support.example.com, which the very
# next check refuses. Any --app-url example the script prints has to survive
# the script's own guard.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALLER="$ROOT_DIR/scripts/self-host/install.sh"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

[ -f "$INSTALLER" ] || { echo "The installer is missing: $INSTALLER" >&2; exit 1; }

REFUSAL='documentation placeholder'
failures=0

# Runs the installer far enough to decide, and prints the first line it emits.
# --no-start and a throwaway directory keep it from starting anything; the
# cases that get past the guard stop at release resolution, which is the first
# thing after it.
attempt() {
    local url="$1"
    shift
    bash "$INSTALLER" --app-url "$url" --dir "$WORK_DIR/target" --no-start "$@" 2>&1 | head -1
}

expect_refused() {
    local url="$1" out
    shift
    out="$(attempt "$url" "$@" || true)"

    case "$out" in
        *"$REFUSAL"*) ;;
        *)
            echo "FAIL: --app-url $url should be refused as a reserved name, got: $out" >&2
            failures=$((failures + 1))
            ;;
    esac
}

expect_allowed() {
    local url="$1" out
    shift
    out="$(attempt "$url" "$@" || true)"

    case "$out" in
        *"$REFUSAL"*)
            echo "FAIL: --app-url $url is a legitimate hostname and must not be refused." >&2
            failures=$((failures + 1))
            ;;
    esac
}

# RFC 2606 reserves these, and no authority will certify one. Spellings that
# reach the same name are covered because each has reached it before in some
# other parser: case, a trailing dot, userinfo, a port, a path.
expect_refused 'https://support.example.com'
expect_refused 'https://example.com'
expect_refused 'https://EXAMPLE.COM'
expect_refused 'https://support.example.com.'
expect_refused 'https://user@foo.example.com:8443/path'
expect_refused 'https://example.org'
expect_refused 'https://a.b.example.net'
expect_refused 'https://bar.example'
expect_refused 'https://wayfindr.invalid'
# The refusal is about a hostname nobody can reach, which is true whatever the
# scheme or proxy mode -- even where no certificate is ever requested.
expect_refused 'http://support.example.com'
expect_refused 'https://support.example.com' --behind-proxy

# Hostnames an operator would really use, including the two shapes that contain
# the reserved strings without being reserved. A false positive here stops
# somebody installing anything at all, because this script ships from main.
expect_allowed 'https://localhost'
expect_allowed 'https://support.acme.co.uk'
expect_allowed 'https://myexample.com'
expect_allowed 'https://example.company.io'
expect_allowed 'https://notexample.org'
expect_allowed 'https://wayfindr.local'
expect_allowed 'http://192.168.1.50:8000'
expect_allowed 'https://[::1]:8443'

# An install already running on a reserved name must still upgrade. The guard
# exists to stop a NEW broken install, not to strand one somebody depends on.
upgrade_dir="$WORK_DIR/existing"
mkdir -p "$upgrade_dir"
printf 'APP_URL=https://support.example.com\n' > "$upgrade_dir/.env"
printf 'services:\n  web:\n    image: placeholder\n' > "$upgrade_dir/compose.yml"

upgrade_out="$(WAYFINDR_HANDED_OFF=1 bash "$INSTALLER" --upgrade --dir "$upgrade_dir" --ref v0.7.0 2>&1 | head -6 || true)"

case "$upgrade_out" in
    *"$REFUSAL"*)
        echo "FAIL: --upgrade refused an existing install whose APP_URL is a reserved name." >&2
        failures=$((failures + 1))
        ;;
esac

# Every --app-url the script itself prints, in usage or in an error, has to
# survive the script's own guard. Without this the installer can go back to
# recommending a value it rejects, which it did.
while read -r suggested; do
    [ -n "$suggested" ] || continue

    out="$(attempt "$suggested" || true)"

    case "$out" in
        *"$REFUSAL"*)
            echo "FAIL: install.sh suggests --app-url $suggested, which it then refuses." >&2
            failures=$((failures + 1))
            ;;
    esac
done < <(grep -oE '[-][-]app-url (https?://[A-Za-z0-9.:/-]+)' "$INSTALLER" \
    | sed 's/^--app-url //' \
    | grep -v '^https://support\.example\.com$' \
    | sort -u)

# The suggestion sweep above is worthless if its pattern matches nothing, and a
# grep that finds nothing exits 1 under `set -e` only when it is not in a pipe.
suggestion_count="$(grep -coE '[-][-]app-url https?://' "$INSTALLER" || true)"

if [ "${suggestion_count:-0}" -lt 1 ]; then
    echo "FAIL: found no --app-url examples in install.sh, so the suggestion check tested nothing." >&2
    failures=$((failures + 1))
fi

# Piped the way the README runs it, an early refusal must still consume the whole
# script. bash executes a pipe as it reads it, so a `die` in the first few
# kilobytes used to leave the rest unread, and the writer -- curl, for an
# operator -- died of SIGPIPE and printed "curl: (23) Failure writing output"
# beneath the real error. `cat` stands in for curl; 141 is its SIGPIPE status.
pipe_status="$(set +e; cat "$INSTALLER" | bash -s -- --not-an-option >/dev/null 2>&1; echo "${PIPESTATUS[0]}")"

if [ "$pipe_status" != "0" ]; then
    echo "FAIL: piped into bash, install.sh exited before reading itself (writer status $pipe_status), which makes curl report '(23) Failure writing output'." >&2
    failures=$((failures + 1))
fi

if [ "$failures" -ne 0 ]; then
    echo "$failures installer argument check(s) failed." >&2
    exit 1
fi

echo "Installer refuses reserved --app-url hostnames, allows real ones, still upgrades, suggests nothing it rejects, and reads itself whole when piped."
