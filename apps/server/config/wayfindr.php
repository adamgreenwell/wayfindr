<?php

use App\Support\ReleaseIdentity;

return [
    'documentation' => [
        'forge_url' => env('WAYFINDR_FORGE_DOCS_URL', 'https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/laravel-forge.md'),
        'runtime_requirements_url' => env('WAYFINDR_RUNTIME_REQUIREMENTS_DOCS_URL', 'https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/runtime-requirements.md'),
        'self_hosting_url' => env('WAYFINDR_SELF_HOSTING_DOCS_URL', 'https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/install.md'),
    ],

    'data_responsibility' => [
        'label' => 'Operator reminder',
        'message' => 'Retaining visitor-supplied data may create privacy, security, and legal obligations.',
        'guidance' => 'Keep only what you need, set a retention period you can justify, and make sure your privacy notice matches how this Wayfindr installation is used.',
        'docs_url' => 'https://github.com/adamgreenwell/wayfindr/blob/main/docs/privacy/data-responsibility.md',
    ],

    'cobrowse' => [
        'content_retention_hours' => (int) env('WAYFINDR_COBROWSE_CONTENT_RETENTION_HOURS', 72),
        'session_idle_expiry_minutes' => (int) env('WAYFINDR_COBROWSE_SESSION_IDLE_EXPIRY_MINUTES', 15),
    ],

    'retention' => [
        'label' => 'Operator-owned retention',
        'status' => 'manual',
        'summary' => 'Cobrowse page content is pruned automatically; broader retention stays operator-owned.',
        'description' => 'Assume application records, logs, and backups persist according to infrastructure defaults until an operator removes them or the host lifecycle removes them.',
        'docs_url' => 'https://github.com/adamgreenwell/wayfindr/blob/main/docs/privacy/data-inventory.md#retention-posture',
        'items' => [
            [
                'label' => 'Application records',
                'value' => 'Manual lifecycle',
                'description' => 'Conversations, messages, tickets, visitors, cobrowse metadata, and audit records stay in the application database until an operator removes or prunes them.',
            ],
            [
                'label' => 'Logs and backups',
                'value' => 'Infrastructure lifecycle',
                'description' => 'Server logs, snapshots, database dumps, and storage backups follow host and provider retention policies outside Wayfindr.',
            ],
            [
                'label' => 'Cobrowse page content',
                'value' => 'Auto-pruned '.((int) env('WAYFINDR_COBROWSE_CONTENT_RETENTION_HOURS', 72)).' hours after a session ends',
                'description' => 'The scheduled wayfindr:prune-cobrowse-content command strips raw snapshot HTML, page text, and retained mutation batches from ended cobrowse sessions, keeping only content-free provenance (counts, timestamps, hashes, and audit events).',
            ],
            [
                'label' => 'Automatic deletion',
                'value' => 'Cobrowse content only',
                'description' => 'Beyond cobrowse page content, deletion, export, and retention controls remain future work; explain that before real support traffic.',
            ],
        ],
        'reminders' => [
            'Review privacy notices before real visitor traffic reaches the install.',
            'Keep retention expectations aligned with backups, logs, and support workflows.',
        ],
    ],

    'widget_rate_limits' => [
        'bootstrap_per_minute' => (int) env('WAYFINDR_WIDGET_BOOTSTRAP_RATE_LIMIT', 120),
        'broadcast_auth_per_minute' => (int) env('WAYFINDR_WIDGET_BROADCAST_AUTH_RATE_LIMIT', 120),
        'conversation_per_minute' => (int) env('WAYFINDR_WIDGET_CONVERSATION_RATE_LIMIT', 30),
        'message_per_minute' => (int) env('WAYFINDR_WIDGET_MESSAGE_RATE_LIMIT', 240),
        'cobrowse_per_minute' => (int) env('WAYFINDR_WIDGET_COBROWSE_RATE_LIMIT', 1200),
        'attachment_per_minute' => (int) env('WAYFINDR_WIDGET_ATTACHMENT_RATE_LIMIT', 600),
        'attachment_upload_per_minute' => (int) env('WAYFINDR_WIDGET_ATTACHMENT_UPLOAD_RATE_LIMIT', 60),
    ],

    // Conversation message attachments (ADR 0007). Limits are server-enforced
    // and independent of the client; the allowlist is matched against the
    // SERVER-detected MIME (never the client's Content-Type).
    'attachments' => [
        // Which filesystem disk NEW uploads land on: 'attachments' (local
        // private disk, the default) or 'attachments-s3' (S3-compatible).
        // Every row records its own disk, so switching this affects only new
        // uploads — existing files keep serving from their recorded home, and
        // no migration is forced. Unknown or unsafe values fail loud at upload
        // time and surface on readiness rather than landing files somewhere
        // unintended.
        'storage_disk' => env('WAYFINDR_ATTACHMENT_STORAGE_DISK', 'attachments'),

        'max_file_bytes' => (int) env('WAYFINDR_ATTACHMENT_MAX_FILE_BYTES', 10 * 1024 * 1024),
        'max_per_message' => (int) env('WAYFINDR_ATTACHMENT_MAX_PER_MESSAGE', 5),
        'max_conversation_bytes' => (int) env('WAYFINDR_ATTACHMENT_MAX_CONVERSATION_BYTES', 100 * 1024 * 1024),

        // Default allowlist: images, PDF, and plain text/log. SVG, HTML,
        // archives, and executables are deliberately excluded (active-content
        // and decompression-bomb vectors). Operators may extend this per install.
        'allowed_mime_types' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'WAYFINDR_ATTACHMENT_ALLOWED_MIME_TYPES',
                'image/png,image/jpeg,image/gif,image/webp,application/pdf,text/plain'
            ))
        ))),

        // Retention sweep (wayfindr:sweep-orphaned-attachments). An unbound
        // upload older than this was abandoned before its message was sent and
        // is removed with its binary. A stored file with no row is only deleted
        // once older than the grace window, so an in-flight upload (file written,
        // row not yet committed) is never swept out from under itself.
        'pending_expiry_hours' => (int) env('WAYFINDR_ATTACHMENT_PENDING_EXPIRY_HOURS', 24),
        'orphan_grace_hours' => (int) env('WAYFINDR_ATTACHMENT_ORPHAN_GRACE_HOURS', 1),

        // Pluggable malware scanner (ADR 0007). Default is null: accept with
        // defense-in-depth (the allowlist/private-storage/forced-download/nosniff
        // protections stand, and readiness surfaces that no scanner is
        // configured). Set to 'clamav' to scan every upload synchronously against
        // a local clamd before it is stored.
        'scanner' => [
            'driver' => env('WAYFINDR_ATTACHMENT_SCANNER'),

            // When the scanner is unreachable: fail_closed (default) rejects the
            // upload rather than store an unscanned file; false accepts it (still
            // logged). Only an explicit false-y value opens the gate — blank,
            // unset, or invalid values stay fail-closed (the safe default). A
            // configured-but-unreachable scanner also shows on readiness.
            'fail_closed' => ! in_array(
                strtolower(trim((string) env('WAYFINDR_ATTACHMENT_SCANNER_FAIL_CLOSED'))),
                ['false', '0', 'no', 'off'],
                true,
            ),

            'timeout_seconds' => (int) env('WAYFINDR_ATTACHMENT_SCANNER_TIMEOUT', 30),

            'clamav' => [
                // clamd address: tcp://host:port or unix:///path/to/clamd.ctl
                'socket' => env('WAYFINDR_CLAMAV_SOCKET', 'tcp://127.0.0.1:3310'),
            ],
        ],
    ],

    // Resolved through ReleaseIdentity so a blank env_file override falls
    // back to the identity baked into the official image (see the class).
    'release' => [
        'commit' => ReleaseIdentity::commit(),
        'version' => ReleaseIdentity::version(),
    ],
];
