#!/usr/bin/env bash
# Exercises the shipped upgrade controller, with only its Docker/network boundary
# replaced. No daemon, registry, or GitHub request is used by this test.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALLER="$ROOT_DIR/scripts/self-host/install.sh"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT
mkdir -p "$WORK_DIR/bin"
export WF_TEST_ROOT="$ROOT_DIR" WF_TEST_PHP="${PHP:-$(command -v php)}"
export WF_TEST_DIGEST="sha256:$(printf '%064d' 1)"
export WF_TEST_IMAGE_ID="sha256:$(printf '%064d' 2)"
export WF_TEST_OLD_ID="sha256:$(printf '%064d' 3)"
export WF_TEST_COMMIT="$(printf '%040d' 4)"
pass=0

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    [ ! -f "${CASE_DIR:-}/output" ] || cat "$CASE_DIR/output" >&2
    [ ! -f "${CASE_DIR:-}/calls" ] || cat "$CASE_DIR/calls" >&2
    exit 1
}

cat > "$WORK_DIR/bin/curl" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
out="" format="" url=""
while [ "$#" -gt 0 ]; do
    case "$1" in
        -o|--output) out="$2"; shift 2 ;;
        -w|--write-out) format="$2"; shift 2 ;;
        --max-time|--connect-timeout|--retry|--retry-delay|-H|--header) shift 2 ;;
        -*) shift ;;
        *) url="$1"; shift ;;
    esac
done
printf 'curl|%s\n' "$url" >> "$WF_TEST_CASE/calls"
body="" status=200
case "$url" in
    */releases/latest)
        count=0
        [ ! -f "$WF_TEST_CASE/discovery-count" ] || count="$(cat "$WF_TEST_CASE/discovery-count")"
        count=$((count + 1)); printf '%s' "$count" > "$WF_TEST_CASE/discovery-count"
        version=1.1.1
        [ "$count" -eq 1 ] || version=1.2.0
        body="{\"id\":111,\"tag_name\":\"v$version\",\"draft\":false,\"prerelease\":false}"
        ;;
    */tags\?*)
        if [ "$WF_TEST_SCENARIO" = preflight-failure ]; then
            status=503; body='{"message":"temporarily unavailable"}'
        else
            body='[{"name":"v1.1.0"},{"name":"v1.1.1"}]'
        fi
        ;;
    */release-manifest.json)
        if [ "$WF_TEST_SCENARIO" = manifest-download-failure ]; then
            status=503; body='unavailable'
        else
            body="$(cat "$WF_TEST_CASE/manifest.json")"
        fi
        ;;
    */release-image-digest.txt)
        if [ "$WF_TEST_SCENARIO" = digest-download-failure ]; then
            status=404; body='not found'
        elif [ "$WF_TEST_SCENARIO" = malformed-digest ]; then
            body='sha256:not-a-digest'
        else
            body="$WF_TEST_DIGEST"
        fi
        ;;
    */docker/self-hosting/compose.yml)
        if [ "$WF_TEST_SCENARIO" = stack-download-failure ]; then
            status=503; body='unavailable'
        else
            body="$(cat "$WF_TEST_CASE/source/docker/self-hosting/compose.yml")"
        fi
        ;;
    */scripts/self-host/install.sh)
        body="$(cat "$WF_TEST_CASE/source/scripts/self-host/install.sh")"
        ;;
    http://127.0.0.1:8000/up) body='healthy' ;;
    http://127.0.0.1:8000/) body='serving' ;;
    *) printf 'Unhandled fake curl URL: %s\n' "$url" >&2; exit 97 ;;
esac
if [ -n "$out" ]; then printf '%s\n' "$body" > "$out"; else printf '%s\n' "$body"; fi
if [ -n "$format" ]; then printf '%s' "$status"; fi
if [ "$status" -ge 400 ] && [ -z "$format" ]; then exit 22; fi
STUB

