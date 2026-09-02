#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
harness="$root_dir/scripts/smoke/attachment-retention-capacity.sh"
command="$root_dir/apps/server/app/Console/Commands/MeasureAttachmentRetentionCommand.php"
reservation="$root_dir/apps/server/app/Support/Attachments/AttachmentRetentionReportReservation.php"

bash -n "$harness"

grep -F 'WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE=YES' "$harness" >/dev/null
grep -F 'LARAVEL_STORAGE_PATH=' "$harness" >/dev/null
grep -F 'APP_CONFIG_CACHE="$temporary_dir/config.php"' "$harness" >/dev/null
grep -F 'wayfindr-attachment-retention-??????' "$harness" >/dev/null
grep -F 'minio/minio@sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e' "$harness" >/dev/null
grep -F 'S3-compatible measurement requires a loopback endpoint.' "$command" >/dev/null
grep -F 'SQLite must live under the operating-system temporary directory.' "$command" >/dev/null
grep -F 'Attachment-retention measurement refuses cached configuration.' "$command" >/dev/null
grep -F 'Measurement refuses additional configured attachment disks:' "$command" >/dev/null
grep -F '$outputDirectory = realpath(dirname($output));' "$command" >/dev/null
grep -F 'AttachmentRetentionReportReservation::claim($output)' "$command" >/dev/null
grep -F "fopen(\$lockPath, 'x+b')" "$reservation" >/dev/null
grep -F 'link($temporary, $this->output)' "$reservation" >/dev/null
grep -F 'Refusing to publish: HEAD changed during the measurement.' "$command" >/dev/null
grep -F "Schedule::events()" "$command" >/dev/null

preflight_line="$(grep -nF 'artisan wayfindr:measure-attachment-retention "${measurement_options[@]}" --preflight-only' "$harness" | cut -d: -f1)"
migrate_line="$(grep -nF 'artisan migrate:fresh --force' "$harness" | cut -d: -f1)"

if [[ -z "$preflight_line" || -z "$migrate_line" || "$preflight_line" -ge "$migrate_line" ]]; then
    echo "Disposable-target preflight must run before migrate:fresh." >&2
    exit 1
fi

echo "Attachment-retention capacity harness safety guards are intact."
