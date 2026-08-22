#!/usr/bin/env bash
#
# Wayfindr one-line installer.
#
#   curl -fsSL https://raw.githubusercontent.com/adamgreenwell/wayfindr/main/scripts/self-host/install.sh \
#     | bash -s -- --app-url https://support.example.com
#
# Sets up the official Docker Compose stack in a directory, mints secrets,
# starts the services, waits for health, and prints the /setup URL. With an
# https:// URL, FrankenPHP obtains TLS certificates automatically — DNS for
# the hostname must already point at this machine and ports 80/443 must be
# free. Re-running converges; `--upgrade` pulls the newer image and restarts.
set -euo pipefail

RAW_BASE_DEFAULT="https://raw.githubusercontent.com/adamgreenwell/wayfindr"
RELEASES_API="https://api.github.com/repos/adamgreenwell/wayfindr/releases/latest"
TAGS_API="https://api.github.com/repos/adamgreenwell/wayfindr/tags?per_page=100"
# The first release that publishes a release-manifest.json asset (ADR 0013).
# Below this a missing manifest is the truth; at or above it, it is a fault.
MANIFEST_CONTRACT_FROM="0.1.0"
REF=""
IMAGE_TAG=""
PRERELEASE=0
APP_URL=""
MAIL_FROM="support@example.com"
BEHIND_PROXY=0
TARGET_DIR="$PWD/wayfindr"
SOURCE_DIR=""
INSTALLED_IMAGE=""
UPGRADE=0
NO_START=0

usage() {
    cat <<'USAGE'
Usage:
  install.sh --app-url <url> [options]
  install.sh --upgrade [--dir <path>]

Options:
  --app-url <url>   Public URL, or a bare host. A real domain gets a public
                    certificate on 443. Hosts no public CA can issue for
                    (localhost, IP literals, .local/.localhost/.internal/
                    .home.arpa/.test, single-label names) get a locally-issued
                    one, on whatever port the URL names -- so
                    https://localhost:2345 works. Without a scheme, loopback
                    assumes http:// and everything else https://.
  --dir <path>      Install directory. Defaults to ./wayfindr.
  --mail-from <a>   Mail from address placeholder. Defaults to support@example.com.
  --behind-proxy    Your own reverse proxy terminates TLS; every bind stays on
                    loopback and you point the proxy at 127.0.0.1:8000.
  --ref <git-ref>   Git ref to fetch stack files from. Defaults to the latest
                    release tag (main before the first release), keeping the
                    stack files aligned with the image the install pulls.
  --upgrade         Pull the newer image and restart an existing install.
  --no-start        Prepare files and env but do not start the stack.
  --source-dir <p>  Internal: copy stack files from a local checkout instead
                    of downloading (used by the repo smoke tests).
  -h, --help        Show this help.
USAGE
}

# Read a key from the environment file the way everything that consumes it does.
#
# dotenv quoting is valid and common - `WAYFINDR_IMAGE="ghcr.io/.../wayfindr:latest"`
# - and Compose, Laravel and this script must all agree on the value. Keeping the
# quotes made an official image classify as custom, so the preflight skipped; an
# acknowledgement never matched the key it was written for; and a declared origin
# failed to parse. Every one of those fails quietly.
env_value() {
    local raw

    # The LAST assignment, not the first. A duplicate key is what an operator
    # produces by appending a line rather than editing the one already there -
    # and Compose and the artifact's Dotenv both take the later value. Reading
    # the earlier one made the installer act on a stale origin or a stale
    # acknowledgement, permit the pull, and hand over to an artifact that reads
    # the newer value and refuses: a working image replaced over a disagreement
    # about which line counts.
    raw="$(grep -E "^[[:space:]]*(export[[:space:]]+)?$1=" "$ENV_FILE" 2>/dev/null \
        | tail -1 \
        | sed -E "s/^[[:space:]]*(export[[:space:]]+)?$1=//" || true)"

    # Trailing CR, for a file written on Windows.
    raw="${raw%$'\r'}"

    case "$raw" in
        \"*)
            # Quoted: the value is what is between the quotes, and anything after
            # the closing one is an inline comment - documented Compose syntax,
            # and left in it made an official image read as a fork.
            raw="${raw#\"}"
            raw="${raw%%\"*}"
            ;;
        \'*)
            raw="${raw#\'}"
            raw="${raw%%\'*}"
            ;;
        *)
            # Unquoted: a `#` preceded by whitespace starts a comment, and
            # trailing whitespace is not part of the value.
            case "$raw" in
                *[[:space:]]\#*) raw="${raw%%[[:space:]]\#*}" ;;
            esac

            # Written as a loop rather than the nested-expansion idiom, which
            # is one layer of quoting away from silently doing nothing.
            while :; do
                case "$raw" in
                    *[[:space:]]) raw="${raw%?}" ;;
                    *) break ;;
                esac
            done
            ;;
    esac

    printf '%s' "$raw"
}

# Whether a value depends on Compose's variable interpolation, which this cannot
# resolve - it has no access to the environment Compose will assemble.
#
# Treated as unknown rather than guessed at, and said out loud. Left to fall
# through, an interpolated image classified as a fork: the preflight skipped
# silently and pin_image() declined to retag, so the upgrade restarted the old
# image with nothing said. Rewriting the line would be worse still, since it
# would destroy the interpolation the operator wrote.
env_interpolated() {
    case "$1" in
        *'$'*) return 0 ;;
        *) return 1 ;;
    esac
}

# Whether the configured image is the official one, which is what makes its tag a
# release this can look up.
is_official_image() {
    case "$1" in
        ghcr.io/adamgreenwell/wayfindr:*) return 0 ;;
        *) return 1 ;;
    esac
}

# GNU coreutils spells this `--decode` and BSD spells it `-D`, and an installer
# runs on whichever the operator has. Chosen once, by asking.
if printf 'eA==' | base64 --decode >/dev/null 2>&1; then
    B64_DECODE="base64 --decode"
else
    B64_DECODE="base64 -D"
fi

unprose() { printf '%s' "$1" | $B64_DECODE 2>/dev/null || printf '%s' "$1"; }

# Advisory notices from the releases this upgrade traverses (ADR 0013).
#
# Printed and never acted on. It takes no exit path and returns nothing, so no
# caller can accidentally make advice block a pull - the separation is in the
# shape of the function, not in a flag every caller has to respect.
report_notices() {
    [ -n "$1" ] || return 0

    printf '\n  \033[1;36mThis release advises:\033[0m\n'
    printf '%s\n' "$1" | while IFS='|' read -r _ key summary detail; do
        [ -n "$key" ] || continue
        printf '    %s - %s\n' "$key" "$(unprose "$summary")"
        [ -n "$detail" ] && printf '      %s\n' "$(unprose "$detail")"
    done
    printf '\n  Nothing here blocks the upgrade. Once it is running,\n'
    printf '  `wayfindr:upgrade-guard` reports which of these still apply.\n\n'
}

say() { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

# The value for an option that takes one, or a clear failure.
#
# `--app-url --upgrade` would otherwise consume the flag as the URL and
# silently discard it, and a value-taking option in LAST position made the
# bare `shift 2` abort under `set -e` with no message at all. generate-env.sh
# carries the same guard: both are documented entry points.
option_value() {
    case "${2:-}" in
        ''|-*) die "$1 needs a value." ;;
    esac

    printf '%s\n' "$2"
}

# The parse loop below consumes $@ with `shift`, so the hand-off would have
# nothing left to replay. Capture the operator's arguments first — losing --dir
# would silently upgrade a different install than the one they asked for.
WAYFINDR_ORIGINAL_ARGS=("$@")

while [ "$#" -gt 0 ]; do
    case "$1" in
        --app-url) APP_URL="$(option_value "--app-url" "${2:-}")"; shift 2 ;;
        --dir) TARGET_DIR="$(option_value "--dir" "${2:-}")"; shift 2 ;;
        --mail-from) MAIL_FROM="$(option_value "--mail-from" "${2:-}")"; shift 2 ;;
        --behind-proxy) BEHIND_PROXY=1; shift ;;
        --ref) REF="$(option_value "--ref" "${2:-}")"; shift 2 ;;
        --source-dir) SOURCE_DIR="$(option_value "--source-dir" "${2:-}")"; shift 2 ;;
        --upgrade) UPGRADE=1; shift ;;
        --no-start) NO_START=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; die "Unknown option: $1" ;;
    esac
done

command -v docker >/dev/null 2>&1 || die "Docker is required. Install it from https://docs.docker.com/engine/install/ first."
docker compose version >/dev/null 2>&1 || die "The Docker Compose plugin is required (docker compose)."
docker info >/dev/null 2>&1 || die "The Docker daemon is not reachable. Is it running, and can your user access it?"

COMPOSE_FILE="$TARGET_DIR/compose.yml"
ENV_FILE="$TARGET_DIR/.env"

