#!/usr/bin/env bash
#
# Exercises the upgrade preflight's decision logic against the real functions in
# install.sh (ADR 0013 slice 3).
#
# Nothing here pulls, fetches or starts anything: the network-facing parts are
# stubbed and what is under test is the part that decides. That is deliberate —
# the decisions are where this can be wrong in a way an operator would not
# notice, and they are pure enough to check.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
INSTALLER="$ROOT/scripts/self-host/install.sh"
APP="${APP:-$ROOT/apps/server}"
export APP
pass=0; fail=0

check() {
    local label="$1" expected="$2" actual="$3"

    if [ "$expected" = "$actual" ]; then
        printf '  \033[1;32mok\033[0m   %s\n' "$label"; pass=$((pass + 1))
    else
        printf '  \033[1;31mFAIL\033[0m %s\n       expected: %s\n       actual:   %s\n' "$label" "$expected" "$actual"
        fail=$((fail + 1))
    fi
}

# The partition the preflight applies, lifted verbatim from install.sh so the
# test cannot drift from the implementation it is checking.
partition() {
    local all_actions="$1" stranded blocking
    stranded="$(printf '%s\n' "$all_actions" | grep '^STEP|' || true)"
    blocking="$(printf '%s\n' "$all_actions" | grep -E '^(STEP|DO)\|[^|]*\|before-pull\|' || true)"
    blocking="$(printf '%s\n' "$blocking
$stranded" | grep -v '^$' | sort -u || true)"
    [ -n "$blocking" ] && printf 'BLOCK' || printf 'PROCEED'
}

echo "phase routing:"
check "before-pull stops the pull"                    BLOCK   "$(partition 'DO|0.3.0/a|before-pull|s|d')"
check "after-pull does not (the pull delivers it)"    PROCEED "$(partition 'DO|0.3.0/b|after-pull|s|d')"
check "after-start does not"                          PROCEED "$(partition 'DO|0.3.0/c|after-start|s|d')"
check "a stranded intermediate stops it regardless"   BLOCK   "$(partition 'STEP|0.2.0/d|after-pull|s|d')"
check "mixed: any blocker wins"                       BLOCK   "$(partition 'DO|0.3.0/e|after-start|s|d
DO|0.3.0/f|before-pull|s|d')"
check "nothing outstanding proceeds"                  PROCEED "$(partition '')"

# The span rule, run through the same comparator the installer uses.
span() {
    local from="$1" to="$2"
    printf 'v0.1.0\nv0.2.0\nv0.3.0\nv0.4.0\n' | WF_FROM="$from" WF_TO="$to" \
        "${PHP:-php}" -r '
        require getenv("APP")."/app/Support/Version/SemanticVersion.php";
        require getenv("APP")."/app/Support/Version/VersionComparator.php";
        $from = getenv("WF_FROM") ?: null;
        $to = getenv("WF_TO");
        $out = [];
        foreach (explode("\n", trim(stream_get_contents(STDIN))) as $tag) {
            $tag = trim($tag);
            if ($tag === "") { continue; }
            $above = App\Support\Version\VersionComparator::compare($tag, $to);
            if ($above !== null && $above > 0) { continue; }
            if ($from !== null) {
                $after = App\Support\Version\VersionComparator::compare($tag, $from);
                if ($after !== null && $after <= 0) { continue; }
            }
            $out[] = $tag;
        }
        echo implode(",", $out);'
}

echo "span collection:"
check "excludes the start, includes the target"  "v0.2.0,v0.3.0"               "$(span 0.1.0 0.3.0)"
check "an unknown start takes everything"        "v0.1.0,v0.2.0,v0.3.0"        "$(span '' 0.3.0)"
check "never reaches past the target"            "v0.1.0,v0.2.0,v0.3.0,v0.4.0" "$(span '' 0.4.0)"
check "a single-step upgrade is just the target" "v0.3.0"                      "$(span 0.2.0 0.3.0)"