cat > "$WORK_DIR/bin/docker" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
log() { printf '%s\n' "$*" >> "$WF_TEST_CASE/calls"; }
effective_image() {
    if [ -n "${WAYFINDR_IMAGE:-}" ]; then printf '%s' "$WAYFINDR_IMAGE"; return; fi
    local configured
    configured="$(sed -nE 's/^[[:space:]]*(export[[:space:]]+)?WAYFINDR_IMAGE=//p' "$env_file" | tail -1)"
    case "$configured" in
        \"*) configured="${configured#\"}"; configured="${configured%%\"*}" ;;
        \'*) configured="${configured#\'}"; configured="${configured%%\'*}" ;;
    esac
    printf '%s' "${configured:-ghcr.io/adamgreenwell/wayfindr:latest}"
}
image_id() {
    if [ "$WF_TEST_SCENARIO" = wrong-local-id ]; then printf '%s' "$WF_TEST_OLD_ID"; else printf '%s' "$WF_TEST_IMAGE_ID"; fi
}
case "$1" in
    info|version) exit 0 ;;
    image)
        shift; [ "$1" = inspect ] || exit 97; shift
        template=""
        while [ "$#" -gt 0 ]; do
            case "$1" in
                -f|--format) template="$2"; shift 2 ;;
                *) image="$1"; shift ;;
            esac
        done
        log "image-inspect|$image|$template"
        if [ "$WF_TEST_SCENARIO" = missing-local-image ]; then exit 1; fi
        case "$template" in
            *RepoDigests*)
                if [ "$WF_TEST_SCENARIO" = digest-mismatch ]; then
                    printf 'ghcr.io/adamgreenwell/wayfindr@%s\n' "$WF_TEST_OLD_ID"
                else
                    printf 'ghcr.io/adamgreenwell/wayfindr@%s\n' "$WF_TEST_DIGEST"
                fi
                ;;
            *'.Id'*) image_id; printf '\n' ;;
            *) printf '[{"Id":"%s","RepoDigests":["ghcr.io/adamgreenwell/wayfindr@%s"]}]\n' "$(image_id)" "$WF_TEST_DIGEST" ;;
        esac
        exit 0
        ;;
    create)
        log "create|$*"
        printf 'abcdefabcdef\n'; exit 0
        ;;
    cp)
        log "cp|$2"
        case "$2" in
            *:/etc/wayfindr/release.json) cp "$WF_TEST_CASE/baked.json" "$3" ;;
            *:/etc/wayfindr/version)
                if [ "$WF_TEST_SCENARIO" = baked-version-mismatch ]; then printf 'v1.1.0\n' > "$3"; else printf 'v1.1.1\n' > "$3"; fi
                ;;
            *:/etc/wayfindr/commit)
                if [ "$WF_TEST_SCENARIO" = baked-commit-mismatch ]; then printf '%040d\n' 9 > "$3"; else printf '%s\n' "$WF_TEST_COMMIT" > "$3"; fi
                ;;
            *) exit 97 ;;
        esac
        exit 0
        ;;
    rm) log "remove|$*"; exit 0 ;;
    inspect)
        shift; template=""
        while [ "$#" -gt 0 ]; do
            case "$1" in
                -f|--format) template="$2"; shift 2 ;;
                *) cid="$1"; shift ;;
            esac
        done
        log "container-inspect|$cid|$template"
        case "$template" in
            *'.State.Running'*)
                if [ "$WF_TEST_SCENARIO" = startup-refusal ]; then printf 'false\n'; else printf 'true\n'; fi
                ;;
            *'.State.ExitCode'*)
                if [ "$WF_TEST_SCENARIO" = startup-refusal ]; then printf '78\n'; else printf '0\n'; fi
                ;;
            *'.Image'*)
                if [ ! -f "$WF_TEST_CASE/applied" ] || [ "$WF_TEST_SCENARIO" = stale-web ] || { [ "$WF_TEST_SCENARIO" = stale-worker ] && [ "$cid" = queue-container ]; }; then
                    printf '%s\n' "$WF_TEST_OLD_ID"
                else
                    printf '%s\n' "$WF_TEST_IMAGE_ID"
                fi
                ;;
            *) printf 'Unhandled container inspect: %s\n' "$template" >&2; exit 97 ;;
        esac
        exit 0
        ;;
    compose) shift ;;
    exec)
        log "exec|runtime-identity"
        # Runtime identity verification receives a real container boundary;
        # this fixture represents config bootstrapped inside that container.
        version=v1.1.1; commit="$WF_TEST_COMMIT"
        [ "$WF_TEST_SCENARIO" != runtime-version-mismatch ] || version=v1.1.0
        [ "$WF_TEST_SCENARIO" != runtime-commit-mismatch ] || commit="$(printf '%040d' 9)"
        printf '%s|%s\n' "$version" "$commit"
        exit 0
        ;;
    *) printf 'Unhandled fake docker command: %s\n' "$*" >&2; exit 97 ;;