# Stack files and image must describe the same release: without an explicit
# --ref, pin both to the latest release tag. Before the first release the
# API has none and everything follows main/latest.
resolve_release() {
    local latest

    if [ -n "$REF" ]; then
        # An explicit release tag pins the image too; branches and SHAs have
        # no matching published image, so they run :latest deliberately.
        case "$REF" in
            v[0-9]*)
                IMAGE_TAG="${REF#v}"
                say "Pinned to $REF (stack files and image)."
                ;;
            *)
                say "Using ref $REF with the :latest image (no matching published image for non-tag refs)."
                ;;
        esac

        return
    fi

    latest="$(curl -fsSL "$RELEASES_API" 2>/dev/null | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p' | head -n 1)" || true

    # A bare v* git tag publishes an image without creating a GitHub
    # Release — resolve through the tags API before ever considering main.
    if [ -z "$latest" ]; then
        latest="$(curl -fsSL "$TAGS_API" 2>/dev/null | sed -n 's/.*"name": *"\(v[0-9][^"]*\)".*/\1/p' | sort -V | tail -n 1)" || true
    fi

    if [ -n "$latest" ]; then
        REF="$latest"
        IMAGE_TAG="${latest#v}"
        say "Pinned to release $latest."
    else
        REF="main"
        PRERELEASE=1
        say "No published release found; using main (pre-release mode)."
    fi
}

# Rewrite WAYFINDR_IMAGE, matching every spelling env_value() accepts.
#
# The reader takes leading whitespace, an `export` prefix and quoting; a
# substitution anchored on a bare column-zero assignment does not. A file using
# any other spelling therefore classified as official, was evaluated against the
# newly resolved release, and was never retagged - so Compose went on pulling the
# old tag and the upgrade silently restarted the previous image.
#
# The prefix is captured and put back rather than normalised away, because an
# `export` there may be load-bearing for whatever else sources this file.
set_image() {
    sed -i.bak -E "s#^([[:space:]]*(export[[:space:]]+)?)WAYFINDR_IMAGE=.*#\1WAYFINDR_IMAGE=$1#" "$ENV_FILE"
    rm -f "$ENV_FILE.bak"
}

pin_image() {
    # An operator-supplied WAYFINDR_IMAGE wins; otherwise a resolved release
    # tag pins the published image.
    local configured
    configured="$(env_value WAYFINDR_IMAGE)"

    if [ -n "${WAYFINDR_IMAGE:-}" ]; then
        set_image "$WAYFINDR_IMAGE"
    elif [ -n "$IMAGE_TAG" ] && env_interpolated "$configured"; then
        say "Leaving WAYFINDR_IMAGE alone: it uses variable interpolation."
        printf '    %s\n' "$configured"
        printf '    Rewriting it would replace the expression with a literal tag.\n'
        printf '    Update it yourself to pin %s.\n' "$IMAGE_TAG"
    elif [ -n "$IMAGE_TAG" ] && is_official_image "$configured"; then
        set_image "ghcr.io/adamgreenwell/wayfindr:$IMAGE_TAG"
    fi
}

# Wait for the new release to actually be serving, and surface it if it refuses.
#
# `compose up -d` returns once the containers are STARTED, not once they are up -
# so a container whose entrypoint refuses to migrate and exits is invisible to
# it. The installer printed "Upgrade complete" over a service that had already
# stopped, with the actionable refusal buried in logs nobody had been told to
# read. An after-pull requirement reaches exactly this path: the preflight
# deliberately lets it through, because the pull is what delivers what the action
# needs, and the artifact is then the thing that refuses.
await_web_start() {
    local tries=0 cid running code this_code local_bind local_url serving

    local_bind="$(env_value WAYFINDR_LOCAL_BIND)"
    local_url="http://${local_bind:-127.0.0.1:8000}"

    while [ "$tries" -lt 60 ]; do
        # `--all`, or a container that has EXITED is not listed - which is
        # precisely the container this is looking for. Without it the refusal
        # went unseen, the loop ran its full two minutes, and the operator got a
        # generic "did not come up" instead of the release's own message.
        #
        # Every id is inspected rather than the first, because a replaced
        # container can still be listed alongside its replacement and nothing
        # promises an order. Running anywhere means still starting; otherwise the
        # first non-zero exit is the one to report.
        running=""
        code=""

        for cid in $(compose ps --all -q web 2>/dev/null || true); do
            if [ "$(docker inspect -f '{{.State.Running}}' "$cid" 2>/dev/null || true)" = "true" ]; then
                running="true"
                break
            fi

            running="false"
            this_code="$(docker inspect -f '{{.State.ExitCode}}' "$cid" 2>/dev/null || true)"

            if [ -n "$this_code" ] && [ "$this_code" != "0" ]; then
                code="$this_code"
                break
            fi

            code="${this_code:-0}"
        done

        if [ -n "$running" ]; then
            if [ "$running" = "false" ] && [ "$code" = "78" ]; then
                printf '\n\033[1;31mTHE NEW RELEASE REFUSED TO START\033[0m\n\n'
                printf '  It needs something done before its migrations may run, and stopped\n'
                printf '  rather than half-upgrading. Its own message:\n\n'
                compose logs --tail 40 web 2>/dev/null || true
                printf '\n  The database has NOT been changed. Do what it asks, then run this again.\n\n'

                return 1
            fi

            if [ "$running" = "false" ] && [ -n "$code" ] && [ "$code" != "0" ]; then
                printf '\n\033[1;31mTHE NEW RELEASE STOPPED\033[0m (exit %s)\n\n' "$code"
                compose logs --tail 40 web 2>/dev/null || true

                return 1
            fi
        fi

        if curl -fs --max-time 2 "$local_url/up" >/dev/null 2>&1; then
            # Alive, which is not the same as serving. `/up` is deliberately
            # exempt from the serving gate so an orchestrator does not restart a
            # release that is refusing on purpose - which means a healthy `/up`
            # with an outstanding after-start action sits in front of a service
            # returning 503 to every user, and reporting the upgrade complete
            # there is the same mistake as reporting it over a stopped container.
            serving="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$local_url/" 2>/dev/null || true)"

            # Only a real answer counts. `/up` is database-independent by design,
            # so a broken database or a failed boot shows up as a healthy `/up` in
            # front of 500s - and treating anything-that-is-not-503 as success
            # reported an upgrade complete over exactly that. 4xx is an answer
            # (a login redirect, a 404 on `/`); 5xx and a failed request are not.
            case "$serving" in
                5*|000|'') : ;;
                *) return 0 ;;
            esac
        fi

        tries=$((tries + 1))
        sleep 2
    done

    # Out of patience. A 503 that has persisted this long is the serving gate
    # rather than a slow boot, and it carries the operator's instructions - so it
    # is worth reading back rather than reporting as a generic failure.
    serving="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$local_url/" 2>/dev/null || true)"

    if [ "$serving" = "503" ]; then
        printf '\n\033[1;31mTHE RELEASE IS UP BUT WILL NOT SERVE\033[0m\n\n'
        printf '  Migrations ran. Something the release declared must be done before it\n'
        printf '  will answer requests, and it is refusing rather than serving half a\n'
        printf '  service. What it asks for:\n\n'
        curl -s --max-time 5 "$local_url/" 2>/dev/null | sed 's/^/    /' || true
        printf '\n  Do that, then restart the stack. Nothing needs re-running here.\n\n'

        return 1
    fi

    printf '\n\033[1;31mTHE NEW RELEASE DID NOT COME UP\033[0m\n\n'
    printf '  The container is running, but the application answered %s rather than\n' "${serving:-nothing}"
    printf '  serving. Inspect: docker compose -f %s --env-file %s logs web\n\n' "$COMPOSE_FILE" "$ENV_FILE"

    return 1
}

# Collects the declarations for every release this upgrade traverses and refuses
# to pull when one of them needs the operator first (ADR 0013).
#
# This is the good experience, NOT the guarantee. An install whose installer
# predates this has no preflight at all, which is exactly why the artifact
# refuses to migrate on its own. Anything reported here the image reports again
# on start; the difference is that here nothing has been pulled yet.
#
# JSON and version ordering are handled by the CURRENT image's php via
# `compose run`, because Docker is already a hard requirement of this script
# while jq, python and a host php are not. It runs against the image already on
# disk, needs no network, and does not depend on the release being gated.
# php_in_current_image <php-code> [NAME=VALUE]...
#
# Named rather than positional. This grew from three fixed slots to five as the
# checks needed more context, and each addition meant every caller passing empty
# strings for arguments it did not use - which is how a value ends up in the
# wrong slot without anything complaining.
php_in_current_image() {
    local code="$1"
    shift

    local env_args=()

    while [ "$#" -gt 0 ]; do
        env_args+=(-e "$1")
        shift
    done

    # WAYFINDR_IMAGE is pinned for the duration of the probe.
    #
    # compose.yml names the service image `${WAYFINDR_IMAGE:-...}`, and Compose
    # interpolates from the shell environment ahead of --env-file - so an
    # exported override made every one of these probes run, and therefore PULL,
    # the target image. The preflight would have fetched the very release it
    # exists to check before pulling, which defeats before-pull entirely.
    #
    # INSTALLED_IMAGE is the value persisted in the environment file: what is
    # running now, which is what "in the current image" was always supposed to
    # mean.
    #
    # `--pull never` because a probe must never fetch anything. Pinning the name
    # stops it running the target, but a pinned tag that is no longer in the local
    # store - pruned while the old container kept running - would otherwise be
    # PULLED to run the probe. For a moving tag that means downloading the target
    # before a single before-pull requirement has been read.
    #
    # A missing image makes the probe fail, which reads as "this image cannot
    # evaluate the preflight" and skips. That is the right answer: it cannot.
    WAYFINDR_IMAGE="${INSTALLED_IMAGE:-${WAYFINDR_IMAGE:-}}" \
        compose run --rm --no-deps --pull never -T ${env_args[@]+"${env_args[@]}"} \
        --entrypoint php web -r "$code" 2>/dev/null
}

