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
    local scenario="$1" body status

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
    local scenario="$1" body status

    curl() {
        case "$scenario" in
            ok)     printf '[{"name": "v0.2.0"}]' > "$3"; printf '200' ;;
            rated)  printf '{"message":"rate limited"}' > "$3"; printf '403'; return 22 ;;
            down)   printf '000'; return 7 ;;
        esac
    }

    body="$(mktemp)"
    status="$(curl -sSL -o "$body" -w '%{http_code}' https://example.invalid 2>/dev/null || true)"
    [ -n "$status" ] || status="000"
    rm -f "$body"
    unset -f curl

    [ "$status" = "200" ] && printf 'PROCEED' || printf 'REFUSE'
}

echo
echo "tag discovery fails closed:"
check "a usable tag list proceeds"          PROCEED "$(discovery ok)"
check "a rate-limited API refuses"          REFUSE  "$(discovery rated)"
check "an unreachable API refuses"          REFUSE  "$(discovery down)"

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