esac

env_file="" compose_file="" project_dir=""
while [ "$#" -gt 0 ]; do
    case "$1" in
        --env-file) env_file="$2"; shift 2 ;;
        -f|--file) compose_file="$2"; shift 2 ;;
        --project-directory) project_dir="$2"; shift 2 ;;
        *) break ;;
    esac
done
action="$1"; shift
[ "$action" != version ] || exit 0
if [ "$action" = run ] || [ "$action" = exec ]; then
    log "compose|$action|$project_dir|$env_file|$compose_file|php-probe|image=${WAYFINDR_IMAGE:-}"
else
    log "compose|$action|$project_dir|$env_file|$compose_file|$*|image=${WAYFINDR_IMAGE:-}"
fi
case "$action" in
    config)
        [ "$WF_TEST_SCENARIO" != invalid-compose ] || exit 17
        case "$*" in
            *--images*) effective_image; printf '\n' ;;
            *--services*) printf 'storage-init\nweb\nqueue\nbackup-queue\nscheduler\nreverb\npostgres\nredis\n' ;;
            *) printf 'validated\n' ;;
        esac
        ;;
    run)
        env_args=("WAYFINDR_RELEASE_STATE_PATH=$WF_TEST_CASE/state.json")
        code=""
        while [ "$#" -gt 0 ]; do
            case "$1" in
                -e|--env) env_args+=("$2"); shift 2 ;;
                -r) code="$2"; shift 2 ;;
                *) shift ;;
            esac
        done
        [ -n "$code" ] || { printf 'Fake compose run has no PHP program.\n' >&2; exit 97; }
        code="${code//\/app\/apps\/server/$WF_TEST_ROOT/apps/server}"
        env "${env_args[@]}" "$WF_TEST_PHP" -r "$code"
        ;;
    pull)
        if [ "$WF_TEST_SCENARIO" = pull-failure ] || [ "$WF_TEST_SCENARIO" = local-source ]; then exit 23; fi
        ;;
    up) printf 'applied\n' > "$WF_TEST_CASE/applied" ;;
    ps)
        service="${*: -1}"
        [ "$WF_TEST_SCENARIO" != missing-worker ] || [ "$service" != queue ] || exit 0
        printf '%s-container\n' "$service"
        ;;
    exec)
        version=v1.1.1; commit="$WF_TEST_COMMIT"
        [ "$WF_TEST_SCENARIO" != runtime-version-mismatch ] || version=v1.1.0
        [ "$WF_TEST_SCENARIO" != runtime-commit-mismatch ] || commit="$(printf '%040d' 9)"
        printf '%s|%s\n' "$version" "$commit"
        ;;
    logs) printf 'The release requires operator action before migrations.\n' ;;
    *) printf 'Unhandled fake compose action: %s\n' "$action" >&2; exit 97 ;;
esac
STUB
printf '#!/usr/bin/env bash\nexit 0\n' > "$WORK_DIR/bin/sleep"
chmod +x "$WORK_DIR/bin/"*
export PATH="$WORK_DIR/bin:$PATH"