# The release the ARTIFACT will consider this install to be at, which is not what
# the image tag says. It reads its own state file, and that is the value its floor
# check uses - so a preflight that predicts a different one predicts the wrong
# outcome.
# Whether a tag names one specific release, rather than a moving alias.
#
# The image workflow publishes `{{major}}.{{minor}}` and `latest` alongside the
# full version, so `0.3` is a real published tag - and it parses as no version at
# all. Left through, the span comparisons go undecidable and the target
# manifest's canonical `0.3.0` never equals it, so its floor is never checked and
# the pull proceeds against a release the preflight has not read.
names_one_release() {
    local answer
    answer="$(php_in_current_image '
        require "/app/apps/server/app/Support/Version/SemanticVersion.php";
        $raw = trim((string) getenv("WF_TO"));
        echo App\Support\Version\SemanticVersion::parse($raw) === null ? "NO" : "YES";
    ' "WF_TO=$1")"

    [ "$answer" = "YES" ]
}

# An operator-declared origin, held to the ARTIFACT's rule rather than the
# looser one a recorded version gets.
#
# `declaredOrigin()` rejects a development identity: it parses but does not
# order, so it can never be ranked against a floor, and accepting one would clear
# the unknown-origin refusal without satisfying anything. Accepting it here while
# the artifact rejects it is the worst split of the two - the installer pulls,
# and the artifact then refuses on a release that is already installed.
declared_origin() {
    php_in_current_image '
        require "/app/apps/server/app/Support/Version/SemanticVersion.php";

        $raw = trim((string) getenv("WF_FROM"));

        if ($raw === "") { exit(0); }

        $parsed = App\Support\Version\SemanticVersion::parse($raw);

        if ($parsed === null || $parsed->isDevelopment()) { exit(0); }

        echo $parsed->canonical();
    ' "WF_FROM=$1"
}

# Emits `<version>|<span-origin>|<span-known>`.
#
# TWO origins, because they answer different questions and the artifact keeps
# them apart. `version` is where the install IS, used for the floor and for
# deciding whether an action was newly traversed. `satisfied_through` is the last
# release that owed nothing, and is where the SPAN starts - it can sit further
# back when a previous upgrade left work outstanding.
#
# Reading the span from `version` skips the releases whose debt is still unpaid,
# so an intermediate action that cannot be performed on a direct jump is not
# recognised as stranded until after the release carrying it has been replaced.
#
# The tri-state on `satisfied_through` matches the artifact exactly: an ABSENT
# key falls back to the recorded version, a written null means the origin is
# unknown and the whole history is in span.
release_state() {
    php_in_current_image '
        require "/app/apps/server/app/Support/Version/SemanticVersion.php";

        // The path the APP resolves, not the default alone. An operator who set
        // WAYFINDR_RELEASE_STATE_PATH has a state file the artifact reads and
        // this would not see - reporting no record for an install that has one,
        // which is the same disagreement from the other side.
        $path = getenv("WAYFINDR_RELEASE_STATE_PATH")
            ?: "/app/apps/server/storage/app/release-state.json";

        if (! is_file($path)) { exit(0); }

        $state = json_decode((string) file_get_contents($path), true);

        if (! is_array($state)) { exit(0); }

        $version = is_string($state["version"] ?? null) ? $state["version"] : "";

        // Parsed and canonicalised, exactly as recordedVersion() does. A
        // malformed value is no origin at all to the artifact, which then falls
        // through to the declared one - so keeping it raw here meant the
        // preflight held a version the artifact discards AND never read the
        // declaration that would have replaced it.
        if ($version !== "") {
            $version = App\Support\Version\SemanticVersion::parse($version)?->canonical() ?? "";
        }

        if (! array_key_exists("satisfied_through", $state)) {
            $span = $version;
            $spanKnown = "1";
        } elseif (is_string($state["satisfied_through"])) {
            $span = $state["satisfied_through"];
            $spanKnown = "1";
        } else {
            $span = "";
            $spanKnown = "0";
        }

        echo $version, "|", $span, "|", $spanKnown;
    '
}

# Whether the CURRENTLY INSTALLED image can evaluate any of this.
#
# The preflight deliberately runs inside that image - the release being upgraded
# FROM - which is older than this installer by definition. Anything cut before
# ADR 0013 carries none of these classes, so every `require` below fatals,
# php_in_current_image suppresses stderr, and each check silently reads as
# "nothing to report": the span collapses to the target alone and no action is
# ever seen.
#
# Say so and skip instead. A skipped preflight is the DOCUMENTED position for an
# install predating the mechanism: the artifact refuses to migrate on its own,
# which is exactly why this is the good experience and not the guarantee.
preflight_supported() {
    local answer
    answer="$(php_in_current_image '
        $needed = [
            "/app/apps/server/app/Support/Version/SemanticVersion.php",
            "/app/apps/server/app/Support/Version/VersionComparator.php",
            "/app/apps/server/app/Support/Release/ReleaseManifest.php",
        ];
        foreach ($needed as $path) {
            if (! is_file($path)) { echo "NO"; exit(0); }
        }
        echo "YES";
    ')"

    [ "$answer" = "YES" ]
}

# Whether a release is one that publishes a manifest, so a missing asset can be
# told apart from one that never existed.
manifest_expected() {
    local answer
    answer="$(php_in_current_image '
        require "/app/apps/server/app/Support/Version/SemanticVersion.php";
        require "/app/apps/server/app/Support/Version/VersionComparator.php";
        $rank = App\Support\Version\VersionComparator::compare(getenv("WF_FROM"), getenv("WF_TO"));
        // An undecidable rank counts as expected. The exemption is for releases
        // we can SHOW predate the contract; one we cannot place must not inherit
        // it, or an unparseable tag becomes a way to skip the check entirely.
        echo ($rank === null || $rank >= 0) ? "YES" : "NO";
    ' "WF_FROM=$1" "WF_TO=$MANIFEST_CONTRACT_FROM")"

    [ "$answer" = "YES" ]
}


