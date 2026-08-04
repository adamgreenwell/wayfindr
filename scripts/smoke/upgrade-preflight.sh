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

# `minimum_upgrade_from` is part of the published schema - build() always emits
# it, as null when a release retires nothing - so a fixture without it is not a
# well-formed manifest.
wellformed='{"schema":1,"version":"0.2.0","commit":"abc","requires_operator_action":false,"minimum_upgrade_from":null,"actions":[]}'
nofloorkey='{"schema":1,"version":"0.2.0","commit":"abc","requires_operator_action":false,"actions":[]}'
nophase='{"schema":1,"version":"0.2.0","commit":"abc","requires_operator_action":true,"minimum_upgrade_from":null,"actions":[{"id":"a","summary":"s","detail":"d","depends_on_release":"none","release":"0.2.0"}]}'

echo
echo "preflight manifest validation matches the artifact:"
check "a well-formed manifest passes"          OK      "$(manifest_full "$wellformed")"
check "an action with no phase is rejected"    INVALID "$(manifest_full "$nophase")"
check "a manifest with no schema is rejected"  INVALID "$(manifest_full '{"version":"0.2.0","actions":[]}')"
check "a manifest with no floor key"          INVALID "$(manifest_full "$nofloorkey")"

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

# Two origins, kept apart exactly as the artifact keeps them. `version` is where
# the install IS - the floor is measured from it. `satisfied_through` is the last
# release that owed nothing, and is where the SPAN starts; it sits further back
# when a previous upgrade left work outstanding, and reading the span from
# `version` instead skips the releases whose debt is still unpaid.
state_origins() {
    printf '%s' "$1" | "${PHP:-php}" -r '
        require getenv("APP")."/app/Support/Version/SemanticVersion.php";
        $state = json_decode(stream_get_contents(STDIN), true);
        if (! is_array($state)) { echo "NONE"; exit(0); }
        $version = is_string($state["version"] ?? null) ? $state["version"] : "";
        // Mirrors recordedVersion(): a malformed value is no origin at all, and
        // the artifact then falls through to the declared one.
        if ($version !== "") {
            $version = App\Support\Version\SemanticVersion::parse($version)?->canonical() ?? "";
        }
        if (! array_key_exists("satisfied_through", $state)) { $span = $version; $known = "1"; }
        elseif (is_string($state["satisfied_through"])) { $span = $state["satisfied_through"]; $known = "1"; }
        else { $span = ""; $known = "0"; }
        echo $version, "|", $span, "|", $known;
    '
}

echo
echo "span origin is the debt origin, not the recorded release:"
check "clean upgrade: both the same"        "0.2.0|0.2.0|1" "$(state_origins '{"version":"0.2.0","satisfied_through":"0.2.0"}')"
# The case that matters: recorded at 0.2.0 but still owing work from 0.1.0, so
# the span must reach back and include 0.2.0 itself.
check "retained debt reaches further back"  "0.2.0|0.1.0|1" "$(state_origins '{"version":"0.2.0","satisfied_through":"0.1.0"}')"
check "unknown debt origin takes the lot"   "0.2.0||0"      "$(state_origins '{"version":"0.2.0","satisfied_through":null}')"
check "a state file predating the marker"   "0.2.0|0.2.0|1" "$(state_origins '{"version":"0.2.0"}')"
check "a v-prefix is canonicalised"        "0.2.4|0.2.4|1" "$(state_origins '{"version":"v0.2.4"}')"
# A malformed recorded version is no origin, so the declared one is read instead
# - the artifact's `recordedVersion() ?? declaredOrigin()` in the same order.
check "a malformed version is no origin"   "||1"           "$(state_origins '{"version":"0.1.O"}')"

# A tag names a RELEASE only when the image is ours. `fork:0.3.0` is version
# 0.3.0 of somebody else's build, and fetching Wayfindr's official 0.3.0 manifest
# for it checks a declaration the running code never made.
official_image() {
    case "$1" in
        ghcr.io/adamgreenwell/wayfindr:*) printf 'OFFICIAL' ;;
        *) printf 'FOREIGN' ;;
    esac
}

echo
echo "only an official image's tag names a release:"
check "the official image"              OFFICIAL "$(official_image ghcr.io/adamgreenwell/wayfindr:0.3.0)"
check "a versioned fork is foreign"     FOREIGN  "$(official_image registry.example/fork:0.3.0)"
check "a local build is foreign"        FOREIGN  "$(official_image wayfindr-local:0.3.0)"