new_case() {
    local name="$1" scenario="$2"
    CASE_DIR="$WORK_DIR/$name"
    mkdir -p "$CASE_DIR/active" "$CASE_DIR/source/docker/self-hosting" "$CASE_DIR/source/scripts/self-host" "$CASE_DIR/before"
    export WF_TEST_CASE="$CASE_DIR" WF_TEST_SCENARIO="$scenario"
    : > "$CASE_DIR/calls"
    cp "$ROOT_DIR/docker/self-hosting/compose.yml" "$CASE_DIR/source/docker/self-hosting/compose.yml"
    printf '\n# fetched candidate stack\n' >> "$CASE_DIR/source/docker/self-hosting/compose.yml"
    cp "$INSTALLER" "$CASE_DIR/source/scripts/self-host/install.sh"
    printf '# active compose stays here until preparation succeeds\n' > "$CASE_DIR/active/compose.yml"
    printf '#!/usr/bin/env bash\nprintf "old active installer\\n"\n' > "$CASE_DIR/active/install.sh"
    cat > "$CASE_DIR/active/.env" <<'ENV'
WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:1.1.0
WAYFINDR_VERSION=
WAYFINDR_COMMIT=
APP_KEY=keep-this-secret
APP_URL=https://support.wayfindr.test
WAYFINDR_LOCAL_BIND=127.0.0.1:8000
CADDY_SERVER_EXTRA_DIRECTIVES=tls internal
SERVER_NAME=192.0.2.50
ENV
    chmod 640 "$CASE_DIR/active/.env"
    chmod 750 "$CASE_DIR/active/install.sh"
    printf '{"version":"1.1.0","satisfied_through":"1.1.0","installation_profile":"image"}\n' > "$CASE_DIR/state.json"
    printf '{"schema":1,"version":"1.1.1","commit":"%s","requires_operator_action":false,"minimum_upgrade_from":"1.1.0","actions":[]}\n' "$WF_TEST_COMMIT" > "$CASE_DIR/manifest.json"
    cp "$CASE_DIR/manifest.json" "$CASE_DIR/baked.json"
    cp -p "$CASE_DIR/active/compose.yml" "$CASE_DIR/active/.env" "$CASE_DIR/active/install.sh" "$CASE_DIR/before/"
}

run_upgrade() {
    set +e
    bash "$INSTALLER" --upgrade --dir "$CASE_DIR/active" "$@" > "$CASE_DIR/output" 2>&1
    LAST_STATUS=$?
    set -e
    if [ "$WF_TEST_SCENARIO" != concurrent-lock ] && [ -e "$CASE_DIR/active/.upgrade.lock" ]; then
        fail "${CASE_DIR##*/}: installer did not release its upgrade lock"
    fi
}

expect_unchanged() {
    local file
    for file in compose.yml .env install.sh; do
        cmp -s "$CASE_DIR/active/$file" "$CASE_DIR/before/$file" || fail "${CASE_DIR##*/}: active $file changed before preparation succeeded"
        [ "$(ls -ld "$CASE_DIR/active/$file" | awk '{print $1}')" = "$(ls -ld "$CASE_DIR/before/$file" | awk '{print $1}')" ] || fail "${CASE_DIR##*/}: active $file permissions changed"
    done
    [ ! -f "$CASE_DIR/applied" ] || fail "${CASE_DIR##*/}: restarted the active stack"
    ! grep -qE 'Upgrade complete' "$CASE_DIR/output" || fail "${CASE_DIR##*/}: claimed success"
}

expect_failure() {
    [ "$LAST_STATUS" -ne 0 ] || fail "${CASE_DIR##*/}: unexpectedly succeeded"
    ! grep -qE 'Upgrade complete' "$CASE_DIR/output" || fail "${CASE_DIR##*/}: claimed success"
}

expect_success() {
    [ "$LAST_STATUS" -eq 0 ] || fail "${CASE_DIR##*/}: upgrade failed"
    grep -qE 'Upgrade complete' "$CASE_DIR/output" || fail "${CASE_DIR##*/}: omitted verified success"
    if compgen -G "$CASE_DIR/active/.upgrade.*" >/dev/null; then
        fail "${CASE_DIR##*/}: an owned preparation stage remained after success"
    fi
}

