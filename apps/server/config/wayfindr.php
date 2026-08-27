<?php

use App\Support\ReleaseIdentity;

return [

    'mail' => [
        // Shared with the mail provider that posts inbound messages. Empty
        // means the channel is off, and the endpoint answers 404 rather than
        // standing open.
        'inbound_secret' => env('WAYFINDR_INBOUND_MAIL_SECRET', ''),
    ],

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

    // Per token per minute, for the public API (ADR 0018). Separate from the
    // widget limits above on purpose: those protect a visitor's browser from a
    // mistake, this protects an account's data from a script.
    'api_rate_limit' => (int) env('WAYFINDR_API_RATE_LIMIT', 120),

    // FAILED authentication attempts per minute per IP. Only failures spend
    // it, so a working integration never touches this however much traffic it
    // sends -- and going over it refuses credentials that do not authenticate
    // rather than the address, so one broken script cannot lock every other
    // integration behind the same NAT out of its own account.
    'api_failed_auth_per_minute' => (int) env('WAYFINDR_API_FAILED_AUTH_PER_MINUTE', 60),

    // The install's own default dashboard language, read from the environment
    // rather than from `app.locale` at runtime: `App::setLocale()` MUTATES that
    // config value, so a request rendered for a German agent leaves it saying
    // "de" and the next agent with no preference inherits a language they never
    // chose. Laravel never touches this key.
    'dashboard_locale' => (string) env('APP_LOCALE', 'en'),

    'widget_rate_limits' => [
        'bootstrap_per_minute' => (int) env('WAYFINDR_WIDGET_BOOTSTRAP_RATE_LIMIT', 120),
        // Per VISITOR, not per source IP -- see the widget-presence limiter.
        // A 45-second cadence is 1.33 a minute per open tab, and tabs of one
        // browser share an anonymous ID, so this is roughly twenty tabs' worth.
        'presence_per_minute' => (int) env('WAYFINDR_WIDGET_PRESENCE_PER_MINUTE', 30),

        // The abuse cap, per source IP and site. Covers about nine hundred
        // simultaneous visitors behind one address at the standard cadence;
        // an install that genuinely has more raises it rather than watching
        // its board flicker.
        'presence_per_ip_per_minute' => (int) env('WAYFINDR_WIDGET_PRESENCE_PER_IP_PER_MINUTE', 1200),
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
    'presence' => [
        // ADR 0019 §4. Shortening is an operator's to choose; lengthening is
        // not, and the command clamps to the stated maximum regardless of what
        // is set here.
        'retention_days' => (int) env('WAYFINDR_PRESENCE_RETENTION_DAYS', 30),
    ],

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

    'backup' => [
        // Where wayfindr:backup writes archives. Map a host path here to keep
        // backups off the container's ephemeral layer; the default lives in
        // the durable storage volume (ADR 0009).
        'path' => env('WAYFINDR_BACKUP_PATH', storage_path('app/backups')),

        // Optional offsite mirror (ADR 0010): a configured filesystem disk
        // (S3/R2/MinIO/any) the finished archive is uploaded to after the local
        // write. Unset = local-only. The local copy is always retained.
        'disk' => env('WAYFINDR_BACKUP_DISK'),

        // Age-based retention (ADR 0010): after a successful backup, prune
        // archives older than this many days on BOTH the local path and the
        // remote disk. 0/unset = keep everything (the operator prunes).
        'retention_days' => (int) env('WAYFINDR_BACKUP_RETENTION_DAYS', 0),

        // Per-install namespace for offsite archives (ADR 0010). Uploads land
        // under this key prefix and retention only ever prunes within it, so
        // two installs can share one backup disk/bucket without pruning each
        // other's archives. Unset = a stable prefix derived from APP_KEY.
        'prefix' => env('WAYFINDR_BACKUP_PREFIX'),

        // Max seconds the queued "run a backup now" job may run (ADR 0011). A
        // backup (dump + archive + offsite upload) can far exceed the default
        // 90s worker timeout, so the job sets its own generous timeout, which
        // overrides the worker's --timeout for that job. Raise it for very large
        // installs.
        'job_timeout' => (int) env('WAYFINDR_BACKUP_JOB_TIMEOUT', 3600),

        // Lifetime of the instance-wide serialization lock that keeps two
        // backups from running at once (ADR 0011). It MUST exceed the longest a
        // backup can take, for BOTH entry points: the queued job (bounded by
        // job_timeout) and the scheduled wayfindr:backup command (which has no
        // job timeout — it runs until it finishes). If a long scheduled backup
        // outlives this lock, a second backup could acquire the lock and run
        // concurrently, so operators with multi-hour backups should raise this
        // to cover them. It also bounds how long a crashed backup blocks the
        // next one, so it is not simply "infinite". Defaults above job_timeout.
        'lock_ttl' => (int) env('WAYFINDR_BACKUP_LOCK_TTL', (int) env('WAYFINDR_BACKUP_JOB_TIMEOUT', 3600) + 300),

        // Cache drivers the in-GUI restore trusts to hold its status and lock
        // (ADR 0011 slice 3b). A restore reloads the database, so the cache MUST
        // (a) survive that — not the `database` driver — and (b) be shared between
        // the web process that records the status/lock and the worker that runs
        // the restore — not process-local `array`/`null`, and not a wrapper like
        // `failover` whose members can't be vouched for here. So it is an
        // allowlist of network-shared stores; anything else sends the operator to
        // the CLI. (The test suite runs on the array cache in a single process
        // and adds 'array' to this list.)
        'restore_safe_cache_drivers' => ['redis', 'memcached', 'dynamodb'],

        // The in-GUI restore enters maintenance mode from the worker; the marker
        // must be visible to the web process too. A `cache`-driver maintenance
        // store on a shared cache is provably cross-process, but the default
        // `file` driver is only shared when the web and worker processes share the
        // storage volume — a deployment fact the app cannot detect. Set this true
        // ONLY when they do (the shipped compose mounts one storage volume across
        // every app service); otherwise file maintenance is rejected and the
        // operator is sent to the CLI. Ignored when the maintenance driver is
        // `cache` on a shared store.
        'restore_file_maintenance_shared' => (bool) env('WAYFINDR_RESTORE_FILE_MAINTENANCE_SHARED', false),

        // How long the "a restore is pending" claim is held so the GUI accepts
        // only one confirmed restore at a time (ADR 0011). It must outlast a
        // realistic queue wait (a restore can sit behind a running backup on the
        // shared worker) PLUS the restore's own run. If it lapses and a newer
        // restore claims the slot, the older job validates ownership on start and
        // aborts as superseded (rather than restoring a second archive back to
        // back). Defaults to twice the lock lifetime; raise it for deep queues.
        'restore_pending_ttl' => (int) env('WAYFINDR_RESTORE_PENDING_TTL', 2 * (int) env('WAYFINDR_BACKUP_LOCK_TTL', (int) env('WAYFINDR_BACKUP_JOB_TIMEOUT', 3600) + 300)),

        // Seconds the restore waits after entering maintenance mode, before it
        // touches the database, to let HTTP requests that were already in flight
        // when maintenance engaged finish writing (maintenance mode only blocks
        // NEW requests). Raise it if you serve long uploads/requests; the safest
        // restore is still run during a quiet window.
        'restore_drain_seconds' => (int) env('WAYFINDR_RESTORE_DRAIN_SECONDS', 5),
    ],

    // Resolved through ReleaseIdentity so a blank env_file override falls
    // back to the identity baked into the official image (see the class).
    'release' => [
        'commit' => ReleaseIdentity::commit(),
        'version' => ReleaseIdentity::version(),

        // Actions the operator asserts they have completed, as a comma-separated
        // list of `<release>/<action-id>` (ADR 0013). Read before migrations
        // run, so it cannot live in the database — env is the one channel that
        // exists at that point. Scoped per action so it can never become a
        // blanket opt-out.
        'acknowledged_actions' => env('WAYFINDR_ACKNOWLEDGED_ACTIONS'),

        // Where an install predating the state file states it is upgrading FROM.
        // Only consulted when nothing is recorded, and only so the floor check
        // has something to verify against — without it, an install whose origin
        // cannot be established is refused, since "unknown" is not evidence the
        // jump is supported.
        'upgrade_from' => env('WAYFINDR_UPGRADE_FROM'),

        // Where this install records the release it last ran, so the next one
        // knows where the upgrade started. On the storage volume because
        // /etc/wayfindr is root-owned and the app runs unprivileged.
        'state_path' => env('WAYFINDR_RELEASE_STATE_PATH', storage_path('app/release-state.json')),

        // Baked by the image build. Overridable so tests can point at fixtures;
        // operators have no reason to move them.
        'manifest_path' => env('WAYFINDR_RELEASE_MANIFEST_PATH', '/etc/wayfindr/release.json'),
        'history_path' => env('WAYFINDR_RELEASE_HISTORY_PATH', '/etc/wayfindr/release-history.json'),

        // Where the same two files live in a source deployment, which has no
        // /etc/wayfindr because only the image build writes there. Without these
        // the guard finds no manifest on a host install and enforces nothing —
        // silently, on exactly the deployments the installer preflight does not
        // cover. The history is committed; the deploy generates the manifest.
        //
        // Set to an empty string to disable the fallback, which is what the test
        // suite does so a fixture's absence is not quietly answered by the real
        // repository files.
        'manifest_fallback_path' => env('WAYFINDR_RELEASE_MANIFEST_FALLBACK', base_path('../../release-manifest.json')),
        'history_fallback_path' => env('WAYFINDR_RELEASE_HISTORY_FALLBACK', base_path('../../releases/history.json')),
    ],
];