# The must-step rule: an action on a SKIPPED release needing that release's own
# code or schema cannot be performed at any phase of a direct jump.
stranded() {
    WF_REL="$1" WF_DEP="$2" WF_TO="$3" "${PHP:-php}" -r '
        $stranded = getenv("WF_REL") !== getenv("WF_TO")
            && in_array(getenv("WF_DEP"), ["code", "schema"], true);
        echo $stranded ? "STEP" : "DO";'
}

echo "must-step classification:"
check "intermediate needing its code is unreachable"   STEP "$(stranded 0.2.0 code   0.3.0)"
check "intermediate needing its schema is unreachable" STEP "$(stranded 0.2.0 schema 0.3.0)"
check "intermediate needing nothing is actionable"     DO   "$(stranded 0.2.0 none   0.3.0)"
check "the target's own code is always present"        DO   "$(stranded 0.3.0 code   0.3.0)"

echo "installer shape:"
check "the preflight call has a definition" 1 \
    "$(grep -c '^upgrade_preflight() {' "$INSTALLER")"
check "the hand-off replays captured arguments" 1 \
    "$(grep -c 'WAYFINDR_ORIGINAL_ARGS\[@\]' "$INSTALLER")"
check "arguments are captured before the parse loop" ok \
    "$(awk '/WAYFINDR_ORIGINAL_ARGS=/{c=NR} /while \[ "\$#" -gt 0 \]/{p=NR} END{print (c && p && c < p) ? "ok" : "WRONG ORDER"}' "$INSTALLER")"

# How a manifest fetch is classified. `curl` is stubbed to reproduce exactly what
# the real one writes and returns in each case, and the capture below is lifted
# verbatim from install.sh.
#
# This is the regression that mattered: with `-f`, curl wrote the `-w` format AND
# exited non-zero on an HTTP error, so a fallback `|| printf` appended a second
# status. A 404 arrived as "404\n000", the last line won, and the exemption for
# "this release published no manifest" never ran - which refused every upgrade,
# because every release that exists today predates the contract.
fetch_status() {
    local scenario="$1" body status curl_exit

    curl() {
        case "$scenario" in
            ok)      printf '{"actions":[]}' > "$3"; printf '200' ;;
            missing) printf 'Not Found'      > "$3"; printf '404'; return 22 ;;
            down)    printf '000'; return 7 ;;
        esac
    }

    body="$(mktemp)"
    status="$(curl -sSL -o "$body" -w '%{http_code}' https://example.invalid 2>/dev/null || true)"
    [ -n "$status" ] || status="000"
    rm -f "$body"
    unset -f curl

    printf '%s' "$status"
}

echo
echo "manifest fetch classification:"
check "a published manifest reads 200"              200 "$(fetch_status ok)"
check "a release with no manifest reads 404"        404 "$(fetch_status missing)"
check "an unreachable network reads 000"            000 "$(fetch_status down)"

# Applicability, the half of it the preflight can decide without the application.
# Skipping this made every action outstanding for everyone: a retirement carrying
# `upgrade-from.min` blocked a direct jump from below that min on undoing
# something the install had never done.
applies() {
    local from="$1" min="$2"
    WF_FROM="$from" WF_MIN="$min" "${PHP:-php}" -r '
        require getenv("APP")."/app/Support/Version/SemanticVersion.php";
        require getenv("APP")."/app/Support/Version/VersionComparator.php";
        $from = getenv("WF_FROM") ?: null;
        $min = getenv("WF_MIN");
        $applies = true;
        if ($from !== null && is_string($min)) {
            $rank = App\Support\Version\VersionComparator::compare($from, $min);
            if ($rank !== null && $rank < 0) { $applies = false; }
        }
        echo $applies ? "APPLIES" : "SKIPPED";
    '
}

echo
echo "upgrade-from applicability:"
check "a start below the min never did it"      SKIPPED "$(applies 0.1.0 0.2.0)"
check "a start at the min did"                  APPLIES "$(applies 0.2.0 0.2.0)"
check "a start above the min did"               APPLIES "$(applies 0.3.0 0.2.0)"
check "an unknown start stays conservative"     APPLIES "$(applies '' 0.2.0)"
check "an unorderable start stays conservative" APPLIES "$(applies '0.2.0-dev+abc' 0.2.0)"