ok() { printf '  ok  %s\n' "$1"; pass=$((pass + 1)); }

printf '\n  shipped installer upgrade controller\n\n'
for scenario in stack-download-failure preflight-failure pull-failure manifest-download-failure digest-download-failure malformed-digest invalid-compose; do
    new_case "$scenario" "$scenario"
    run_upgrade --ref v1.1.1
    expect_failure
    expect_unchanged
    ok "$scenario preserves the active stack"
done

# A clean recorded target has no outstanding manifest span, so these requests
# reach the controller's own published-identity parser instead of being rejected
# first by the artifact's separate release-requirements validator.
for malformed in truncated trailing-garbage duplicate-version escaped-key nested-only; do
    new_case "manifest-$malformed" success
    printf '{"version":"1.1.1","satisfied_through":"1.1.1","installation_profile":"image"}\n' > "$CASE_DIR/state.json"
    case "$malformed" in
        truncated) printf '{"version":"1.1.1","commit":' > "$CASE_DIR/manifest.json" ;;
        trailing-garbage) printf '\ntrailing garbage\n' >> "$CASE_DIR/manifest.json" ;;
        duplicate-version)
            printf '{"version":"1.1.1","version":"1.1.1","commit":"%s"}\n' "$WF_TEST_COMMIT" > "$CASE_DIR/manifest.json"
            ;;
        escaped-key)
            printf '{"\\u0076ersion":"1.1.1","commit":"%s"}\n' "$WF_TEST_COMMIT" > "$CASE_DIR/manifest.json"
            ;;
        nested-only)
            printf '{"nested":{"version":"1.1.1","commit":"%s"}}\n' "$WF_TEST_COMMIT" > "$CASE_DIR/manifest.json"
            ;;
    esac
    run_upgrade --ref v1.1.1
    expect_failure
    expect_unchanged
    ! grep -qE 'compose\|pull\|' "$CASE_DIR/calls" || fail "manifest-$malformed: metadata was not validated before pulling"
    ok "published manifest $malformed cannot supply release identity"
done

new_case prepare-only success
run_upgrade --ref v1.1.1 --no-start
[ "$LAST_STATUS" -eq 0 ] || fail 'prepare-only failed'
expect_unchanged
grep -qE 'not started|not activated|prepared' "$CASE_DIR/output" || fail 'prepare-only lacks instructions'
ok '--no-start stages a candidate without activating it'

new_case fixed-release success
run_upgrade
expect_success
[ "$(cat "$CASE_DIR/discovery-count")" -eq 1 ] || fail 'release discovery ran more than once'
[ "$(grep -c 'Handing off to the staged installer' "$CASE_DIR/output" || true)" -eq 1 ] || fail 'protocol-capable target handoff did not occur exactly once'
! grep -qE '/v1\.2\.0/' "$CASE_DIR/calls" || fail 'candidate drifted after resolving the release'
grep -qE 'fetched candidate stack' "$CASE_DIR/active/compose.yml" || fail 'candidate stack was not activated'
grep -qE '^APP_KEY=keep-this-secret$' "$CASE_DIR/active/.env" || fail 'application secret was lost'
! grep -qE '^WAYFINDR_(VERSION|COMMIT)=$' "$CASE_DIR/active/.env" || fail 'blank release overrides remain'
grep -qE '^CADDY_GLOBAL_OPTIONS_EXTRA=default_sni 192\.0\.2\.50$' "$CASE_DIR/active/.env" || fail 'staged environment migration was lost'
grep -qE 'compose\|up\|.*--pull never' "$CASE_DIR/calls" || fail 'activation can pull a different image'
grep -qE '^WAYFINDR_UPGRADE_PROTOCOL=1$' "$CASE_DIR/active/install.sh" || fail 'active installer lost the hardened controller'
probe_count="$(grep -cE 'compose\|run\|' "$CASE_DIR/calls" || true)"
[ "$probe_count" -gt 0 ] || fail 'fixture did not exercise a real preflight probe'
if grep -E 'compose\|run\|' "$CASE_DIR/calls" | grep -vF "image=$WF_TEST_OLD_ID" >/dev/null; then
    fail 'a preflight probe ran a tag or the target image instead of the captured active image ID'
