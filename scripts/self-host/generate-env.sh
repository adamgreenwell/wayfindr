#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEFAULT_OUTPUT="$ROOT_DIR/docker/self-hosting/.env"

OUTPUT_FILE="$DEFAULT_OUTPUT"
APP_URL=""
APP_NAME="Wayfindr"
MAIL_FROM_ADDRESS="support@example.com"
BEHIND_PROXY=0
FORCE=0

usage() {
    cat <<'USAGE'
Usage:
  scripts/self-host/generate-env.sh --app-url <url> [options]

Options:
  --app-url <url>          Required public URL, such as https://support.example.com.
                           A bare host works too (localhost:2345, wayfindr.local):
                           loopback infers http://, anything else https://.
                           Hosts no public CA can issue for -- localhost, IP
                           literals, .local/.localhost/.internal/.home.arpa/.test,
                           and single-label names -- get a locally-issued
                           certificate, on whatever port the URL names.
  --output <path>          Env file to write. Defaults to docker/self-hosting/.env.
  --app-name <name>        Application name. Defaults to Wayfindr.
  --mail-from <email>      Mail from address placeholder. Defaults to support@example.com.
  --behind-proxy           Your own reverse proxy terminates TLS: keeps every
                           bind on loopback while APP_URL, cookies, and the
                           browser websocket values stay https.
  --force                  Overwrite the output file if it already exists.
  -h, --help               Show this help text.

The generated env is a starter file for the self-host Docker Compose prototype.
Review DNS, TLS, mail, scheduler, queue, Reverb, storage, and backups before
sending real visitor traffic to the instance.
USAGE
}

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Missing required command: $1" >&2
        exit 1
    fi
}

absolute_path() {
    local target="$1"
    local directory
    local basename

    directory="$(dirname "$target")"
    basename="$(basename "$target")"
    mkdir -p "$directory"

    directory="$(cd "$directory" && pwd -P)"
    printf '%s/%s\n' "$directory" "$basename"
}

url_scheme() {
    case "$APP_URL" in
        https://*) printf 'https\n' ;;
        http://*) printf 'http\n' ;;
        *)
            echo "--app-url must start with http:// or https://." >&2
            exit 1
            ;;
    esac
}

# Everything between the scheme and the first path segment: "support.example.com",
# "localhost:2345", "[::1]:8443". The scheme strip is a no-op on a bare host, so
# this is also usable before the scheme has been inferred.
# The authority exactly as written, userinfo included. Only the credential
# check wants this; everything else wants url_authority below.
url_authority_raw() {
    local without_scheme

    without_scheme="${APP_URL#*://}"

    # The authority ends at the first delimiter, and a query or fragment can
    # arrive with no path at all: http://localhost?preview=1 has the host
    # "localhost", but stopping only at "/" made the host
    # "localhost?preview=1" -- matching no loopback pattern, so published on
    # every interface, and written into REVERB_HOST as well.
    without_scheme="${without_scheme%%\#*}"
    without_scheme="${without_scheme%%\?*}"

    # A backslash ends the authority too. For http and https the URL standard
    # normalises it to a path separator, so a browser given
    # http://localhost\path connects to localhost -- while keeping it in the
    # host matched no loopback pattern and published on every interface.
    without_scheme="${without_scheme%%\\*}"

    printf '%s\n' "${without_scheme%%/*}"
}

url_authority() {
    local authority

    authority="$(url_authority_raw)"

    # Userinfo is part of the authority but is NOT the host: the host of
    # http://guest@localhost is localhost. Left attached, the classification
    # saw "guest@localhost", matched no loopback pattern, and published on
    # every interface while the browser still reached localhost.
    printf '%s\n' "${authority##*@}"
}

url_host() {
    local authority

    authority="$(url_authority)"

    case "$authority" in
        # An IPv6 literal is bracketed and carries colons of its own, so the
        # host/port split cannot cut at the first colon -- that returns "["
        # for http://[::1]:8443, and a "[" host poisons SERVER_NAME,
        # REVERB_HOST and every classification below it.
        \[*\]*) printf '%s\n' "${authority%%\]*}]" ;;
        *) printf '%s\n' "${authority%%:*}" ;;
    esac
}