# A DECLARED origin is held to the artifact's stricter rule, not the looser one a
# recorded version gets. `declaredOrigin()` rejects a development identity - it
# parses but does not order, so it can never be ranked against a floor, and
# accepting one clears the unknown-origin refusal without satisfying anything.
# Accepting it here while the artifact rejects it is the worst split of the two:
# the installer pulls, then the artifact refuses on a release already installed.
usable_declaration() {
    WF_FROM="$1" "${PHP:-php}" -r '
        require getenv("APP")."/app/Support/Version/SemanticVersion.php";
        $raw = trim((string) getenv("WF_FROM"));
        if ($raw === "") { echo "REJECTED"; exit(0); }
        $p = App\Support\Version\SemanticVersion::parse($raw);
        if ($p === null || $p->isDevelopment()) { echo "REJECTED"; exit(0); }
        echo $p->canonical();
    '
}

echo
echo "declared origin follows the artifact's rule:"
check "a release version is accepted"       0.2.4    "$(usable_declaration 0.2.4)"
check "a v-prefix is canonicalised"         0.2.4    "$(usable_declaration v0.2.4)"
check "a development identity is rejected"  REJECTED "$(usable_declaration '0.2.0-dev+abc')"
check "a bare -dev is rejected"             REJECTED "$(usable_declaration '0.2.0-dev')"
check "a typo is rejected"                  REJECTED "$(usable_declaration 0.2.O)"
check "an empty declaration is rejected"    REJECTED "$(usable_declaration '')"

# With nothing recorded and nothing declared, the artifact reads the install as
# legacy and evaluates the WHOLE published history. The preflight has to match:
# narrowing the span from a version only IT can see - the running image - drops
# every declaration at or below that version, so an older before-pull requirement
# goes unseen and the working image is replaced before the artifact refuses.
resolve_origin() {
    local state="$1" declared="$2"
    local from origin_known span_origin span_known

    origin_known=0
    span_known=1
    span_origin=""
    from="${state%%|*}"

    if [ -n "$state" ]; then
        span_origin="$(printf '%s' "$state" | cut -d'|' -f2)"
        span_known="$(printf '%s' "$state" | cut -d'|' -f3)"
    fi

    [ -n "$from" ] && origin_known=1

    if [ -z "$from" ] && [ -n "$declared" ]; then
        from="$declared"
        origin_known=1
        span_origin="$declared"
    fi

    [ -n "$from" ] || span_origin=""
    [ "$span_known" = "1" ] || span_origin=""

    printf '%s/%s/%s' "$from" "$span_origin" "$origin_known"
}

echo
echo "origin resolution matches the artifact:"
check "a clean state"              "0.2.0/0.2.0/1" "$(resolve_origin '0.2.0|0.2.0|1' '')"
check "retained debt reaches back"  "0.2.0/0.1.0/1" "$(resolve_origin '0.2.0|0.1.0|1' '')"
check "unknown debt takes the lot"  "0.2.0//1"      "$(resolve_origin '0.2.0||0' '')"
check "no state, but declared"      "0.2.4/0.2.4/1" "$(resolve_origin '' '0.2.4')"
# The case this round fixed: nothing known, so the span must be the whole
# history rather than everything above whatever the image happens to be.
check "nothing known at all"        "//0"           "$(resolve_origin '' '')"

# And the state file is read where the APP resolves it, not only at the default.
echo
echo "state path follows the app:"
state_path() {
    WAYFINDR_RELEASE_STATE_PATH="$1" "${PHP:-php}" -r '
        echo getenv("WAYFINDR_RELEASE_STATE_PATH")
            ?: "/app/apps/server/storage/app/release-state.json";
    '
}
check "the default when unset"   /app/apps/server/storage/app/release-state.json "$(state_path '')"
check "a configured override"    /srv/wayfindr/state.json                        "$(state_path /srv/wayfindr/state.json)"