fi
ok 'one resolved release is prepared, activated, and verified'

new_case concurrent-lock concurrent-lock
mkdir -m 700 "$CASE_DIR/active/.upgrade.lock"
printf '999999\n' > "$CASE_DIR/active/.upgrade.lock/pid"
run_upgrade --ref v1.1.1
expect_failure
expect_unchanged
grep -qE 'Another upgrade owns' "$CASE_DIR/output" || fail 'concurrent upgrade lock refusal is unclear'
[ "$(cat "$CASE_DIR/active/.upgrade.lock/pid")" = 999999 ] || fail 'an updater removed a lock it did not own'
! grep -qE '^curl\|' "$CASE_DIR/calls" || fail 'locked upgrade still prepared a release'
ok 'a concurrent upgrade refuses before preparation and preserves the other lock'

new_case spoofed-lock-stage concurrent-lock
mkdir -m 700 "$CASE_DIR/active/.upgrade.lock"
printf '999999\n' > "$CASE_DIR/active/.upgrade.lock/pid"
export WAYFINDR_UPGRADE_PARENT_STAGE="$CASE_DIR/active/.upgrade.lock" WAYFINDR_HANDED_OFF=1
run_upgrade --ref v1.1.1
unset WAYFINDR_UPGRADE_PARENT_STAGE WAYFINDR_HANDED_OFF
expect_failure
expect_unchanged
[ "$(cat "$CASE_DIR/active/.upgrade.lock/pid")" = 999999 ] || fail 'ambient parent stage made cleanup remove another updater lock'
ok 'ambient parent-stage metadata cannot remove another updater lock'

new_case ambient-identity-probe stack-download-failure
export UPGRADE_IDENTITY_PROBE=deadbeefdead
run_upgrade --ref v1.1.1
unset UPGRADE_IDENTITY_PROBE
expect_failure
expect_unchanged
! grep -qE '^remove\|' "$CASE_DIR/calls" || fail 'ambient probe metadata made cleanup remove an unrelated container'
ok 'ambient identity-probe metadata cannot remove an unrelated container'

new_case foreign-parent-stage stack-download-failure
foreign_stage="$CASE_DIR/active/.upgrade.abcdefgh"
mkdir -m 700 "$foreign_stage"
printf '999999\n' > "$foreign_stage/controller-pid"
printf 'owned by another controller\n' > "$foreign_stage/sentinel"
export WAYFINDR_UPGRADE_PARENT_STAGE="$foreign_stage" WAYFINDR_HANDED_OFF=1
run_upgrade --ref v1.1.1
unset WAYFINDR_UPGRADE_PARENT_STAGE WAYFINDR_HANDED_OFF
expect_failure
expect_unchanged
grep -qF 'owned by another controller' "$foreign_stage/sentinel" || fail 'cleanup removed a preparation stage owned by another controller'
ok 'a correctly named parent stage still requires matching controller ownership'

for scenario in baked-version-mismatch baked-commit-mismatch; do
    new_case "$scenario" "$scenario"
    if [ "$scenario" = baked-version-mismatch ]; then
        sed -i.bak 's/"version":"1.1.1"/"version":"1.1.0"/' "$CASE_DIR/baked.json"
    else
        sed -i.bak "s/$WF_TEST_COMMIT/$(printf '%040d' 9)/" "$CASE_DIR/baked.json"
    fi
    rm -f "$CASE_DIR/baked.json.bak"
    run_upgrade --ref v1.1.1
    expect_failure
    expect_unchanged
    ok "$scenario refuses before activation"
done

