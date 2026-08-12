#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNNER="$ROOT_DIR/scripts/smoke/disposable-vm-evidence-runner.sh"

# Sourcing the runner loads its value helpers without starting an evidence run.
# shellcheck disable=SC1090
. "$RUNNER"

scenarios=(
    clean-install-latest
    upgrade-v0.2.0-latest-custom-backup-queue
    recovery-latest-synthetic-skew-restore
    recovery-latest-v0.3.1-image-rollback-retry
    upgrade-v0.1.0-latest
    upgrade-v0.1.0-v0.2.0-latest
)

for scenario in "${scenarios[@]}"; do
    slug="$(sanitize_name "$scenario")"
    run_id="20260812t17591786557592z-$slug"
    email="$(default_agent_email "$run_id")"
    local_part="${email%@*}"

    if [ "${#local_part}" -gt 64 ]; then
        echo "Generated evidence email local part is too long for $scenario: ${#local_part}" >&2
        exit 1
    fi

    php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_EMAIL) === false ? 1 : 0);' "$email"
done

if grep -Eq '^require_command[[:space:]]+php$' "$ROOT_DIR/scripts/smoke/public-artifact-install.sh"; then
    echo "The public-artifact harness must not require host PHP; PHP runs in the application container." >&2
    exit 1
fi

echo "Disposable VM evidence defaults stay valid on a Docker-only host."