upgrade_preflight() {
    local from to ack tags span manifest all_actions actions tag floor

    # Pinned FIRST, because every probe below runs through compose and would
    # otherwise resolve the service image through an exported override - pulling
    # the target before a single check has run.
    INSTALLED_IMAGE="$(env_value WAYFINDR_IMAGE)"

    if [ -z "$INSTALLED_IMAGE" ] || env_interpolated "$INSTALLED_IMAGE"; then
        # Nothing usable to pin to, so a probe could not be trusted to run the
        # installed release. Skipping is the honest answer; the artifact still
        # refuses to migrate on its own.
        say "Preflight skipped: cannot tell which image is currently installed."
        printf '    %s\n' "${INSTALLED_IMAGE:-(unset)}"
        printf '    The release enforces its own requirements when it starts.\n'

        return 0
    fi

    to="${IMAGE_TAG:-}"

    # Whatever compose will ACTUALLY pull, mirroring pin_image()'s own precedence
    # rather than guessing at it:
    #
    #   1. an exported WAYFINDR_IMAGE wins outright - pin_image() writes it
    #   2. otherwise an OFFICIAL image already in .env is retagged to the resolved
    #      release, so the resolved tag is the target
    #   3. otherwise .env holds a custom image that pin_image() leaves alone, and
    #      that is what gets pulled
    #
    # Case 3 is the one this missed: an override persisted into .env by an earlier
    # install survives the process variable that put it there, so a later upgrade
    # with nothing exported pulled the custom image while the preflight evaluated
    # the resolved release. Reading .env unconditionally would be wrong in the
    # other direction - case 2's .env still names the version being upgraded FROM.
    local effective_image persisted
    effective_image="${WAYFINDR_IMAGE:-}"

    if [ -z "$effective_image" ]; then
        persisted="$(env_value WAYFINDR_IMAGE)"

        if [ -n "$persisted" ] && ! is_official_image "$persisted"; then
            effective_image="$persisted"
        fi
    fi

    if [ -n "$effective_image" ]; then
        # A tag only names a RELEASE when the image is ours. `fork:0.3.0` is
        # version 0.3.0 of somebody else's build, and fetching Wayfindr's official
        # 0.3.0 manifest for it checks a declaration the running code never made -
        # so a before-pull action the fork declares goes unseen while an official
        # one it does not declare is demanded.
        #
        # There is nowhere to fetch a fork's manifest from, so this skips. The
        # fork's own artifact still refuses to migrate, which is the guarantee.
        if env_interpolated "$effective_image"; then
            say "Preflight skipped: the image name uses variable interpolation."
            printf '    %s\n' "$effective_image"
            printf '    Which release that resolves to is Compose to decide, not this script.\n'
            printf '    The release enforces its own requirements when it starts.\n'

            return 0
        fi

        if ! is_official_image "$effective_image"; then
            say "Preflight skipped: the image is not an official Wayfindr build."
            printf '    %s\n' "$effective_image"
            printf '    Its release declarations are not published where this can read them.\n'
            printf '    The image enforces its own requirements when it starts.\n'

            return 0
        fi

        # Split on the last colon AFTER the last slash, so a registry port
        # (registry:5000/wayfindr) is not mistaken for a tag.
        local image_name
        image_name="${effective_image##*/}"

        case "$image_name" in
            *:*) to="${image_name##*:}" ;;
            *) to="" ;;
        esac

        to="${to#v}"

        case "$to" in
            ''|latest)
                say "Preflight skipped: the image override names no specific release."
                printf '    %s\n' "$effective_image"
                printf '    The release it will install enforces its own requirements when it\n'
                printf '    starts, and refuses to migrate rather than proceeding silently.\n'

                return 0
                ;;
        esac
    fi

    if [ -z "$to" ]; then
        say "Preflight skipped: no resolved release to check against."
        return 0
    fi

    # Checked wherever the tag came from, override or resolved release, because
    # `--ref v0.3` reaches the same place by a different road.
    if ! names_one_release "$to"; then
        say "Preflight skipped: \"$to\" names no one release."
        printf '    Floating tags move, so there is no single declaration to read.\n'
        printf '    Pin a full version to have this checked before pulling.\n'
        printf '    The release enforces its own requirements when it starts.\n'

        return 0
    fi

    if ! preflight_supported; then
        say "Preflight skipped: the installed release predates this check."
        printf '    The new release enforces its own requirements when it starts, and\n'
        printf '    refuses to migrate rather than proceeding silently.\n'

        return 0
    fi

    # Resolved in the artifact's own order, because the floor decision has to
    # predict what the artifact will do rather than what the installer can see:
    #
    #   1. the recorded state file - what the artifact reads
    #   2. WAYFINDR_UPGRADE_FROM - the operator's declared origin, which the
    #      refusal below tells them to set, so it has to be read or the refusal
    #      cannot be cleared
    #   3. the image tag or its baked version - useful to the preflight for
    #      working out which actions apply, and INVISIBLE to the artifact
    #
    # Only the first two make the artifact's origin known. An install sitting on
    # case 3 is one the artifact will refuse the moment it starts, whatever the
    # image tag says, so the preflight must refuse first - while the release that
    # is running is still the one running.
    local origin_known state span_origin span_known
    origin_known=0
    span_known=1

    state="$(release_state)"
    from="${state%%|*}"

    if [ -n "$state" ]; then
        span_origin="$(printf '%s' "$state" | cut -d'|' -f2)"
        span_known="$(printf '%s' "$state" | cut -d'|' -f3)"
    fi

    [ -n "$from" ] && origin_known=1

    if [ -z "$from" ]; then
        local declared
        declared="$(env_value WAYFINDR_UPGRADE_FROM)"

        if [ -n "$declared" ]; then
            from="$(declared_origin "$declared")"
            [ -n "$from" ] && origin_known=1
            span_origin="$from"
        fi
    fi

    # Nothing recorded and nothing declared means the artifact will read this as a
    # legacy install and evaluate the WHOLE published history. The preflight has
    # to do the same.
    #
    # A version derived from the running image was used here, and it narrowed the
    # span: everything at or below it dropped out, so an older before-pull
    # requirement - or an after-start debt now stranded - went unseen and the
    # working image was replaced before the artifact refused. The image is
    # something only the installer can see, and every use of it so far has turned
    # into a disagreement, so it is gone rather than narrowed again.
    [ -n "$from" ] || span_origin=""

    # An unknown span origin means the whole published history is in scope, which
    # is what an empty WF_FROM already means to the span filter.
    [ "$span_known" = "1" ] || span_origin=""
    : "${span_origin:=}"
    ack="$(env_value WAYFINDR_ACKNOWLEDGED_ACTIONS)"

    # A declaration describes its own release, so an upgrade must read EVERY
    # release it passes through, not just the one it lands on. Skipping the
    # middle is the several-releases-behind case this exists to catch.
    #
    # Fail CLOSED, and read EVERY page.
    #
    # Discarding the error made a transient fault or a 403 look identical to
    # "there are no other releases": the span fell back to the target alone,
    # every intermediate manifest went unread, and a before-pull action in one of
    # them was skipped without a word. That is the one outcome this preflight
    # exists to prevent, arrived at by not being able to look.
    #
    # Pagination is the same failure with a slower fuse. `per_page=100` caps a
    # single response, so past a hundred tags the older half of the span simply
    # is not in the answer - and an upgrade from far enough back would compute an
    # empty span and call it clear. Walk the pages until one comes back short.
    # `page` is the loop index and nothing else. Landing the parsed response on
    # it made `page=$((page + 1))` evaluate a multiline string as arithmetic,
    # which under `set -u` aborts the installer outright - so the preflight broke
    # the moment the tag history needed a second page.
    local tags_body tags_status tags_curl_exit page page_body page_count tag_count
    tags_body="$(mktemp)"
    tags=""
    page=1

    while :; do
        # curl's own exit status as well as the HTTP code, for the same reason
        # the manifest fetch checks both: a transfer that dies partway still
        # reports the 200 it saw in the headers, and a truncated page is short -
        # which this loop would read as "the last page" and stop, dropping every
        # release after it.
        tags_curl_exit=0
        tags_status="$(curl -sSL -o "$tags_body" -w '%{http_code}' "${TAGS_API}&page=${page}" 2>/dev/null)" || tags_curl_exit=$?
        [ -n "$tags_status" ] || tags_status="000"
        [ "$tags_curl_exit" -eq 0 ] || tags_status="000"

        if [ "$tags_status" != "200" ]; then
            rm -f "$tags_body"
            printf '\n\033[1;31mPREFLIGHT COULD NOT VERIFY THIS UPGRADE\033[0m\n\n'
            printf '  Could not list releases to work out what this upgrade passes through (HTTP %s).\n' "$tags_status"
            printf '  Refusing rather than checking only the target and calling that the whole span.\n\n'
            printf '  Nothing has been pulled or changed. Retry when the network settles.\n\n'
            exit 78
        fi

        # Parsed, not grepped. A 200 carrying HTML from a proxy, or a truncated
        # body, matches nothing - which reads as a short page, ends pagination,
        # and leaves the span with only the target. Counting occurrences fixed
        # the minified-response case but still never asked whether the response
        # was a tag list at all.
        #
        # The two numbers differ deliberately: the page count is EVERY entry,
        # because a full page of non-release tags still means there is another
        # page to read, while only release-shaped names are collected.
        page_body="$(printf '%s' "$(cat "$tags_body")" | php_in_current_image '
            $decoded = json_decode(stream_get_contents(STDIN), true);

            if (! is_array($decoded) || array_is_list($decoded) === false) {
                echo "INVALID";
                exit(0);
            }

            $names = [];

            foreach ($decoded as $entry) {
                if (! is_array($entry)) { echo "INVALID"; exit(0); }
                $name = $entry["name"] ?? null;
                if (is_string($name)) { $names[] = $name; }
            }

            echo "COUNT:", count($decoded), "\n", implode("\n", $names);
        ')"

        if [ "${page_body%%$'\n'*}" = "INVALID" ] || [ -z "$page_body" ]; then
            rm -f "$tags_body"
            printf '\n\033[1;31mPREFLIGHT COULD NOT VERIFY THIS UPGRADE\033[0m\n\n'
            printf '  The release list came back unreadable.\n'
            printf '  Refusing rather than treating an unparseable response as a short page.\n\n'
            printf '  Nothing has been pulled or changed. Retry when the network settles.\n\n'
            exit 78
        fi

        page_count="$(printf '%s' "$page_body" | sed -n 's/^COUNT://p' | head -1)"
        [ -n "$page_count" ] || page_count=0
        tag_count="$(printf '%s' "$page_body" | sed '1d' | grep -E '^v[0-9]' || true)"

        [ -n "$tag_count" ] && tags="${tags}${tag_count}