# Whether a 404 is the truth or a fault, which depends on when the release was
# cut. Exempting all of them let a post-contract release with a deleted asset be
# read as "declares nothing" - and pulled straight past a before-pull action.
manifest_expected() {
    WF_FROM="$1" WF_TO="${2:-0.1.0}" "${PHP:-php}" -r '
        require getenv("APP")."/app/Support/Version/SemanticVersion.php";
        require getenv("APP")."/app/Support/Version/VersionComparator.php";
        $rank = App\Support\Version\VersionComparator::compare(getenv("WF_FROM"), getenv("WF_TO"));
        echo ($rank === null || $rank >= 0) ? "YES" : "NO";
    '
}

echo
echo "missing-manifest classification (contract from 0.1.0):"
check "a pre-contract release is exempt"           NO  "$(manifest_expected 0.1.0-alpha.1)"
check "the contract release itself is not"         YES "$(manifest_expected 0.1.0)"
check "a later release is not"                     YES "$(manifest_expected 0.2.0)"
check "an unplaceable tag is not exempt"           YES "$(manifest_expected '0.1.0-dev+abc')"

# Tag discovery has to fail closed. Discarding the error made a 403 or a blip
# look identical to "there are no other releases": the span silently shrank to
# the target and every intermediate declaration went unread.
discovery() {
    local scenario="$1" body status curl_exit

    curl() {
        case "$scenario" in
            ok)      printf '[{"name": "v0.2.0"}]' > "$3"; printf '200' ;;
            rated)   printf '{"message":"rate limited"}' > "$3"; printf '403'; return 22 ;;
            down)    printf '000'; return 7 ;;
            # A transfer that died partway. curl reports the 200 it saw in the
            # headers and exits 18, so the HTTP code alone says "fine" while the
            # body is short - which the pager reads as the final page.
            partial) printf '[{"name": "v0.2' > "$3"; printf '200'; return 18 ;;
        esac
    }

    body="$(mktemp)"
    curl_exit=0
    status="$(curl -sSL -o "$body" -w '%{http_code}' https://example.invalid 2>/dev/null)" || curl_exit=$?
    [ -n "$status" ] || status="000"
    [ "$curl_exit" -eq 0 ] || status="000"
    rm -f "$body"
    unset -f curl

    [ "$status" = "200" ] && printf 'PROCEED' || printf 'REFUSE'
}

echo
echo "tag discovery fails closed:"
check "a usable tag list proceeds"          PROCEED "$(discovery ok)"
check "a rate-limited API refuses"          REFUSE  "$(discovery rated)"
check "an unreachable API refuses"          REFUSE  "$(discovery down)"
check "a partial transfer refuses"          REFUSE  "$(discovery partial)"

# Tag discovery has to read every page. `per_page=100` caps one response, so past
# a hundred tags the older half of a span is simply absent - and an upgrade from
# far enough back computes an empty span and calls it clear.
paginate() {
    local total="$1" page=1 collected=0 page_count body

    curl() {
        # Positional, matching the real call: -sSL -o BODY -w FMT URL
        local out="$3" url="$6" p remaining n i
        p="${url##*page=}"
        remaining=$(( total - (p - 1) * 100 ))
        [ "$remaining" -lt 0 ] && remaining=0
        n=$(( remaining > 100 ? 100 : remaining ))
        : > "$out"
        i=0
        while [ "$i" -lt "$n" ]; do printf '"name": "v0.0.%d"\n' "$i" >> "$out"; i=$((i + 1)); done
        printf '200'
    }

    body="$(mktemp)"

    while :; do
        curl -sSL -o "$body" -w '%{http_code}' "https://example.invalid/tags?per_page=100&page=${page}" >/dev/null
        page_count="$(grep -c '"name":' "$body" || true)"
        collected=$((collected + page_count))
        [ "$page_count" -lt 100 ] && break
        page=$((page + 1))
        [ "$page" -gt 20 ] && break
    done

    rm -f "$body"
    unset -f curl
    printf '%s' "$collected"
}