url_port() {
    local port_digits
    local authority
    local port

    authority="$(url_authority)"
    port=""

    case "$authority" in
        \[*\]:*) port="${authority##*:}" ;;
        \[*\]) port="" ;;
        *:*) port="${authority##*:}" ;;
    esac

    if [ -n "$port" ]; then
        # Normalised to plain decimal, because everything downstream COMPARES
        # this: the ACME guard against 443, and the collision checks that keep
        # the fallback binds and the ops port clear of it. A URL client reads
        # `0443` as 443, so leaving the spelling alone refused a valid install
        # and let :0443 sit undetected on top of a bind already using 443.
        case "$port" in
            *[!0-9]*)
                echo "--app-url has a non-numeric port: $port" >&2
                exit 1
                ;;
        esac

        # Leading zeros are a valid spelling, so they come off first.
        #
        # Written as a loop rather than the nested-expansion idiom, which
        # install.sh already avoids for being one layer of quoting away from
        # silently doing nothing.
        port_digits="$port"

        while :; do
            case "$port_digits" in
                0?*) port_digits="${port_digits#0}" ;;
                *) break ;;
            esac
        done

        # The LENGTH is checked before the arithmetic, because bash arithmetic
        # is 64-bit and WRAPS. A long enough digit string lands back inside the
        # valid range -- 18446744073709552059 evaluates to 443 -- and would
        # then pass every check after it, publishing on 443 while APP_URL kept
        # a port no client will accept. An install reporting success at a URL
        # that cannot be opened is the worst shape this can fail in.
        if [ "${#port_digits}" -gt 5 ]; then
            echo "--app-url port is out of range: $port" >&2
            exit 1
        fi

        port=$((10#$port_digits))

        if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
            echo "--app-url port is out of range: $port" >&2
            exit 1
        fi

        printf '%s\n' "$port"
    elif [ "$SCHEME" = "https" ]; then
        printf '443\n'
    else
        printf '80\n'
    fi
}

# The comparison copy of a host: brackets stripped and folded to lower case.
#
# Brackets belong in a URL and in a Caddy site address but never in a match
# against a bare address. Case matters because DNS is case-INsensitive while
# shell globs are not: `http://LOCALHOST` is the same host as `localhost`, and
# failing to match it published the plain-HTTP service on every interface --
# the classification failing open, on the one input that most clearly means
# "this machine only".
bare_host() {
    local host="$1"

    host="${host#"["}"
    host="${host%"]"}"

    # A trailing dot is the DNS root, and `localhost.` is the valid absolute
    # spelling of `localhost` -- left on, it matched no pattern and the host
    # was published on every interface. Exactly one is stripped: "localhost.."
    # is not a name worth accommodating.
    host="${host%.}"

    printf '%s' "$host" | tr 'A-Z' 'a-z'
    printf '\n'
}

strip_leading_zeros() {
    local value="$1"

    while :; do
        case "$value" in
            0?*) value="${value#0}" ;;
            *) break ;;
        esac
    done

    printf '%s\n' "$value"
}

# One component of an IPv4 literal, in the bases inet_aton accepts: decimal,
# octal with a leading zero, or hex with an 0x prefix. Prints its value, or
# fails if this is not a number at all.
#
# Each base carries a digit-count limit, checked BEFORE the arithmetic runs.
# Bash arithmetic is 64-bit and wraps, so without this a long enough component
# lands back inside the 32-bit range and passes the validation below it -- the
# same trap the port parsing hit, where 18446744073709552059 evaluated to 443.
# The limits are simply how many digits a 32-bit value takes in each base.
ipv4_component() {
    local part="$1"
    local digits

    case "$part" in
        '') return 1 ;;
        0[xX]*)
            digits="$(strip_leading_zeros "${part#0[xX]}")"

            case "$digits" in
                ''|*[!0-9a-fA-F]*) return 1 ;;
            esac

            [ "${#digits}" -le 8 ] || return 1

            printf '%d\n' "$((16#$digits))"
            ;;
        0)
            printf '0\n'
            ;;
        0*)
            digits="$(strip_leading_zeros "${part#0}")"

            case "$digits" in
                *[!0-7]*) return 1 ;;
            esac

            [ -n "$digits" ] || digits=0
            [ "${#digits}" -le 11 ] || return 1

            printf '%d\n' "$((8#$digits))"
            ;;
        *[!0-9]*) return 1 ;;
        *)
            digits="$(strip_leading_zeros "$part")"

            [ "${#digits}" -le 10 ] || return 1

            printf '%d\n' "$((10#$digits))"
            ;;
    esac
}

# An IPv4 literal written any way a URL parser accepts, as a dotted quad --
# or failure if this is not an IPv4 literal at all.
#
# inet_aton takes one to four parts, each decimal, octal or hex, and packs the
# LAST one into whatever bits remain: 127.0.0.1, 127.1, 0177.0.0.1,
# 2130706433 and 0x7f000001 are all the same address. Checking only for a
# dotted quad read the other four as hostnames and published them on every
# interface -- the IPv4 half of the mistake the IPv6 folding above prevents.
#
# The canonical form, not just a verdict, because the caller needs an address
# Docker will actually listen on: `0177.0.0.1` is loopback but not something
# to hand to a bind.
ipv4_canonical() {
    local raw="$1"
    local count value p1 p2 p3 p4

    case "$raw" in
        ''|.*|*.|*..*) return 1 ;;
        *[!0-9a-fA-FxX.]*) return 1 ;;
    esac

    local IFS=.
    set -- $raw
    unset IFS

    count=$#
    [ "$count" -ge 1 ] && [ "$count" -le 4 ] || return 1

    # Every component has to parse, or this is a hostname that merely looks
    # numeric -- 127.example.com must not read as an address.
    p1="$(ipv4_component "$1")" || return 1

    case "$count" in
        1)
            value="$p1"
            ;;
        2)
            p2="$(ipv4_component "$2")" || return 1
            { [ "$p1" -le 255 ] && [ "$p2" -le 16777215 ]; } || return 1
            value=$(((p1 << 24) | p2))
            ;;
        3)
            p2="$(ipv4_component "$2")" || return 1
            p3="$(ipv4_component "$3")" || return 1
            { [ "$p1" -le 255 ] && [ "$p2" -le 255 ] && [ "$p3" -le 65535 ]; } || return 1
            value=$(((p1 << 24) | (p2 << 16) | p3))
            ;;
        *)
            p2="$(ipv4_component "$2")" || return 1
            p3="$(ipv4_component "$3")" || return 1
            p4="$(ipv4_component "$4")" || return 1
            { [ "$p1" -le 255 ] && [ "$p2" -le 255 ] && [ "$p3" -le 255 ] && [ "$p4" -le 255 ]; } || return 1
            value=$(((p1 << 24) | (p2 << 16) | (p3 << 8) | p4))
            ;;
    esac

    [ "$value" -ge 0 ] && [ "$value" -le 4294967295 ] || return 1

    printf '%d.%d.%d.%d\n' \
        "$(((value >> 24) & 255))" "$(((value >> 16) & 255))" \
        "$(((value >> 8) & 255))" "$((value & 255))"
}

