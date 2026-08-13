#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GENERATOR="$ROOT_DIR/scripts/self-host/generate-env.sh"
TMP_DIR="$(mktemp -d)"
ENV_FILE="$TMP_DIR/wayfindr.env"
CONFIG_JSON_FILE="$TMP_DIR/compose-rendered.json"

cleanup() {
    rm -rf "$TMP_DIR"
}

trap cleanup EXIT

"$GENERATOR" --output "$ENV_FILE" --app-url "https://support.example.test"

grep -E '^APP_KEY=base64:.+' "$ENV_FILE" >/dev/null
grep -E '^DB_PASSWORD=[0-9a-f]{48}$' "$ENV_FILE" >/dev/null
grep -E '^POSTGRES_PASSWORD=[0-9a-f]{48}$' "$ENV_FILE" >/dev/null
grep -E '^REVERB_APP_KEY=[0-9a-f]{32}$' "$ENV_FILE" >/dev/null
grep -E '^REVERB_APP_SECRET=[0-9a-f]{64}$' "$ENV_FILE" >/dev/null
grep -F 'APP_URL=https://support.example.test' "$ENV_FILE" >/dev/null
grep -F 'REVERB_HOST=support.example.test' "$ENV_FILE" >/dev/null
grep -F 'SESSION_SECURE_COOKIE=true' "$ENV_FILE" >/dev/null
grep -F 'MAIL_MAILER=log' "$ENV_FILE" >/dev/null

DB_PASSWORD="$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)"
POSTGRES_PASSWORD="$(grep '^POSTGRES_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)"

if [ "$DB_PASSWORD" != "$POSTGRES_PASSWORD" ]; then
    echo "DB_PASSWORD and POSTGRES_PASSWORD should match." >&2
    exit 1
fi

if "$GENERATOR" --output "$ENV_FILE" --app-url "https://support.example.test" >/dev/null 2>&1; then
    echo "Generator should refuse to overwrite an existing env file." >&2
    exit 1
fi

"$GENERATOR" --force --output "$ENV_FILE" --app-url "http://127.0.0.1:8000" >/dev/null
grep -F 'SESSION_SECURE_COOKIE=false' "$ENV_FILE" >/dev/null
grep -F 'REVERB_HOST=127.0.0.1' "$ENV_FILE" >/dev/null

# The loopback ops site must move off a port the public bind now claims.
# Leaving both on 8000 makes Compose refuse the stack over a duplicate publish.
grep -F 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:8000' "$ENV_FILE" >/dev/null
grep -F 'WAYFINDR_LOCAL_BIND=127.0.0.1:18000' "$ENV_FILE" >/dev/null

expect_env() {
    if ! grep -qF "$1" "$ENV_FILE"; then
        echo "Expected '$1' in the env generated for $CURRENT_URL:" >&2
        grep -E '^(APP_URL|SERVER_NAME|WAYFINDR_(PUBLIC|LOCAL)|CADDY_)' "$ENV_FILE" >&2
        exit 1
    fi
}

generate_for() {
    CURRENT_URL="$1"
    shift
    "$GENERATOR" --force --output "$ENV_FILE" --app-url "$CURRENT_URL" "$@" >/dev/null 2>&1
}

# A bare host is accepted, and loopback infers http:// rather than handing the
# operator a certificate to trust before the first page will load.
generate_for "localhost"
expect_env 'APP_URL=http://localhost'
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:80'
expect_env 'SESSION_SECURE_COOKIE=false'

# A bare host that is NOT loopback is something another machine resolves, so
# it infers https://.
generate_for "support.example.com"
expect_env 'APP_URL=https://support.example.com'
expect_env 'SESSION_SECURE_COOKIE=true'

