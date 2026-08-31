<?php

namespace App\Support;

use App\Models\OperatorReadinessConfirmation;
use App\Models\User;
use App\Support\Attachments\AttachmentStorage;
use App\Support\Attachments\Scanning\AttachmentScanner;
use App\Support\Settings\OperatorSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class OperatorReadiness
{
    private const CONFIRMATION_STALE_AFTER_DAYS = [
        'scheduler' => 7,
        'backups_restore' => 30,
        // A dedicated attestation for the guided onboarding checklist that a
        // queue worker AND the scheduler are running — kept distinct from the
        // 'scheduler' key so scheduler-only proof can't satisfy worker readiness.
        'background_workers' => 7,
    ];

    /** @var array<string, OperatorReadinessConfirmation> */
    private array $confirmations = [];

    public function __construct(
        private readonly RealtimeHealth $realtimeHealth,
        private readonly CobrowseTransportReadiness $cobrowseTransportReadiness,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function confirmableKeys(): array
    {
        return array_keys(self::CONFIRMATION_STALE_AFTER_DAYS);
    }

    /**
     * @return array{
     *     attention_count: int,
     *     check_attention_count: int,
     *     checks: array<int, array{action: string, commands?: array<int, string>, detail: string, key: string, label: string, status: string, status_label: string, summary: string}>,
     *     cobrowse_budget_defaults: array<int, array{description: string, items: array<int, array{label: string, value: string}>, label: string}>,
     *     dogfood_summary: array{attention_count: int, items: array<int, array{action: string, commands: array<int, string>, detail: string, docs_url: string|null, key: string, label: string, status: string, status_label: string, summary: string}>, label: string, manual_count: int, ready_count: int, status: string, summary: string},
     *     label: string,
     *     manual_count: int,
     *     next_step: array{action: string, commands?: array<int, string>, detail: string, key: string, label: string, status: string, status_label: string, summary: string},
     *     proof_coverage: array{fresh_count: int, items: array<int, array{key: string, label: string, note_status: string, status: string, status_label: string, summary: string}>, missing_count: int, stale_count: int},
     *     ready_count: int,
     *     retention_needs_attention: bool,
     *     retention_summary: array{description: string, docs_url: string|null, items: array<int, array{description: string, label: string, value: string}>, label: string, reminders: array<int, string>, status: string, status_label: string, summary: string},
     *     smoke_path: array<int, array{action: string, commands: array<int, string>, key: string, label: string, status: string, status_label: string, summary: string}>
     * }
     */
    public function summary(): array
    {
        $this->loadConfirmations();

        $checks = [
            $this->applicationKey(),
            $this->publicUrl(),
            $this->securityPosture(),
            $this->databaseConnection(),
            $this->cacheStore(),
            $this->mailTransport(),
            $this->queueWorker(),
            $this->realtimeBroadcasting(),
            $this->cobrowseTransportReadiness->check(),
            $this->storagePaths(),
            $this->attachmentStorage(),
            $this->attachmentScanning(),
            $this->scheduler(),
            $this->alertDigestDelivery(),
            $this->backupsRestore(),
            // Both surfaces or neither. Present only on the checklist, this
            // step let the two readiness screens contradict each other about
            // the same install: onboarding asking an operator to confirm the
            // clock while the console next to it reported Ready, kept the
            // warning out of the Health tab, and never offered it as the next
            // step. Whichever screen an operator happened to open decided
            // whether the install had a problem.
            $this->languageAndRegion(),
        ];

        $retentionSummary = $this->retentionSummary();
        $retentionNeedsAttention = ($retentionSummary['status'] ?? null) === 'attention';

        $checkAttentionCount = count(array_filter($checks, fn (array $check): bool => $check['status'] === 'attention'));
        $attentionCount = $checkAttentionCount + ($retentionNeedsAttention ? 1 : 0);
        $manualCount = count(array_filter($checks, fn (array $check): bool => $check['status'] === 'manual'));
        $readyCount = count(array_filter($checks, fn (array $check): bool => $check['status'] === 'ready'));
        $checksByKey = collect($checks)->keyBy('key')->all();
        $smokePath = $this->postInstallSmokePath($checksByKey);
        $smokePathByKey = collect($smokePath)->keyBy('key')->all();

        return [
            'attention_count' => $attentionCount,
            // attention_count covers the whole install, so it answers the
            // top-line status. These two say WHERE the problem is, which is
            // what a per-section badge needs: the checks and the retention
            // summary are read on different screens.
            'check_attention_count' => $checkAttentionCount,
            'checks' => $checks,
            'retention_needs_attention' => $retentionNeedsAttention,
            'cobrowse_budget_defaults' => CobrowsePayloadBudget::readinessDefaults(),
            'dogfood_summary' => $this->dogfoodSummary($checksByKey, $smokePathByKey),
            'label' => $attentionCount > 0 ? 'Needs attention' : 'Ready',
            'manual_count' => $manualCount,
            'next_step' => $this->nextStep($checks, $smokePath, $retentionSummary),
            'proof_coverage' => $this->proofCoverage($checks),
            'ready_count' => $readyCount,
            'retention_summary' => $retentionSummary,
            'smoke_path' => $smokePath,
        ];
    }

    private function loadConfirmations(): void
    {
        try {
            if (! Schema::hasTable('operator_readiness_confirmations')) {
                $this->confirmations = [];

                return;
            }

            $this->confirmations = OperatorReadinessConfirmation::query()
                ->with('confirmedBy')
                ->get()
                ->keyBy('key')
                ->all();
        } catch (Throwable) {
            $this->confirmations = [];
        }
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function applicationKey(): array
    {
        if ($this->hasValue(config('app.key'))) {
            return $this->check(
                key: 'application_key',
                label: 'Application key',
                status: 'ready',
                summary: 'APP_KEY is set.',
                detail: 'Encrypted cookies, sessions, and signed data have an application key available.',
                action: 'Keep this value secret and stable between deploys.'
            );
        }

        return $this->check(
            key: 'application_key',
            label: 'Application key',
            status: 'attention',
            summary: 'APP_KEY is missing.',
            detail: 'Laravel cannot safely encrypt sessions, cookies, or signed data without an application key.',
            action: 'Run php artisan key:generate and save the generated APP_KEY in the environment.',
            commands: ['php artisan key:generate']
        );
    }

    /**
     * Whether anyone has said what language and clock this install reads in.
     *
     * The only checklist item that is a CHOICE rather than a condition, and it
     * earns its place for the reason it is easy to leave out: an install that
     * guessed wrong is not broken, it is merely foreign, so nobody reports it.
     * An agent in Berlin reading `14:32` assumes that is how the product works.
     *
     * "Attention" therefore means "we guessed and nobody has confirmed it",
     * not "something is wrong" -- and an operator for whom English and UTC are
     * right clears it by agreeing once, which is the whole point of asking.
     *
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function languageAndRegion(): array
    {
        // The settings store is READ here, in the request, and the store can
        // be down. With `SESSION_DRIVER=database` and `CACHE_STORE=redis` --
        // both documented -- an operator stays signed in through a Redis
        // outage, and this is the page they open when something is wrong. The
        // provider's boot-time catch does not cover a later read, so without
        // this the checklist answers 500 exactly when it is needed.
        //
        // Unreachable is not the same as unconfirmed, but it is not "ready"
        // either: we genuinely do not know what was chosen, and the env
        // fallback is what the dashboard is serving meanwhile. Saying so is
        // the honest answer.
        try {
            $settings = app(OperatorSettings::class);

            $language = DashboardLanguage::normalise($settings->effective('localization.language'));
            $timezone = DashboardTimezone::normalise($settings->effective('localization.timezone'));

            // Stored AND still resolvable. A tzdata update can retire a zone
            // the operator chose in good faith, and then a presence check
            // alone reads as confirmed while the normalisation below quietly
            // serves UTC instead -- hiding the one thing this step exists to
            // surface, an unexpected clock nobody agreed to.
            $chosen = $settings->isSet('localization.language')
                && $settings->isSet('localization.timezone')
                && $language !== null
                && $timezone !== null;
        } catch (Throwable) {
            // Not null. When the store is unreachable the dashboard does not
            // fall back to English and UTC -- it falls back to whatever the
            // ENVIRONMENT configured, which is exactly what the provider left
            // standing in config. Reporting the hardcoded defaults here would
            // misstate the language and clock this install is actually serving,
            // during the one outage this branch exists to survive.
            $language = DashboardLanguage::normalise(config('wayfindr.dashboard_locale'));
            $timezone = DashboardTimezone::normalise(config('wayfindr.dashboard_timezone'));

            // Still unconfirmed, whatever those turn out to be: what the
            // operator chose is precisely what could not be read.
            $chosen = false;
        }

        $language ??= DashboardLanguage::FALLBACK;
        $timezone ??= DashboardTimezone::FALLBACK;
        $summary = sprintf('The dashboard reads in %s, on %s.', DashboardLanguage::SUPPORTED[$language] ?? $language, $timezone);

        if (! $chosen) {
            return $this->check(
                key: 'language_and_region',
                label: 'Language and region',
                status: 'attention',
                summary: $summary.' Nobody has confirmed that is right.',
                detail: 'These are the defaults for every agent who has not chosen their own, which on a new install is all of them. A wrong clock does not look like a fault: reports cover a day that ended hours before the reader\'s did, and nobody thinks to report it.',
                action: 'Confirm the language and timezone this install should read in.',
                statusLabel: 'Confirm this'
            );
        }

        return $this->check(
            key: 'language_and_region',
            label: 'Language and region',
            status: 'ready',
            summary: $summary,
            detail: 'Agents who prefer another language or clock can still choose their own on their profile.',
            action: 'Change it whenever the desk moves; agents keep any choice they made for themselves.'
        );
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function publicUrl(): array
    {
        $url = $this->normalizedPublicUrl();
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($url === '' || $host === '' || $scheme !== 'https' || $this->isLocalHost($host)) {
            return $this->check(
                key: 'public_url',
                label: 'Public URL',
                status: 'attention',
                summary: 'APP_URL is local or not secure.',
                detail: 'Visitors, agents, cookies, callbacks, and widget snippets need the real public HTTPS URL.',
                action: 'Set APP_URL to the public HTTPS URL visitors and agents will use.'
            );
        }

        return $this->check(
            key: 'public_url',
            label: 'Public URL',
            status: 'ready',
            summary: sprintf('APP_URL is %s.', $url),
            detail: 'Wayfindr can generate public links and widget snippets from the production URL.',
            action: 'Keep APP_URL stable and update it intentionally when changing domains.'
        );
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function securityPosture(): array
    {
        $debugEnabled = (bool) config('app.debug');
        $environment = (string) app()->environment();

        if ($debugEnabled && app()->environment('production')) {
            return $this->check(
                key: 'security_posture',
                label: 'Debug mode',
                status: 'attention',
                summary: 'Debug mode is enabled in production.',
                detail: 'APP_DEBUG=true in production exposes stack traces, environment values, and configuration to anyone who triggers an error, which can leak database credentials, API keys, and visitor data.',
                action: 'Set APP_DEBUG=false in the environment, then run php artisan config:cache so the cached config reflects it.',
                commands: ['php artisan config:cache']
            );
        }

        if ($debugEnabled) {
            return $this->check(
                key: 'security_posture',
                label: 'Debug mode',
                status: 'ready',
                summary: sprintf('Debug mode is on in the %s environment.', $environment),
                detail: 'Debug mode is expected outside production. A production install must keep APP_DEBUG=false so errors never expose stack traces, environment values, or secrets.',
                action: 'Keep APP_ENV=production and APP_DEBUG=false on the live install, then run php artisan config:cache after changing them.',
                commands: ['php artisan config:cache']
            );
        }

        return $this->check(
            key: 'security_posture',
            label: 'Debug mode',
            status: 'ready',
            summary: 'Debug mode is disabled.',
            detail: sprintf('APP_DEBUG is off in the %s environment, so application errors will not expose stack traces, environment values, or secrets.', $environment),
            action: 'Keep APP_DEBUG=false in production and re-run php artisan config:cache after environment changes.',
            commands: ['php artisan config:cache']
        );
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function databaseConnection(): array
    {
        $connection = (string) config('database.default', 'unknown');

        try {
            DB::connection()->select('select 1');
        } catch (Throwable $exception) {
            return $this->check(
                key: 'database_connection',
                label: 'Database connection',
                status: 'attention',
                summary: sprintf('The %s connection could not be reached.', $connection),
                detail: $exception->getMessage(),
                action: 'Review DB_CONNECTION, DB_HOST, DB_DATABASE, DB_USERNAME, and DB_PASSWORD.'
            );
        }

        return $this->check(
            key: 'database_connection',
            label: 'Database connection',
            status: 'ready',
            summary: sprintf('The %s connection responded.', $connection),
            detail: 'Wayfindr can reach the configured database.',
            action: 'Keep migrations in the deploy script so schema changes land with releases.'
        );
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function cacheStore(): array
    {
        $default = (string) config('cache.default', 'file');
        $isFailover = $this->cacheStoreDriver($default) === 'failover';
        $persistentStores = $this->persistentCacheStores($default);

        // A store (or failover chain) with no persistent backing only matters in
        // production, where non-persistent caches silently break shared config,
        // rate limiters, and locks. Outside production it is expected.
        if ($persistentStores === []) {
            if (app()->environment('production')) {
                return $this->check(
                    key: 'cache_store',
                    label: 'Cache store',
                    status: 'attention',
                    summary: sprintf('CACHE_STORE is %s in production.', $default),
                    detail: 'The configured cache store does not persist between requests or across workers, so cached config, rate limiters, and locks will not behave correctly in production.',
                    action: 'Set CACHE_STORE to a shared, persistent store such as redis, memcached, database, or file (a failover chain must include a persistent store).'
                );
            }

            return $this->check(
                key: 'cache_store',
                label: 'Cache store',
                status: 'ready',
                summary: sprintf('CACHE_STORE is %s.', $default),
                detail: 'Non-persistent cache stores are expected outside production. A production install should use a shared, persistent store.',
                action: 'Use redis, memcached, database, or file for the cache store before relying on it in production.'
            );
        }

        // Probe the persistent backends directly. For a failover chain this means
        // each persistent member, so a broken primary is surfaced even when a
        // non-persistent fallback would otherwise let the round-trip pass.
        foreach ($persistentStores as $name) {
            $failure = $this->probeCacheStore($name);

            if ($failure !== null) {
                $where = ($isFailover && $name !== $default)
                    ? sprintf('The %s store in the %s cache chain', $name, $default)
                    : sprintf('The %s cache store', $name);

                return $this->check(
                    key: 'cache_store',
                    label: 'Cache store',
                    status: 'attention',
                    summary: sprintf('%s could not be verified.', $where),
                    detail: $failure,
                    action: 'Check the cache server connection and credentials (for redis or memcached) or the cache table (for the database store), then retry.'
                );
            }
        }

        return $this->check(
            key: 'cache_store',
            label: 'Cache store',
            status: 'ready',
            summary: $isFailover
                ? sprintf('The %s cache chain responded.', $default)
                : sprintf('The %s cache store responded.', $default),
            detail: 'Wayfindr wrote and read back a value from the persistent cache backend'.(count($persistentStores) > 1 ? 's' : '').'.',
            action: 'Keep the cache store shared across web and queue workers.'
        );
    }

    private function cacheStoreDriver(string $name): ?string
    {
        $driver = config("cache.stores.{$name}.driver");

        return is_string($driver) ? $driver : null;
    }

    /**
     * Persistent backends to probe for the configured cache store. A failover
     * chain expands to its persistent member stores; non-persistent stores
     * (array/null) are excluded because they cannot prove a shared cache works.
     *
     * @return list<string>
     */
    private function persistentCacheStores(string $default): array
    {
        $members = $this->cacheStoreDriver($default) === 'failover'
            ? array_values(array_filter((array) config("cache.stores.{$default}.stores", []), 'is_string'))
            : [$default];

        return array_values(array_filter(
            $members,
            fn (string $name): bool => ! in_array($this->cacheStoreDriver($name), ['array', 'null', null], true),
        ));
    }

    private function probeCacheStore(string $name): ?string
    {
        try {
            $probeKey = 'wayfindr:readiness:cache:'.Str::random(12);
            Cache::store($name)->put($probeKey, '1', 5);
            $roundTripped = Cache::store($name)->get($probeKey) === '1';
            Cache::store($name)->forget($probeKey);
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }

        return $roundTripped
            ? null
            : 'A readiness probe wrote a value to the cache but read back a different result, which usually means the store is misconfigured or evicting immediately.';
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function mailTransport(): array
    {
        $mailer = strtolower((string) config('mail.default', 'log'));

        if (in_array($mailer, ['', 'array', 'log'], true)) {
            return $this->check(
                key: 'mail_transport',
                label: 'Mail transport',
                status: 'attention',
                summary: sprintf('MAIL_MAILER is %s.', $mailer === '' ? 'not set' : $mailer),
                detail: 'Local-only mailers do not deliver password resets, support alerts, or operator notices outside the app.',
                action: 'Configure smtp, ses, postmark, resend, or another real outbound mail transport before relying on email alerts.'
            );
        }

        if ($mailer === 'smtp' && $this->isLocalMailHost((string) config('mail.mailers.smtp.host'))) {
            return $this->check(
                key: 'mail_transport',
                label: 'Mail transport',
                status: 'attention',
                summary: 'SMTP is still pointed at a local mail host.',
                detail: sprintf(
                    'MAIL_HOST is %s and MAIL_PORT is %s, which usually means mail is still aimed at a local development sink.',
                    (string) config('mail.mailers.smtp.host', 'not set'),
                    (string) config('mail.mailers.smtp.port', 'not set')
                ),
                action: 'Set MAIL_HOST, MAIL_PORT, and MAIL_FROM_ADDRESS to a real outbound mail provider before relying on email alerts.'
            );
        }

        if ($mailer === 'smtp' && $this->hasUnsupportedSmtpScheme(config('mail.mailers.smtp.scheme'))) {
            return $this->check(
                key: 'mail_transport',
                label: 'Mail transport',
                status: 'attention',
                summary: 'SMTP has an unsupported MAIL_SCHEME value.',
                detail: sprintf(
                    'MAIL_SCHEME is %s, but Laravel SMTP supports smtp, smtps, or no explicit scheme.',
                    (string) config('mail.mailers.smtp.scheme')
                ),
                action: 'Unset MAIL_SCHEME for port 587 STARTTLS SMTP, or set it to smtps when using port 465.'
            );
        }

        if (! $this->hasValue(config('mail.from.address'))) {
            return $this->check(
                key: 'mail_transport',
                label: 'Mail transport',
                status: 'attention',
                summary: 'MAIL_FROM_ADDRESS is missing.',
                detail: 'Outbound support email needs a sender address agents and visitors can recognize.',
                action: 'Set MAIL_FROM_ADDRESS to a monitored sender before relying on email alerts.'
            );
        }

        if ($this->isPlaceholderMailFrom((string) config('mail.from.address'))) {
            return $this->check(
                key: 'mail_transport',
                label: 'Mail transport',
                status: 'attention',
                summary: 'MAIL_FROM_ADDRESS still looks like a placeholder.',
                detail: 'Default sender addresses make outbound support mail harder to trust and easier to lose in delivery checks.',
                action: 'Set MAIL_FROM_ADDRESS to a monitored sender before relying on email alerts.'
            );
        }

        return $this->check(
            key: 'mail_transport',
            label: 'Mail transport',
            status: 'ready',
            summary: sprintf('MAIL_MAILER is %s.', $mailer),
            detail: 'Wayfindr has an outbound mail transport configured.',
            action: 'Run php artisan wayfindr:mail-test --to=you@example.com from apps/server after deploy. For STARTTLS ports such as 587 or 2587, leave MAIL_SCHEME unset; use smtps only for port 465.',
            commands: ['php artisan wayfindr:mail-test --to=you@example.com']
        );
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function queueWorker(): array
    {
        $connection = (string) config('queue.default', 'sync');

        if (in_array($connection, ['null', 'sync'], true)) {
            return $this->check(
                key: 'queue_worker',
                label: 'Queue worker',
                status: 'attention',
                summary: sprintf('QUEUE_CONNECTION is %s.', $connection),
                detail: 'Synchronous queues are useful locally, but production installs should run durable background workers.',
                action: 'Use database or redis queues and run php artisan queue:work under Forge, Supervisor, systemd, or your process manager.',
                commands: ['php artisan queue:work']
            );
        }

        return $this->check(
            key: 'queue_worker',
            label: 'Queue worker',
            status: 'ready',
            summary: sprintf('QUEUE_CONNECTION is %s.', $connection),
            detail: 'The queue driver is configured for background work.',
            action: 'Confirm php artisan queue:work is managed by Forge, Supervisor, systemd, or your deployment platform, then run php artisan queue:failed after testing to inspect failures.',
            commands: ['php artisan queue:work', 'php artisan queue:failed']
        );
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function realtimeBroadcasting(): array
    {
        $realtime = $this->realtimeHealth->summary();
        $status = $realtime['status'] === 'ready' ? 'ready' : 'attention';

        return $this->check(
            key: 'realtime_broadcasting',
            label: 'Realtime broadcasting',
            status: $status,
            summary: $realtime['label'],
            detail: $realtime['message'],
            action: $status === 'ready'
                ? 'Run php artisan reverb:start --host=127.0.0.1 --port=8080 under your process manager, and keep php artisan reverb:restart in the deploy script so long-running Reverb workers refresh cleanly.'
                : 'Set BROADCAST_CONNECTION=reverb plus REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET, REVERB_HOST, REVERB_PORT, and REVERB_SCHEME.',
            commands: $status === 'ready'
                ? ['php artisan reverb:start --host=127.0.0.1 --port=8080', 'php artisan reverb:restart']
                : []
        );
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function storagePaths(): array
    {
        $paths = [
            storage_path('framework'),
            storage_path('logs'),
        ];
        $unwritablePaths = array_filter($paths, fn (string $path): bool => ! is_dir($path) || ! is_writable($path));

        if ($unwritablePaths !== []) {
            return $this->check(
                key: 'storage_paths',
                label: 'Storage paths',
                status: 'attention',
                summary: 'One or more storage paths are not writable.',
                detail: implode(', ', $unwritablePaths),
                action: 'Make storage/framework and storage/logs writable by the PHP user.'
            );
        }

        return $this->check(
            key: 'storage_paths',
            label: 'Storage paths',
            status: 'ready',
            summary: 'Laravel storage paths are writable.',
            detail: 'Cache, compiled views, sessions, and logs can be written by the application.',
            action: 'Keep storage shared between zero-downtime releases.'
        );
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function attachmentStorage(): array
    {
        try {
            $diskName = AttachmentStorage::diskName();
        } catch (Throwable $exception) {
            return $this->check(
                key: 'attachment_storage',
                label: 'Attachment storage',
                status: 'attention',
                summary: 'Attachment storage is misconfigured.',
                detail: $exception->getMessage().' New uploads are being rejected until this is corrected.',
                action: 'Set WAYFINDR_ATTACHMENT_STORAGE_DISK to attachments (local) or attachments-s3, then run php artisan config:cache.',
                commands: ['php artisan config:cache'],
            );
        }

        $driver = (string) config("filesystems.disks.{$diskName}.driver", 'local');

        // Probe what Wayfindr can honestly verify: the active disk accepts a
        // write, reads it back, and deletes it. Durability and backups stay
        // operator-owned, per the documented posture. The probe key is a
        // dotfile so the orphan sweep never races it.
        try {
            // The probe lives under its own dotfile prefix: listing that prefix
            // is a one-object ListObjects call (never a full-bucket list), and
            // the dotfile names keep the orphan sweep from ever racing it.
            $probeDir = '.wayfindr-readiness-probe-'.Str::random(12);
            $probeKey = $probeDir.'/.probe';
            $disk = Storage::disk($diskName);
            $wrote = $disk->put($probeKey, 'ok') !== false;
            $read = $wrote && $disk->get($probeKey) === 'ok';
            $listed = $read && in_array($probeKey, $disk->files($probeDir), true);
            $deleted = $disk->delete($probeKey);

            if (! $read) {
                throw new \RuntimeException('The disk accepted a connection but a write/read round-trip failed.');
            }

            // The retention sweep reconciles by LISTING the disk; credentials
            // that can write/read but not list would let it silently do nothing.
            if (! $listed) {
                return $this->check(
                    key: 'attachment_storage',
                    label: 'Attachment storage',
                    status: 'attention',
                    summary: sprintf('The %s disk cannot list objects.', $diskName),
                    detail: 'Writes and reads work, but a listing probe did not return the probe object — the retention sweep cannot reconcile orphaned storage without listing.',
                    action: $driver === 's3'
                        ? 'Grant the credentials ListBucket permission on the bucket.'
                        : 'Make the attachments storage directory readable and listable by the PHP user.',
                );
            }

            // The disks run with throw => false, so a delete refused by the
            // credentials returns false silently. The retention sweep and
            // chip-removal cleanup depend on delete working — surface it
            // rather than reporting ready.
            if ($deleted === false || $disk->exists($probeKey)) {
                return $this->check(
                    key: 'attachment_storage',
                    label: 'Attachment storage',
                    status: 'attention',
                    summary: sprintf('The %s disk cannot delete objects.', $diskName),
                    detail: 'Writes and reads work, but the delete probe failed — the retention sweep and upload cleanup cannot reclaim storage, so it will grow unbounded.',
                    action: $driver === 's3'
                        ? 'Grant the credentials DeleteObject permission on the bucket.'
                        : 'Make the attachments storage directory writable and deletable by the PHP user.',
                );
            }
        } catch (Throwable $exception) {
            return $this->check(
                key: 'attachment_storage',
                label: 'Attachment storage',
                status: 'attention',
                summary: sprintf('The %s disk failed its write/read probe.', $diskName),
                detail: $exception->getMessage(),
                action: $driver === 's3'
                    ? 'Check WAYFINDR_ATTACHMENT_S3_KEY/SECRET/REGION/BUCKET (and ENDPOINT + USE_PATH_STYLE for non-AWS stores), then reload.'
                    : 'Make the attachments storage directory writable by the PHP user.',
            );
        }

        return $this->check(
            key: 'attachment_storage',
            label: 'Attachment storage',
            status: 'ready',
            summary: sprintf('New uploads are stored on the %s disk (%s).', $diskName, $driver === 's3' ? 'S3-compatible' : 'local'),
            detail: 'The active disk passed a write/read/delete probe. Existing attachments keep serving from the disk recorded on each row, so switching surfaces never breaks old files. Durability and backups remain operator-owned.',
            action: $driver === 's3'
                ? 'Keep the bucket private (block public access) — downloads always stream through Wayfindr, never from a bucket URL.'
                : 'Keep storage shared between zero-downtime releases, or set WAYFINDR_ATTACHMENT_STORAGE_DISK=attachments-s3 to store new uploads in an S3-compatible bucket.',
        );
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function attachmentScanning(): array
    {
        $driver = strtolower(trim((string) config('wayfindr.attachments.scanner.driver')));

        if ($driver === '' || $driver === 'null' || $driver === 'none') {
            // Accept-with-defense-in-depth is a valid, safe default — surfaced
            // here so the operator knows uploads are not virus-scanned, but not
            // flagged as attention or a pending manual action.
            return $this->check(
                key: 'attachment_scanning',
                label: 'Attachment scanning',
                status: 'ready',
                summary: 'No malware scanner configured (accepting with defense-in-depth).',
                detail: 'Uploaded attachments are accepted with a byte-sniffed type allowlist, private storage, forced-download disposition, and nosniff, but they are not virus-scanned. Configure a scanner if your policy requires one.',
                action: 'Set WAYFINDR_ATTACHMENT_SCANNER=clamav with WAYFINDR_CLAMAV_SOCKET pointing at a reachable clamd, or knowingly keep the defense-in-depth default.'
            );
        }

        if ($driver !== 'clamav') {
            // An unknown driver makes every upload throw (fail loud), so surface
            // it here rather than silently disabling scanning.
            return $this->check(
                key: 'attachment_scanning',
                label: 'Attachment scanning',
                status: 'attention',
                summary: sprintf('Unknown malware scanner driver "%s".', $driver),
                detail: 'WAYFINDR_ATTACHMENT_SCANNER is set to an unsupported value, so uploads are being rejected until it is corrected.',
                action: "Set WAYFINDR_ATTACHMENT_SCANNER to 'clamav' or leave it unset."
            );
        }

        $failClosed = (bool) config('wayfindr.attachments.scanner.fail_closed', true);

        if (! app(AttachmentScanner::class)->isAvailable()) {
            return $this->check(
                key: 'attachment_scanning',
                label: 'Attachment scanning',
                status: 'attention',
                summary: sprintf('The %s scanner is configured but unreachable.', $driver),
                detail: $failClosed
                    ? 'Scanning is fail-closed, so uploads are being rejected until the scanner recovers.'
                    : 'Scanning is fail-open, so uploads are currently being accepted WITHOUT a malware scan.',
                action: 'Confirm the ClamAV daemon (clamd) is running and reachable at WAYFINDR_CLAMAV_SOCKET.'
            );
        }

        return $this->check(
            key: 'attachment_scanning',
            label: 'Attachment scanning',
            status: 'ready',
            summary: sprintf('The %s scanner is reachable.', $driver),
            detail: 'Uploaded attachments are scanned before they are stored; infected uploads are rejected and audited.',
            action: 'Keep clamd and its signature database (freshclam) running and up to date.'
        );
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function scheduler(): array
    {
        return $this->manualCheck(
            key: 'scheduler',
            label: 'Scheduler',
            summary: 'Confirm the Laravel scheduler is running once per minute.',
            detail: 'Wayfindr cannot safely prove cron or external scheduler setup from inside the request. Alert digest email depends on the scheduler running the hourly digest command.',
            action: 'Configure * * * * * cd /path/to/apps/server && php artisan schedule:run or the equivalent scheduled job in your host, then run php artisan schedule:list and confirm php artisan wayfindr:send-alert-digests is listed.',
            commands: [
                '* * * * * cd /path/to/apps/server && php artisan schedule:run',
                'php artisan schedule:list',
            ]
        );
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function alertDigestDelivery(): array
    {
        if (! Schema::hasTable('users')) {
            return $this->check(
                key: 'alert_digest_delivery',
                label: 'Alert digest delivery',
                status: 'manual',
                summary: 'Agent alert preferences are not available yet.',
                detail: 'Run migrations before checking digest delivery state.',
                action: 'Run php artisan migrate from apps/server, then revisit readiness once agents can opt into digest email.',
                commands: ['php artisan migrate']
            );
        }

        $digestAgents = User::query()
            ->whereNotNull('account_id')
            ->whereNull('deactivated_at')
            ->get()
            ->filter(fn (User $agent): bool => $agent->alertEmailEnabled()
                && $agent->alertMode() !== User::ALERT_MODE_QUIET
                && $agent->alertCadence() === User::ALERT_CADENCE_DIGEST)
            ->values();

        if ($digestAgents->isEmpty()) {
            return $this->check(
                key: 'alert_digest_delivery',
                label: 'Alert digest delivery',
                status: 'ready',
                summary: 'No active digest email agents yet.',
                detail: 'Wayfindr will begin recording digest delivery state after agents opt into digest email.',
                action: 'Use agent profiles or account settings to enable digest cadence when a team wants quieter email alerts.'
            );
        }

        $failedAgents = $digestAgents
            ->filter(fn (User $agent): bool => $agent->alertDigestDeliveryStatus()['status'] === User::ALERT_DIGEST_DELIVERY_FAILED)
            ->values();

        if ($failedAgents->isNotEmpty()) {
            $failedCount = $failedAgents->count();

            return $this->check(
                key: 'alert_digest_delivery',
                label: 'Alert digest delivery',
                status: 'attention',
                summary: sprintf(
                    '%d digest-enabled %s %s delivery attention.',
                    $failedCount,
                    str('agent')->plural($failedCount),
                    $failedCount === 1 ? 'needs' : 'need',
                ),
                detail: sprintf(
                    'The latest digest delivery attempt failed for %s. Raw provider errors stay in logs instead of the readiness page.',
                    $failedAgents->pluck('name')->filter()->join(', '),
                ),
                action: 'Check the application logs and mail provider, then run php artisan wayfindr:send-alert-digests from apps/server to record a fresh delivery state.'
            );
        }

        $notRunAgents = $digestAgents
            ->filter(fn (User $agent): bool => $agent->alertDigestDeliveryStatus()['status'] === User::ALERT_DIGEST_DELIVERY_NOT_RUN)
            ->values();

        if ($notRunAgents->isNotEmpty()) {
            $notRunCount = $notRunAgents->count();

            return $this->check(
                key: 'alert_digest_delivery',
                label: 'Alert digest delivery',
                status: 'manual',
                summary: sprintf(
                    '%d digest-enabled %s %s no recorded digest run yet.',
                    $notRunCount,
                    str('agent')->plural($notRunCount),
                    $notRunCount === 1 ? 'has' : 'have',
                ),
                detail: 'The scheduler may not have run since digest cadence was enabled, or the team has not had digest-ready alerts yet.',
                action: 'Confirm the scheduler is running, then run php artisan wayfindr:send-alert-digests once from apps/server to establish a baseline delivery state.'
            );
        }

        $digestCount = $digestAgents->count();

        return $this->check(
            key: 'alert_digest_delivery',
            label: 'Alert digest delivery',
            status: 'ready',
            summary: sprintf(
                'Latest digest delivery state is recorded for %d digest-enabled %s.',
                $digestCount,
                str('agent')->plural($digestCount),
            ),
            detail: 'The latest digest attempts are either queued or found no digest-ready alerts.',
            action: 'Keep the scheduler confirmed and review this check after mail transport or alert cadence changes.'
        );
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function backupsRestore(): array
    {
        return $this->manualCheck(
            key: 'backups_restore',
            label: 'Backups and restore',
            summary: 'Confirm database and storage backups outside Wayfindr.',
            detail: 'Wayfindr cannot prove host snapshots, database dumps, object storage retention, or restore drills from inside a request.',
            action: 'Confirm database and storage backups are scheduled, retained, monitored, and restorable before real support traffic arrives.'
        );
    }

    /**
     * The readiness items the guided onboarding checklist shows, in guided order
     * — mail, public URL, background workers, backups. Computes ONLY these, so
     * the focused checklist (including the first post-/setup landing and every
     * refresh) never runs unrelated diagnostics like the S3 attachment-disk
     * write/read/list/delete probe or the ClamAV reachability check, which the
     * full summary() suite performs and which can block on external-service
     * timeouts.
     *
     * @return list<array<string, mixed>>
     */
    public function onboardingChecklist(): array
    {
        $this->loadConfirmations();

        return [
            $this->mailTransport(),
            $this->publicUrl(),
            $this->backgroundWorkersStep(),
            $this->backupsRestore(),
            $this->languageAndRegion(),
        ];
    }

    /**
     * A dedicated background-workers step for the guided onboarding checklist. A
     * configured async queue only proves config, not that a queue:work process is
     * actually running — which a request cannot verify — so completion requires
     * the operator's manual attestation that BOTH the worker and the scheduler
     * run. It uses its own 'background_workers' confirmation key, kept distinct
     * from the 'scheduler' check so scheduler-only proof cannot mark it ready.
     *
     * @return array{action: string, commands: array<int, string>, confirmation: array{age_label: string|null, confirmed_at: string|null, confirmed_by: string, freshness_status: string, key: string, note_present: bool, stale_after_days: int|null}|null, confirmation_key: string, confirmable: bool, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    public function backgroundWorkersStep(): array
    {
        $this->loadConfirmations();

        $connection = (string) config('queue.default', 'sync');

        // A synchronous/null queue does not run background jobs at all — fix the
        // driver before there is anything to attest to.
        if (in_array($connection, ['null', 'sync'], true)) {
            return $this->check(
                key: 'background_workers',
                label: 'Confirm background workers',
                status: 'attention',
                summary: sprintf('QUEUE_CONNECTION is %s.', $connection),
                detail: 'Synchronous queues run inline and never process queued jobs like alert digests, so there is no background worker to confirm yet.',
                action: 'Set QUEUE_CONNECTION to database or redis and run php artisan queue:work under Forge, Supervisor, systemd, or your process manager, then confirm here.',
                commands: ['php artisan queue:work', 'php artisan schedule:list'],
            );
        }

        return $this->manualCheck(
            key: 'background_workers',
            label: 'Confirm background workers',
            summary: 'Confirm a queue worker and the scheduler are both running.',
            detail: 'A configured async queue only proves config, not that a queue:work process is running. Queued alerts and the once-a-minute scheduler both need process-manager coverage outside the request lifecycle.',
            action: 'Confirm php artisan queue:work is managed by Forge, Supervisor, systemd, or your host; run php artisan queue:failed to inspect failures; and verify * * * * * php artisan schedule:run is configured once per minute.',
            commands: [
                'php artisan queue:work',
                'php artisan queue:failed',
                '* * * * * cd /path/to/apps/server && php artisan schedule:run',
                'php artisan schedule:list',
            ],
        );
    }

    /**
     * @param  array<string, array{action: string, commands?: array<int, string>, detail: string, key: string, label: string, status: string, status_label: string, summary: string}>  $checks
     * @return array<int, array{action: string, commands: array<int, string>, key: string, label: string, status: string, status_label: string, summary: string}>
     */
    private function postInstallSmokePath(array $checks): array
    {
        $backgroundStatus = $this->statusFromCheck($checks, 'queue_worker') === 'attention'
            ? 'attention'
            : $this->statusFromCheck($checks, 'scheduler');
        $schedulerCheck = $checks['scheduler'] ?? null;
        $backupsCheck = $checks['backups_restore'] ?? null;
        $cobrowseTransportCheck = $checks['cobrowse_transport'] ?? null;

        return [
            $this->smokeStep(
                key: 'public_endpoint',
                label: 'Open the public app URL',
                status: $this->statusFromCheck($checks, 'public_url'),
                summary: 'Use the same HTTPS URL agents, visitors, cookies, and widget snippets will use.',
                action: 'Open APP_URL from outside the server, confirm /up returns 200, then sign in from the public domain.'
            ),
            $this->smokeStep(
                key: 'outbound_mail',
                label: 'Send a real email',
                status: $this->statusFromCheck($checks, 'mail_transport'),
                summary: 'Alerts are only useful after mail leaves the server and lands in a real inbox.',
                action: 'Run php artisan wayfindr:mail-test --to=you@example.com from apps/server, then confirm the message lands in a real inbox.',
                commands: ['php artisan wayfindr:mail-test --to=you@example.com']
            ),
            $this->smokeStep(
                key: 'background_processes',
                label: 'Confirm background workers',
                status: $backgroundStatus,
                summary: 'Queues and the scheduler need process-manager coverage outside the request lifecycle.',
                action: 'Confirm php artisan queue:work is managed by Forge, Supervisor, systemd, or your host; run php artisan queue:failed to inspect failures; verify * * * * * cd /path/to/apps/server && php artisan schedule:run is configured once per minute; and confirm php artisan wayfindr:send-alert-digests appears in php artisan schedule:list.',
                confirmationKey: $backgroundStatus === 'attention' ? null : 'scheduler',
                confirmable: $backgroundStatus !== 'attention',
                confirmation: $schedulerCheck['confirmation'] ?? null,
                statusLabel: $backgroundStatus === 'attention' ? null : ($schedulerCheck['status_label'] ?? null),
                commands: [
                    'php artisan queue:work',
                    'php artisan queue:failed',
                    '* * * * * cd /path/to/apps/server && php artisan schedule:run',
                    'php artisan schedule:list',
                ],
            ),
            $this->smokeStep(
                key: 'widget_smoke',
                label: 'Send a test message from the widget',
                status: $this->statusFromCheck($checks, 'realtime_broadcasting'),
                summary: 'The real support loop is visitor message, agent reply, and live updates without manual refresh.',
                action: 'Install the widget on a test site, send a visitor message, reply as an agent, and confirm both sides update without a refresh.'
            ),
            $this->smokeStep(
                key: 'cobrowse_transport_smoke',
                label: 'Check cobrowse can connect',
                status: $this->statusFromCheck($checks, 'cobrowse_transport'),
                summary: $cobrowseTransportCheck['summary'] ?? 'Confirm aggregate cobrowse transport state before relying on cobrowse.',
                action: ($cobrowseTransportCheck['status'] ?? null) === 'manual'
                    ? $cobrowseTransportCheck['action']
                    : 'Run php artisan wayfindr:cobrowse-transport-smoke from apps/server after a consented widget test, then review aggregate transport state before relying on cobrowse.',
                statusLabel: $cobrowseTransportCheck['status_label'] ?? null,
                commands: ($cobrowseTransportCheck['status'] ?? null) === 'manual'
                    ? []
                    : ['php artisan wayfindr:cobrowse-transport-smoke'],
            ),
            $this->smokeStep(
                key: 'backup_restore',
                label: 'Confirm backups can restore',
                status: $this->statusFromCheck($checks, 'backups_restore'),
                summary: 'Database and storage backups live in the operator infrastructure, not inside Wayfindr.',
                action: 'Confirm backup schedule, retention, monitoring, and at least one restore drill before real support traffic.',
                confirmationKey: 'backups_restore',
                confirmable: true,
                confirmation: $backupsCheck['confirmation'] ?? null,
                statusLabel: $backupsCheck['status_label'] ?? null,
            ),
        ];
    }

    /**
     * @param  array<string, array{action: string, commands?: array<int, string>, detail: string, key: string, label: string, status: string, status_label: string, summary: string}>  $checks
     * @param  array<string, array{action: string, commands: array<int, string>, key: string, label: string, status: string, status_label: string, summary: string}>  $smokePath
     * @return array{attention_count: int, items: array<int, array{action: string, commands: array<int, string>, detail: string, docs_url: string|null, key: string, label: string, status: string, status_label: string, summary: string}>, label: string, manual_count: int, ready_count: int, status: string, summary: string}
     */
    private function dogfoodSummary(array $checks, array $smokePath): array
    {
        $supportLoopStatus = $this->dogfoodDependencyStatus($checks, [
            'application_key',
            'public_url',
            'database_connection',
            'queue_worker',
            'storage_paths',
        ]);
        $supportLoopStatus = $supportLoopStatus === 'attention' ? 'attention' : 'manual';

        $ticketWorkflowStatus = $this->dogfoodDependencyStatus($checks, [
            'database_connection',
            'storage_paths',
        ]);
        $ticketWorkflowStatus = $ticketWorkflowStatus === 'attention' ? 'attention' : 'manual';

        $alertStatus = $this->dogfoodDependencyStatus($checks, [
            'mail_transport',
            'scheduler',
            'alert_digest_delivery',
        ]);
        $dataResponsibility = config('wayfindr.data_responsibility');
        $dataResponsibility = is_array($dataResponsibility) ? $dataResponsibility : [];
        $dataResponsibilityMessage = (string) ($dataResponsibility['message'] ?? 'Review the self-hosted data responsibility guidance.');
        $dataResponsibilityGuidance = (string) ($dataResponsibility['guidance'] ?? 'Keep privacy notices, retention expectations, and support data handling aligned with how this installation is used.');
        $dataResponsibilityDocsUrl = $this->stringOrNull($dataResponsibility['docs_url'] ?? null);
        $supportLoopCommand = 'WAYFINDR_BASE_URL="https://support.example.com" WAYFINDR_HOST_PAGE_URL="https://docs.example.com/help" WAYFINDR_SITE_PUBLIC_KEY="site_public_key_here" WAYFINDR_AGENT_EMAIL="agent@example.com" WAYFINDR_AGENT_PASSWORD="agent-password" scripts/smoke/support-loop.sh';
        $widgetSmoke = $smokePath['widget_smoke'] ?? null;

        $items = [
            $this->dogfoodGate(
                key: 'production_https_host',
                label: 'Production-like HTTPS host',
                status: $this->statusFromCheck($checks, 'public_url'),
                summary: $checks['public_url']['summary'] ?? 'Confirm the public app URL is the HTTPS URL used for the demo.',
                detail: 'Agents, widgets, cookies, callbacks, and setup scripts need the same public HTTPS origin.',
                action: 'Open APP_URL from outside the server, confirm /up returns 200, then sign in from /login on that public domain.',
            ),
            $this->dogfoodGate(
                key: 'demo_account_site',
                label: 'Demo account and site',
                status: 'manual',
                summary: 'Confirm a demo account, agent login, support site, and site public key exist.',
                detail: 'Wayfindr keeps support data out of operator readiness, so this remains a safe manual gate.',
                action: 'Sign in as the demo agent, confirm the account and site are visible, then copy the intended site public key for the host widget.',
            ),
            $this->dogfoodGate(
                key: 'host_widget_install',
                label: 'Widget installed on the real site',
                status: $this->statusFromCheck($checks, 'public_url') === 'attention' ? 'attention' : 'manual',
                summary: 'Confirm the real host page loads the intended Wayfindr widget and site public key.',
                detail: 'The host project is outside this app, so the strongest evidence is the scripted browser run against the live host page.',
                action: 'Run the full support-loop script with the real host page URL before real traffic arrives.',
                commands: [$supportLoopCommand],
            ),
            $this->dogfoodGate(
                key: 'support_loop_smoke',
                label: 'A full support conversation, end to end',
                status: $supportLoopStatus,
                summary: $supportLoopStatus === 'attention'
                    ? 'Fix the failing app and runtime checks before running the scripted support loop.'
                    : 'Run the scripted support loop from widget load through ticket creation. Falling back to a manual refresh is acceptable if realtime is not ready.',
                detail: $widgetSmoke
                    ? sprintf('Current live-update signal: %s. This can still pass with a manual refresh as a stated fallback.', rtrim($widgetSmoke['summary'], '.'))
                    : 'One run must prove visitor message, agent reply, support-code lookup, and ticket creation.',
                action: 'Run scripts/smoke/support-loop.sh with the staging app URL, host page URL, site public key, and disposable demo agent credentials.',
                commands: [$supportLoopCommand],
                docsUrl: 'https://github.com/adamgreenwell/wayfindr/blob/main/docs/product/mvp-demo-rehearsal.md#full-support-loop-smoke',
            ),
            $this->dogfoodGate(
                key: 'ticket_workflow',
                label: 'Durable ticket workflow',
                status: $ticketWorkflowStatus,
                summary: $ticketWorkflowStatus === 'attention'
                    ? 'Fix database or storage readiness before relying on tickets.'
                    : 'Manually prove ticket assignment, labels, replies, status changes, and support-code lookup.',
                detail: 'That run creates the first ticket; the ticket should then be worked through the lifecycle a person would use.',
                action: 'Once that ticket exists, assign it, label it, reply, move it through one status change, and find it again by support code.',
            ),
            $this->dogfoodGate(
                key: 'alerts_email',
                label: 'Alerts and email',
                status: $alertStatus,
                summary: $alertStatus === 'attention'
                    ? 'Mail, scheduler, or digest delivery needs attention before email alerts are part of the demo.'
                    : 'Confirm alert preferences and email delivery match the demo agent configuration.',
                detail: 'Not every agent needs email, but any alert path that is configured should actually work.',
                action: 'Run the mail test, confirm scheduler status, and verify alert preference behavior for the disposable demo agent.',
                commands: ['php artisan wayfindr:mail-test --to=you@example.com', 'php artisan schedule:list'],
            ),
            $this->dogfoodGate(
                key: 'cobrowse_observe_mode',
                label: 'Consent-based cobrowse observe mode',
                status: $this->statusFromCheck($checks, 'cobrowse_transport'),
                summary: $checks['cobrowse_transport']['summary'] ?? 'Confirm cobrowse transport before relying on observe mode.',
                detail: 'Cobrowse should be requested observe-only, receive explicit visitor consent, and show no page state before that consent.',
                action: 'Run a consented cobrowse session after the support loop, then confirm aggregate transport state stays healthy.',
                commands: ['php artisan wayfindr:cobrowse-transport-smoke'],
            ),
            $this->dogfoodGate(
                key: 'operator_boundary',
                label: 'Readiness stays out of support data',
                status: 'ready',
                summary: 'Readiness shows how the installation is doing, without customer support data.',
                detail: 'The operator console is for runtime gaps, safe activity, setup guidance, and what has been confirmed.',
                action: 'Use /operator and /dashboard/readiness during the demo, and keep conversation bodies, ticket contents, visitor identifiers, and proof notes out of operator review.',
            ),
            $this->dogfoodGate(
                key: 'data_responsibility',
                label: 'Data responsibility review',
                status: 'manual',
                summary: $dataResponsibilityMessage,
                detail: $dataResponsibilityGuidance,
                action: 'Review the data responsibility docs before using real visitor traffic, then explain the current retention and privacy boundaries during the demo.',
                docsUrl: $dataResponsibilityDocsUrl,
            ),
        ];

        $attentionCount = count(array_filter($items, fn (array $item): bool => $item['status'] === 'attention'));
        $manualCount = count(array_filter($items, fn (array $item): bool => $item['status'] === 'manual'));
        $readyCount = count(array_filter($items, fn (array $item): bool => $item['status'] === 'ready'));
        $status = $attentionCount > 0 ? 'attention' : ($manualCount > 0 ? 'manual' : 'ready');

        return [
            'attention_count' => $attentionCount,
            'items' => $items,
            'label' => match ($status) {
                'ready' => 'Ready for real traffic',
                'manual' => 'Waiting on you',
                default => 'Not ready yet',
            },
            'manual_count' => $manualCount,
            'ready_count' => $readyCount,
            'status' => $status,
            'summary' => sprintf(
                '%d ready / %d to confirm / %d not ready',
                $readyCount,
                $manualCount,
                $attentionCount,
            ),
        ];
    }

    /**
     * @param  array<int, string>  $commands
     * @return array{action: string, commands: array<int, string>, detail: string, docs_url: string|null, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function dogfoodGate(
        string $key,
        string $label,
        string $status,
        string $summary,
        string $detail,
        string $action,
        array $commands = [],
        ?string $docsUrl = null,
    ): array {
        return [
            'action' => $action,
            'commands' => $commands,
            'detail' => $detail,
            'docs_url' => $docsUrl,
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'status_label' => match ($status) {
                'ready' => 'Ready',
                'manual' => 'Confirm this',
                default => 'Needs attention',
            },
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, array{status: string}>  $checks
     * @param  array<int, string>  $keys
     */
    private function dogfoodDependencyStatus(array $checks, array $keys): string
    {
        $statuses = array_map(fn (string $key): string => $this->statusFromCheck($checks, $key), $keys);

        if (in_array('attention', $statuses, true)) {
            return 'attention';
        }

        if (in_array('manual', $statuses, true)) {
            return 'manual';
        }

        return 'ready';
    }

    /**
     * @return array{description: string, docs_url: string|null, items: array<int, array{description: string, label: string, value: string}>, label: string, reminders: array<int, string>, status: string, status_label: string, summary: string}
     */
    private function retentionSummary(): array
    {
        $retention = config('wayfindr.retention');
        $retention = is_array($retention) ? $retention : [];
        $status = in_array($retention['status'] ?? null, ['ready', 'manual', 'attention'], true)
            ? $retention['status']
            : 'manual';

        return [
            'description' => $this->stringOrDefault(
                $retention['description'] ?? null,
                'Assume application records, logs, and backups persist according to infrastructure defaults until an operator removes them or the host lifecycle removes them.',
            ),
            'docs_url' => $this->stringOrNull($retention['docs_url'] ?? null),
            'items' => $this->retentionSummaryItems($retention['items'] ?? null),
            'label' => $this->stringOrDefault($retention['label'] ?? null, 'Operator-owned retention'),
            'reminders' => $this->retentionSummaryReminders($retention['reminders'] ?? null),
            'status' => $status,
            'status_label' => match ($status) {
                'ready' => 'Documented',
                'attention' => 'Needs attention',
                default => 'Manual ownership',
            },
            'summary' => $this->stringOrDefault(
                $retention['summary'] ?? null,
                'Automatic retention controls are not enabled yet.',
            ),
        ];
    }

    /**
     * @return array<int, array{description: string, label: string, value: string}>
     */
    private function retentionSummaryItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'description' => $this->stringOrDefault($item['description'] ?? null, ''),
                'label' => $this->stringOrDefault($item['label'] ?? null, 'Retention item'),
                'value' => $this->stringOrDefault($item['value'] ?? null, 'Review required'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function retentionSummaryReminders(mixed $reminders): array
    {
        if (! is_array($reminders)) {
            return [];
        }

        return collect($reminders)
            ->map(fn (mixed $reminder): ?string => $this->stringOrNull($reminder))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $commands
     * @return array{action: string, commands: array<int, string>, confirmation: array{age_label: string|null, confirmed_at: string|null, confirmed_by: string, freshness_status: string, key: string, note_present: bool, stale_after_days: int|null}|null, confirmation_key: string, confirmable: bool, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function manualCheck(string $key, string $label, string $summary, string $detail, string $action, array $commands = []): array
    {
        $confirmation = $this->confirmations[$key] ?? null;

        if ($confirmation) {
            $confirmationPayload = $this->confirmationPayload($confirmation);
            $isStale = $confirmationPayload['freshness_status'] === 'stale';

            return $this->check(
                key: $key,
                label: $label,
                status: $isStale ? 'manual' : 'ready',
                summary: $isStale ? "{$label} confirmation needs refresh." : "{$label} confirmed.",
                detail: $this->confirmationDetail($confirmation, $confirmationPayload),
                action: 'Refresh this confirmation if the process manager, schedule, backup policy, or restore proof changes.',
                confirmable: true,
                confirmation: $confirmationPayload,
                confirmationKey: $key,
                statusLabel: $isStale ? 'Due again' : null,
                commands: $commands,
            );
        }

        return $this->check(
            key: $key,
            label: $label,
            status: 'manual',
            summary: $summary,
            detail: $detail,
            action: $action,
            confirmable: true,
            confirmationKey: $key,
            commands: $commands,
        );
    }

    /**
     * @param  array{age_label: string|null, confirmed_at: string|null, confirmed_by: string, freshness_status: string, key: string, note_present: bool, stale_after_days: int|null}  $confirmationPayload
     */
    private function confirmationDetail(OperatorReadinessConfirmation $confirmation, array $confirmationPayload): string
    {
        $confirmedBy = $confirmation->confirmedBy?->name ?? 'Unknown operator';
        $ageLabel = $confirmationPayload['age_label'];
        $detail = $ageLabel
            ? "Confirmed by {$confirmedBy} {$ageLabel}."
            : "Confirmed by {$confirmedBy}.";

        return $confirmationPayload['note_present'] ? "{$detail} Evidence note recorded." : $detail;
    }

    /**
     * @return array{age_label: string|null, confirmed_at: string|null, confirmed_by: string, freshness_status: string, key: string, note_present: bool, stale_after_days: int|null}
     */
    private function confirmationPayload(OperatorReadinessConfirmation $confirmation): array
    {
        $confirmedAt = $confirmation->confirmed_at;
        $staleAfterDays = self::CONFIRMATION_STALE_AFTER_DAYS[$confirmation->key] ?? null;

        return [
            'age_label' => $this->confirmationAgeLabel($confirmedAt),
            'confirmed_at' => $confirmedAt?->toIso8601String(),
            'confirmed_by' => $confirmation->confirmedBy?->name ?? 'Unknown operator',
            'freshness_status' => $this->isConfirmationStale($confirmation) ? 'stale' : 'fresh',
            'key' => $confirmation->key,
            'note_present' => trim((string) $confirmation->note) !== '',
            'stale_after_days' => $staleAfterDays,
        ];
    }

    /**
     * @param  array<int, array{confirmation?: array{age_label: string|null, confirmed_at: string|null, confirmed_by: string, freshness_status: string, key: string, note_present: bool, stale_after_days: int|null}|null, confirmable?: bool, key: string, label: string}>  $checks
     * @return array{fresh_count: int, items: array<int, array{key: string, label: string, note_status: string, status: string, status_label: string, summary: string}>, missing_count: int, stale_count: int}
     */
    private function proofCoverage(array $checks): array
    {
        $items = collect($checks)
            ->filter(fn (array $check): bool => (bool) ($check['confirmable'] ?? false))
            ->map(function (array $check): array {
                $confirmation = $check['confirmation'] ?? null;

                if (! $confirmation) {
                    return [
                        'key' => $check['key'],
                        'label' => $check['label'],
                        'note_status' => 'No evidence note recorded',
                        'status' => 'missing',
                        'status_label' => 'Missing',
                        'summary' => 'No confirmation recorded.',
                    ];
                }

                $isStale = $confirmation['freshness_status'] === 'stale';
                $ageLabel = $confirmation['age_label'];
                $summary = $ageLabel
                    ? sprintf('Confirmed by %s %s.', $confirmation['confirmed_by'], $ageLabel)
                    : sprintf('Confirmed by %s.', $confirmation['confirmed_by']);

                return [
                    'key' => $check['key'],
                    'label' => $check['label'],
                    'note_status' => $confirmation['note_present']
                        ? 'Evidence note recorded'
                        : 'No evidence note recorded',
                    'status' => $isStale ? 'stale' : 'fresh',
                    'status_label' => $isStale ? 'Due again' : 'Confirmed',
                    'summary' => $summary,
                ];
            })
            ->values();

        return [
            'fresh_count' => $items->where('status', 'fresh')->count(),
            'items' => $items->all(),
            'missing_count' => $items->where('status', 'missing')->count(),
            'stale_count' => $items->where('status', 'stale')->count(),
        ];
    }

    private function isConfirmationStale(OperatorReadinessConfirmation $confirmation): bool
    {
        $staleAfterDays = self::CONFIRMATION_STALE_AFTER_DAYS[$confirmation->key] ?? null;

        if ($staleAfterDays === null || $confirmation->confirmed_at === null) {
            return false;
        }

        return $confirmation->confirmed_at->lt(now()->subDays($staleAfterDays));
    }

    private function confirmationAgeLabel(?CarbonInterface $confirmedAt): ?string
    {
        if (! $confirmedAt) {
            return null;
        }

        $days = (int) $confirmedAt->diffInDays(now());

        if ($days > 0 && $days < 30) {
            return $days === 1 ? '1 day ago' : "{$days} days ago";
        }

        return $confirmedAt->diffForHumans();
    }

    /**
     * @param  array<int, array{action: string, commands?: array<int, string>, detail: string, key: string, label: string, status: string, status_label: string, summary: string}>  $checks
     * @param  array<int, array{action: string, commands: array<int, string>, key: string, label: string, status: string, status_label: string, summary: string}>  $smokePath
     * @return array{action: string, commands?: array<int, string>, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function nextStep(array $checks, array $smokePath, array $retentionSummary = []): array
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'attention') {
                return [
                    ...$check,
                    'label' => sprintf('Fix %s', $check['label']),
                ];
            }
        }

        if (($retentionSummary['status'] ?? null) === 'attention') {
            return $this->check(
                key: 'retention_posture',
                label: 'Fix how long data is kept',
                status: 'attention',
                summary: $this->stringOrDefault($retentionSummary['summary'] ?? null, 'Data retention needs attention.'),
                detail: $this->stringOrDefault($retentionSummary['description'] ?? null, 'A configured retention status is blocking readiness.'),
                action: 'Resolve the configured retention attention before onboarding real visitor traffic.',
                statusLabel: $this->stringOrNull($retentionSummary['status_label'] ?? null),
            );
        }

        foreach ($smokePath as $step) {
            if ($step['status'] === 'manual') {
                // A smoke step has one sentence, not a summary and a detail.
                // Copying the summary across made the card print the same line
                // twice wherever a smoke step was the next step.
                return [
                    ...$step,
                    'detail' => '',
                ];
            }
        }

        return $this->check(
            key: 'ready_for_traffic',
            label: 'Ready for traffic',
            status: 'ready',
            summary: 'No readiness items need attention.',
            detail: 'Keep setup tests, mail checks, queue monitoring, and restore checks in the operator rhythm.',
            action: 'Onboard real sites gradually, starting with a low-risk support surface.'
        );
    }

    /**
     * @return array{action: string, commands: array<int, string>, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function check(
        string $key,
        string $label,
        string $status,
        string $summary,
        string $detail,
        string $action,
        bool $confirmable = false,
        ?array $confirmation = null,
        ?string $confirmationKey = null,
        ?string $statusLabel = null,
        array $commands = [],
    ): array {
        return [
            'action' => $action,
            'commands' => $commands,
            'confirmation' => $confirmation,
            'confirmation_key' => $confirmationKey ?? $key,
            'confirmable' => $confirmable,
            'detail' => $detail,
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'status_label' => $statusLabel ?? match ($status) {
                'ready' => 'Ready',
                'manual' => 'Confirm this',
                default => 'Needs attention',
            },
            'summary' => $summary,
        ];
    }

    /**
     * @return array{action: string, commands: array<int, string>, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function smokeStep(
        string $key,
        string $label,
        string $status,
        string $summary,
        string $action,
        ?string $confirmationKey = null,
        bool $confirmable = false,
        ?array $confirmation = null,
        ?string $statusLabel = null,
        array $commands = [],
    ): array {
        return [
            'action' => $action,
            'commands' => $commands,
            'confirmation' => $confirmation,
            'confirmation_key' => $confirmationKey ?? $key,
            'confirmable' => $confirmable,
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'status_label' => $statusLabel ?? match ($status) {
                'ready' => 'Ready',
                'manual' => 'Confirm this',
                default => 'Needs attention',
            },
            'summary' => $summary,
        ];
    }

    private function hasValue(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : $value !== null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        return $this->stringOrNull($value) ?? $default;
    }

    private function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function isLocalMailHost(string $host): bool
    {
        return $this->isLocalHost(strtolower(trim($host)));
    }

    private function hasUnsupportedSmtpScheme(mixed $scheme): bool
    {
        if (! $this->hasValue($scheme)) {
            return false;
        }

        return ! in_array(strtolower((string) $scheme), ['smtp', 'smtps'], true);
    }

    private function isPlaceholderMailFrom(string $address): bool
    {
        return in_array(strtolower(trim($address)), [
            'hello@example.com',
            'hello@example.test',
            'hello@wayfindr.local',
        ], true);
    }

    /**
     * @param  array<string, array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}>  $checks
     */
    private function statusFromCheck(array $checks, string $key): string
    {
        return $checks[$key]['status'] ?? 'attention';
    }

    private function normalizedPublicUrl(): string
    {
        $url = config('app.url');

        if (! is_string($url)) {
            return '';
        }

        return rtrim(trim($url), '/');
    }
}
