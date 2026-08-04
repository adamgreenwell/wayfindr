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

pin_image() {
    # An operator-supplied WAYFINDR_IMAGE wins; otherwise a resolved release
    # tag pins the published image.
    if [ -n "${WAYFINDR_IMAGE:-}" ]; then
        sed -i.bak "s#^WAYFINDR_IMAGE=.*#WAYFINDR_IMAGE=$WAYFINDR_IMAGE#" "$ENV_FILE"
        rm -f "$ENV_FILE.bak"
    elif [ -n "$IMAGE_TAG" ] && grep -q '^WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:' "$ENV_FILE"; then
        sed -i.bak "s#^WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:.*#WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:$IMAGE_TAG#" "$ENV_FILE"
        rm -f "$ENV_FILE.bak"
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
php_in_current_image() {
    compose run --rm --no-deps -T \
        -e WF_FROM="${1:-}" -e WF_TO="${2:-}" -e WF_ACK="${3:-}" \
        --entrypoint php web -r "$4" 2>/dev/null
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
    answer="$(php_in_current_image "" "" "" '
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
    answer="$(php_in_current_image "$1" "$MANIFEST_CONTRACT_FROM" "" '
        require "/app/apps/server/app/Support/Version/SemanticVersion.php";
        require "/app/apps/server/app/Support/Version/VersionComparator.php";
        $rank = App\Support\Version\VersionComparator::compare(getenv("WF_FROM"), getenv("WF_TO"));
        // An undecidable rank counts as expected. The exemption is for releases
        // we can SHOW predate the contract; one we cannot place must not inherit
        // it, or an unparseable tag becomes a way to skip the check entirely.
        echo ($rank === null || $rank >= 0) ? "YES" : "NO";
    ')"

    [ "$answer" = "YES" ]
}

current_release() {
    # The pinned image tag is the most reliable statement of what is installed:
    # it is what compose actually runs. `latest` names no version.
    local pinned baked
    pinned="$(grep -E '^WAYFINDR_IMAGE=' "$ENV_FILE" 2>/dev/null | head -1 | sed 's#.*:##')"

    case "$pinned" in
        ''|latest) : ;;
        *) printf '%s' "$pinned"; return 0 ;;
    esac

    # An unversioned pin still runs a real image, and that image knows what it
    # is: the build bakes its own manifest. Ask it, rather than reporting an
    # unknown origin and refusing an upgrade that is perfectly determinable.
    #
    # `latest` is the default for anyone who installed without pinning, so this
    # is the common case, not an edge one.
    baked="$(php_in_current_image "" "" "" '
        $path = "/etc/wayfindr/release.json";
        if (! is_file($path)) { exit(0); }
        $m = json_decode((string) file_get_contents($path), true);
        if (is_array($m) && is_string($m["version"] ?? null)) { echo $m["version"]; }
    ')"

    printf '%s' "$baked"
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
        persisted="$(grep -E '^WAYFINDR_IMAGE=' "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- || true)"

        case "$persisted" in
            ghcr.io/adamgreenwell/wayfindr:*|'') : ;;
            *) effective_image="$persisted" ;;
        esac
    fi

    if [ -n "$effective_image" ]; then
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

    if ! preflight_supported; then
        say "Preflight skipped: the installed release predates this check."
        printf '    The new release enforces its own requirements when it starts, and\n'
        printf '    refuses to migrate rather than proceeding silently.\n'

        return 0
    fi

    from="$(current_release)"

    # The refusal below tells the operator to state where they are, so the
    # preflight has to READ that - otherwise it is a refusal they cannot clear,
    # which is the failure the artifact-side escape was added to avoid.
    if [ -z "$from" ]; then
        from="$(grep -E '^WAYFINDR_UPGRADE_FROM=' "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- || true)"
    fi
    ack="$(grep -E '^WAYFINDR_ACKNOWLEDGED_ACTIONS=' "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- || true)"

    # A declaration describes its own release, so an upgrade must read EVERY
    # release it passes through, not just the one it lands on. Skipping the
    # middle is the several-releases-behind case this exists to catch.
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
    local tags_body tags_status tags_curl_exit page page_count tag_count
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
        page="$(printf '%s' "$(cat "$tags_body")" | php_in_current_image "" "" "" '
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

        if [ "${page%%$'\n'*}" = "INVALID" ] || [ -z "$page" ]; then
            rm -f "$tags_body"
            printf '\n\033[1;31mPREFLIGHT COULD NOT VERIFY THIS UPGRADE\033[0m\n\n'
            printf '  The release list came back unreadable.\n'
            printf '  Refusing rather than treating an unparseable response as a short page.\n\n'
            printf '  Nothing has been pulled or changed. Retry when the network settles.\n\n'
            exit 78
        fi

        page_count="$(printf '%s' "$page" | sed -n 's/^COUNT://p' | head -1)"
        [ -n "$page_count" ] || page_count=0
        tag_count="$(printf '%s' "$page" | sed '1d' | grep -E '^v[0-9]' || true)"

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

    span="$(printf '%s\n' "$tags" | php_in_current_image "$from" "$to" "" '
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
        }')"

    [ -n "$span" ] || span="v$to"

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

        actions="$(printf '%s' "$manifest" | php_in_current_image "$from" "$to" "$ack" '
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

            // An origin that does not parse is not an origin. A typo would
            // otherwise compare as null against the floor, and "no definite
            // answer" does not refuse - so the typo would clear the check it was
            // supposed to be measured by. A DEVELOPMENT identity is kept: it
            // parses, it simply does not order, and the artifact treats a
            // recorded development version the same way.
            if ($from !== null && App\Support\Version\SemanticVersion::parse($from) === null) {
                $from = null;
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
                if ($from === null) {
                    // A floor in force and no way to tell where this install
                    // started. The artifact refuses this same case - so pulling
                    // anyway replaces a WORKING service with a container that
                    // cannot start, which is the outcome the preflight exists to
                    // prevent. Refuse here, while the old release is still up.
                    printf("FLOORUNKNOWN|%s\n", $floor);
                } else {
                    $rank = App\Support\Version\VersionComparator::compare($from, $floor);

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

                if (($applicability["type"] ?? "always") === "upgrade-from" && $from !== null) {
                    $min = $applicability["min"] ?? null;

                    if (is_string($min)) {
                        $rank = App\Support\Version\VersionComparator::compare($from, $min);

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
            }')"

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