"

        [ "$page_count" -lt 100 ] && break

        page=$((page + 1))

        # A stop, not a limit to raise. Twenty full pages is two thousand tags,
        # which means the loop is not terminating - and looping forever against
        # someone's API is a worse failure than refusing.
        if [ "$page" -gt 20 ]; then
            rm -f "$tags_body"
            printf '\n\033[1;31mPREFLIGHT COULD NOT VERIFY THIS UPGRADE\033[0m\n\n'
            printf '  Gave up listing releases after %d pages.\n' "$((page - 1))"
            printf '  Refusing rather than checking an incomplete span.\n\n'
            printf '  Nothing has been pulled or changed.\n\n'
            exit 78
        fi
    done

    rm -f "$tags_body"
    tags="$(printf '%s' "$tags" | grep -v '^$' || true)"

    span="$(printf '%s\n' "$tags" | php_in_current_image '
        require "/app/apps/server/app/Support/Version/SemanticVersion.php";
        require "/app/apps/server/app/Support/Version/VersionComparator.php";
        $from = getenv("WF_FROM") ?: null;
        $to = getenv("WF_TO");
        foreach (explode("\n", trim(stream_get_contents(STDIN))) as $tag) {
            $tag = trim($tag);
            if ($tag === "") { continue; }
            $above = App\Support\Version\VersionComparator::compare($tag, $to);
            if ($above !== null && $above > 0) { continue; }
            if ($from !== null) {
                $after = App\Support\Version\VersionComparator::compare($tag, $from);
                if ($after !== null && $after <= 0) { continue; }
            }
            echo $tag, "\n";
        }' "WF_FROM=$span_origin" "WF_TO=$to")"

    # The target is ALWAYS in its own span, appended rather than substituted for
    # an empty one. A tag list that does not contain it - deleted after release,
    # or the releases endpoint updating ahead of the tags listing - produced a
    # nonempty span of OLDER releases, which suppressed this fallback entirely:
    # the target's own manifest was never fetched, so its floor and its
    # before-pull actions went unread before it was pulled and started.
    # The target is added when this upgrade actually TRAVERSES it - which is not
    # the same as always. An install already recorded at the target has a
    # legitimately empty span, `(target, target]`, and evaluating the target
    # anyway made a re-run of `--upgrade` refuse forever: a fresh install holds no
    # acknowledgement for the target's own upgrade-only work, because the artifact
    # exempted it rather than asking, so an always-applicable before-pull action
    # would exit 78 on every convergence run.
    #
    # The missing-tags fallback is what this is for: when the tag list does not
    # contain the release being installed, and the install is somewhere else, the
    # target still has to be read.
    if [ "$from" != "$to" ]; then
        span="$(printf '%s\nv%s\n' "$span" "$to" | grep -v '^$' | sort -u || true)"
    fi

    all_actions=""

    for tag in $span; do
        local status body curl_exit
        body="$(mktemp)"

        # Deliberately no `-f`. With it, curl writes the `-w` format AND exits
        # non-zero on an HTTP error, so the `|| printf '\n000'` fallback appended
        # a SECOND status line: a real 404 arrived as "404\n000", `tail -1` read
        # 000, and the 404 exemption below never ran. Every release with no
        # manifest asset - which is every release cut before this contract, so
        # every release that exists today - was read as a failure to reach the
        # network and refused the upgrade outright.
        #
        # Without `-f`, curl exits 0 for any response it actually received and
        # `-w` writes the real code. The body is only read when that code is 200,
        # so an error page can never be parsed as a declaration. A genuine
        # transport failure still writes 000 and is still refused.
        # curl's OWN exit status matters as well as the HTTP code. A transfer
        # that dies partway still reports the 200 it received in the headers, so
        # the code alone would wave through a half-downloaded declaration.
        curl_exit=0
        status="$(curl -sSL -o "$body" -w '%{http_code}' "https://github.com/adamgreenwell/wayfindr/releases/download/${tag}/release-manifest.json" 2>/dev/null)" || curl_exit=$?
        [ -n "$status" ] || status="000"
        [ "$curl_exit" -eq 0 ] || status="000"

        if [ "$status" = "200" ]; then
            manifest="$(cat "$body")"
        else
            manifest=""
        fi

        rm -f "$body"

        # A 404 means the release published no manifest - true of anything cut
        # before the contract existed, and not an error: it declared nothing.
        #
        # Anything else is a FAILURE TO KNOW, and must not be read as "no
        # requirements". A transient network fault would otherwise let the pull
        # proceed past a before-pull action that was never seen.
        case "$status" in
            200) : ;;
            404)
                # A release that published no manifest. True of everything cut
                # before this contract existed - they declared nothing and had no
                # way to say so - and not an error.
                #
                # It is an error for anything from the contract onward: those
                # releases publish the asset, so a missing one means deleted or
                # never uploaded, and reading it as "declares nothing" would pull
                # straight past a before-pull requirement nobody saw.
                if manifest_expected "$tag"; then
                    printf '\n\033[1;31mPREFLIGHT COULD NOT VERIFY THIS UPGRADE\033[0m\n\n'
                    printf '  %s publishes a release manifest, but it is missing (HTTP 404).\n' "$tag"
                    printf '  Refusing rather than assuming it requires nothing.\n\n'
                    printf '  Nothing has been pulled or changed. Report this - the release is incomplete.\n\n'
                    exit 78
                fi

                continue
                ;;
            *)
                printf '\n\033[1;31mPREFLIGHT COULD NOT VERIFY THIS UPGRADE\033[0m\n\n'
                printf '  Could not fetch the declaration for %s (HTTP %s).\n' "$tag" "$status"
                printf '  Refusing rather than assuming it requires nothing.\n\n'
                printf '  Nothing has been pulled or changed. Retry when the network settles.\n\n'
                exit 78
                ;;
        esac

        [ -n "$manifest" ] || continue

        actions="$(printf '%s' "$manifest" | php_in_current_image '
            require "/app/apps/server/app/Support/Version/SemanticVersion.php";
            require "/app/apps/server/app/Support/Version/VersionComparator.php";
            require "/app/apps/server/app/Support/Release/ReleaseManifest.php";
            // Validated with the artifact own validator rather than a minimal
            // shape check. An action missing `phase` passes any looser test and
            // then classifies as neither before-pull nor anything else - so the
            // pull proceeds, and the artifact rejects the manifest afterwards,
            // once the only phase that could have prevented anything is past.
            //
            // decode(), NOT assertPublished(): this runs in the image being
            // upgraded FROM, and that image only has the API its own release
            // shipped. A method added by the release being installed does not
            // exist there, and the undefined-method Error would be caught below
            // as "malformed manifest" - refusing every upgrade, including the
            // valid ones.
            try {
                $m = App\Support\Release\ReleaseManifest::decode(stream_get_contents(STDIN));
            } catch (Throwable $e) {
                echo "INVALID\n";
                exit(0);
            }

            // And it must be the manifest for THIS release. decode() only asks
            // whether the document is internally consistent, so an asset
            // generated for another release attaches and validates perfectly -
            // and then the floor check, which fires only when the manifest names
            // the target, silently does not run. The pull replaces a working
            // container with one whose own baked manifest refuses the origin.
            $expected = App\Support\Version\SemanticVersion::parse((string) getenv("WF_TAG"));

            if ($expected === null || $m["version"] !== $expected->canonical()) {
                echo "MISMATCH\n";
                exit(0);
            }

            $ack = array_map("trim", explode(",", (string) getenv("WF_ACK")));
            $target = getenv("WF_TO");
            $from = getenv("WF_FROM") ?: null;

            // Where the install IS, which is not where the span starts. The
            // floor is measured from this, and so is the applicability of any
            // action belonging to a release ABOVE it - those are newly traversed.
            // Anything at or below it is debt retained from an earlier upgrade,
            // whose applicability was settled by where THAT upgrade began.
            $recorded = getenv("WF_RECORDED") ?: null;

            // An origin that does not parse is not an origin. A typo would
            // otherwise compare as null against the floor, and "no definite
            // answer" does not refuse - so the typo would clear the check it was
            // supposed to be measured by. A DEVELOPMENT identity is kept: it
            // parses, it simply does not order, and the artifact treats a
            // recorded development version the same way.
            if ($from !== null && App\Support\Version\SemanticVersion::parse($from) === null) {
                $from = null;
            }

            if ($recorded !== null && App\Support\Version\SemanticVersion::parse($recorded) === null) {
                $recorded = null;
            }

            // The floor stops the pull, not the container afterwards. An install
            // below `minimum_upgrade_from` is refused by the new release the
            // moment it starts - so without this the preflight reports clear
            // (a floor-bearing release often declares no actions at all), the
            // pull replaces a WORKING deployment with one that refuses to
            // migrate, and the operator is told to step through a release while
            // the version that could have done it is no longer running.
            $floor = $m["minimum_upgrade_from"] ?? null;

            if (is_string($floor) && ($m["version"] ?? null) === $target) {
                // The artifact refuses a floor it cannot verify, and it can only
                // verify one against an origin IT has - its state file, or a
                // declared one. A version derived from the image tag is invisible
                // to it, so an install resting on that is refused the moment the
                // new release starts however orderable that version looks here.
                //
                // This is also where a development identity lands: it parses but
                // does not order, so it can neither clear the floor nor be ranked
                // against it.
                $originKnown = getenv("WF_ORIGIN_KNOWN") === "1";

                if ($recorded === null || ! $originKnown) {
                    // A floor in force and no way to tell where this install
                    // started. The artifact refuses this same case - so pulling
                    // anyway replaces a WORKING service with a container that
                    // cannot start, which is the outcome the preflight exists to
                    // prevent. Refuse here, while the old release is still up.
                    printf("FLOORUNKNOWN|%s\n", $floor);
                } else {
                    $rank = App\Support\Version\VersionComparator::compare($recorded, $floor);

                    // Only a definite "below" refuses. An undecidable comparison
                    // is a development identity on one side, which is not
                    // evidence of an unsupported jump.
                    if ($rank !== null && $rank < 0) {
                        printf("FLOOR|%s\n", $floor);
                    }
                }
            }

            foreach ($m["actions"] ?? [] as $a) {
                $release = $a["release"] ?? "";
                $key = $release . "/" . ($a["id"] ?? "");

                // Strandedness is decided BEFORE the acknowledgement is honoured,
                // matching the artifact. An acknowledged stranded action dropped
                // out here, the preflight reported clear, the image was pulled -
                // and the artifact then rejected that same acknowledgement and
                // exited 78, leaving a working install replaced by one that will
                // not start. The two must agree about what an acknowledgement can
                // settle, and neither lets it reach a release being skipped.
                // Three answers, not two.
                //
                // An action needing its own release code, where that release is
                // not the target, cannot be performed once the pull replaces the
                // code. What differs is whether the install can still do it NOW:
                //
                //   STEP - it never ran that release, so the only route is to
                //          stop at the release itself
                //   NOW  - it ran that release and the code is still here, so
                //          the work is possible until the moment we pull
                //   DO   - nothing special: performable after the upgrade
                //
                // NOW must stop the pull. Reclassifying it as an ordinary DO put
                // it in the "later" list, the preflight reported success, and the
                // pull then removed the only code that could have done it.
                $ownRelease = $release !== $target
                    && in_array($a["depends_on_release"] ?? "none", ["code", "schema"], true);

                $stranded = $ownRelease;
                $onlyNow = false;

                // Equality only. Ordering does not prove the install ever RAN a
                // release - direct jumps are supported - so a restored install
                // recorded at 0.4 that originally went 0.1 to 0.4 must not be
                // credited with having run 0.2.
                if ($ownRelease && $recorded !== null && $release === $recorded) {
                    $stranded = false;
                    $onlyNow = true;
                }

                if (! $stranded && in_array($key, $ack, true)) { continue; }

                // Applicability decides whether the action is for THIS upgrade
                // at all, and skipping it made every action outstanding for
                // everyone. A retirement is the case that shows it: an action
                // undoing what an earlier release set up carries
                // `upgrade-from.min`, and without this a direct jump from below
                // that min is blocked on undoing something it never did - with
                // no way to proceed but to acknowledge work that does not exist.
                //
                // `upgrade-from` is pure version comparison, so it is decided
                // here. `state` needs a check only the running application
                // implements, and this runs before that application exists, so
                // it is left in scope: "cannot tell" must stay conservative, and
                // the artifact evaluates it properly once it is up.
                $applicability = $a["applicability"] ?? ["type" => "always"];

                // Retained debt keeps the origin its own upgrade started from;
                // anything above the recorded release is newly traversed and is
                // measured from where the install is now. Sharing one origin
                // fails open: an install running v3 that still owes v2 work would
                // judge a v4 retirement of v3 state against the older origin,
                // decide it never had that state, and drop the retirement.
                $origin = $from;

                if ($recorded !== null && $release !== "") {
                    $above = App\Support\Version\VersionComparator::compare($release, $recorded);
                    if ($above !== null && $above > 0) { $origin = $recorded; }
                }

                if (($applicability["type"] ?? "always") === "upgrade-from" && $origin !== null) {
                    $min = $applicability["min"] ?? null;

                    if (is_string($min)) {
                        $rank = App\Support\Version\VersionComparator::compare($origin, $min);

                        // Only a decided comparison may drop it. A null means one
                        // side is a development identity and does not order.
                        if ($rank !== null && $rank < 0) { continue; }
                    }
                }

                // A `check` names a condition only the RELEASE implements, and
                // this runs before that release is here - so the preflight
                // cannot evaluate it and reports it as unverified rather than as
                // unmet. Refusing on one it cannot read is the safe direction (a
                // missed before-pull action is the failure this exists to
                // prevent), but the wording has to say which it is, or an
                // operator reads a check they have already satisfied as an
                // accusation they have not.
                $unverifiable = ($a["verification"]["type"] ?? "attest") === "check";

                // Prose is base64d, because it is prose. A summary or detail may
                // legitimately contain a newline or a `|` - a shell pipeline in
                // an instruction is the obvious case - and this is a
                // pipe-delimited, newline-separated protocol. Raw, an
                // instruction could truncate itself at the first pipe or forge
                // an entire extra action at the first newline.
                printf("%s|%s|%s|%s|%s|%s\n", $stranded ? "STEP" : ($onlyNow ? "NOW" : "DO"),
                    $key, $a["phase"] ?? "",
                    base64_encode((string) ($a["summary"] ?? "")),
                    base64_encode((string) ($a["detail"] ?? "")),
                    $unverifiable ? "CHECK" : "ATTEST");
            }

            // Advisory notices (ADR 0013). Emitted on their own prefix and
            // stripped out of the action list before the partition ever sees
            // them, so nothing here can turn advice into a refusal.
            //
            // Deliberately unevaluated: a notice is usually verified by a `check`
            // that only the release being installed implements, and this runs
            // before that release is here. So these are printed as what the
            // release ADVISES rather than as what this install is missing, and
            // the artifact reports the live answer once it is up.
            //
            // A release that declares none omits the key entirely, and every
            // release published before notices existed has no key at all - so
            // the null coalesce is the normal path, not an edge case.
            foreach ($m["notices"] ?? [] as $n) {
                $key = ($n["release"] ?? "") . "/" . ($n["id"] ?? "");

                // An acknowledgement silences a notice HERE too. The documented
                // way to stop being told about one is to add its key to
                // WAYFINDR_ACKNOWLEDGED_ACTIONS, and an operator who did that
                // was still told during preflight - so the promise the docs make
                // was false on one of the three surfaces. The list is already
                // parsed for actions; it just was not consulted.
                if (in_array($key, $ack, true)) { continue; }

                // Only the TARGET release advises. The artifact evaluates
                // notices against the running release alone - a notice is about
                // how this release wants to be operated, not work owed by the
                // hop - so reporting notices from an intermediate release here would
                // promise advice the install will never repeat.
                if (($m["version"] ?? null) !== $target) { continue; }

                printf("NOTICE|%s|%s|%s\n", $key,
                    base64_encode((string) ($n["summary"] ?? "")),
                    base64_encode((string) ($n["detail"] ?? "")));
            }' "WF_FROM=$span_origin" "WF_TO=$to" "WF_ACK=$ack" \
                "WF_ORIGIN_KNOWN=$origin_known" "WF_RECORDED=$from" "WF_TAG=$tag")"

        # Refused before anything is pulled, which is the whole point: the old
        # release is still running and is the one that can still upgrade.
        if printf '%s' "$actions" | grep -q '^FLOORUNKNOWN|'; then
            floor="$(printf '%s' "$actions" | sed -n 's/^FLOORUNKNOWN|//p' | head -1)"
            printf '\n\033[1;31mTHIS UPGRADE CANNOT BE VERIFIED\033[0m\n\n'
            printf '  %s only supports upgrading from %s or later, and nothing here\n' "$to" "$floor"
            printf '  records which version this install is running.\n\n'
            # NEVER PRINT THE FLOOR AS THE VALUE HERE. This used to read
            # `WAYFINDR_UPGRADE_FROM=$floor`, which handed the operator the one
            # value that defeats the check: env_value() reads it back as a known
            # origin so the next preflight permits the pull, and the artifact
            # trusts the same declaration, so an install genuinely below the
            # floor migrates on a path whose migrations no longer ship - through
            # the primary installer flow, having done exactly as it was told.
            #
            # The artifact's copy of this refusal (App\Support\Release\FloorAdvice)
            # had the same defect and was fixed with it. Two independently
            # rendered halves of one message, which is the drift this area keeps
            # producing - when either changes, grep the WHOLE repo for the other.
            printf '  State the release you are upgrading FROM in %s:\n' "$ENV_FILE"
            printf '    WAYFINDR_UPGRADE_FROM=<the release you are upgrading from>\n\n'
            printf '  Or upgrade to %s first, which records it.\n\n' "$floor"
            printf '  Stating a version below %s is still refused - this establishes\n' "$floor"
            printf '  where you started, it does not grant permission.\n\n'
            printf '  Nothing has been pulled or changed - the release you are running is intact.\n\n'
            exit 78
        fi

        if printf '%s' "$actions" | grep -q '^FLOOR|'; then
            floor="$(printf '%s' "$actions" | sed -n 's/^FLOOR|//p' | head -1)"
            printf '\n\033[1;31mTHIS UPGRADE IS NOT SUPPORTED DIRECTLY\033[0m\n\n'
            printf '  This install (%s) is older than %s allows to upgrade from.\n' "${from:-unknown}" "$to"
            printf '  The oldest supported starting point is %s.\n\n' "$floor"
            printf '  Upgrade to %s first, let it start, then run this again.\n' "$floor"
            printf '  Acknowledgement cannot help: the migrations for this jump no longer ship.\n\n'
            printf '  Nothing has been pulled or changed - the release you are running is intact.\n\n'
            exit 78
        fi

        if [ "$actions" = "MISMATCH" ]; then
            printf '\n\033[1;31mPREFLIGHT COULD NOT VERIFY THIS UPGRADE\033[0m\n\n'
            printf '  The declaration published for %s is a declaration for a different\n' "$tag"
            printf '  release. Refusing rather than checking one release against another.\n\n'
            printf '  Nothing has been pulled or changed. Report this - the release is\n'
            printf '  mispublished.\n\n'
            exit 78
        fi

        if [ "$actions" = "INVALID" ]; then
            printf '\n\033[1;31mPREFLIGHT COULD NOT VERIFY THIS UPGRADE\033[0m\n\n'
            printf '  The declaration for %s came back unreadable.\n' "$tag"
            printf '  Refusing rather than treating an unparseable response as "requires nothing".\n\n'
            printf '  Nothing has been pulled or changed. Retry when the network settles.\n\n'
            exit 78
        fi

        [ -n "$actions" ] && all_actions="${all_actions}${actions}