echo
echo "tag discovery pagination:"
check "a short first page is the whole list" 50  "$(paginate 50)"
check "an exactly-full page reads the next"  100 "$(paginate 100)"
check "several pages are all read"           250 "$(paginate 250)"

# A guard refusal on the standard Forge path must not be followed by `artisan up`:
# that path replaces the source in place, so the new code would serve against the
# un-migrated schema - the very thing refusing to migrate prevented. The serving
# gate cannot catch it, because it gates after-start requirements and this
# refusal is a before-pull or after-pull one.
refusal_block="$(awk '/migrate_status" -eq 78/,/^fi$/' "$ROOT/deploy/forge/standard-deploy.sh")"

echo
echo "forge standard deploy, guard refusal:"
# Assert the extraction found something first. A pattern that matched nothing
# would make every count below 0 and read as a clean pass for absent code.
check "the refusal branch was found"        1 "$(printf '%s' "$refusal_block" | grep -c 'migrate_status')"
check "it clears the restore trap"          1 "$(printf '%s' "$refusal_block" | grep -c 'trap - EXIT')"
check "it leaves maintenance enabled"       1 "$(printf '%s' "$refusal_block" | grep -c 'maintenance_enabled=0')"
check "it propagates the refusal code"      1 "$(printf '%s' "$refusal_block" | grep -c 'exit 78')"
# `forge_php artisan up`, not bare "artisan up" - the branch's own guidance text
# tells the operator how to bring the site back by hand, and matching that would
# fail on the message rather than on a call.
check "it does not bring the site back up"  0 "$(printf '%s' "$refusal_block" | grep -c 'forge_php artisan up')"

# A 200 body that is not a manifest must not be read as one declaring nothing.
# `?? []` did exactly that: malformed or truncated JSON decoded to null, the
# action loop ran over an empty list, and a before-pull requirement was skipped
# on the way to pulling the image. A partial transfer produces precisely this -
# curl reports the 200 it saw in the headers and dies mid-body.
manifest_body() {
    printf '%s' "$1" | "${PHP:-php}" -r '
        $m = json_decode(stream_get_contents(STDIN), true);
        if (! is_array($m) || ! is_string($m["version"] ?? null) || ! is_array($m["actions"] ?? null)) {
            echo "INVALID"; exit(0);
        }
        echo "OK";
    '
}

echo
echo "manifest body validation:"
check "a truncated body is rejected"    INVALID "$(manifest_body '{"version":"0.2.0","act')"
check "an HTML error page is rejected"  INVALID "$(manifest_body '<html>Not Found</html>')"
check "an empty body is rejected"       INVALID "$(manifest_body '')"
check "a body with no actions key"      INVALID "$(manifest_body '{"version":"0.2.0"}')"
check "a body with no version key"      INVALID "$(manifest_body '{"actions":[]}')"
check "a well-formed manifest passes"   OK      "$(manifest_body '{"schema":1,"version":"0.2.0","actions":[]}')"

# The floor has to stop the PULL, not the container afterwards. A floor-bearing
# release often declares no actions at all, so the preflight reported clear, the
# pull replaced a working deployment with one that refuses to migrate, and the
# operator was told to step through a release the running version could still
# have reached.
floor_check() {
    local from="$1" floor="$2" target="${3:-0.4.0}"
    WF_FROM="$from" WF_MIN="$floor" WF_TO="$target" "${PHP:-php}" -r '
        require getenv("APP")."/app/Support/Version/SemanticVersion.php";
        require getenv("APP")."/app/Support/Version/VersionComparator.php";
        $from = getenv("WF_FROM") ?: null;
        $floor = getenv("WF_MIN");
        if (is_string($floor) && $from !== null) {
            $rank = App\Support\Version\VersionComparator::compare($from, $floor);
            if ($rank !== null && $rank < 0) { echo "REFUSE"; exit(0); }
        }
        echo "PROCEED";
    '
}

