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
UPGRADE=0
NO_START=0

usage() {
    cat <<'USAGE'
Usage:
  install.sh --app-url <url> [options]
  install.sh --upgrade [--dir <path>]

Options:
  --app-url <url>   Public URL (https://support.example.com for automatic TLS,
                    http://... for smoke tests or behind your own proxy).
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

    raw="$(grep -E "^[[:space:]]*(export[[:space:]]+)?$1=" "$ENV_FILE" 2>/dev/null \
        | head -1 \
        | sed -E "s/^[[:space:]]*(export[[:space:]]+)?$1=//" || true)"

    # Trailing CR, for a file written on Windows.
    raw="${raw%$'\r'}"

    case "$raw" in
        \"*\") raw="${raw#\"}"; raw="${raw%\"}" ;;
        \'*\') raw="${raw#\'}"; raw="${raw%\'}" ;;
    esac

    printf '%s' "$raw"
}

# Whether the configured image is the official one, which is what makes its tag a
# release this can look up.
is_official_image() {
    case "$1" in
        ghcr.io/adamgreenwell/wayfindr:*) return 0 ;;
        *) return 1 ;;
    esac
}

say() { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

# The parse loop below consumes $@ with `shift`, so the hand-off would have
# nothing left to replay. Capture the operator's arguments first — losing --dir
# would silently upgrade a different install than the one they asked for.
WAYFINDR_ORIGINAL_ARGS=("$@")

while [ "$#" -gt 0 ]; do
    case "$1" in
        --app-url) APP_URL="${2:-}"; shift 2 ;;
        --dir) TARGET_DIR="${2:-}"; shift 2 ;;
        --mail-from) MAIL_FROM="${2:-}"; shift 2 ;;
        --behind-proxy) BEHIND_PROXY=1; shift ;;
        --ref) REF="${2:-}"; shift 2 ;;
        --source-dir) SOURCE_DIR="${2:-}"; shift 2 ;;
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
    if [ -n "${WAYFINDR_IMAGE:-}" ]; then
        set_image "$WAYFINDR_IMAGE"
    elif [ -n "$IMAGE_TAG" ] && is_official_image "$(env_value WAYFINDR_IMAGE)"; then
        set_image "ghcr.io/adamgreenwell/wayfindr:$IMAGE_TAG"
    fi
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

    compose run --rm --no-deps -T ${env_args[@]+"${env_args[@]}"} \
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
    span="$(printf '%s\nv%s\n' "$span" "$to" | grep -v '^$' | sort -u || true)"

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
                if (in_array($key, $ack, true)) { continue; }

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

                // An action belonging to an INTERMEDIATE release that needs that
                // release own code or schema cannot be performed at any point in
                // a direct jump: before-pull has the old release, and both
                // after-pull and after-start have the target. The only way to
                // satisfy it is to stop at the release it belongs to.
                $stranded = $release !== $target
                    && in_array($a["depends_on_release"] ?? "none", ["code", "schema"], true);

                printf("%s|%s|%s|%s|%s\n", $stranded ? "STEP" : "DO",
                    $key, $a["phase"] ?? "", $a["summary"] ?? "", $a["detail"] ?? "");
            }' "WF_FROM=$span_origin" "WF_TO=$to" "WF_ACK=$ack" \
                "WF_ORIGIN_KNOWN=$origin_known" "WF_RECORDED=$from")"

        # Refused before anything is pulled, which is the whole point: the old
        # release is still running and is the one that can still upgrade.
        if printf '%s' "$actions" | grep -q '^FLOORUNKNOWN|'; then
            floor="$(printf '%s' "$actions" | sed -n 's/^FLOORUNKNOWN|//p' | head -1)"
            printf '\n\033[1;31mTHIS UPGRADE CANNOT BE VERIFIED\033[0m\n\n'
            printf '  %s only supports upgrading from %s or later, and nothing here\n' "$to" "$floor"
            printf '  records which version this install is running.\n\n'
            printf '  Pin the version you are on in %s, for example:\n' "$ENV_FILE"
            printf '    WAYFINDR_UPGRADE_FROM=%s\n\n' "$floor"
            printf '  Or upgrade to %s first, which records it.\n\n' "$floor"
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

    if [ -z "$all_actions" ]; then
        say "Preflight: nothing outstanding between ${from:-unknown} and $to."
        return 0
    fi

    local stranded blocking later
    stranded="$(printf '%s\n' "$all_actions" | grep '^STEP|' || true)"

    # Only `before-pull` may stop the pull. An `after-pull` action needs the new
    # code, and `after-start` needs the migrated schema - neither exists yet, so
    # refusing to pull because of them would make them permanently unsatisfiable.
    # The artifact enforces those itself once the code is there: after-pull blocks
    # the migration, after-start gates serving.
    blocking="$(printf '%s\n' "$all_actions" | grep -E '^(STEP|DO)\|[^|]*\|before-pull\|' || true)"
    blocking="$(printf '%s\n' "$blocking
$stranded" | grep -v '^$' | sort -u || true)"
    later="$(printf '%s\n' "$all_actions" | grep -vE '^(STEP|DO)\|[^|]*\|before-pull\|' | grep -v '^STEP|' || true)"

    if [ -z "$blocking" ]; then
        say "Preflight: nothing to do before pulling."

        if [ -n "$later" ]; then
            printf '\n  \033[1;33mAfter this upgrade you will need to:\033[0m\n'
            printf '%s\n' "$later" | while IFS='|' read -r _ key phase summary _; do
                [ -n "$key" ] || continue
                printf '    %s (%s) - %s\n' "$key" "$phase" "$summary"
            done
            printf '\n  The release enforces these itself: it refuses to migrate or to serve\n'
            printf '  until they are done or acknowledged.\n\n'
        fi

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

        printf '%s\n' "$stranded" | while IFS='|' read -r _ key phase summary detail; do
            [ -n "$key" ] || continue
            printf '  \033[1;31m%s\033[0m (%s, must run on its own release)\n    %s\n' "$key" "$phase" "$summary"
            [ -n "$detail" ] && printf '    %s\n' "$detail"
            printf '\n'
        done
    fi

    printf '%s\n' "$all_actions" | grep '^DO|' | while IFS='|' read -r _ key phase summary detail; do
        [ -n "$key" ] || continue
        printf '  \033[1;33m%s\033[0m (%s)\n    %s\n' "$key" "$phase" "$summary"
        [ -n "$detail" ] && printf '    %s\n' "$detail"
        printf '\n'
    done

    printf '  Nothing has been pulled or changed.\n'

    if [ -n "$stranded" ]; then
        printf '  Acknowledging will not help with the steps above: they are unreachable\n'
        printf '  from this jump, not merely undone.\n\n'
    else
        printf '  Do the work, then add the entries above to WAYFINDR_ACKNOWLEDGED_ACTIONS\n'
        printf '  in %s and run this again.\n\n' "$ENV_FILE"
    fi

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
    say "Upgrade complete."
    exit 0
fi

[ -n "$APP_URL" ] || die "--app-url is required, e.g. --app-url https://support.example.com"

case "$APP_URL" in
    http://*|https://*) ;;
    *) die "--app-url must start with http:// or https://" ;;
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
    "$TARGET_DIR/generate-env.sh" "${generate_args[@]}" >/dev/null
    pin_image
fi

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

  Readiness checks live at $APP_URL/dashboard/readiness after you sign in.

DONE