# The regression this suite could not see before: every published port was a
# constant, so an operator's port only worked when it happened to match one.
# The URL is the contract -- whatever port it names is the port that serves.
generate_for "https://localhost:2345"
expect_env 'WAYFINDR_PUBLIC_HTTPS_BIND=127.0.0.1:2345'
expect_env 'REVERB_CLIENT_PORT=2345'
expect_env 'SESSION_SECURE_COOKIE=true'
# The certificate covers the hostname; the operator's port lives on the host
# side of the publish, so SERVER_NAME stays portless.
expect_env 'SERVER_NAME=localhost'
expect_env 'CADDY_SERVER_EXTRA_DIRECTIVES=tls internal'

# Names no public CA can issue for get a locally-issued certificate rather
# than an ACME challenge that can only fail.
generate_for "https://wayfinder.local"
expect_env 'CADDY_SERVER_EXTRA_DIRECTIVES=tls internal'
expect_env 'SERVER_NAME=wayfinder.local'
# Not loopback: reachable from the rest of the network, as the URL implies.
expect_env 'WAYFINDR_PUBLIC_HTTPS_BIND=443'

generate_for "https://192.168.10.4:8443"
expect_env 'CADDY_SERVER_EXTRA_DIRECTIVES=tls internal'
# A routable literal still binds every interface: the address in the URL is
# often NOT on a local interface (NAT), and binding it would fail at `up`.
expect_env 'WAYFINDR_PUBLIC_HTTPS_BIND=8443'

# "127." has to match an ADDRESS, not a prefix. DNS labels may begin with a
# digit, so 127.example.com is a real publicly-resolvable name -- treating it
# as loopback would publish a public site on 127.0.0.1 and nowhere else.
generate_for "https://127.example.com"
expect_env 'WAYFINDR_PUBLIC_HTTPS_BIND=443'
expect_env 'CADDY_SERVER_EXTRA_DIRECTIVES='

# RFC 6761 requires *.localhost to resolve to loopback, so it binds there:
# publishing on every interface would expose ports for a name nothing off
# this machine can resolve to reach anyway.
generate_for "https://wayfindr.localhost"
expect_env 'WAYFINDR_PUBLIC_HTTPS_BIND=127.0.0.1:443'
expect_env 'CADDY_SERVER_EXTRA_DIRECTIVES=tls internal'

# DNS is case-insensitive; shell globs are not. Failing to fold the case
# published the plain-HTTP service on every interface for the one input that
# most clearly means "this machine only" -- the classification failing open.
generate_for "http://LOCALHOST"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:80'
generate_for "http://wayfindr.LOCALHOST"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:80'

# ::1 spelled out in full is the same address, and an exact-string match let it
# fall through to an all-interfaces bind.
generate_for "http://[0:0:0:0:0:0:0:1]"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=[0:0:0:0:0:0:0:1]:80'

# A trailing dot is the DNS root: localhost. is the absolute spelling of the
# same name, and leaving it on matched no pattern at all.
generate_for "http://localhost."
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:80'

# An IPv4-mapped literal is loopback, but it is also the one address Docker
# refuses to publish on ("ports are not available"), so the bind unwraps to
# the embedded address a client actually reaches.
generate_for "http://[::ffff:127.0.0.1]"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:80'

# ...and a mapped address that is NOT loopback stays on every interface.
generate_for "http://[::ffff:192.168.10.5]"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=80'

# home.arpa is the only reserved suffix that is not a single label, so the
# apex fell through to the public branch and would have been sent to a CA
# that can never issue for it.
generate_for "https://home.arpa"
expect_env 'CADDY_SERVER_EXTRA_DIRECTIVES=tls internal'

# The suffix must not match a real domain that merely ends in those letters.
generate_for "https://arpa.example.com"
expect_env 'CADDY_SERVER_EXTRA_DIRECTIVES='

# RFC 7686: an onion service is reached through Tor, never through a path a
# public CA can validate.
generate_for "https://support.example.onion"
expect_env 'CADDY_SERVER_EXTRA_DIRECTIVES=tls internal'