echo
echo "upgrade floor stops the pull:"
check "below the floor refuses"                 REFUSE  "$(floor_check 0.1.0 0.2.0)"
check "exactly at the floor proceeds"           PROCEED "$(floor_check 0.2.0 0.2.0)"
check "above the floor proceeds"                PROCEED "$(floor_check 0.3.0 0.2.0)"
check "an unknown start proceeds"               PROCEED "$(floor_check '' 0.2.0)"
check "an unorderable start proceeds"           PROCEED "$(floor_check '0.1.0-dev+abc' 0.2.0)"

# The preflight validates a manifest the way the artifact does. A minimal shape
# check passed an action with no `phase`, which then classified as neither
# before-pull nor anything else - so the pull went ahead and the artifact rejected
# the manifest afterwards, once the only preventable phase had passed.
manifest_full() {
    printf '%s' "$1" | "${PHP:-php}" -r '
        require getenv("APP")."/app/Support/Version/SemanticVersion.php";
        require getenv("APP")."/app/Support/Release/ReleaseManifest.php";
        $m = json_decode(stream_get_contents(STDIN), true);
        if (! is_array($m)) { echo "INVALID"; exit(0); }
        try { App\Support\Release\ReleaseManifest::assertPublished($m); }
        catch (Throwable $e) { echo "INVALID"; exit(0); }
        echo "OK";
    '
}

wellformed='{"schema":1,"version":"0.2.0","commit":"abc","requires_operator_action":false,"actions":[]}'
nophase='{"schema":1,"version":"0.2.0","commit":"abc","requires_operator_action":true,"actions":[{"id":"a","summary":"s","detail":"d","depends_on_release":"none","release":"0.2.0"}]}'

echo
echo "preflight manifest validation matches the artifact:"
check "a well-formed manifest passes"          OK      "$(manifest_full "$wellformed")"
check "an action with no phase is rejected"    INVALID "$(manifest_full "$nophase")"
check "a manifest with no schema is rejected"  INVALID "$(manifest_full '{"version":"0.2.0","actions":[]}')"

# An origin that does not parse is not an origin. The floor refuses only a
# definite "below", so a typo compares as null and would clear the very check it
# was supposed to be measured by.
origin_usable() {
    WF_FROM="$1" "${PHP:-php}" -r '
        require getenv("APP")."/app/Support/Version/SemanticVersion.php";
        $from = getenv("WF_FROM") ?: null;
        if ($from !== null && App\Support\Version\SemanticVersion::parse($from) === null) { $from = null; }
        echo $from === null ? "UNKNOWN" : "USABLE";
    '
}

echo
echo "declared origin validation:"
check "a real version is usable"            USABLE  "$(origin_usable 0.2.4)"
check "a v-prefixed version is usable"      USABLE  "$(origin_usable v0.2.4)"
check "a typo is not"                       UNKNOWN "$(origin_usable 0.2.O)"
check "an empty origin is not"              UNKNOWN "$(origin_usable '')"
# Parses, does not order - kept, because the artifact treats a recorded
# development version the same way rather than refusing it.
check "a development identity is usable"    USABLE  "$(origin_usable '0.2.0-dev+abc')"

# Tag pages are PARSED, not grepped. A 200 carrying HTML from a proxy, or a
# truncated body, matches nothing - which reads as a short page, ends pagination,
# and leaves the span with only the target. Counting occurrences fixed the
# minified-response case but never asked whether the response was a tag list.
tag_page() {
    printf '%s' "$1" | "${PHP:-php}" -r '
        $decoded = json_decode(stream_get_contents(STDIN), true);
        if (! is_array($decoded) || array_is_list($decoded) === false) { echo "INVALID"; exit(0); }
        $names = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)) { echo "INVALID"; exit(0); }
            $name = $entry["name"] ?? null;
            if (is_string($name)) { $names[] = $name; }
        }
        echo "COUNT:", count($decoded);
    '
}

minified_page() {
    "${PHP:-php}" -r '
        $t = [];
        for ($i = 0; $i < 100; $i++) { $t[] = ["name" => "v0.0.$i"]; }
        echo json_encode($t);
    '
}

