<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Throwable;

/**
 * Translate the request-neutral diagnostics shown by the operator console.
 *
 * Readiness is also consumed by commands and low-level tests, so the support
 * classes keep their existing English diagnostic fields. Stable keys and
 * variants cross this boundary; only this request-facing presenter consults
 * the catalogue or formats values for the current reader.
 */
final class OperatorDashboardPresenter
{
    /** @param array<string, mixed> $summary */
    public static function readiness(array $summary): array
    {
        $checks = collect($summary['checks'] ?? [])
            ->filter(fn (mixed $check): bool => is_array($check))
            ->map(fn (array $check): array => OperatorReadinessPresenter::localize($check))
            ->values()
            ->all();
        $checksByKey = collect($checks)->keyBy('key')->all();
        $rawSmokePath = collect($summary['smoke_path'] ?? [])
            ->filter(fn (mixed $step): bool => is_array($step))
            ->values();
        $smokePath = $rawSmokePath
            ->map(fn (array $step): array => self::smokeStep($step, $checksByKey))
            ->all();
        $smokeByKey = collect($smokePath)->keyBy('key')->all();
        $retention = self::retention(is_array($summary['retention_summary'] ?? null)
            ? $summary['retention_summary']
            : []);

        return [
            ...$summary,
            'checks' => $checks,
            'cobrowse_budget_defaults' => self::cobrowseBudgetDefaults(),
            'dogfood_summary' => self::dogfood(
                is_array($summary['dogfood_summary'] ?? null) ? $summary['dogfood_summary'] : [],
                $checksByKey,
            ),
            'label' => (int) ($summary['attention_count'] ?? 0) > 0
                ? __('operator.readiness.status.needs_attention')
                : __('operator.readiness.status.ready'),
            'next_step' => self::nextStep(
                is_array($summary['next_step'] ?? null) ? $summary['next_step'] : [],
                $checksByKey,
                $smokeByKey,
                $retention,
            ),
            'proof_coverage' => self::proofCoverage(
                is_array($summary['proof_coverage'] ?? null) ? $summary['proof_coverage'] : [],
                $checksByKey,
            ),
            'retention_summary' => $retention,
            'smoke_path' => $smokePath,
        ];
    }

