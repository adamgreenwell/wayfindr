#!/usr/bin/env bash
set -euo pipefail

base_url="${WAYFINDR_BASE_URL:?Set WAYFINDR_BASE_URL, for example https://support.example.com}"
site_public_key="${WAYFINDR_SITE_PUBLIC_KEY:?Set WAYFINDR_SITE_PUBLIC_KEY}"
anonymous_id="anon-smoke-$(date +%s)"

json_post() {
    local path="$1"
    local payload="$2"

    curl -fsS \
        -H 'Accept: application/json' \
        -H 'Content-Type: application/json' \
        -X POST \
        --data "$payload" \
        "${base_url%/}${path}"
}

echo "Checking health endpoint..."
curl -fsS "${base_url%/}/up" >/dev/null

echo "Bootstrapping widget visitor..."
bootstrap_response="$(json_post '/api/widget/bootstrap' "{
    \"site_public_key\": \"${site_public_key}\",
    \"anonymous_id\": \"${anonymous_id}\",
    \"page_url\": \"${base_url%/}/smoke\"
}")"

visitor_token="$(printf '%s' "$bootstrap_response" | php -r '$payload = json_decode(stream_get_contents(STDIN), true); echo $payload["data"]["visitor"]["token"] ?? "";')"

if [[ -z "$visitor_token" ]]; then
    echo "Unable to read visitor token from bootstrap response." >&2
    exit 1
fi

echo "Creating conversation..."
conversation_response="$(json_post '/api/conversations' "{
    \"site_public_key\": \"${site_public_key}\",
    \"anonymous_id\": \"${anonymous_id}\",
    \"visitor_token\": \"${visitor_token}\",
    \"subject\": \"Forge smoke test\",
    \"page_url\": \"${base_url%/}/smoke\"
}")"

support_code="$(printf '%s' "$conversation_response" | php -r '$payload = json_decode(stream_get_contents(STDIN), true); echo $payload["data"]["support_code"] ?? "";')"

if [[ -z "$support_code" ]]; then
    echo "Unable to read support_code from conversation response." >&2
    exit 1
fi

echo "Sending message to ${support_code}..."
json_post "/api/conversations/${support_code}/messages" "{
    \"site_public_key\": \"${site_public_key}\",
    \"anonymous_id\": \"${anonymous_id}\",
    \"visitor_token\": \"${visitor_token}\",
    \"body\": \"Hello from the Forge smoke test.\"
}" >/dev/null

echo "Smoke test passed for ${support_code}."