# Rewrite a trailing dotted quad as the two hex groups it stands for, so
# everything downstream only ever has to reason about hex.
#
# This exists because enumerating spellings was a losing game. ::ffff:127.0.0.1
# and ::ffff:7f00:1 are the same address, as are ::1, 0:0:0:0:0:0:0:1 and
# 0000:...:0001 -- and each spelling that was handled separately left the next
# one as a fresh fail-open bug. Folding to ONE representation answers all of
# them at once, and the questions below get asked a single way.
ipv6_fold_mapped() {
    local addr="$1"
    local dotted a b c d octet

    case "$addr" in
        *:*.*.*.*) ;;
        *) printf '%s\n' "$addr"; return 0 ;;
    esac

    dotted="${addr##*:}"

    IFS='.' read -r a b c d <<EOF
$dotted
EOF

    for octet in "$a" "$b" "$c" "$d"; do
        case "$octet" in
            ''|*[!0-9]*) printf '%s\n' "$addr"; return 0 ;;
        esac

        [ "$octet" -le 255 ] || { printf '%s\n' "$addr"; return 0; }
    done

    printf '%s:%x:%x\n' "${addr%:*}" "$((a * 256 + b))" "$((c * 256 + d))"
}

# The IPv4 address embedded in an IPv4-mapped literal, or nothing.
ipv6_mapped_ipv4() {
    local addr high low

    addr="$(ipv6_fold_mapped "$1")"

    case "$addr" in
        *:ffff:*:*) ;;
        *) return 1 ;;
    esac

    # The mapping prefix is 80 zero bits then ffff then EXACTLY two groups.
    # Without the count, ::ffff:0:7f00:1 -- a distinct address with an extra
    # group -- passed the zero-prefix test and had its last two groups read as
    # the mapped address, binding v4 loopback for a URL that names neither.
    case "${addr#*:ffff:}" in
        *:*:*) return 1 ;;
        *:*) ;;
        *) return 1 ;;
    esac

    low="${addr##*:}"
    high="${addr%:*}"
    high="${high##*:}"

    case "$high$low" in
        ''|*[!0-9a-f]*) return 1 ;;
    esac

    # Everything above the mapping prefix has to be zero, or this is some
    # other address that merely contains an ffff group.
    case "${addr%%:ffff:*}" in
        *[!0:]*) return 1 ;;
    esac

    printf '%d.%d.%d.%d\n' \
        "$(((0x$high >> 8) & 255))" "$((0x$high & 255))" \
        "$(((0x$low >> 8) & 255))" "$((0x$low & 255))"
}

# Does this IPv4 address mean "this machine"?
#
# 127/8 is loopback. 0/8 is the unspecified range, which as a DESTINATION also
# means this host -- a client given 0.0.0.0 reaches the local machine.
#
# This lives in one place because FOUR call sites need the answer: the plain
# literal, the IPv4-mapped literal, and the bind for each. Written out
# separately at each of them, one was missed every single time -- first the
# plain 0.0.0.0 case, then the mapped one.
ipv4_is_local() {
    case "$1" in
        127.*|0.*) return 0 ;;
        *) return 1 ;;
    esac
}

# ...and may it be used verbatim as a BIND?
#
# Only 127/8. The same 0.0.0.0 that means "this host" as a destination is the
# WILDCARD as a bind, covering every interface -- so binding the literal would
# produce the exposure the classification exists to prevent. It falls back to
# the v4 loopback instead, exactly as [::] does.
ipv4_is_bindable_loopback() {
    case "$1" in
        127.*) return 0 ;;
        *) return 1 ;;
    esac
}