"
    done

    all_actions="$(printf '%s' "$all_actions" | grep -v '^$' || true)"

    # Advisory notices are separated out HERE, before anything partitions the
    # list, so no downstream grep can accidentally treat one as work that blocks.
    # Without this they would survive the `later` filter's three greps and be
    # reported as post-upgrade requirements, which is exactly the overstatement
    # the advisory channel exists to avoid.
    local all_notices
    all_notices="$(printf '%s\n' "$all_actions" | grep '^NOTICE|' || true)"
    all_actions="$(printf '%s\n' "$all_actions" | grep -v '^NOTICE|' || true)"

    if [ -z "$all_actions" ]; then
        say "Preflight: nothing outstanding between ${from:-unknown} and $to."
        report_notices "$all_notices"
        return 0
    fi

    local stranded blocking later onlynow
    stranded="$(printf '%s\n' "$all_actions" | grep '^STEP|' || true)"

    # Only `before-pull` may stop the pull. An `after-pull` action needs the new
    # code, and `after-start` needs the migrated schema - neither exists yet, so
    # refusing to pull because of them would make them permanently unsatisfiable.
    # The artifact enforces those itself once the code is there: after-pull blocks
    # the migration, after-start gates serving.
    blocking="$(printf '%s\n' "$all_actions" | grep -E '^(STEP|DO|NOW)\|[^|]*\|before-pull\|' || true)"

    # `NOW` is work the install can still do because the release it belongs to is
    # the one running - and cannot do afterwards, because the pull replaces that
    # code. Letting it through as "later" was the same silent progression a
    # before-pull action is stopped for.
    onlynow="$(printf '%s\n' "$all_actions" | grep '^NOW|' || true)"

    blocking="$(printf '%s\n' "$blocking