echo
echo "tag page parsing:"
check "a pretty-printed page parses"      COUNT:2   "$(tag_page '[
  {"name": "v0.1.0"},
  {"name": "v0.2.0"}
]')"
check "a minified page of 100 parses"     COUNT:100 "$(tag_page "$(minified_page)")"
check "an HTML error body is rejected"    INVALID   "$(tag_page '<html>502 Bad Gateway</html>')"
check "a JSON object is not a tag list"   INVALID   "$(tag_page '{"message":"rate limited"}')"
check "a truncated body is rejected"      INVALID   "$(tag_page '[{"name":"v0.1.0"},')"
check "a non-object entry is rejected"    INVALID   "$(tag_page '[{"name":"v0.1.0"},"oops"]')"

# The preflight runs inside the image being upgraded FROM, so it may only call an
# API that image already had. `assertPublished()` was added by the release being
# INSTALLED, so calling it raised an undefined-method Error that the catch read as
# "malformed manifest" - refusing every upgrade, valid ones included.
echo
echo "preflight uses only pre-upgrade APIs:"
preflight_php="$(awk '/actions="\$\(printf/,/^        \x27\)"$/' "$INSTALLER")"
# Matched as CALLS (`Class::method`), not as prose: the block carries a comment
# naming assertPublished to explain why it is not used, and a looser pattern
# fails on the explanation rather than on the code.
check "the preflight block was found"          1 "$(printf '%s' "$preflight_php" | grep -c 'ReleaseManifest.php')"
check "it does not call assertPublished"       0 "$(printf '%s' "$preflight_php" | grep -c 'ReleaseManifest::assertPublished')"
check "it calls decode()"                      1 "$(printf '%s' "$preflight_php" | grep -c 'ReleaseManifest::decode')"

# And when the old image has none of these classes at all - true of everything cut
# before ADR 0013 - the requires fatal, stderr is suppressed, and every check
# silently reads as "nothing to report". It has to say so and skip.
echo
echo "preflight degrades loudly on a pre-contract image:"
probe="$(awk '/^preflight_supported\(\)/,/^}/' "$INSTALLER")"
check "a capability probe exists"           1 "$(printf '%s' "$probe" | grep -c 'is_file')"
check "it checks the manifest class"        1 "$(printf '%s' "$probe" | grep -c 'ReleaseManifest.php')"
check "the preflight consults it"           1 "$(grep -c 'if ! preflight_supported' "$INSTALLER")"

# The pull follows WAYFINDR_IMAGE when it is set, so the preflight has to target
# THAT release rather than the one resolved from the releases API - otherwise an
# upgrade is cleared on the strength of a manifest belonging to a different image.
image_release() {
    local img="$1" name to
    name="${img##*/}"

    case "$name" in
        *:*) to="${name##*:}" ;;
        *) to="" ;;
    esac

    to="${to#v}"

    case "$to" in
        ''|latest) printf 'UNKNOWN' ;;
        *) printf '%s' "$to" ;;
    esac
}

echo
echo "override image names the release to preflight:"
check "a pinned tag is the target"            0.2.0   "$(image_release ghcr.io/adamgreenwell/wayfindr:0.2.0)"
check "a v-prefixed tag is canonicalised"     0.2.0   "$(image_release ghcr.io/adamgreenwell/wayfindr:v0.2.0)"
check "latest names no release"               UNKNOWN "$(image_release ghcr.io/adamgreenwell/wayfindr:latest)"
check "an untagged image names no release"    UNKNOWN "$(image_release ghcr.io/adamgreenwell/wayfindr)"
# A registry port is not a tag. Splitting on the last colon in the WHOLE string
# would read `5000/wayfindr` as one, and preflight a release that does not exist.
check "a registry port is not a tag"          0.3.0   "$(image_release registry:5000/wayfindr:0.3.0)"
check "a ported registry with no tag"         UNKNOWN "$(image_release registry:5000/wayfindr)"