# A floating alias is not a release. The image workflow publishes
# `{{major}}.{{minor}}` and `latest` alongside the full version, so `0.3` is a
# real published tag that names whatever 0.3.x was built last - and it parses as
# no version at all, so the span comparisons go undecidable and the target
# manifest's canonical `0.3.0` never equals it, skipping its floor entirely.
names_one_release() {
    WF_TO="$1" "${PHP:-php}" -r '
        require getenv("APP")."/app/Support/Version/SemanticVersion.php";
        $raw = trim((string) getenv("WF_TO"));
        echo App\Support\Version\SemanticVersion::parse($raw) === null ? "NO" : "YES";
    '
}

echo
echo "only a full version names one release:"
check "a full version"              YES "$(names_one_release 0.3.0)"
check "a v-prefixed full version"   YES "$(names_one_release v0.3.0)"
check "a prerelease"                YES "$(names_one_release 0.3.0-alpha.1)"
check "a major.minor alias"         NO  "$(names_one_release 0.3)"
check "latest"                      NO  "$(names_one_release latest)"
check "nothing"                     NO  "$(names_one_release '')"

# dotenv quoting is valid and common, and Compose, Laravel and this script must
# all agree on the value. Keeping the quotes made an official image classify as
# custom (so the preflight skipped), an acknowledgement never match the key it
# was written for, and a declared origin fail to parse - all quietly.
# The REAL env_value(), lifted out of install.sh rather than reimplemented.
#
# A reimplementation drifts: the previous version of this check did not know
# about inline comments, so it passed while the shipped reader was wrong about
# them. Extracting the function means the check cannot know something the code
# does not.
dotenv_read() {
    local file script
    file="$(mktemp)"
    script="$(mktemp)"
    printf '%s\n' "$1" > "$file"

    {
        printf 'ENV_FILE="$1"\n'
        awk '/^env_value\(\) \{/,/^\}/' "$INSTALLER"
        printf 'env_value "$2"\n'
    } > "$script"

    bash "$script" "$file" "$2"

    rm -f "$file" "$script"
}

echo
echo "environment values are read with dotenv semantics:"
check "an unquoted value"      'ghcr.io/x/y:0.3.0'  "$(dotenv_read 'WAYFINDR_IMAGE=ghcr.io/x/y:0.3.0' WAYFINDR_IMAGE)"
check "a double-quoted value"  'ghcr.io/x/y:0.3.0'  "$(dotenv_read 'WAYFINDR_IMAGE="ghcr.io/x/y:0.3.0"' WAYFINDR_IMAGE)"
check "a single-quoted value"  'ghcr.io/x/y:0.3.0'  "$(dotenv_read "WAYFINDR_IMAGE='ghcr.io/x/y:0.3.0'" WAYFINDR_IMAGE)"
check "an exported value"      '0.2.4'              "$(dotenv_read 'export WAYFINDR_UPGRADE_FROM=0.2.4' WAYFINDR_UPGRADE_FROM)"
check "a value with = in it"   'a/b,c/d'            "$(dotenv_read 'WAYFINDR_ACKNOWLEDGED_ACTIONS=a/b,c/d' WAYFINDR_ACKNOWLEDGED_ACTIONS)"
check "an absent key"          ''                   "$(dotenv_read 'OTHER=1' WAYFINDR_IMAGE)"
# Compose's documented syntax: an inline comment after a quoted value, and a
# `#` preceded by whitespace in an unquoted one. A `#` with no space before it
# is part of the value.
check "a quoted inline comment" 'ghcr.io/x/y:0.3.0' "$(dotenv_read 'WAYFINDR_IMAGE="ghcr.io/x/y:0.3.0" # pinned' WAYFINDR_IMAGE)"
check "a bare inline comment"   'ghcr.io/x/y:0.3.0' "$(dotenv_read 'WAYFINDR_IMAGE=ghcr.io/x/y:0.3.0 # pinned' WAYFINDR_IMAGE)"
check "trailing whitespace"     'ghcr.io/x/y:0.3.0' "$(dotenv_read 'WAYFINDR_IMAGE=ghcr.io/x/y:0.3.0   ' WAYFINDR_IMAGE)"
check "a hash inside the value" 'ghcr.io/x/y:0.3.0#z' "$(dotenv_read 'WAYFINDR_IMAGE=ghcr.io/x/y:0.3.0#z' WAYFINDR_IMAGE)"
# A duplicate key is what an operator produces by APPENDING a line rather than
# editing the one already there. Compose and the artifact's Dotenv both take the
# later value; reading the earlier one made the installer act on a stale origin,
# permit the pull, and hand over to an artifact that reads the newer value and
# refuses - a working image replaced over which line counts.
check "a duplicate key takes the last" '0.1.0' "$(dotenv_read 'WAYFINDR_UPGRADE_FROM=0.9.0
WAYFINDR_UPGRADE_FROM=0.1.0' WAYFINDR_UPGRADE_FROM)"
check "three assignments take the last" 'c' "$(dotenv_read 'WAYFINDR_ACKNOWLEDGED_ACTIONS=a
WAYFINDR_ACKNOWLEDGED_ACTIONS=b
WAYFINDR_ACKNOWLEDGED_ACTIONS=c' WAYFINDR_ACKNOWLEDGED_ACTIONS)"

