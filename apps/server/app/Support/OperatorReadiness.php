<?php

namespace App\Support;

use App\Models\OperatorReadinessConfirmation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OperatorReadiness
{
    private const CONFIRMATION_STALE_AFTER_DAYS = [
        'scheduler' => 7,
        'backups_restore' => 30,
    ];

    /** @var array<string, OperatorReadinessConfirmation> */
    private array $confirmations = [];

    public function __construct(
        private readonly RealtimeHealth $realtimeHealth,
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
     *     checks: array<int, array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}>,
     *     label: string,
     *     manual_count: int,
     *     next_step: array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string},
     *     ready_count: int,
     *     smoke_path: array<int, array{action: string, key: string, label: string, status: string, status_label: string, summary: string}>
     * }
     */
    public function summary(): array
    {
        $this->loadConfirmations();

        $checks = [
            $this->applicationKey(),
            $this->publicUrl(),
            $this->databaseConnection(),
            $this->mailTransport(),
            $this->queueWorker(),
            $this->realtimeBroadcasting(),
            $this->storagePaths(),
            $this->scheduler(),
            $this->alertDigestDelivery(),
            $this->backupsRestore(),
        ];

        $attentionCount = count(array_filter($checks, fn (array $check): bool => $check['status'] === 'attention'));
        $manualCount = count(array_filter($checks, fn (array $check): bool => $check['status'] === 'manual'));
        $readyCount = count(array_filter($checks, fn (array $check): bool => $check['status'] === 'ready'));
        $checksByKey = collect($checks)->keyBy('key')->all();
        $smokePath = $this->postInstallSmokePath($checksByKey);

        return [
            'attention_count' => $attentionCount,
            'checks' => $checks,
            'label' => $attentionCount > 0 ? 'Needs attention' : 'Ready',
            'manual_count' => $manualCount,
            'next_step' => $this->nextStep($checks, $smokePath),
            'ready_count' => $readyCount,
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
            action: 'Run php artisan key:generate and save the generated APP_KEY in the environment.'
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
            action: 'Run php artisan wayfindr:mail-test --to=you@example.com from apps/server after deploy. For STARTTLS ports such as 587 or 2587, leave MAIL_SCHEME unset; use smtps only for port 465.'
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
                action: 'Use database or redis queues and run php artisan queue:work under Forge, Supervisor, systemd, or your process manager.'
            );
        }

        return $this->check(
            key: 'queue_worker',
            label: 'Queue worker',
            status: 'ready',
            summary: sprintf('QUEUE_CONNECTION is %s.', $connection),
            detail: 'The queue driver is configured for background work.',
            action: 'Confirm php artisan queue:work is managed by Forge, Supervisor, systemd, or your deployment platform, then run php artisan queue:failed after smoke tests to inspect failures.'
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
                : 'Set BROADCAST_CONNECTION=reverb plus REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET, REVERB_HOST, REVERB_PORT, and REVERB_SCHEME.'
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
    private function scheduler(): array
    {
        return $this->manualCheck(
            key: 'scheduler',
            label: 'Scheduler',
            summary: 'Confirm the Laravel scheduler is running once per minute.',
            detail: 'Wayfindr cannot safely prove cron or external scheduler setup from inside the request. Alert digest email depends on the scheduler running the hourly digest command.',
            action: 'Configure * * * * * cd /path/to/apps/server && php artisan schedule:run or the equivalent scheduled job in your host, then run php artisan schedule:list and confirm php artisan wayfindr:send-alert-digests is listed.'
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
                action: 'Run php artisan migrate from apps/server, then revisit readiness once agents can opt into digest email.'
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
     * @param  array<string, array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}>  $checks
     * @return array<int, array{action: string, key: string, label: string, status: string, status_label: string, summary: string}>
     */
    private function postInstallSmokePath(array $checks): array
    {
        $backgroundStatus = $this->statusFromCheck($checks, 'queue_worker') === 'attention'
            ? 'attention'
            : $this->statusFromCheck($checks, 'scheduler');
        $schedulerCheck = $checks['scheduler'] ?? null;
        $backupsCheck = $checks['backups_restore'] ?? null;

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
                action: 'Run php artisan wayfindr:mail-test --to=you@example.com from apps/server, then confirm the message lands in a real inbox.'
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
            ),
            $this->smokeStep(
                key: 'widget_smoke',
                label: 'Send a widget smoke test',
                status: $this->statusFromCheck($checks, 'realtime_broadcasting'),
                summary: 'The real support loop is visitor message, agent reply, and live updates without manual refresh.',
                action: 'Install the widget on a smoke site, send a visitor message, reply as an agent, and confirm both sides update.'
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
     * @return array{action: string, confirmation: array{confirmed_at: string|null, confirmed_by: string, key: string, note: string|null}|null, confirmation_key: string, confirmable: bool, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function manualCheck(string $key, string $label, string $summary, string $detail, string $action): array
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
                statusLabel: $isStale ? 'Refresh due' : null,
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
        );
    }

    /**
     * @param  array{age_label: string|null, confirmed_at: string|null, confirmed_by: string, freshness_status: string, key: string, note: string|null, stale_after_days: int|null}  $confirmationPayload
     */
    private function confirmationDetail(OperatorReadinessConfirmation $confirmation, array $confirmationPayload): string
    {
        $confirmedBy = $confirmation->confirmedBy?->name ?? 'Unknown operator';
        $ageLabel = $confirmationPayload['age_label'];
        $note = trim((string) $confirmation->note);
        $detail = $ageLabel
            ? "Confirmed by {$confirmedBy} {$ageLabel}."
            : "Confirmed by {$confirmedBy}.";

        return $note !== '' ? "{$detail} Note: {$note}" : $detail;
    }

    /**
     * @return array{age_label: string|null, confirmed_at: string|null, confirmed_by: string, freshness_status: string, key: string, note: string|null, stale_after_days: int|null}
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
            'note' => $confirmation->note,
            'stale_after_days' => $staleAfterDays,
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
     * @param  array<int, array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}>  $checks
     * @param  array<int, array{action: string, key: string, label: string, status: string, status_label: string, summary: string}>  $smokePath
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function nextStep(array $checks, array $smokePath): array
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'attention') {
                return [
                    ...$check,
                    'label' => sprintf('Fix %s', $check['label']),
                ];
            }
        }

        foreach ($smokePath as $step) {
            if ($step['status'] === 'manual') {
                return [
                    ...$step,
                    'detail' => $step['summary'],
                ];
            }
        }

        return $this->check(
            key: 'ready_for_traffic',
            label: 'Ready for traffic',
            status: 'ready',
            summary: 'No readiness items need attention.',
            detail: 'Keep smoke tests, mail checks, queue monitoring, and restore checks in the operator rhythm.',
            action: 'Onboard real sites gradually, starting with a low-risk support surface.'
        );
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
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
    ): array {
        return [
            'action' => $action,
            'confirmation' => $confirmation,
            'confirmation_key' => $confirmationKey ?? $key,
            'confirmable' => $confirmable,
            'detail' => $detail,
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'status_label' => $statusLabel ?? match ($status) {
                'ready' => 'Ready',
                'manual' => 'Manual check',
                default => 'Needs attention',
            },
            'summary' => $summary,
        ];
    }

    /**
     * @return array{action: string, key: string, label: string, status: string, status_label: string, summary: string}
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
    ): array {
        return [
            'action' => $action,
            'confirmation' => $confirmation,
            'confirmation_key' => $confirmationKey ?? $key,
            'confirmable' => $confirmable,
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'status_label' => $statusLabel ?? match ($status) {
                'ready' => 'Ready',
                'manual' => 'Manual check',
                default => 'Needs attention',
            },
            'summary' => $summary,
        ];
    }

    private function hasValue(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : $value !== null;
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
