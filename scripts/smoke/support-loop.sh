#!/usr/bin/env bash
set -euo pipefail

base_url="${WAYFINDR_BASE_URL:?Set WAYFINDR_BASE_URL, for example https://support.example.com}"
site_public_key="${WAYFINDR_SITE_PUBLIC_KEY:?Set WAYFINDR_SITE_PUBLIC_KEY}"
agent_email="${WAYFINDR_AGENT_EMAIL:?Set WAYFINDR_AGENT_EMAIL}"
agent_password="${WAYFINDR_AGENT_PASSWORD:?Set WAYFINDR_AGENT_PASSWORD}"
host_page_url="${WAYFINDR_HOST_PAGE_URL:-}"
subject="${WAYFINDR_SMOKE_SUBJECT:-MVP support loop smoke}"
message="${WAYFINDR_SMOKE_MESSAGE:-Hello from the Wayfindr support-loop smoke.}"
ticket_category="${WAYFINDR_SMOKE_TICKET_CATEGORY:-question}"
ticket_priority="${WAYFINDR_SMOKE_TICKET_PRIORITY:-normal}"
anonymous_id="${WAYFINDR_ANONYMOUS_ID:-anon-support-loop-smoke-$(date +%s)}"
tmp_dir="$(mktemp -d)"
cookie_jar="$tmp_dir/cookies.txt"

base_url="${base_url%/}"
page_url="${host_page_url:-$base_url/smoke}"

cleanup() {
    rm -rf "$tmp_dir"
}

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Missing required command: $1" >&2
        exit 1
    fi
}

json_value() {
    local path="$1"

    php -r '
        $payload = json_decode(stream_get_contents(STDIN), true);
        $value = $payload;

        foreach (explode(".", $argv[1]) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                exit(0);
            }

            $value = $value[$part];
        }

        if (is_scalar($value)) {
            echo $value;
        }
    ' "$path"
}

json_object() {
    php -r '
        $pairs = array_slice($argv, 1);
        $payload = [];

        for ($i = 0; $i < count($pairs); $i += 2) {
            $payload[$pairs[$i]] = $pairs[$i + 1];
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    ' "$@"
}

urlencode() {
    php -r 'echo rawurlencode($argv[1]);' "$1"
}

csrf_token_from() {
    php -r '
        $html = file_get_contents($argv[1]);

        if (preg_match("/<meta name=\"csrf-token\" content=\"([^\"]+)\"/", $html, $matches)) {
            echo $matches[1];
            exit(0);
        }

        fwrite(STDERR, "Unable to find CSRF token in {$argv[1]}.\n");
        exit(1);
    ' "$1"
}

assert_contains() {
    local file="$1"
    local needle="$2"
    local label="$3"

    if ! grep -F "$needle" "$file" >/dev/null; then
        echo "Expected $label to contain: $needle" >&2
        echo "--- $label excerpt ---" >&2
        sed -n '1,80p' "$file" >&2
        exit 1
    fi
}

resolve_url() {
    php -r '
        $base = $argv[1];
        $src = $argv[2];

        if (preg_match("#^https?://#i", $src)) {
            echo $src;
            exit(0);
        }

        $parts = parse_url($base);
        $origin = $parts["scheme"]."://".$parts["host"].(isset($parts["port"]) ? ":".$parts["port"] : "");

        if (str_starts_with($src, "//")) {
            echo $parts["scheme"].":".$src;
            exit(0);
        }

        if (str_starts_with($src, "/")) {
            echo $origin.$src;
            exit(0);
        }

        $path = $parts["path"] ?? "/";
        $dir = preg_replace("#/[^/]*$#", "/", $path);

        echo $origin.$dir.$src;
    ' "$1" "$2"
}

post_json() {
    local route_path="$1"
    local payload="$2"
    local response_file="$tmp_dir/response-$(date +%s%N).json"
    local http_code

    http_code="$(
        curl -sS \
            -o "$response_file" \
            -w '%{http_code}' \
            -H 'Accept: application/json' \
            -H 'Content-Type: application/json' \
            -X POST \
            --data "$payload" \
            "$base_url$route_path"
    )"

    case "$http_code" in
        2*) cat "$response_file" ;;
        *)
            echo "POST $route_path failed with HTTP $http_code." >&2
            cat "$response_file" >&2
            exit 1
            ;;
    esac
}

verify_host_page_widget_config() {
    if [[ -z "$host_page_url" ]]; then
        return 0
    fi

    local html_file="$tmp_dir/host-page.html"
    local scripts_file="$tmp_dir/host-scripts.txt"
    local script_file="$tmp_dir/host-script.js"

    echo "Checking host page widget config..."
    curl -fsS -L "$host_page_url" -o "$html_file"

    if grep -F "$site_public_key" "$html_file" >/dev/null && grep -F "$base_url" "$html_file" >/dev/null; then
        return 0
    fi

    php -r '
        $html = file_get_contents($argv[1]);

        if (preg_match_all("/<script[^>]+src=[\"\\x27]([^\"\\x27]+)[\"\\x27]/i", $html, $matches)) {
            echo implode(PHP_EOL, $matches[1]);
        }
    ' "$html_file" > "$scripts_file"

    while IFS= read -r script_src || [[ -n "$script_src" ]]; do
        [[ -z "$script_src" ]] && continue

        script_url="$(resolve_url "$host_page_url" "$script_src")"

        if curl -fsS -L "$script_url" -o "$script_file" 2>/dev/null; then
            if grep -F "$site_public_key" "$script_file" >/dev/null && grep -F "$base_url" "$script_file" >/dev/null; then
                return 0
            fi
        fi
    done < "$scripts_file"

    echo "Host page did not expose the expected Wayfindr base URL and site public key." >&2
    exit 1
}