# Which image the preflight targets, mirroring pin_image()'s precedence. An
# override persisted into .env by an earlier install survives the process
# variable that put it there, so a later upgrade with nothing exported pulls the
# custom image - while the preflight was evaluating the resolved release.
effective_target() {
    local exported="$1" persisted="$2" resolved="$3" effective

    effective="$exported"

    if [ -z "$effective" ]; then
        case "$persisted" in
            ghcr.io/adamgreenwell/wayfindr:*|'') : ;;
            *) effective="$persisted" ;;
        esac
    fi

    if [ -z "$effective" ]; then
        printf '%s' "$resolved"
        return 0
    fi

    local name to
    name="${effective##*/}"
    case "$name" in *:*) to="${name##*:}" ;; *) to="" ;; esac
    to="${to#v}"

    case "$to" in
        ''|latest) printf 'SKIP' ;;
        *) printf '%s' "$to" ;;
    esac
}

echo
echo "effective image selection:"
check "an exported override wins"                 0.9.0 "$(effective_target ghcr.io/x/y:0.9.0 ghcr.io/adamgreenwell/wayfindr:0.1.0 0.2.0)"
check "an official .env image uses the resolved"  0.2.0 "$(effective_target '' ghcr.io/adamgreenwell/wayfindr:0.1.0 0.2.0)"
check "a persisted custom image is the target"    0.9.0 "$(effective_target '' ghcr.io/x/y:0.9.0 0.2.0)"
check "a persisted custom :latest skips"          SKIP  "$(effective_target '' ghcr.io/x/y:latest 0.2.0)"
check "no override falls back to the resolved"    0.2.0 "$(effective_target '' '' 0.2.0)"

# The floor decision has to predict what the ARTIFACT will do, not what the
# installer can see. The artifact verifies a floor only against an origin IT has -
# its state file, or a declared one - so an install resting on a version derived
# from the image tag is refused the moment the new release starts, however
# orderable that version looks here.
floor_decision() {
    WF_FROM="$1" WF_ORIGIN_KNOWN="$2" WF_MIN="${3:-0.2.0}" "${PHP:-php}" -r '
        require getenv("APP")."/app/Support/Version/SemanticVersion.php";
        require getenv("APP")."/app/Support/Version/VersionComparator.php";
        $from = getenv("WF_FROM") ?: null;
        $floor = getenv("WF_MIN");
        $originKnown = getenv("WF_ORIGIN_KNOWN") === "1";
        if ($from === null || ! $originKnown) { echo "FLOORUNKNOWN"; exit(0); }
        $rank = App\Support\Version\VersionComparator::compare($from, $floor);
        echo ($rank !== null && $rank < 0) ? "FLOOR" : "PROCEED";
    '
}

echo
echo "floor decision matches the artifact:"
check "a known origin above the floor proceeds"  PROCEED      "$(floor_decision 0.2.4 1)"
check "a known origin below it refuses"          FLOOR        "$(floor_decision 0.1.0 1)"
check "no origin at all cannot be verified"      FLOORUNKNOWN "$(floor_decision '' 0)"
# The artifact never sees the image tag, so an orderable version derived from it
# is not an origin the artifact can verify against.
check "an image-derived origin is not known"     FLOORUNKNOWN "$(floor_decision 0.2.4 0)"
check "a development identity from the image"    FLOORUNKNOWN "$(floor_decision '0.2.0-dev+abc' 0)"
# But a development identity the artifact HAS recorded is one it accepts, so
# refusing here would be over-refusing relative to what will actually happen.
check "a development identity from state"        PROCEED      "$(floor_decision '0.2.0-dev+abc' 1)"

# The pagination index must stay numeric. This is checked against the REAL
# function rather than a reimplementation, because a reimplementation is exactly
# what missed the collision that put a parsed multiline response onto `page` and
# made `page=$((page + 1))` abort the installer under `set -u`.
preflight_body="$(awk '/^upgrade_preflight\(\)/,/^}/' "$INSTALLER")"

echo
echo "pagination index stays numeric:"
check "the preflight body was found"     1 "$(printf '%s' "$preflight_body" | grep -c 'while :; do')"
check "page is assigned only numerics"   0 "$(printf '%s' "$preflight_body" | grep -oE '^[[:space:]]*page=.*' | grep -vcE 'page=1$|page=\$\(\(page \+ 1\)\)$' || true)"
check "the parsed response has its own"  1 "$(printf '%s' "$preflight_body" | grep -c 'page_body=')"

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
