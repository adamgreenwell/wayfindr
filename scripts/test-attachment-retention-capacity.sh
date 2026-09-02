#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
harness="$root_dir/scripts/smoke/attachment-retention-capacity.sh"
command="$root_dir/apps/server/app/Console/Commands/MeasureAttachmentRetentionCommand.php"

bash -n "$harness"

grep -F 'WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE=YES' "$harness" >/dev/null
grep -F 'LARAVEL_STORAGE_PATH=' "$harness" >/dev/null
grep -F 'wayfindr-attachment-retention-??????' "$harness" >/dev/null
grep -F 'minio/minio@sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e' "$harness" >/dev/null
grep -F 'S3-compatible measurement requires a loopback endpoint.' "$command" >/dev/null
grep -F 'SQLite must live under the operating-system temporary directory.' "$command" >/dev/null
grep -F 'Refusing to publish: HEAD changed during the measurement.' "$command" >/dev/null
grep -F "Schedule::events()" "$command" >/dev/null

echo "Attachment-retention capacity harness safety guards are intact."