require_command curl
require_command grep
require_command php

trap cleanup EXIT

verify_host_page_widget_config

echo "Checking health endpoint..."
curl -fsS "$base_url/up" >/dev/null

echo "Bootstrapping widget visitor..."
bootstrap_payload="$(json_object \
    site_public_key "$site_public_key" \
    anonymous_id "$anonymous_id" \
    page_url "$page_url")"
bootstrap_response="$(post_json '/api/widget/bootstrap' "$bootstrap_payload")"
visitor_token="$(printf '%s' "$bootstrap_response" | json_value 'data.visitor.token')"

if [[ -z "$visitor_token" ]]; then
    echo "Unable to read visitor token from bootstrap response." >&2
    exit 1
fi

echo "Creating visitor conversation..."
conversation_payload="$(json_object \
    site_public_key "$site_public_key" \
    anonymous_id "$anonymous_id" \
    visitor_token "$visitor_token" \
    subject "$subject" \
    page_url "$page_url")"
conversation_response="$(post_json '/api/conversations' "$conversation_payload")"
support_code="$(printf '%s' "$conversation_response" | json_value 'data.support_code')"

if [[ -z "$support_code" ]]; then
    echo "Unable to read support_code from conversation response." >&2
    exit 1
fi

support_code_path="$(urlencode "$support_code")"

echo "Sending visitor message to $support_code..."
message_payload="$(json_object \
    site_public_key "$site_public_key" \
    anonymous_id "$anonymous_id" \
    visitor_token "$visitor_token" \
    body "$message")"
post_json "/api/conversations/$support_code_path/messages" "$message_payload" >/dev/null

echo "Signing in as agent..."
login_page="$tmp_dir/login.html"
curl -fsS -c "$cookie_jar" "$base_url/login" -o "$login_page"
csrf_token="$(csrf_token_from "$login_page")"

curl -sS \
    -b "$cookie_jar" \
    -c "$cookie_jar" \
    -o "$tmp_dir/login-post.html" \
    -X POST \
    -H "X-CSRF-TOKEN: $csrf_token" \
    --data-urlencode "email=$agent_email" \
    --data-urlencode "password=$agent_password" \
    "$base_url/login" >/dev/null

dashboard_page="$tmp_dir/dashboard.html"
dashboard_code="$(curl -sS -b "$cookie_jar" -o "$dashboard_page" -w '%{http_code}' "$base_url/dashboard")"

if [[ "$dashboard_code" != "200" ]]; then
    echo "Agent dashboard did not load after login. HTTP $dashboard_code." >&2
    sed -n '1,80p' "$dashboard_page" >&2
    exit 1
fi

assert_contains "$dashboard_page" "Workspace shortcuts" "agent dashboard"

echo "Opening agent conversation..."
conversation_page="$tmp_dir/conversation.html"
conversation_code="$(curl -sS -b "$cookie_jar" -o "$conversation_page" -w '%{http_code}' "$base_url/dashboard/conversations/$support_code_path")"

if [[ "$conversation_code" != "200" ]]; then
    echo "Agent conversation did not load. HTTP $conversation_code." >&2
    sed -n '1,80p' "$conversation_page" >&2
    exit 1
fi

assert_contains "$conversation_page" "$support_code" "agent conversation"
assert_contains "$conversation_page" "$message" "agent conversation"

echo "Creating ticket from conversation..."
csrf_token="$(csrf_token_from "$conversation_page")"
ticket_create_response="$tmp_dir/ticket-create-response.html"
ticket_create_code="$(
    curl -sS \
        -b "$cookie_jar" \
        -c "$cookie_jar" \
        -o "$ticket_create_response" \
        -w '%{http_code}' \
        -X POST \
        -H "X-CSRF-TOKEN: $csrf_token" \
        --data-urlencode "category=$ticket_category" \
        --data-urlencode "priority=$ticket_priority" \
        "$base_url/dashboard/conversations/$support_code_path/tickets"
)"

if [[ "$ticket_create_code" != "302" ]]; then
    echo "Ticket create flow did not redirect after POST. HTTP $ticket_create_code." >&2
    sed -n '1,80p' "$ticket_create_response" >&2
    exit 1
fi

ticket_create_page="$tmp_dir/ticket-create.html"
conversation_code="$(curl -sS -b "$cookie_jar" -o "$ticket_create_page" -w '%{http_code}' "$base_url/dashboard/conversations/$support_code_path")"

if [[ "$conversation_code" != "200" ]]; then
    echo "Conversation did not reload after ticket creation. HTTP $conversation_code." >&2
    sed -n '1,80p' "$ticket_create_page" >&2
    exit 1
fi

ticket_path="$(
    php -r '
        $html = file_get_contents($argv[1]);

        if (preg_match("#/dashboard/tickets/[0-9]+#", $html, $matches)) {
            echo $matches[0];
        }
    ' "$ticket_create_page"
)"

if [[ -z "$ticket_path" ]]; then
    echo "Unable to find linked ticket URL after ticket creation." >&2
    sed -n '1,120p' "$ticket_create_page" >&2
    exit 1
fi

echo "Opening ticket detail..."
ticket_page="$tmp_dir/ticket.html"
ticket_code="$(curl -sS -b "$cookie_jar" -o "$ticket_page" -w '%{http_code}' "$base_url$ticket_path")"

if [[ "$ticket_code" != "200" ]]; then
    echo "Ticket detail did not load. HTTP $ticket_code." >&2
    sed -n '1,80p' "$ticket_page" >&2
    exit 1
fi

assert_contains "$ticket_page" "Support artifacts" "ticket detail"
assert_contains "$ticket_page" "$support_code" "ticket detail"

echo "Support-loop smoke passed."
echo "Support code: $support_code"
echo "Ticket URL: $base_url$ticket_path"