new_case baked-manifest-mismatch success
sed -i.bak 's/"requires_operator_action":false/"requires_operator_action":true/' "$CASE_DIR/baked.json"
rm -f "$CASE_DIR/baked.json.bak"
run_upgrade --ref v1.1.1
expect_failure
expect_unchanged
ok 'baked manifest bytes must match the published manifest'

for scenario in digest-mismatch stale-web stale-worker missing-worker runtime-version-mismatch runtime-commit-mismatch startup-refusal; do
    new_case "$scenario" "$scenario"
    run_upgrade --ref v1.1.1
    expect_failure
    if [ "$scenario" = digest-mismatch ]; then expect_unchanged; fi
    ok "$scenario cannot claim upgrade success"
done

new_case legacy-target success
sed -i.bak '/^WAYFINDR_UPGRADE_PROTOCOL=1$/d' "$CASE_DIR/source/scripts/self-host/install.sh"
rm -f "$CASE_DIR/source/scripts/self-host/install.sh.bak"
run_upgrade --ref v1.1.1
expect_success
grep -qE '^WAYFINDR_UPGRADE_PROTOCOL=1$' "$CASE_DIR/active/install.sh" || fail 'legacy target installer replaced the hardened controller'
ok 'a legacy target cannot replace or re-execute an older upgrade controller'

new_case newer-protocol success
sed -i.bak 's/^WAYFINDR_UPGRADE_PROTOCOL=1$/WAYFINDR_UPGRADE_PROTOCOL=2/' "$CASE_DIR/source/scripts/self-host/install.sh"
rm -f "$CASE_DIR/source/scripts/self-host/install.sh.bak"
run_upgrade --ref v1.1.1
expect_failure
expect_unchanged
ok 'an unsupported updater protocol refuses without changing the active stack'

new_case invalid-installer success
printf '\nsyntax-error )\n' >> "$CASE_DIR/source/scripts/self-host/install.sh"
run_upgrade --ref v1.1.1
expect_failure
expect_unchanged
ok 'an incomplete or invalid downloaded installer cannot reach activation'

new_case custom-image success
sed -i.bak 's#ghcr.io/adamgreenwell/wayfindr:1.1.0#registry.wayfindr.test/custom:stable#' "$CASE_DIR/active/.env"
rm -f "$CASE_DIR/active/.env.bak"
run_upgrade --ref v1.1.1
expect_success
grep -qE 'custom|not an official|unverified|provenance' "$CASE_DIR/output" || fail 'custom image provenance caveat is missing'
grep -qE '^WAYFINDR_IMAGE=registry.wayfindr.test/custom:stable$' "$CASE_DIR/active/.env" || fail 'custom image was replaced with an official image'
ok 'custom images retain their configured source and report reduced release guarantees'

new_case source-ref success
run_upgrade --ref main
expect_success
grep -qE '^WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:latest$' "$CASE_DIR/active/.env" || fail 'source ref did not retain its floating image selector'
grep -qE 'published digest guarantees are unavailable|custom image' "$CASE_DIR/output" || fail 'source ref guarantees were overstated'
grep -qE "compose\|up\|.*image=$WF_TEST_IMAGE_ID" "$CASE_DIR/calls" || fail 'source ref did not execute the prepared concrete image'
ok 'a source ref keeps its floating selector while execution uses the inspected image ID'

new_case local-source local-source
sed -i.bak 's#ghcr.io/adamgreenwell/wayfindr:1.1.0#wayfindr-server:local#' "$CASE_DIR/active/.env"
rm -f "$CASE_DIR/active/.env.bak"
run_upgrade --ref v1.1.1 --source-dir "$CASE_DIR/source"
expect_success
grep -qE 'local|source|pull' "$CASE_DIR/output" || fail 'local-source fallback was not explained'
ok 'an explicit source fixture uses its inspected local image without a registry pull'
! grep -qE 'compose\|pull\|' "$CASE_DIR/calls" || fail 'explicit local source contacted a registry'