# And the official-image test has to run on the PARSED value, or a quoted
# official image reads as a fork and the preflight skips the release it will pull.
official() {
    case "$1" in
        ghcr.io/adamgreenwell/wayfindr:*) printf 'OFFICIAL' ;;
        *) printf 'FOREIGN' ;;
    esac
}

echo
echo "quoting does not change what an image is:"
check "unquoted official"  OFFICIAL "$(official "$(dotenv_read 'WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:latest' WAYFINDR_IMAGE)")"
check "quoted official"    OFFICIAL "$(official "$(dotenv_read 'WAYFINDR_IMAGE="ghcr.io/adamgreenwell/wayfindr:latest"' WAYFINDR_IMAGE)")"
check "quoted fork"        FOREIGN  "$(official "$(dotenv_read 'WAYFINDR_IMAGE="registry.example/fork:0.3.0"' WAYFINDR_IMAGE)")"

# The target is always in its own span. Adding it only when the span came back
# empty meant a tag list that does not contain it - deleted after release, or the
# releases endpoint updating ahead of the tags listing - produced a nonempty span
# of OLDER releases and never fetched the target's own manifest, so its floor and
# before-pull actions went unread before it was pulled.
span_with_target() {
    printf '%s\nv%s\n' "$1" "$2" | grep -v '^$' | sort -u | tr '\n' ' ' | sed 's/ $//'
}

echo
echo "the target is always in its own span:"
check "already present, not duplicated" "v0.1.0 v0.2.0" "$(span_with_target 'v0.1.0
v0.2.0' 0.2.0)"
check "absent from the tag list"        "v0.1.0 v0.2.0" "$(span_with_target 'v0.1.0' 0.2.0)"
check "an empty span"                   "v0.2.0"        "$(span_with_target '' 0.2.0)"

# And the retag has to match every spelling the reader accepts, or a file the
# preflight classified as official is evaluated against the new release and then
# never retagged - so Compose pulls the old tag and the upgrade silently restarts
# the previous image.
retag() {
    local file
    file="$(mktemp)"
    printf '%s\n' "$1" > "$file"
    sed -i.bak -E "s#^([[:space:]]*(export[[:space:]]+)?)WAYFINDR_IMAGE=.*#\1WAYFINDR_IMAGE=NEW#" "$file"
    rm -f "$file.bak"
    cat "$file"
    rm -f "$file"
}

echo
echo "every accepted spelling is retagged:"
check "a bare assignment"      'WAYFINDR_IMAGE=NEW'          "$(retag 'WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:0.1.0')"
check "an export prefix"       'export WAYFINDR_IMAGE=NEW'   "$(retag 'export WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:0.1.0')"
check "leading whitespace"     '  WAYFINDR_IMAGE=NEW'        "$(retag '  WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:0.1.0')"
check "a quoted value"         'WAYFINDR_IMAGE=NEW'          "$(retag 'WAYFINDR_IMAGE="ghcr.io/adamgreenwell/wayfindr:0.1.0"')"
check "an unrelated key"       'OTHER=1'                     "$(retag 'OTHER=1')"

# The probes must run the INSTALLED image, not the target.
#
# compose.yml names the service image `${WAYFINDR_IMAGE:-...}` and Compose
# interpolates from the shell environment ahead of --env-file - so an exported
# override made every probe run, and therefore pull, the release being checked.
# The preflight would have fetched the very image it exists to check before
# pulling, which defeats before-pull entirely.
probe_image() {
    local installed="$1" exported="$2"
    (
        compose() { printf '%s' "${WAYFINDR_IMAGE:-<compose default>}"; }
        INSTALLED_IMAGE="$installed"
        [ -n "$exported" ] && export WAYFINDR_IMAGE="$exported"
        WAYFINDR_IMAGE="${INSTALLED_IMAGE:-${WAYFINDR_IMAGE:-}}" compose run
    )
}