# The address Docker should publish on for a loopback host.
#
# Normally the literal itself, so [::1] stays on the v6 loopback rather than
# silently moving to the v4 one. An IPv4-MAPPED literal is the exception and
# has to be unwrapped: Docker refuses [::ffff:127.0.0.1] outright ("ports are
# not available"), and the address a client reaches through it is the embedded
# 127.0.0.1 anyway -- so binding that is both accepted and correct.
loopback_bind_address() {
    local host mapped canonical

    host="$(bare_host "$1")"

    if mapped="$(ipv6_mapped_ipv4 "$host")"; then
        if ipv4_is_bindable_loopback "$mapped"; then
            printf '%s\n' "$mapped"
        else
            printf '127.0.0.1\n'
        fi

        return 0
    fi

    case "$host" in
        *:*)
            # ::1 is the only listenable IPv6 loopback. The rest of what the
            # ::/96 backstop accepts is not assigned to any interface, so
            # Docker refuses to listen on it -- and `::` is the WILDCARD,
            # which Docker takes happily and publishes on every interface.
            # Binding the literal there would turn the safety net into the
            # exposure it exists to prevent, so anything that is not ::1
            # falls back to the v4 loopback: always listenable, always
            # private, and these addresses were never reachable anyway.
            if ipv6_is_loopback "$host"; then
                printf '[%s]\n' "$host"
            else
                printf '127.0.0.1\n'
            fi
            ;;
        *)
            # Canonicalised, because a shorthand literal is loopback without
            # being bindable: Docker gets 127.0.0.1, never `0177.0.0.1`.
            canonical="$(ipv4_canonical "$host" 2>/dev/null || true)"

            if [ -z "$canonical" ]; then
                printf '%s\n' "$host"
            elif ipv4_is_bindable_loopback "$canonical"; then
                printf '%s\n' "$canonical"
            else
                printf '127.0.0.1\n'
            fi
            ;;
    esac
}

# Whether the top 96 bits are zero -- the ::/96 range, which holds ::1, ::
# itself, and the deprecated IPv4-compatible forms like ::127.0.0.1.
#
# This is the backstop that ends the enumeration. Nothing in ::/96 is a
# routable interface address: it either means this machine or means nothing at
# all, so binding it to loopback is right in the first case and harmless in the
# second. "All interfaces" is the only answer that could be actively wrong,
# which is why the unrecognised spelling lands here rather than in the open.
ipv6_high_bits_zero() {
    local addr head tail group

    addr="$(ipv6_fold_mapped "$1")"

    case "$addr" in
        *::*)
            # `::` may sit anywhere, not only at the front: 0::1 is a valid
            # spelling of ::1, and matching only a LEADING :: missed it while
            # the eight-group branch below could not see it either -- so a
            # loopback address published on every interface.
            #
            # Splitting at the :: gives the two halves. The tail occupies the
            # LAST groups, so more than two of them puts something above the
            # low 32 bits; whatever is written before the :: has to be zeros.
            head="${addr%%::*}"
            tail="${addr##*::}"

            case "$tail" in
                *:*:*) return 1 ;;
            esac

            if [ -n "$head" ]; then
                local IFS=:
                set -- $head
                unset IFS

                for group in "$@"; do
                    case "$group" in
                        ''|*[!0]*) return 1 ;;
                    esac
                done
            fi

            return 0
            ;;
    esac

    # Written out in full: the first six groups must all be zero, however
    # many leading zeros each was padded with.
    local IFS=:
    set -- $addr
    unset IFS

    [ "$#" -eq 8 ] || return 1

    for group in "$1" "$2" "$3" "$4" "$5" "$6"; do
        case "$group" in
            ''|*[!0]*) return 1 ;;
        esac
    done

    return 0
}

# Whether an IPv6 address is ::1, however it happens to be spelled.
#
# Asks the only question that matters: is the final group 1, and is everything
# before it zeros? The final-group test is what keeps `1::` (a real,
# non-loopback address that is all zeros and a one) from matching -- its last
# group is empty, not 1.
ipv6_is_loopback() {
    local addr head last

    addr="$(ipv6_fold_mapped "$1")"
    last="${addr##*:}"
    head="${addr%:*}"

    case "$last" in
        1|01|001|0001) ;;
        *) return 1 ;;
    esac

    # Zeros and colons only. Rejects an empty head, so a bare "1" -- which has
    # no colon and so survives the strip unchanged -- is not read as an address.
    case "$head" in
        "") return 1 ;;
        *[!0:]*) return 1 ;;
    esac

    return 0
}

# Whether this host can only ever mean "this machine", which is what decides
# between a loopback publish and one on every interface.
#
# "127." has to be matched as an ADDRESS, not as a prefix. A DNS label may
# legally begin with a digit, so the glob 127.* also matches the real,
# publicly-resolvable hostname 127.example.com -- and classifying that as
# loopback would publish a public site on 127.0.0.1 only, leaving it
# unreachable through the DNS name it was installed under.
#
# *.localhost belongs here too. RFC 6761 requires those names to resolve to
# loopback, so binding them to every interface exposes ports for a name
# nothing off this machine can resolve to reach anyway.
host_is_loopback() {
    local host mapped

    host="$(bare_host "$1")"

    case "$host" in
        localhost|*.localhost) return 0 ;;
        *:*)
            # An IPv4-mapped literal is loopback exactly when the address it
            # embeds is, whichever way that address happens to be written --
            # ::ffff:127.0.0.1 and ::ffff:7f00:1 are the same thing. A mapped
            # address that is NOT loopback is a real routable one, so it
            # answers here rather than falling through to the backstop.
            mapped="$(ipv6_mapped_ipv4 "$host")" || mapped=""

            if [ -n "$mapped" ]; then
                ipv4_is_local "$mapped"
                return $?
            fi

            ipv6_high_bits_zero "$host"
            ;;
        *)
            # Any spelling of an address in 127/8, plus 0/8.
            #
            # 0.0.0.0 is the IPv4 counterpart of :: and carries the same split
            # meaning: as a DESTINATION it is this host, which is what an
            # operator typing it asks for, but as a BIND it is the wildcard.
            # Leaving it out while :: was confined to loopback was the
            # inconsistency, not the fix. Nothing else in 0/8 is a usable
            # destination either, so the whole range answers the same way --
            # the same reasoning as the ::/96 backstop.
            ipv4_is_local "$(ipv4_canonical "$host" 2>/dev/null || true)"
            ;;
    esac
}