new_case custom-pull-failure pull-failure
sed -i.bak 's#ghcr.io/adamgreenwell/wayfindr:1.1.0#registry.wayfindr.test/custom:stable#' "$CASE_DIR/active/.env"
rm -f "$CASE_DIR/active/.env.bak"
cp -p "$CASE_DIR/active/.env" "$CASE_DIR/before/.env"
run_upgrade --ref v1.1.1
expect_failure
expect_unchanged
ok 'a custom registry pull failure preserves active files without a source override'

new_case absent-image-key success
sed -i.bak '/^WAYFINDR_IMAGE=/d' "$CASE_DIR/active/.env"
rm -f "$CASE_DIR/active/.env.bak"
run_upgrade --ref v1.1.1
expect_success
grep -qE '^WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:' "$CASE_DIR/active/.env" || fail 'missing image key was not added for the candidate'
ok 'an absent image assignment is added without losing active image identity'

new_case absent-image-preflight-failure preflight-failure
sed -i.bak '/^WAYFINDR_IMAGE=/d' "$CASE_DIR/active/.env"
rm -f "$CASE_DIR/active/.env.bak"
cp -p "$CASE_DIR/active/.env" "$CASE_DIR/before/.env"
run_upgrade --ref v1.1.1
expect_failure
expect_unchanged
! grep -qE 'compose\|pull\|' "$CASE_DIR/calls" || fail 'absent persisted image bypassed release-history preflight before pulling'
probe_count="$(grep -cE 'compose\|run\|' "$CASE_DIR/calls" || true)"
[ "$probe_count" -gt 0 ] || fail 'absent persisted image did not run a real preflight probe'
if grep -E 'compose\|run\|' "$CASE_DIR/calls" | grep -vF "image=$WF_TEST_OLD_ID" >/dev/null; then
    fail 'absent persisted image preflight ran something other than the captured active image ID'
fi
ok 'an absent image assignment still checks release history using the captured active image'

new_case duplicate-image success
printf '\n  export WAYFINDR_IMAGE="ghcr.io/adamgreenwell/wayfindr:1.1.0" # final assignment wins\n' >> "$CASE_DIR/active/.env"
run_upgrade --ref v1.1.1
expect_success
grep -qE '^  export WAYFINDR_IMAGE=' "$CASE_DIR/active/.env" || fail 'export assignment formatting was lost'
ok 'quoted exported duplicate image assignments update the effective image'

new_case override-ref-mismatch success
export WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:1.2.0
run_upgrade --ref v1.1.1
unset WAYFINDR_IMAGE
expect_failure
expect_unchanged
ok 'an official image override cannot disagree with the requested release'

new_case matching-bare-digest success
export WAYFINDR_IMAGE="ghcr.io/adamgreenwell/wayfindr@$WF_TEST_DIGEST"
run_upgrade --ref v1.1.1
unset WAYFINDR_IMAGE
expect_success
grep -qF "WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:1.1.1@$WF_TEST_DIGEST" "$CASE_DIR/active/.env" || fail 'matching official digest did not gain its proven release identity'
grep -qE '^curl\|.*tags\?per_page=100' "$CASE_DIR/calls" || fail 'matching official digest skipped the requested release preflight'
ok 'a matching official bare digest is bound to the requested published release'

new_case bare-digest-preflight-failure preflight-failure
export WAYFINDR_IMAGE="ghcr.io/adamgreenwell/wayfindr@$WF_TEST_DIGEST"
run_upgrade --ref v1.1.1
unset WAYFINDR_IMAGE
expect_failure
expect_unchanged
! grep -qE 'compose\|pull\|' "$CASE_DIR/calls" || fail 'official digest bypassed release-history preflight before pulling'
ok 'an official bare digest still requires the requested release history preflight'

new_case wrong-bare-digest success
export WAYFINDR_IMAGE="ghcr.io/adamgreenwell/wayfindr@$WF_TEST_OLD_ID"
run_upgrade --ref v1.1.1
unset WAYFINDR_IMAGE
expect_failure
expect_unchanged
ok 'a different official bare digest cannot bypass requested release verification'

printf '\n%s upgrade-controller regressions passed.\n' "$pass"