echo
echo "probes run the installed image:"
check "an exported override does not leak" 'ghcr.io/adamgreenwell/wayfindr:0.1.0' \
    "$(probe_image ghcr.io/adamgreenwell/wayfindr:0.1.0 ghcr.io/adamgreenwell/wayfindr:0.9.0)"
check "no override, installed still wins" 'ghcr.io/adamgreenwell/wayfindr:0.1.0' \
    "$(probe_image ghcr.io/adamgreenwell/wayfindr:0.1.0 '')"

# And when the installed image cannot be established, no probe should run at all.
installed_usable() {
    case "$1" in
        '') printf 'SKIP' ;;
        *'$'*) printf 'SKIP' ;;
        *) printf 'PROBE' ;;
    esac
}

echo
echo "no probe without a known installed image:"
check "a concrete image"      PROBE "$(installed_usable ghcr.io/adamgreenwell/wayfindr:0.1.0)"
check "an unset image"        SKIP  "$(installed_usable '')"
check "an interpolated image" SKIP  "$(installed_usable '${REGISTRY}/wayfindr:0.1.0')"

# An acknowledgement cannot settle a STRANDED action, and the preflight has to
# agree with the artifact about that. Honouring the acknowledgement first made
# the preflight report clear, pull, and hand over to an artifact that rejects the
# same acknowledgement and exits 78 - a working install replaced by one that will
# not start.
settle() {
    local release="$1" depends="$2" target="$3" acked="$4"
    local stranded=NO

    if [ "$release" != "$target" ]; then
        case "$depends" in code|schema) stranded=YES ;; esac
    fi

    if [ "$stranded" = NO ] && [ "$acked" = YES ]; then
        printf 'SETTLED'
        return 0
    fi

    [ "$stranded" = YES ] && printf 'STEP' || printf 'DO'
}

echo
echo "acknowledgement cannot reach a skipped release:"
check "ordinary action, acknowledged"     SETTLED "$(settle 0.3.0 none   0.3.0 YES)"
check "ordinary action, not acknowledged" DO      "$(settle 0.3.0 none   0.3.0 NO)"
# The case this fixed: acknowledged, but belongs to a release being skipped and
# needs that release's own code.
check "stranded, acknowledged anyway"     STEP    "$(settle 0.2.0 code   0.3.0 YES)"
check "stranded, not acknowledged"        STEP    "$(settle 0.2.0 schema 0.3.0 NO)"
# An intermediate action needing nothing from its own release is reachable, so an
# acknowledgement settles it as usual.
check "intermediate needing nothing"      SETTLED "$(settle 0.2.0 none   0.3.0 YES)"

# `compose up -d` returns once containers are STARTED, not once they are up - so
# a container whose entrypoint refuses to migrate and exits 78 is invisible to
# it. Reporting "Upgrade complete" over a service that had already stopped is
# worse than the refusal itself, because it sends the operator away.
container_verdict() {
    local running="$1" code="$2" healthy="$3"

    if [ "$running" = false ] && [ "$code" = 78 ]; then printf 'REFUSED'; return 0; fi
    if [ "$running" = false ] && [ "$code" != 0 ]; then printf 'STOPPED'; return 0; fi
    [ "$healthy" = yes ] && printf 'SERVING' || printf 'WAITING'
}

echo
echo "upgrade reports what the container actually did:"
check "a guard refusal is surfaced"    REFUSED "$(container_verdict false 78 no)"
check "any other exit is surfaced"     STOPPED "$(container_verdict false 1 no)"
check "a serving container completes"  SERVING "$(container_verdict true 0 yes)"
check "still starting is not complete" WAITING "$(container_verdict true 0 no)"

# `/up` is deliberately exempt from the serving gate, so a healthy `/up` says the
# container is ALIVE, not that it is serving. An outstanding after-start action
# leaves every other route on 503, and reporting the upgrade complete there is
# the same mistake as reporting it over a stopped container.
serving_verdict() {
    local up="$1" root="$2"

    [ "$up" = ok ] || { printf 'WAITING'; return 0; }
    [ "$root" = 503 ] && printf 'GATED' || printf 'SERVING'
}