$stranded
$onlynow" | grep -v '^$' | sort -u || true)"
    later="$(printf '%s\n' "$all_actions" | grep -vE '^(STEP|DO|NOW)\|[^|]*\|before-pull\|' | grep -v '^STEP|' | grep -v '^NOW|' || true)"

    if [ -z "$blocking" ]; then
        say "Preflight: nothing to do before pulling."

        if [ -n "$later" ]; then
            printf '\n  \033[1;33mAfter this upgrade you will need to:\033[0m\n'
            printf '%s\n' "$later" | while IFS='|' read -r _ key phase summary _; do
                [ -n "$key" ] || continue
                printf '    %s (%s) - %s\n' "$key" "$phase" "$(unprose "$summary")"
            done
            printf '\n  The release enforces these itself: it refuses to migrate or to serve\n'
            printf '  until they are done or acknowledged.\n\n'
        fi

        # After the enforced list, so the two are never confused: everything
        # above this line will stop the release; nothing below it will.
        report_notices "$all_notices"

        return 0
    fi

    all_actions="$blocking"

    printf '\n\033[1;31mUPGRADE NEEDS YOU FIRST\033[0m\n\n'
    printf '  Upgrading %s -> %s requires operator action.\n\n' "${from:-unknown}" "$to"

    if [ -n "$stranded" ]; then
        printf '  \033[1;31mThis jump cannot be made directly.\033[0m The steps below belong to a\n'
        printf '  release you would skip over, and need that release own code or schema -\n'
        printf '  so they can be run neither before the pull nor after it. Upgrade to that\n'
        printf '  release first with --ref, complete them there, then continue.\n\n'

        printf '%s\n' "$stranded" | while IFS='|' read -r _ key phase summary detail verification; do
            [ -n "$key" ] || continue
            printf '  \033[1;31m%s\033[0m (%s, must run on its own release)\n    %s\n' "$key" "$phase" "$(unprose "$summary")"
            [ -n "$detail" ] && printf '    %s\n' "$(unprose "$detail")"
            printf '\n'
        done
    fi

    if [ -n "$onlynow" ]; then
        printf '  \033[1;33mDo these before upgrading.\033[0m They belong to the release you are\n'
        printf '  running and need its code, which the pull replaces - so this is the last\n'
        printf '  moment they can be done. If you have already done them, acknowledge them\n'
        printf '  and run this again.\n\n'

        printf '%s\n' "$onlynow" | while IFS='|' read -r _ key phase summary detail verification; do
            [ -n "$key" ] || continue
            printf '  \033[1;33m%s\033[0m (%s, do it before pulling)\n    %s\n' "$key" "$phase" "$(unprose "$summary")"
            [ -n "$detail" ] && printf '    %s\n' "$(unprose "$detail")"
            printf '\n'
        done
    fi

    printf '%s\n' "$all_actions" | grep '^DO|' | while IFS='|' read -r _ key phase summary detail verification; do
        [ -n "$key" ] || continue
        printf '  \033[1;33m%s\033[0m (%s)\n    %s\n' "$key" "$phase" "$(unprose "$summary")"
        [ -n "$detail" ] && printf '    %s\n' "$(unprose "$detail")"

        # Said plainly, because the two are different situations for the reader.
        # A `check` is verified by the release itself, and this cannot run it -
        # so listing it without saying so reads as an accusation of something
        # undone, when the honest statement is that nothing here could tell.
        if [ "$verification" = "CHECK" ]; then
            printf '    (This release verifies this itself. The preflight cannot run that\n'
            printf '     check before pulling, so it is listed as unverified rather than\n'
            printf '     as undone - if it is already done, the release will say so.)\n'
        fi

        printf '\n'
    done

    printf '  Nothing has been pulled or changed.\n'

    # Both statements can be true at once, so neither may speak for "the steps
    # above" as a whole. A refusal carrying a skipped-release step AND work the
    # install can still do was telling the operator that acknowledging would not
    # help - while the section directly above it told them to acknowledge - which
    # sends someone holding a usable key through a rollback they do not need.
    #
    # Each is scoped by the label its own section printed.
    local acknowledgeable
    acknowledgeable="$(printf '%s\n' "$all_actions" | grep -E '^(DO|NOW)\|' || true)"

    if [ -n "$stranded" ]; then
        printf '  The steps marked "must run on its own release" cannot be acknowledged:\n'
        printf '  they are unreachable from this jump, not merely undone.\n'
    fi

    if [ -n "$acknowledgeable" ]; then
        [ -n "$stranded" ] && printf '\n  For the rest:\n'
        printf '  Do the work, then add its entry to WAYFINDR_ACKNOWLEDGED_ACTIONS in\n'
        printf '  %s and run this again.\n' "$ENV_FILE"
    fi

    printf '\n'

    exit 78
}

migrate_env() {
    # Installs generated before release identity was baked carry blank
    # WAYFINDR_VERSION= / WAYFINDR_COMMIT= lines, and env_file entries
    # override the image ENV — leaving them would keep /operator blank
    # forever. Drop ONLY the empty ones; a value the operator set is theirs.
    if grep -qE '^WAYFINDR_(VERSION|COMMIT)=$' "$ENV_FILE"; then
        sed -i.bak -E '/^WAYFINDR_(VERSION|COMMIT)=$/d' "$ENV_FILE"
        rm -f "$ENV_FILE.bak"
        say "Removed blank release-identity overrides so /operator reports the image version."
    fi

    # 0.4.0 shipped IP-address installs that obtained a certificate and then
    # refused every connection: a client connecting to an IP sends no SNI, so
    # Caddy had no name to select one by and aborted the handshake.
    #
    # The fix is a `default_sni` line, and an environment file generated by
    # 0.4.0 has no such key — the file is preserved across upgrades, by design,
    # because it holds the secrets. Without this, an upgrade would pull the
    # corrected image, restart, pass its loopback health check on :8000, and
    # still be broken at the public address, with nothing in the output saying
    # so. The whole point of the release would miss the installs that need it.
    local sni

    case "$(env_value CADDY_SERVER_EXTRA_DIRECTIVES)" in
        *"tls internal"*)
            if [ -z "$(env_value CADDY_GLOBAL_OPTIONS_EXTRA)" ]; then
                # SERVER_NAME is the host for any locally-issued certificate,
                # and brackets belong in a Caddy site address but not in an
                # SNI value.
                sni="$(env_value SERVER_NAME)"
                sni="${sni#"["}"
                sni="${sni%"]"}"

                if [ -n "$sni" ]; then
                    if grep -qE '^CADDY_GLOBAL_OPTIONS_EXTRA=' "$ENV_FILE"; then
                        sed -i.bak -E "s|^CADDY_GLOBAL_OPTIONS_EXTRA=.*|CADDY_GLOBAL_OPTIONS_EXTRA=default_sni $sni|" "$ENV_FILE"
                        rm -f "$ENV_FILE.bak"
                    else
                        printf 'CADDY_GLOBAL_OPTIONS_EXTRA=default_sni %s\n' "$sni" >> "$ENV_FILE"
                    fi

                    say "Set default_sni for the locally-issued certificate; clients that send no SNI (anything reaching an IP address) could not complete a handshake."
                fi
            fi
            ;;
    esac
}