host_is_ip_literal() {
    local host

    host="$(bare_host "$1")"

    case "$host" in
        *:*) return 0 ;;
        [0-9]*.[0-9]*.[0-9]*.[0-9]*)
            case "$host" in
                *[!0-9.]*) return 1 ;;
                *) return 0 ;;
            esac
            ;;
        *) return 1 ;;
    esac
}

# Whether a public certificate authority could ever issue for this host.
#
# Wrong in either direction is expensive. Call a real domain internal and a
# public site quietly serves a certificate no visitor's browser trusts. Call
# an unreachable name public and Caddy retries an ACME challenge that cannot
# succeed, which is how a rate limit gets burned for the whole domain.
#
# So the question is "can a CA validate this from the internet?", not "does it
# look local": IP literals (no CA issues for private space), the suffixes
# reserved by RFC 6761/6762/8375, and any single-label name -- which has no
# registrable domain to validate in the first place.
host_is_internal() {
    local host

    host="$(bare_host "$1")"

    host_is_ip_literal "$host" && return 0

    # An address written in one of inet_aton's shorthand forms is still an
    # address, and no public CA issues for one -- without this, https://127.1
    # would be sent to ACME. host_is_ip_literal stays strict because it also
    # governs what is used as a BIND address, where only forms Docker accepts
    # are safe; certificate eligibility is a different question with a
    # different answer.
    ipv4_canonical "$host" >/dev/null 2>&1 && return 0

    case "$host" in
        localhost|*.localhost) return 0 ;;
        *.local|*.internal|*.home.arpa) return 0 ;;
        *.test|*.example|*.invalid) return 0 ;;
        # RFC 7686. A Tor onion service is reached through Tor, never through
        # a CA-validatable path, so ACME could only fail here.
        *.onion) return 0 ;;
        # RFC 9476 reserves .alt for namespaces outside the global DNS, so
        # there is nothing for a public CA to validate against.
        *.alt) return 0 ;;
        # The apex of the one reserved suffix that is not a single label.
        # `local`, `internal`, `test` and friends are caught by the
        # single-label branch below; `home.arpa` would have fallen through to
        # `*.*` and been sent to a public CA that will never issue for it.
        home.arpa) return 0 ;;
        *.*) return 1 ;;
        *) return 0 ;;
    esac
}

# A loopback port for the protocol that is NOT serving, chosen clear of the
# ports already claimed.
#
# compose.yml maps both protocols unconditionally, so the inactive one still
# has to be published somewhere -- and Compose refuses the entire stack over a
# duplicate host port. Handing back a fixed 18080/18443 meant
# `--app-url https://wayfindr.local:18080` claimed 18080 twice and could not
# start at all. Preference order keeps the familiar port when nothing collides.
pick_spare_port() {
    local preferred="$1"
    shift

    local claimed=" $* "
    local candidate offset

    for offset in 0 1 2 3; do
        candidate=$((preferred + offset))

        case "$claimed" in
            *" $candidate "*) continue ;;
        esac

        printf '%s\n' "$candidate"
        return 0
    done

    echo "Could not place the unused $preferred bind clear of$claimed. Choose a different --app-url port." >&2
    exit 1
}