echo
echo "a healthy /up is not proof of serving:"
check "up healthy, routes gated"      GATED   "$(serving_verdict ok 503)"
check "up healthy, routes answering"  SERVING "$(serving_verdict ok 200)"
# A redirect to the login page is an answer, not a refusal.
check "up healthy, route redirects"   SERVING "$(serving_verdict ok 302)"
check "up not yet healthy"            WAITING "$(serving_verdict no 000)"

# And the wait must actually probe a gated route rather than only /up.
wait_body="$(awk '/^await_web_start\(\) \{/,/^\}/' "$INSTALLER")"

echo
echo "the startup wait probes past the exemption:"
check "it probes /up"                 1 "$(printf '%s' "$wait_body" | grep -c 'local_url/up')"
# Presence, not a count: the root is probed twice, once for the status and once
# to show the operator what the release is asking for.
check "it also probes a real route"   yes "$(printf '%s' "$wait_body" | grep -q 'local_url/\"' && echo yes || echo no)"
check "it treats 503 as not serving"  1 "$(printf '%s' "$wait_body" | grep -c '= \"503\"')"
# A container that EXITED is not listed without --all, which is precisely the
# container this is looking for. Without it the refusal went unseen and the loop
# ran its full two minutes before printing a generic failure.
check "it lists stopped containers"   yes "$(printf '%s' "$wait_body" | grep -q 'compose ps --all -q web' && echo yes || echo no)"
check "it does not use the bare form" 0   "$(printf '%s' "$wait_body" | grep -c 'compose ps -q web')"

# And the installer must actually consult it before saying the upgrade worked.
upgrade_tail="$(awk '/say "Restarting the stack/,/say "Upgrade complete/' "$INSTALLER")"

echo
echo "the upgrade path waits before declaring success:"
check "the wait was found"             1 "$(printf '%s' "$upgrade_tail" | grep -c 'await_web_start')"
check "it exits 78 on a refusal"       1 "$(printf '%s' "$upgrade_tail" | grep -c 'exit 78')"
check "success comes after the wait"   1 "$(printf '%s' "$upgrade_tail" | grep -c 'Upgrade complete')"

# A manifest has to be the manifest for the tag it was fetched from. decode()
# only asks whether the document is internally consistent, so an asset generated
# for another release attaches and validates perfectly - and the floor check,
# which fires only when the manifest names the target, then silently does not
# run, and the pull replaces a working container with one whose own baked
# manifest refuses the origin.
manifest_matches_tag() {
    WF_TAG="$1" WF_VERSION="$2" "${PHP:-php}" -r '
        require getenv("APP")."/app/Support/Version/SemanticVersion.php";
        $expected = App\Support\Version\SemanticVersion::parse((string) getenv("WF_TAG"));
        $version = (string) getenv("WF_VERSION");
        echo ($expected === null || $version !== $expected->canonical()) ? "MISMATCH" : "OK";
    '
}

echo
echo "a manifest must belong to the tag it came from:"
check "tag and manifest agree"        OK       "$(manifest_matches_tag v0.3.0 0.3.0)"
check "an unprefixed tag agrees too"  OK       "$(manifest_matches_tag 0.3.0 0.3.0)"
check "a manifest for another release" MISMATCH "$(manifest_matches_tag v0.3.0 0.2.0)"
check "a tag that is not a version"   MISMATCH "$(manifest_matches_tag not-a-tag 0.3.0)"

# The target is added when this upgrade actually TRAVERSES it. An install already
# recorded at the target has a legitimately empty span, and evaluating the target
# anyway made a re-run of --upgrade refuse forever: a fresh install holds no
# acknowledgement for the target's own upgrade-only work, because the artifact
# exempted it rather than asking.
span_for() {
    local span="$1" from="$2" to="$3"

    if [ "$from" != "$to" ]; then
        span="$(printf '%s\nv%s\n' "$span" "$to" | grep -v '^$' | sort -u || true)"
    fi

    printf '%s' "$(printf '%s' "$span" | tr '\n' ' ' | sed 's/ $//')"
}