require_runnable_image() {
    # Before the first release no ghcr image exists: a fresh install would
    # fail on the pull with a confusing error, so fail early with the way
    # forward instead.
    if [ "$PRERELEASE" = "1" ] && [ -z "${WAYFINDR_IMAGE:-}" ]; then
        die "No published Wayfindr release exists yet, so there is no image to pull. Either set WAYFINDR_IMAGE to an image you have built, or clone the repo and use the compose.build.yml overlay (see docker/self-hosting/README.md)."
    fi
}

compose() {
    # The compose file pins the project name (wayfindr-self-hosting), so
    # repeated runs and upgrades always converge on the same stack.
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

fetch() {
    local path="$1" dest="$2"

    if [ -n "$SOURCE_DIR" ]; then
        cp "$SOURCE_DIR/$path" "$dest"
    else
        curl -fsSL "$RAW_BASE_DEFAULT/$REF/$path" -o "$dest"
    fi
}

if [ "$UPGRADE" = "1" ]; then
    [ -f "$COMPOSE_FILE" ] && [ -f "$ENV_FILE" ] || die "No install found in $TARGET_DIR (use --dir to point at it)."
    resolve_release
    say "Refreshing stack files at $REF."
    fetch docker/self-hosting/compose.yml "$COMPOSE_FILE"

    # Hand off to the version we just downloaded, BEFORE anything is pulled.
    #
    # Without this the upgrade refreshes install.sh on disk and then carries on
    # in the already-parsed process, so a preflight shipped in the new script
    # would sit there unrun while the release it was written to guard is pulled
    # and started. Re-execing is also the only safe way to overwrite a script
    # bash is still reading: bash reads incrementally, so replacing the file
    # underneath a running process can make it resume at the wrong offset.
    #
    # WAYFINDR_HANDED_OFF guards the recursion. It is exported rather than passed
    # as an argument so it cannot collide with the operator's own flags, and it
    # is checked before the fetch so a hand-off never re-fetches.
    if [ -z "${WAYFINDR_HANDED_OFF:-}" ]; then
        fetch scripts/self-host/install.sh "$TARGET_DIR/install.sh.new"
        chmod +x "$TARGET_DIR/install.sh.new"
        mv "$TARGET_DIR/install.sh.new" "$TARGET_DIR/install.sh"

        say "Handing off to the refreshed installer."
        WAYFINDR_HANDED_OFF=1 exec "$TARGET_DIR/install.sh" "${WAYFINDR_ORIGINAL_ARGS[@]}"
    fi

    upgrade_preflight

    migrate_env
    pin_image
    say "Pulling the release image."
    compose pull web || say "Pull failed; keeping the current image (pre-release or locally built installs)."
    say "Restarting the stack (migrations run automatically)."
    compose up -d

    # Reported only once it is actually serving. Saying "complete" over a
    # container that refused and stopped is the one outcome worse than the
    # refusal itself, because it sends the operator away.
    if ! await_web_start; then
        exit 78
    fi

    say "Upgrade complete."
    exit 0
fi

[ -n "$APP_URL" ] || die "--app-url is required, e.g. --app-url https://support.example.com"

# Which hosts get which certificate, and what a bare host infers, live in
# generate-env.sh and ONLY there -- that script has to agree with the binds,
# SERVER_NAME, and Reverb values it writes from the same URL, and a second
# copy of those rules here is exactly how the installer ends up printing a URL
# the stack does not serve.
#
# What is worth catching early is only what cannot become valid either way: a
# scheme this stack will never speak, and whitespace that would have split the
# argument before either script saw it. A bare host falls through on purpose.
# Failing here saves downloading the whole stack first.
case "$APP_URL" in
    *[[:space:]]*) die "--app-url must not contain whitespace: $APP_URL" ;;
    http://*|https://*) ;;
    *://*) die "--app-url supports http:// and https:// only: $APP_URL" ;;
esac

mkdir -p "$TARGET_DIR"

resolve_release
say "Fetching the Wayfindr stack files (${SOURCE_DIR:+local checkout}${SOURCE_DIR:-ref $REF})."
fetch docker/self-hosting/compose.yml "$COMPOSE_FILE"
fetch scripts/self-host/generate-env.sh "$TARGET_DIR/generate-env.sh"
fetch scripts/self-host/install.sh "$TARGET_DIR/install.sh"
chmod +x "$TARGET_DIR/generate-env.sh" "$TARGET_DIR/install.sh"

if [ -f "$ENV_FILE" ]; then
    say "Keeping the existing $ENV_FILE (secrets preserved)."
else
    say "Generating $ENV_FILE with fresh secrets."
    generate_args=(--app-url "$APP_URL" --mail-from "$MAIL_FROM" --output "$ENV_FILE")
    [ "$BEHIND_PROXY" = "1" ] && generate_args+=(--behind-proxy)
    # stdout is the generator's own "next steps" summary, which this script
    # replaces with a tailored one below. Its stderr (the inferred-scheme
    # notice) is deliberately left to pass through.
    "$TARGET_DIR/generate-env.sh" "${generate_args[@]}" >/dev/null
    pin_image
fi

# Read back what was WRITTEN rather than trusting the flag that was passed.
#
# Two ways they differ, both of which end with an operator at a dead URL. The
# generator infers a scheme for a bare host, so `--app-url localhost` becomes
# http://localhost on disk. And a re-run over an existing env keeps the
# ORIGINAL URL by design (secrets are preserved, so the whole file is) while
# --app-url is silently ignored -- printing the flag would send them to a URL
# this stack has never served.
APP_URL="$(env_value APP_URL)"
[ -n "$APP_URL" ] || die "The environment file at $ENV_FILE has no APP_URL."

if [ "$NO_START" = "1" ]; then
    say "Stack prepared in $TARGET_DIR (not started, per --no-start)."
    exit 0
fi

require_runnable_image

say "Starting the stack (first run downloads the application image)."
compose up -d

# sed exits 0 whether or not the key exists, unlike grep under pipefail —
# a hand-written env without WAYFINDR_LOCAL_BIND must fall back, not abort.
LOCAL_BIND="$(sed -n 's/^WAYFINDR_LOCAL_BIND=//p' "$ENV_FILE")"
LOCAL_URL="http://${LOCAL_BIND:-127.0.0.1:8000}"

say "Waiting for the application to come up."
tries=0
until curl -fs --max-time 2 "$LOCAL_URL/up" >/dev/null 2>&1; do
    tries=$((tries + 1))
    [ "$tries" -lt 60 ] || {
        compose ps || true
        die "The web service did not become healthy. Inspect: docker compose -f $COMPOSE_FILE --env-file $ENV_FILE logs web"
    }
    sleep 2
done

cat <<DONE

  Wayfindr is running.

  Create the first account:  $APP_URL/setup
  Environment file:          $ENV_FILE  (mail is set to 'log' — configure SMTP before real traffic)
  Logs:                      docker compose -f $COMPOSE_FILE --env-file $ENV_FILE logs -f
  Upgrade later:             $TARGET_DIR/install.sh --upgrade --dir $TARGET_DIR

  Readiness checks live at $APP_URL/operator after you sign in. The account you
  create at /setup is this install's first platform operator, so it is yours.

DONE

# The proxy upstream is PRINTED rather than left to the documentation, because
# it is no longer always 127.0.0.1:8000. When the operator's own port collides
# with the ops site -- `--behind-proxy` with https://host:8000 -- the ops site
# moves, and a proxy pointed at the documented 8000 would be aimed at its own
# public listener, which loops or 502s. The env file is the truth; this reads
# it back rather than restating a constant.
if [ "$(env_value TRUSTED_PROXIES)" = "*" ]; then
    cat <<PROXY
  Point your reverse proxy at:  $LOCAL_URL

  That is WAYFINDR_LOCAL_BIND in the environment file, and it is NOT always
  127.0.0.1:8000 -- it moves when your own public port would collide with it.
  Websockets are routed internally, so this single upstream covers the
  application and realtime together.

PROXY
fi

# A locally-issued certificate is a certificate, not a warning to click past —
# but only once its root is trusted, and nothing else in this output would
# explain why the first visit to a working install looks broken.
case "$(env_value CADDY_SERVER_EXTRA_DIRECTIVES)" in
    *"tls internal"*)
        cat <<CERT
  No public certificate authority can issue for this host, so Caddy signed
  with its own. Until that root is trusted, browsers will warn. Export it:

    cd $TARGET_DIR && docker compose -f compose.yml --env-file .env cp web:/data/caddy/pki/authorities/local/root.crt ./wayfindr-local-ca.crt

  Then add wayfindr-local-ca.crt to the trust store of every machine that
  browses here (macOS: Keychain Access > System > drag in > Always Trust.
  Debian/Ubuntu: copy to /usr/local/share/ca-certificates/ and run
  update-ca-certificates).

CERT
        ;;
esac