# Accept a bare host and infer the scheme, then say which one was chosen.
#
# `--app-url localhost` is the first thing an operator types, and refusing it
# taught them nothing they could act on. Loopback infers http://: a smoke test
# or a workstation should reach a page before it is asked to trust anything.
# Every other host infers https://, the only safe default for a name another
# machine resolves. The inference is always announced -- a wrong guess about
# TLS has to be visible now, not at first login.
normalize_app_url() {
    case "$APP_URL" in
        http://*|https://*) return 0 ;;
        *://*)
            echo "--app-url supports http:// and https:// only." >&2
            exit 1
            ;;
        */*)
            echo "--app-url needs a scheme when it includes a path, such as https://$APP_URL." >&2
            exit 1
            ;;
    esac

    # Only a BARE host reaches here, which is what makes the bracketing below
    # safe: the scheme's own colon would otherwise count toward the test and
    # wrap a whole URL, turning http://[::1]:8443 into [http://[::1]:8443].
    #
    # A bare IPv6 literal cannot be a URL host without brackets -- unbracketed,
    # the host/port split cuts at the first colon and yields an EMPTY host, so
    # `--app-url ::1` announced "installing as https://::1" and then died with
    # "must include a host". docs/self-hosting/install.md lists ::1 among the
    # loopback hosts a bare argument accepts, making this a documented path.
    #
    # Two or more colons and no bracket means the whole argument is the
    # address: RFC 3986 requires brackets before a port can be appended, so
    # there is no port here to keep separate.
    case "$APP_URL" in
        \[*) ;;
        *:*:*) APP_URL="[$APP_URL]" ;;
    esac

    if host_is_loopback "$(url_host)"; then
        APP_URL="http://$APP_URL"
    else
        APP_URL="https://$APP_URL"
    fi

    echo "No scheme given; installing as $APP_URL." >&2
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --app-url)
            APP_URL="${2:-}"
            shift 2
            ;;
        --output)
            OUTPUT_FILE="${2:-}"
            shift 2
            ;;
        --app-name)
            APP_NAME="${2:-}"
            shift 2
            ;;
        --mail-from)
            MAIL_FROM_ADDRESS="${2:-}"
            shift 2
            ;;
        --behind-proxy)
            BEHIND_PROXY=1
            shift
            ;;
        --force)
            FORCE=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [ -z "$APP_URL" ]; then
    echo "--app-url is required." >&2
    usage >&2
    exit 1
fi

require_command openssl

normalize_app_url

OUTPUT_FILE="$(absolute_path "$OUTPUT_FILE")"
SCHEME="$(url_scheme)"
HOST="$(url_host)"

if [ -z "$HOST" ] || [ "$HOST" = "[]" ]; then
    echo "--app-url must include a host." >&2
    exit 1
fi

# Spellings this script will not pretend to canonicalise are REFUSED, not
# guessed at.
#
# A browser decodes http://local%68ost to localhost and percent-encoding is a
# valid host spelling, so letting it through unchanged classified a loopback
# name as public and published it on every interface. The fix is not to write
# a percent-decoder and an IDNA implementation in shell to keep pace: every
# parser added here has needed its own correction, and a decoder that is
# subtly wrong about a SECURITY classification is worse than no decoder. The
# script asks for the form it can reason about instead, which is also the form
# the operator will see in their browser.
case "$HOST" in
    *%*)
        echo "--app-url must use a decoded host: write local%68ost as localhost." >&2
        exit 1
        ;;
esac

# The authority parsing above stops at these, so they no longer corrupt the
# host -- but a base URL carrying a query or fragment is still meaningless,
# and silently dropping part of what the operator typed is worse than saying
# so. APP_URL feeds every generated link.
case "$APP_URL" in
    *\?*|*\#*)
        echo "--app-url must not include a query string or fragment." >&2
        exit 1
        ;;
esac

# Credentials would be written into the env file, echoed in the installer's
# output, and baked into every link the application generates. They are
# refused rather than quietly stripped: dropping them silently would leave an
# operator believing the install is authenticated in some way it is not.
case "$(url_authority_raw)" in
    *@*)
        echo "--app-url must not include credentials." >&2
        exit 1
        ;;
esac

# Parsed correctly above, but still refused: a backslash in a URL is a typo
# worth naming rather than silently reinterpreting, and APP_URL feeds every
# generated link.
case "$APP_URL" in
    *\\*)
        echo '--app-url must use / rather than \ as a path separator.' >&2
        exit 1
        ;;
esac

if [ -n "$(printf '%s' "$HOST" | tr -d '\000-\177')" ]; then
    echo "--app-url must use the punycode (xn--) form of an internationalised host." >&2
    exit 1
fi

# A shorthand address is canonicalised before anything downstream sees it.
#
# Browsers do this themselves: https://127.1 leaves the client as a request
# for 127.0.0.1, so a Caddy site and a certificate named `127.1` would never
# match it, even once the local CA is trusted. The BIND was already
# canonicalised, which is what makes this necessary rather than cosmetic --
# otherwise the two halves of the same install disagree about its address.
# The root dot goes first, and from the ALREADY-stripped form.
#
# Passing the raw host to the parser meant `127.1.` failed to parse, so the
# URL kept the trailing dot while the bind canonicalised anyway -- the same
# split-brain this block exists to prevent, reached through a different door.
# Names get the same treatment: a certificate is issued for the dotless form.
NORMALISED_HOST="${HOST%.}"

if CANONICAL_IPV4="$(ipv4_canonical "$NORMALISED_HOST" 2>/dev/null)"; then
    NORMALISED_HOST="$CANONICAL_IPV4"
fi

# Compared as a whole AUTHORITY, not just the host, because the port has a
# canonical spelling too: `:0443` normalises to 443 for every comparison the
# script makes, and leaving it raw in APP_URL puts the two halves back out of
# step in the one place this block exists to keep them aligned.
#
# The port test is bracket-aware. Asking "does the authority contain a colon"
# is true of every IPv6 literal, which would have appended a port nobody
# asked for -- http://[::1] becoming http://[::1]:80.
APP_URL_AUTHORITY="$(url_authority)"
APP_URL_PORT=""

case "$APP_URL_AUTHORITY" in
    \[*\]:*) APP_URL_PORT=":$(url_port)" ;;
    \[*\]) ;;
    *:*) APP_URL_PORT=":$(url_port)" ;;
esac

NORMALISED_AUTHORITY="${NORMALISED_HOST}${APP_URL_PORT}"

if [ "$NORMALISED_AUTHORITY" != "$APP_URL_AUTHORITY" ]; then
    APP_URL_PATH="${APP_URL#*://}"
    APP_URL_PATH="${APP_URL_PATH#"$APP_URL_AUTHORITY"}"

    APP_URL="${SCHEME}://${NORMALISED_AUTHORITY}${APP_URL_PATH}"
    HOST="$NORMALISED_HOST"

    echo "Canonicalised the address; installing as $APP_URL." >&2
fi

if [ -e "$OUTPUT_FILE" ] && [ "$FORCE" != "1" ]; then
    echo "$OUTPUT_FILE already exists. Re-run with --force to overwrite it." >&2
    exit 1
fi

APP_KEY="base64:$(openssl rand -base64 32)"
DB_PASSWORD="$(openssl rand -hex 24)"
REVERB_APP_KEY="$(openssl rand -hex 16)"
REVERB_APP_SECRET="$(openssl rand -hex 32)"
PUBLIC_PORT="$(url_port)"
REVERB_PORT="$PUBLIC_PORT"

# The TLS knob (see docker/self-hosting/Caddyfile). Who terminates TLS is
# separate from the public scheme: --behind-proxy keeps every bind on
# loopback while cookies and browser websocket values follow the https
# APP_URL, and TRUSTED_PROXIES lets Laravel honor X-Forwarded-* from the
# operator's proxy. Without the flag, an https URL means FrankenPHP binds
# the public port and obtains certificates itself.
TRUSTED_PROXIES=""
CADDY_GLOBAL_OPTIONS=""
CADDY_SERVER_EXTRA_DIRECTIVES=""
INTERNAL_CERT=0

if [ "$SCHEME" = "https" ]; then
    SECURE_COOKIE="true"
else
    SECURE_COOKIE="false"
fi

# A loopback host publishes on loopback. "localhost" names an interface no
# other machine can reach, so binding it to every interface would expose a
# machine the operator just described as private.
#
# A loopback IP literal binds to ITSELF rather than to 127.0.0.1: ::1 and
# 127.0.0.1 are both loopback but they are not the same address, and
# publishing an operator's [::1] URL on the v4 loopback yields a stack that is
# running, healthy, and unreachable at the URL they typed.
#
# A ROUTABLE literal deliberately does not get the same treatment. Binding
# 203.0.113.10 because the URL says so fails outright on every NAT'd cloud
# instance, where the public address the operator browses to is not on any
# local interface -- "cannot assign requested address", at `up` time, from a
# URL that was perfectly correct.
if host_is_loopback "$HOST"; then
    if host_is_ip_literal "$HOST"; then
        BIND_PREFIX="$(loopback_bind_address "$HOST"):"
    else
        BIND_PREFIX="127.0.0.1:"
    fi
else
    BIND_PREFIX=""
fi

if [ "$SCHEME" = "https" ] && [ "$BEHIND_PROXY" != "1" ] && host_is_internal "$HOST"; then
    INTERNAL_CERT=1
fi

# ACME validates over ports 80 and 443 only, so a PUBLIC certificate on any
# other port simply cannot be issued -- that is a fact about the protocol and
# the refusal below is correct. An internally-issued certificate involves no
# challenge at all, so it is served wherever the operator asked for it, and
# https://localhost:2345 is a perfectly coherent request.
if [ "$SCHEME" = "https" ] && [ "$BEHIND_PROXY" != "1" ] \
    && [ "$INTERNAL_CERT" != "1" ] && [ "$PUBLIC_PORT" != "443" ]; then
    echo "A publicly-issued certificate for $HOST can only be obtained on port 443; port $PUBLIC_PORT needs --behind-proxy (your proxy owns that port) or a portless URL." >&2
    exit 1
fi

# The container always listens on 80 and 443; the operator's port lives on the
# HOST side of the publish. Keeping the container ports fixed is what lets a
# custom port work without Caddy having to agree about it -- the certificate
# covers a hostname, not a port, so SNI still matches on the way through.
if [ "$BEHIND_PROXY" = "1" ]; then
    SERVER_NAME=":80"
    HTTP_BIND="127.0.0.1:18080"
    HTTPS_BIND="127.0.0.1:18443"
    TRUSTED_PROXIES="*"
elif [ "$SCHEME" = "https" ]; then
    SERVER_NAME="$HOST"
    HTTPS_BIND="${BIND_PREFIX}${PUBLIC_PORT}"

    if [ "$PUBLIC_PORT" = "443" ]; then
        # Port 80 carries the HTTP->HTTPS redirect, plus the ACME HTTP-01
        # challenge when the certificate is public.
        HTTP_BIND="${BIND_PREFIX}80"
    else
        # A custom HTTPS port means something already owns 443 on this
        # machine; claiming 80 as well would be a second collision nobody
        # asked for. An internal certificate needs no challenge on it.
        HTTP_BIND="127.0.0.1:$(pick_spare_port 18080 "$PUBLIC_PORT")"
    fi

    if [ "$INTERNAL_CERT" = "1" ]; then
        # Stated outright rather than left to Caddy's own "is this name
        # public" classification: this file decides, and the decision stays
        # auditable in the generated env instead of living in a dependency's
        # heuristics. skip_install_trust stops Caddy trying to write its root
        # into the CONTAINER's trust store on every boot -- it cannot, that is
        # not where the root would help anyone, and the failure reads like a
        # real error in the logs.
        CADDY_SERVER_EXTRA_DIRECTIVES="tls internal"
        CADDY_GLOBAL_OPTIONS="skip_install_trust"
    fi
else
    SERVER_NAME=":80"
    HTTP_BIND="${BIND_PREFIX}${PUBLIC_PORT}"
    HTTPS_BIND="127.0.0.1:$(pick_spare_port 18443 "$PUBLIC_PORT")"
fi

# The always-on loopback ops site -- health probes, and the upstream a
# --behind-proxy install points at -- must not land on a port the public bind
# already claimed. Now that the public port comes from the operator's URL,
# `--app-url http://127.0.0.1:8000` would publish 8000 twice and Compose would
# refuse to start the stack at all. The installer reads this value back for
# its own health probe, so moving it is safe; guessing it is not.
LOCAL_BIND=""
CLAIMED_PORTS=" ${HTTP_BIND##*:} ${HTTPS_BIND##*:} "

for candidate in 8000 18000 18001 18002; do
    case "$CLAIMED_PORTS" in
        *" $candidate "*) continue ;;
    esac

    LOCAL_BIND="127.0.0.1:$candidate"
    break
done

if [ -z "$LOCAL_BIND" ]; then
    echo "Could not place the loopback ops port clear of $CLAIMED_PORTS. Choose a different --app-url port." >&2
    exit 1
fi

umask 077

cat > "$OUTPUT_FILE" <<ENV
WAYFINDR_IMAGE=ghcr.io/adamgreenwell/wayfindr:latest
WAYFINDR_ENV_FILE=$OUTPUT_FILE
SERVER_NAME=$SERVER_NAME
WAYFINDR_PUBLIC_HTTP_BIND=$HTTP_BIND
WAYFINDR_PUBLIC_HTTPS_BIND=$HTTPS_BIND
WAYFINDR_LOCAL_BIND=$LOCAL_BIND
# Caddy knobs (docker/self-hosting/Caddyfile). 'tls internal' means this host
# is one no public CA can issue for, so Caddy signs with its own CA -- the
# root has to be trusted on each machine that browses here.
CADDY_GLOBAL_OPTIONS=$CADDY_GLOBAL_OPTIONS
CADDY_SERVER_EXTRA_DIRECTIVES=$CADDY_SERVER_EXTRA_DIRECTIVES
WAYFINDR_PHP_VERSION=8.4
WAYFINDR_NODE_VERSION=24

APP_NAME=$APP_NAME
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=$APP_URL
TRUSTED_PROXIES=$TRUSTED_PROXIES
# WAYFINDR_VERSION / WAYFINDR_COMMIT are baked into the official image and
# shown on /operator. Setting them here would OVERRIDE the image values with
# whatever you write (including blank) — only do that for custom builds.

DB_DATABASE=wayfindr
DB_USERNAME=wayfindr
DB_PASSWORD=$DB_PASSWORD

POSTGRES_DB=wayfindr
POSTGRES_USER=wayfindr
POSTGRES_PASSWORD=$DB_PASSWORD

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=$SECURE_COOKIE
SESSION_SAME_SITE=lax

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=redis
REDIS_PASSWORD=
REDIS_PORT=6379

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=wayfindr-production
REVERB_APP_KEY=$REVERB_APP_KEY
REVERB_APP_SECRET=$REVERB_APP_SECRET
REVERB_HOST=$HOST
REVERB_PORT=$REVERB_PORT
REVERB_SCHEME=$SCHEME
REVERB_CLIENT_HOST=$HOST
REVERB_CLIENT_PORT=$REVERB_PORT
REVERB_CLIENT_SCHEME=$SCHEME
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_SERVER_PATH=

MAIL_MAILER=log
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_SCHEME=
MAIL_FROM_ADDRESS=$MAIL_FROM_ADDRESS
MAIL_FROM_NAME="\${APP_NAME}"
ENV

# Only one of the two binds is the one Caddy actually serves the public site
# on; naming the other is how an operator ends up debugging a port nothing
# was ever listening on.
if [ "$BEHIND_PROXY" = "1" ]; then
    PUBLISHED_ON="proxy upstream $LOCAL_BIND"
elif [ "$SCHEME" = "https" ]; then
    PUBLISHED_ON="published on $HTTPS_BIND"
else
    PUBLISHED_ON="published on $HTTP_BIND"
fi

cat <<EOF
Generated $OUTPUT_FILE.

Serving $APP_URL ($PUBLISHED_ON).
EOF

if [ "$INTERNAL_CERT" = "1" ]; then
    cat <<EOF

No public certificate authority can issue for $HOST, so Caddy signs with its
own CA. Browsers will warn until that root is trusted. Once the stack is up,
export it and add it to the trust store of each machine that browses here:

  docker compose -f docker/self-hosting/compose.yml --env-file $OUTPUT_FILE \\
    cp web:/data/caddy/pki/authorities/local/root.crt ./wayfindr-local-ca.crt
EOF
fi

cat <<EOF

Next steps before real traffic:
- Review APP_URL, DNS, TLS, and WebSocket proxy routing.
- Configure outbound mail and run the Wayfindr mail smoke test.
- Start the Compose stack, run migrations, and confirm the scheduler.
- Visit /setup to create the first operator/account owner.
- Plan backups, restore drills, storage durability, logs, and upgrades.
EOF