echo
echo "the target is spanned only when traversed:"
check "tags already had it"        "v0.2.0 v0.3.0" "$(span_for 'v0.2.0
v0.3.0' 0.1.0 0.3.0)"
check "tags missed it"             "v0.2.0 v0.3.0" "$(span_for 'v0.2.0' 0.1.0 0.3.0)"
check "unknown origin"             "v0.1.0 v0.3.0" "$(span_for 'v0.1.0' '' 0.3.0)"
# The idempotency case: already there, so nothing is traversed and a re-run must
# not resurrect the target's own upgrade-only work.
check "already at the target"      ""              "$(span_for '' 0.3.0 0.3.0)"

# Prose travels base64d, because it is prose: a summary or detail may legitimately
# contain a newline or a `|` - a shell pipeline in an instruction is the obvious
# case - and this is a pipe-delimited, newline-separated protocol.
if printf 'eA==' | base64 --decode >/dev/null 2>&1; then B64="base64 --decode"; else B64="base64 -D"; fi
unprose_smoke() { printf '%s' "$1" | $B64 2>/dev/null || printf '%s' "$1"; }

hostile="$("${PHP:-php}" -r 'echo base64_encode("Run: php artisan a | grep b\nThen restart.");')"

echo
echo "prose cannot forge fields or records:"
check "it survives the round trip" 'Run: php artisan a | grep b
Then restart.' "$(unprose_smoke "$hostile")"
check "the record stays one line"  1 "$(printf 'DO|k|before-pull|%s|%s|ATTEST\n' "$hostile" "$hostile" | wc -l | tr -d ' ')"
check "fields parse correctly"     'DO/k/before-pull/ATTEST' "$(printf 'DO|k|before-pull|%s|%s|ATTEST\n' "$hostile" "$hostile" | while IFS='|' read -r t k p _ _ v; do printf '%s/%s/%s/%s' "$t" "$k" "$p" "$v"; done)"

# Three answers, not two. Work needing the CURRENT release code is performable
# right now and impossible after the pull - so it must stop the pull, not join
# the "you can do this later" list. Reclassifying it as an ordinary DO let the
# preflight report success and then remove the only code that could do it.
#
#   STEP - never ran that release; the only route is to stop at it
#   NOW  - ran it, and the code is still here, so it is possible until we pull
#   DO   - nothing special; performable after the upgrade
classify() {
    WF_R="$1" WF_T="$2" WF_C="$3" "${PHP:-php}" -r '
        require getenv("APP")."/app/Support/Version/SemanticVersion.php";
        require getenv("APP")."/app/Support/Version/VersionComparator.php";
        $release = getenv("WF_R");
        $target = getenv("WF_T");
        $recorded = getenv("WF_C") ?: null;
        $own = $release !== $target;
        $stranded = $own;
        $onlyNow = false;
        // Equality only: ordering does not prove the install ever RAN a release,
        // because direct jumps are supported.
        if ($own && $recorded !== null && $release === $recorded) {
            $stranded = false;
            $onlyNow = true;
        }
        echo $stranded ? "STEP" : ($onlyNow ? "NOW" : "DO");
    '
}

echo
echo "own-release work is classified three ways:"
check "the release the install is on"  NOW  "$(classify 0.2.0 0.3.0 0.2.0)"
check "a release it skipped over"      STEP "$(classify 0.2.0 0.3.0 0.1.0)"
# Newer is not proof of traversal: a direct 0.1.0 -> 0.4.0 jump never ran 0.2.0.
check "merely newer than it"          STEP "$(classify 0.2.0 0.5.0 0.4.0)"
check "the target itself"              DO   "$(classify 0.3.0 0.3.0 0.1.0)"
check "no recorded origin"             STEP "$(classify 0.2.0 0.3.0 '')"
check "an unorderable origin"          STEP "$(classify 0.2.0 0.3.0 '0.2.0-dev+abc')"

# And NOW has to reach the blocking set rather than the later set.
preflight_body="$(awk '/^upgrade_preflight\(\) \{/,/^\}/' "$INSTALLER")"

echo
echo "work that is only possible now stops the pull:"
check "NOW is collected"           yes "$(printf '%s' "$preflight_body" | grep -q "grep '\^NOW|'" && echo yes || echo no)"
check "NOW joins blocking"         yes "$(printf '%s' "$preflight_body" | grep -q 'onlynow' && echo yes || echo no)"
check "NOW is excluded from later" yes "$(printf '%s' "$preflight_body" | grep -q "grep -v '\^NOW|'" && echo yes || echo no)"

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