# The hex spelling of the mapped loopback is the same address as the dotted
# one. Enumerating spellings was the losing game that motivated folding them
# to a single representation before asking anything.
generate_for "http://[::ffff:7f00:1]"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:80'
generate_for "http://[::127.0.0.1]"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:80'

# THE ONE THAT MATTERS MOST. `::` is the IPv6 WILDCARD, and Docker publishes
# on it happily -- so the ::/96 backstop treating it as loopback must never
# hand it back as the bind address, or the safety net becomes the exposure it
# exists to prevent. It falls back to the v4 loopback instead.
generate_for "http://[::]"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:80'

# A routable v6 address is still published where its URL says.
generate_for "http://[2001:db8::1]:8080"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=8080'

# inet_aton accepts one to four parts in decimal, octal or hex, so 127.1,
# 2130706433 and 0x7f000001 all reach loopback. A dotted-quad-only check read
# them as hostnames and published them on every interface -- the IPv4 half of
# the same mistake the IPv6 folding prevents.
generate_for "http://127.1"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:80'
generate_for "http://2130706433"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:80'
generate_for "http://0x7f000001"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:80'
generate_for "http://0177.0.0.1"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:80'

# ...and https://127.1 must not be sent to a CA that can never issue for it.
generate_for "https://127.1"
expect_env 'CADDY_SERVER_EXTRA_DIRECTIVES=tls internal'

# A hostname that merely begins with 127 is still a hostname. This is the
# negative that the numeric parsing must not swallow.
generate_for "https://127.example.com"
expect_env 'WAYFINDR_PUBLIC_HTTPS_BIND=443'
expect_env 'CADDY_SERVER_EXTRA_DIRECTIVES='

# A non-loopback address in shorthand is still published where the URL says.
generate_for "http://3232235778:8080"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=8080'

# A browser rewrites https://127.1 into a request for 127.0.0.1, so a site and
# a certificate named `127.1` would never match it even once the local CA is
# trusted. Canonicalising only the BIND is what made this necessary: the two
# halves of one install would otherwise disagree about its own address.
generate_for "https://127.1"
expect_env 'APP_URL=https://127.0.0.1'
expect_env 'SERVER_NAME=127.0.0.1'
expect_env 'REVERB_HOST=127.0.0.1'
expect_env 'WAYFINDR_PUBLIC_HTTPS_BIND=127.0.0.1:443'

# The port and any path survive the rewrite.
generate_for "https://0x7f000001:8443"
expect_env 'APP_URL=https://127.0.0.1:8443'
expect_env 'REVERB_CLIENT_PORT=8443'

# The mapping prefix is 80 zero bits, ffff, then EXACTLY two groups.
# ::ffff:0:7f00:1 has an extra one, making it a different address entirely --
# reading its last two groups as the mapped value bound v4 loopback for a URL
# that names neither.
generate_for "http://[::ffff:0:7f00:1]"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=80'

# The root dot has to come off BEFORE the address is parsed, or `127.1.`
# fails to parse, the URL keeps the dot, and the bind canonicalises anyway --
# the same split-brain through a different door.
generate_for "https://127.1."
expect_env 'APP_URL=https://127.0.0.1'
expect_env 'SERVER_NAME=127.0.0.1'

# Names are normalised too: a certificate is issued for the dotless form.
generate_for "https://support.example.com."
expect_env 'APP_URL=https://support.example.com'
expect_env 'SERVER_NAME=support.example.com'

# A URL client reads 0443 as 443. Leaving the spelling alone refused a valid
# install outright, because the ACME guard compares this against "443", and
# would have let :0443 collide undetected with a bind already on 443.
generate_for "https://support.example.com:0443"
expect_env 'WAYFINDR_PUBLIC_HTTPS_BIND=443'
generate_for "https://localhost:02345"
expect_env 'WAYFINDR_PUBLIC_HTTPS_BIND=127.0.0.1:2345'
expect_env 'REVERB_CLIENT_PORT=2345'