    /** @param array<string, mixed> $summary */
    public static function realtime(array $summary): array
    {
        $status = match ($summary['status'] ?? null) {
            'ready' => 'ready',
            'disabled' => 'disabled',
            default => 'incomplete',
        };

        return [
            ...$summary,
            'driver' => self::raw((string) ($summary['driver'] ?? '')),
            'endpoint' => self::runtimeValue((string) ($summary['endpoint'] ?? '')),
            'label' => __("operator.dashboard.realtime.states.{$status}.label"),
            'message' => self::feedback("operator.dashboard.realtime.states.{$status}.message"),
            'scheme' => self::runtimeValue((string) ($summary['scheme'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $summary */
    public static function systemIdentity(array $summary): array
    {
        $itemKeys = [
            'version',
            'revision',
            'environment',
            'debug',
            'php',
            'laravel',
            'queue',
            'broadcast',
        ];
        $docKeys = ['self_hosting', 'runtime', 'forge'];

        return [
            'items' => collect($summary['items'] ?? [])
                ->filter(fn (mixed $item): bool => is_array($item))
                ->values()
                ->map(function (array $item, int $index) use ($itemKeys): array {
                    $key = $itemKeys[$index] ?? 'unknown';
                    $value = (string) ($item['value'] ?? '');

                    if ($key === 'debug') {
                        $value = __(($value === 'Enabled')
                            ? 'operator.dashboard.system.values.enabled'
                            : 'operator.dashboard.system.values.disabled');
                    } else {
                        $value = self::runtimeValue($value);
                    }

                    return [
                        'label' => __("operator.dashboard.system.items.{$key}"),
                        'value' => $value,
                    ];
                })
                ->all(),
            'docs' => collect($summary['docs'] ?? [])
                ->filter(fn (mixed $doc): bool => is_array($doc))
                ->values()
                ->map(function (array $doc, int $index) use ($docKeys): array {
                    $key = $docKeys[$index] ?? 'unknown';

                    return [
                        ...$doc,
                        'description' => __("operator.dashboard.system.docs.{$key}.description"),
                        'label' => __("operator.dashboard.system.docs.{$key}.label"),
                    ];
                })
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, array<string, mixed>>  $checks
     */
    private static function smokeStep(array $step, array $checks): array
    {
        $key = (string) ($step['key'] ?? 'unknown');
        $base = "operator.dashboard.smoke.steps.{$key}";
        $summary = self::feedback($base.'.summary');
        $action = self::feedback($base.'.action');
        $statusLabel = self::statusLabel($step);

        if ($key === 'cobrowse_transport_smoke' && isset($checks['cobrowse_transport'])) {
            $summary = $checks['cobrowse_transport']['summary'];
            $statusLabel = $checks['cobrowse_transport']['status_label'];

            if (($step['commands'] ?? []) === []) {
                $action = $checks['cobrowse_transport']['action'];
            }
        }

        return [
            ...$step,
            'action' => $action,
            'label' => __($base.'.label'),
            'status_label' => $statusLabel,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, array<string, mixed>>  $checks
     */
    private static function dogfood(array $summary, array $checks): array
    {
        $items = collect($summary['items'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item) use ($checks): array {
                $key = (string) ($item['key'] ?? 'unknown');
                $base = "operator.dashboard.dogfood.items.{$key}";
                $summary = self::feedback($base.'.summary');
                $detail = self::feedback($base.'.detail');

                if ($key === 'production_https_host' && isset($checks['public_url'])) {
                    $summary = $checks['public_url']['summary'];
                } elseif ($key === 'support_loop_smoke') {
                    $summary = self::feedback($base.'.'.(($item['status'] ?? null) === 'attention' ? 'attention' : 'manual').'_summary');
                    $detail = match ($item['translation']['detail_variant'] ?? null) {
                        'default' => self::feedback($base.'.detail'),
                        'with_signal' => self::feedback($base.'.detail_with_signal', localized: [
                            'signal' => rtrim(__('operator.dashboard.smoke.steps.widget_smoke.summary'), '.'),
                        ]),
                        default => self::configuredCopy(
                            (string) ($item['detail'] ?? ''),
                            'One run must prove visitor message, agent reply, support-code lookup, and ticket creation.',
                            $base.'.detail',
                        ),
                    };
                } elseif ($key === 'ticket_workflow') {
                    $summary = self::feedback($base.'.'.(($item['status'] ?? null) === 'attention' ? 'attention' : 'manual').'_summary');
                } elseif ($key === 'alerts_email') {
                    $summary = self::feedback($base.'.'.(($item['status'] ?? null) === 'attention' ? 'attention' : 'ready').'_summary');
                } elseif ($key === 'cobrowse_observe_mode' && isset($checks['cobrowse_transport'])) {
                    $summary = $checks['cobrowse_transport']['summary'];
                } elseif ($key === 'data_responsibility') {
                    $summary = self::configuredCopy(
                        (string) ($item['summary'] ?? ''),
                        'Retaining visitor-supplied data may create privacy, security, and legal obligations.',
                        $base.'.summary',
                    );
                    $detail = self::configuredCopy(
                        (string) ($item['detail'] ?? ''),
                        'Keep only what you need, set a retention period you can justify, and make sure your privacy notice matches how this Wayfindr installation is used.',
                        $base.'.detail',
                    );
                }

                return [
                    ...$item,
                    'action' => self::feedback($base.'.action'),
                    'detail' => $detail,
                    'label' => __($base.'.label'),
                    'status_label' => self::statusLabel($item),
                    'summary' => $summary,
                ];
            })
            ->values()
            ->all();
        $status = (string) ($summary['status'] ?? 'attention');

        return [
            ...$summary,
            'items' => $items,
            'label' => __("operator.dashboard.dogfood.status.{$status}"),
            'summary' => self::feedback('operator.dashboard.dogfood.counts', localized: [
                'attention' => ReaderNumber::count((int) ($summary['attention_count'] ?? 0)),
                'manual' => ReaderNumber::count((int) ($summary['manual_count'] ?? 0)),
                'ready' => ReaderNumber::count((int) ($summary['ready_count'] ?? 0)),
            ]),
        ];
    }

    /** @param array<string, mixed> $retention */
    private static function retention(array $retention): array
    {
        $items = collect($retention['items'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => self::retentionItem($item))
            ->values()
            ->all();

        return [
            ...$retention,
            'description' => self::configuredCopy(
                (string) ($retention['description'] ?? ''),
                'Assume application records, logs, and backups persist according to infrastructure defaults until an operator removes them or the host lifecycle removes them.',
                'operator.dashboard.retention.description',
            ),
            'items' => $items,
            'label' => self::configuredCopy(
                (string) ($retention['label'] ?? ''),
                'Operator-owned retention',
                'operator.dashboard.retention.label',
            ),
            'reminders' => collect($retention['reminders'] ?? [])
                ->filter(fn (mixed $reminder): bool => is_string($reminder))
                ->values()
                ->map(fn (string $reminder, int $index) => self::configuredCopy(
                    $reminder,
                    [
                        'Review privacy notices before real visitor traffic reaches the install.',
                        'Keep retention expectations aligned with backups, logs, and support workflows.',
                    ][$index] ?? '',
                    'operator.dashboard.retention.reminders.'.($index + 1),
                ))
                ->all(),
            'status_label' => __('operator.dashboard.retention.status.'.($retention['status'] ?? 'manual')),
            'summary' => self::configuredCopy(
                (string) ($retention['summary'] ?? ''),
                'Cobrowse page content is pruned automatically; broader retention stays operator-owned.',
                'operator.dashboard.retention.summary',
            ),
        ];
    }

    /** @param array<string, mixed> $item */
    private static function retentionItem(array $item): array
    {
        $key = match ((string) ($item['label'] ?? '')) {
            'Application records' => 'application_records',
            'Logs and backups' => 'logs_backups',
            'Cobrowse page content' => 'cobrowse_content',
            'Automatic deletion' => 'automatic_deletion',
            default => null,
        };

        if ($key === null) {
            return [
                ...$item,
                'description' => self::raw((string) ($item['description'] ?? '')),
                'label' => self::raw((string) ($item['label'] ?? '')),
                'value' => self::raw((string) ($item['value'] ?? '')),
            ];
        }

        $defaultDescription = match ($key) {
            'application_records' => 'Conversations, messages, tickets, visitors, cobrowse metadata, and audit records stay in the application database until an operator removes or prunes them.',
            'logs_backups' => 'Server logs, snapshots, database dumps, and storage backups follow host and provider retention policies outside Wayfindr.',
            'cobrowse_content' => 'The scheduled wayfindr:prune-cobrowse-content command strips raw snapshot HTML, page text, and retained mutation batches from ended cobrowse sessions, keeping only content-free provenance (counts, timestamps, hashes, and audit events).',
            'automatic_deletion' => 'Beyond cobrowse page content, deletion, export, and retention controls remain future work; explain that before real support traffic.',
        };
        $actualValue = (string) ($item['value'] ?? '');
        $value = $key === 'cobrowse_content'
            ? self::raw($actualValue)
            : self::configuredCopy(
                $actualValue,
                match ($key) {
                    'application_records' => 'Manual lifecycle',
                    'logs_backups' => 'Infrastructure lifecycle',
                    'automatic_deletion' => 'Cobrowse content only',
                },
                "operator.dashboard.retention.items.{$key}.value",
            );

        if ($key === 'cobrowse_content'
            && preg_match('/Auto-pruned (\d+) hours? after a session ends/', $actualValue, $matches) === 1) {
            $hours = (int) $matches[1];
            $value = trans_choice(
                'operator.dashboard.retention.items.cobrowse_content.value',
                $hours,
                ['count' => ReaderNumber::count($hours)],
            );
        }

        return [
            ...$item,
            'description' => self::configuredCopy(
                (string) ($item['description'] ?? ''),
                $defaultDescription,
                "operator.dashboard.retention.items.{$key}.description",
            ),
            'label' => __("operator.dashboard.retention.items.{$key}.label"),
            'value' => $value,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, array<string, mixed>>  $checks
     * @param  array<string, array<string, mixed>>  $smoke
     * @param  array<string, mixed>  $retention
     */
    private static function nextStep(array $raw, array $checks, array $smoke, array $retention): array
    {
        $key = (string) ($raw['key'] ?? '');

        if (isset($checks[$key])) {
            $check = $checks[$key];

            return [
                ...$check,
                'label' => __('operator.dashboard.next.fix', ['label' => $check['label']]),
            ];
        }

        if ($key === 'retention_posture') {
            return [
                ...$raw,
                'action' => self::feedback('operator.dashboard.next.retention.action'),
                'detail' => $retention['description'],
                'label' => __('operator.dashboard.next.retention.label'),
                'status_label' => __('operator.readiness.status.needs_attention'),
                'summary' => $retention['summary'],
            ];
        }

        if (isset($smoke[$key])) {
            return [...$smoke[$key], 'detail' => ''];
        }

        return [
            ...$raw,
            'action' => self::feedback('operator.dashboard.next.ready.action'),
            'detail' => self::feedback('operator.dashboard.next.ready.detail'),
            'label' => __('operator.dashboard.next.ready.label'),
            'status_label' => __('operator.readiness.status.ready'),
            'summary' => self::feedback('operator.dashboard.next.ready.summary'),
        ];
    }

    /**
     * @param  array<string, mixed>  $coverage
     * @param  array<string, array<string, mixed>>  $checks
     */
    private static function proofCoverage(array $coverage, array $checks): array
    {
        $items = collect($coverage['items'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item) use ($checks): array {
                $key = (string) ($item['key'] ?? '');
                $confirmation = is_array($checks[$key]['confirmation'] ?? null)
                    ? $checks[$key]['confirmation']
                    : null;

                return [
                    ...$item,
                    'label' => $checks[$key]['label'] ?? self::raw((string) ($item['label'] ?? '')),
                    'note_status' => __('operator.dashboard.proof.note.'.(($confirmation['note_present'] ?? false) ? 'recorded' : 'missing')),
                    'status_label' => __('operator.dashboard.proof.status.'.($item['status'] ?? 'missing')),
                    'summary' => $confirmation === null
                        ? self::feedback('operator.dashboard.proof.summary_missing')
                        : self::confirmationSummary($confirmation),
                ];
            })
            ->values()
            ->all();

        return [...$coverage, 'items' => $items];
    }

    /** @param array<string, mixed> $confirmation */
    private static function confirmationSummary(array $confirmation): array
    {
        $parameters = [];
        $localized = [];

        if ((bool) ($confirmation['confirmed_by_known'] ?? true)) {
            $parameters['name'] = (string) ($confirmation['confirmed_by'] ?? '');
        } else {
            $localized['name'] = __('operator.readiness.confirmation.unknown_operator');
        }

        $age = self::relativeAge($confirmation['confirmed_at'] ?? null);

        if ($age !== null) {
            $localized['age'] = $age;
        }

        return self::feedback(
            'operator.dashboard.proof.'.($age === null ? 'confirmed' : 'confirmed_with_age'),
            $parameters,
            $localized,
        );
    }

    private static function relativeAge(mixed $confirmedAt): ?string
    {
        if (! is_string($confirmedAt) || trim($confirmedAt) === '') {
            return null;
        }

        try {
            $confirmed = CarbonImmutable::parse($confirmedAt);
            $days = (int) $confirmed->diffInDays(now());

            if ($days > 0 && $days < 30) {
                return trans_choice(
                    'operator.readiness.confirmation.days_ago',
                    $days,
                    ['count' => ReaderNumber::count($days)],
                );
            }

            return $confirmed->locale(App::currentLocale())->diffForHumans();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    private static function cobrowseBudgetDefaults(): array
    {
        return [
            self::budgetGroup('server', [
                self::budgetItem('snapshot_html', CobrowsePayloadBudget::SNAPSHOT_HTML_MAX_CHARACTERS, 'characters'),
                self::budgetItem('snapshot_text', CobrowsePayloadBudget::SNAPSHOT_TEXT_MAX_CHARACTERS, 'characters'),
                self::budgetItem('server_mutation_batch', CobrowsePayloadBudget::MUTATION_BATCH_MAX_ITEMS, 'items'),
                self::budgetItem('mutation_text', CobrowsePayloadBudget::MUTATION_TEXT_MAX_CHARACTERS, 'characters'),
                self::budgetItem('mutation_html', CobrowsePayloadBudget::MUTATION_HTML_MAX_CHARACTERS, 'characters'),
                self::budgetItem('recent_batches', CobrowsePayloadBudget::MUTATION_RECENT_BATCHES_RETAINED, 'retained'),
                self::budgetItem('server_telemetry', CobrowsePayloadBudget::TELEMETRY_PAYLOAD_MAX_BYTES, 'bytes'),
            ]),
            self::budgetGroup('widget', [
                self::budgetItem('widget_batch', CobrowsePayloadBudget::WIDGET_MUTATION_BATCH_MAX_BYTES, 'bytes'),
                self::budgetItem('widget_queue', CobrowsePayloadBudget::WIDGET_MUTATION_QUEUE_MAX_RECORDS, 'pending'),
                self::budgetItem('mutation_flush', CobrowsePayloadBudget::WIDGET_MUTATION_FLUSH_MS, 'milliseconds'),
                self::budgetItem('pressure_resync', CobrowsePayloadBudget::WIDGET_PRESSURE_RESYNC_MS, 'milliseconds'),
                self::budgetItem('status_poll', CobrowsePayloadBudget::WIDGET_STATUS_POLL_MS, 'milliseconds'),
                self::budgetItem('resync_attempts', CobrowsePayloadBudget::WIDGET_RESYNC_MAX_ATTEMPTS, 'attempts'),
            ]),
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private static function budgetGroup(string $key, array $items): array
    {
        return [
            'description' => self::feedback("operator.dashboard.budget.groups.{$key}.description"),
            'items' => $items,
            'label' => __("operator.dashboard.budget.groups.{$key}.label"),
        ];
    }

    private static function budgetItem(string $key, int $amount, string $unit): array
    {
        return [
            'label' => __("operator.dashboard.budget.items.{$key}"),
            'value' => trans_choice(
                "operator.dashboard.budget.units.{$unit}",
                $amount,
                ['count' => ReaderNumber::count($amount)],
            ),
        ];
    }

    /** @param array<string, mixed> $item */
    private static function statusLabel(array $item): string
    {
        if (($item['confirmation']['freshness_status'] ?? null) === 'stale') {
            return __('operator.readiness.status.due_again');
        }

        return match ($item['status'] ?? null) {
            'ready' => __('operator.readiness.status.ready'),
            'manual' => __('operator.readiness.status.confirm_this'),
            default => __('operator.readiness.status.needs_attention'),
        };
    }

    private static function runtimeValue(string $value): array|string
    {
        return match ($value) {
            'Not configured' => __('operator.dashboard.common.not_configured'),
            'Incomplete' => __('operator.dashboard.common.incomplete'),
            'Missing' => __('operator.dashboard.common.missing'),
            default => self::raw($value),
        };
    }

    private static function configuredCopy(string $actual, string $default, string $key): array
    {
        return $actual === $default ? self::feedback($key) : self::raw($actual);
    }

    /** @return array{raw: string} */
    private static function raw(string $value): array
    {
        return ['raw' => $value];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $localized
     * @return array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}
     */
    private static function feedback(string $key, array $parameters = [], array $localized = []): array
    {
        return [
            'key' => $key,
            'localized_parameters' => array_map(static fn (mixed $value): string => (string) $value, $localized),
            'parameters' => array_map(static fn (mixed $value): string => (string) $value, $parameters),
        ];
    }
}