for bad_port in "http://localhost:abc" "http://localhost:0" "http://localhost:99999"; do
    if generate_for "$bad_port"; then
        echo "Expected $bad_port to be refused as an invalid port." >&2
        exit 1
    fi
done

# Bash arithmetic is 64-bit and WRAPS, so a long enough digit string lands
# back inside the valid range: 18446744073709552059 evaluates to 443. Checked
# before the arithmetic, or the installer reports success on 443 while APP_URL
# keeps a port no client will open.
if generate_for "https://support.example.com:18446744073709552059"; then
    echo "Expected an oversized port to be refused rather than wrapped." >&2
    exit 1
fi

# The port has a canonical spelling too, and APP_URL has to carry it -- the
# whole authority is compared, not just the host.
generate_for "https://support.example.com:0000000443"
expect_env 'APP_URL=https://support.example.com:443'

# ...but a bracketed literal with NO port must not acquire one. "Does the
# authority contain a colon" is true of every IPv6 address.
generate_for "http://[::1]"
expect_env 'APP_URL=http://[::1]'

# The protocol that is NOT serving still gets published, because compose.yml
# maps both -- so its fallback port has to dodge the operator's port too, or
# Compose refuses the whole stack over a duplicate publish.
generate_for "https://wayfindr.local:18080"
expect_env 'WAYFINDR_PUBLIC_HTTPS_BIND=18080'
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:18081'
generate_for "http://192.168.10.9:18443"
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=18443'
expect_env 'WAYFINDR_PUBLIC_HTTPS_BIND=127.0.0.1:18444'

# A real domain must still go to a public CA, on the only port ACME validates.
generate_for "https://support.example.com"
expect_env 'CADDY_SERVER_EXTRA_DIRECTIVES='
expect_env 'WAYFINDR_PUBLIC_HTTPS_BIND=443'

if generate_for "https://support.example.com:8443"; then
    echo "A public certificate on a port ACME cannot validate should be refused." >&2
    exit 1
fi

# --behind-proxy still owns every bind: the operator's proxy holds the port,
# so honouring the URL's port here would fight it for the same one.
generate_for "https://support.example.com" --behind-proxy
expect_env 'WAYFINDR_PUBLIC_HTTP_BIND=127.0.0.1:18080'
expect_env 'WAYFINDR_PUBLIC_HTTPS_BIND=127.0.0.1:18443'
expect_env 'TRUSTED_PROXIES=*'

"$GENERATOR" --force --output "$ENV_FILE" --app-url "http://127.0.0.1:8000" >/dev/null

docker compose --env-file "$ENV_FILE" -f "$ROOT_DIR/docker/self-hosting/compose.yml" config --format json > "$CONFIG_JSON_FILE"

python3 - "$CONFIG_JSON_FILE" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    config = json.load(handle)

for service in ("web", "queue", "scheduler", "reverb"):
    env = config["services"][service]["environment"]

    if env["APP_KEY"] == "base64:replace-with-generated-key":
        raise SystemExit(f"{service} kept the placeholder APP_KEY")

    if env["DB_PASSWORD"] != env["POSTGRES_PASSWORD"]:
        raise SystemExit(f"{service} rendered mismatched database passwords")

    if env["REVERB_APP_SECRET"] == "replace-with-private-reverb-secret":
        raise SystemExit(f"{service} kept the placeholder Reverb secret")

# This env came from --app-url http://127.0.0.1:8000, the case where the
# operator's port collides with the loopback ops site. Compose refuses to
# start a stack that publishes one host port twice, so the proof has to be
# taken at the rendered level, not from the env file alone.
published = [
    (port.get("host_ip"), str(port.get("published")), port.get("protocol"))
    for port in config["services"]["web"]["ports"]
]

if len(published) != len(set(published)):
    raise SystemExit(f"web publishes a host port twice: {published}")

if ("127.0.0.1", "8000", "tcp") not in published:
    raise SystemExit(f"the URL's own port is not published: {published}")
PY

echo "Self-host env generator creates safe starter values and renders through Compose."
