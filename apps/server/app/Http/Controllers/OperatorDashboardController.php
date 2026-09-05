<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\DashboardLanguage;
use App\Support\OperatorDashboardPresenter;
use App\Support\OperatorReadiness;
use App\Support\OperatorSystemIdentity;
use App\Support\ReaderNumber;
use App\Support\RealtimeHealth;
use App\Support\Release\UpgradeGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OperatorDashboardController extends Controller
{
    public function __invoke(
        Request $request,
        OperatorReadiness $readiness,
        OperatorSystemIdentity $systemIdentity,
        UpgradeGuard $guard,
        RealtimeHealth $realtimeHealth,
    ): View {
        return view('operator.dashboard', [
            'operator' => $request->user(),
            'operatorActivity' => $this->operatorActivity(),
            'operatorActivityTotal' => $this->operatorActivityTotal(),
            'readiness' => OperatorDashboardPresenter::readiness($readiness->summary()),
            'realtimeHealth' => OperatorDashboardPresenter::realtime($realtimeHealth->summary()),
            'systemIdentity' => OperatorDashboardPresenter::systemIdentity($systemIdentity->summary()),
            // Advisory notices (ADR 0013). This is the console half of "reported
            // where the operator meets it" — the other half is the upgrade
            // output. `notices()` swallows an unreadable declaration and returns
            // an empty list, so a broken manifest cannot take the console down;
            // the readiness panel and the serving gate are what react to that.
            'releaseNotices' => $guard->notices(),
        ]);
    }

    /**
     * @return Collection<int, array{actor: array|string, body: array|string, details: array<int, array{label: string, value: array|string}>, label: string, occurred_at: Carbon|null}>
     */
    private function operatorActivity(): Collection
    {
        return AuditEvent::query()
            ->with('actor')
            ->whereIn('action', $this->operatorActivityActions())
            ->latest('occurred_at')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (AuditEvent $event): array => [
                'actor' => $this->operatorActivityActor($event),
                'body' => $this->operatorActivityBody($event),
                'details' => $this->operatorActivityDetails($event),
                'label' => $this->operatorActivityLabel($event),
                'occurred_at' => $event->occurred_at,
            ]);
    }

    private function operatorActivityTotal(): int
    {
        return AuditEvent::query()
            ->whereIn('action', $this->operatorActivityActions())
            ->count();
    }

    /**
     * @return array<int, string>
     */
    private function operatorActivityActions(): array
    {
        return [
            'operator_readiness.confirmed',
            'operator_settings.mail.updated',
            'operator_settings.webpush.updated',
            'operator_settings.storage.updated',
            'operator_settings.scanning.updated',
            'operator_settings.backup.updated',
            'operator_settings.backup.triggered',
            'operator_settings.localization.updated',
        ];
    }

    private function operatorActivityActor(AuditEvent $event): array|string
    {
        if ($event->actor instanceof User) {
            return $this->raw($event->actor->name);
        }

        return __('operator.dashboard.activity.system');
    }

    private function operatorActivityLabel(AuditEvent $event): string
    {
        return match ($event->action) {
            'operator_readiness.confirmed' => $this->readinessConfirmationLabel($event),
            'operator_settings.mail.updated' => __('operator.dashboard.activity.labels.mail'),
            'operator_settings.webpush.updated' => __('operator.dashboard.activity.labels.webpush'),
            'operator_settings.storage.updated' => __('operator.dashboard.activity.labels.storage'),
            'operator_settings.scanning.updated' => __('operator.dashboard.activity.labels.scanning'),
            'operator_settings.backup.updated' => __('operator.dashboard.activity.labels.backup'),
            'operator_settings.backup.triggered' => __('operator.dashboard.activity.labels.backup_run'),
            'operator_settings.localization.updated' => __('operator.dashboard.activity.labels.localization'),
            default => __('operator.dashboard.activity.labels.generic'),
        };
    }

    private function operatorActivityBody(AuditEvent $event): array
    {
        if ($event->action === 'operator_settings.mail.updated') {
            return $this->feedback('operator.dashboard.activity.body.mail', [
                'transport' => (string) data_get($event->metadata, 'mailer', 'unknown'),
            ]);
        }

        if ($event->action === 'operator_settings.webpush.updated') {
            return $this->feedback('operator.dashboard.activity.body.webpush', [
                'status' => (string) data_get($event->metadata, 'status', 'unknown'),
            ]);
        }

        if ($event->action === 'operator_settings.storage.updated') {
            return $this->feedback('operator.dashboard.activity.body.storage', [
                'disk' => (string) data_get($event->metadata, 'disk', 'unknown'),
            ]);
        }

        if ($event->action === 'operator_settings.scanning.updated') {
            return $this->feedback('operator.dashboard.activity.body.scanning', [
                'scanner' => (string) data_get($event->metadata, 'driver', 'unknown'),
            ]);
        }

        if ($event->action === 'operator_settings.backup.updated') {
            return $this->feedback('operator.dashboard.activity.body.backup', [
                'offsite' => (string) data_get($event->metadata, 'offsite_disk', 'unknown'),
            ]);
        }

        if ($event->action === 'operator_settings.backup.triggered') {
            return $this->feedback('operator.dashboard.activity.body.backup_run');
        }

        // Before the readiness fall-through below, and that ordering is the
        // whole point: an action allow-listed but not given a body here is not
        // rendered blank, it is captioned "Instance readiness proof was
        // recorded" -- a settings change described as something it is not.
        if ($event->action === 'operator_settings.localization.updated') {
            return $this->feedback('operator.dashboard.activity.body.localization', [
                'language' => (string) data_get($event->metadata, 'language', 'unknown'),
                'timezone' => (string) data_get($event->metadata, 'timezone', 'unknown'),
            ]);
        }

        return match (data_get($event->metadata, 'key')) {
            'scheduler' => $this->feedback('operator.dashboard.activity.body.readiness_scheduler'),
            'backups_restore' => $this->feedback('operator.dashboard.activity.body.readiness_backups'),
            default => $this->feedback('operator.dashboard.activity.body.readiness_generic'),
        };
    }

    /**
     * @return array<int, array{label: string, value: array|string}>
     */
    private function operatorActivityDetails(AuditEvent $event): array
    {
        return match ($event->action) {
            'operator_readiness.confirmed' => [
                [
                    'label' => __('operator.dashboard.activity.details.readiness_item'),
                    'value' => $this->readinessConfirmationItem($event),
                ],
                [
                    'label' => __('operator.dashboard.activity.details.evidence_note'),
                    'value' => $this->readinessConfirmationHasNote($event)
                        ? __('operator.dashboard.activity.values.evidence_recorded')
                        : __('operator.dashboard.activity.values.evidence_missing'),
                ],
                [
                    'label' => __('operator.dashboard.activity.details.event_type'),
                    'value' => __('operator.dashboard.activity.values.readiness_confirmation'),
                ],
            ],
            'operator_settings.mail.updated' => [
                [
                    'label' => __('operator.dashboard.activity.details.transport'),
                    'value' => $this->raw((string) data_get($event->metadata, 'mailer', 'unknown')),
                ],
                [
                    'label' => __('operator.dashboard.activity.details.password'),
                    'value' => match (data_get($event->metadata, 'password_changed')) {
                        'updated' => __('operator.dashboard.activity.values.updated'),
                        'removed' => __('operator.dashboard.activity.values.removed'),
                        default => __('operator.dashboard.activity.values.unchanged'),
                    },
                ],
                [
                    'label' => __('operator.dashboard.activity.details.event_type'),
                    'value' => __('operator.dashboard.activity.values.settings_change'),
                ],
            ],
            'operator_settings.webpush.updated' => [
                [
                    'label' => __('operator.dashboard.activity.details.credentials'),
                    'value' => $this->raw((string) data_get($event->metadata, 'status', 'unknown')),
                ],
                [
                    'label' => __('operator.dashboard.activity.details.event_type'),
                    'value' => __('operator.dashboard.activity.values.settings_change'),
                ],
            ],
            'operator_settings.storage.updated' => [
                [
                    'label' => __('operator.dashboard.activity.details.disk'),
                    'value' => $this->raw((string) data_get($event->metadata, 'disk', 'unknown')),
                ],
                [
                    'label' => __('operator.dashboard.activity.details.credentials'),
                    'value' => match (true) {
                        in_array('updated', [
                            data_get($event->metadata, 'key_changed'),
                            data_get($event->metadata, 'secret_changed'),
                        ], true) => __('operator.dashboard.activity.values.updated'),
                        in_array('cleared', [
                            data_get($event->metadata, 'key_changed'),
                            data_get($event->metadata, 'secret_changed'),
                        ], true) => __('operator.dashboard.activity.values.cleared'),
                        default => __('operator.dashboard.activity.values.unchanged'),
                    },
                ],
                [
                    'label' => __('operator.dashboard.activity.details.event_type'),
                    'value' => __('operator.dashboard.activity.values.settings_change'),
                ],
            ],
            'operator_settings.localization.updated' => [
                [
                    'label' => __('operator.dashboard.activity.details.language'),
                    'value' => $this->raw(DashboardLanguage::SUPPORTED[(string) data_get($event->metadata, 'language')]
                        ?? (string) data_get($event->metadata, 'language', 'unknown')),
                ],
                [
                    'label' => __('operator.dashboard.activity.details.timezone'),
                    'value' => $this->raw((string) data_get($event->metadata, 'timezone', 'unknown')),
                ],
                [
                    'label' => __('operator.dashboard.activity.details.event_type'),
                    'value' => __('operator.dashboard.activity.values.settings_change'),
                ],
            ],
            'operator_settings.scanning.updated' => [
                [
                    'label' => __('operator.dashboard.activity.details.scanner'),
                    'value' => $this->raw((string) data_get($event->metadata, 'driver', 'unknown')),
                ],
                [
                    'label' => __('operator.dashboard.activity.details.unreachable_policy'),
                    'value' => __(data_get($event->metadata, 'fail_closed')
                        ? 'operator.dashboard.activity.values.fail_closed'
                        : 'operator.dashboard.activity.values.fail_open'),
                ],
                [
                    'label' => __('operator.dashboard.activity.details.event_type'),
                    'value' => __('operator.dashboard.activity.values.settings_change'),
                ],
            ],
            'operator_settings.backup.updated' => [
                [
                    'label' => __('operator.dashboard.activity.details.offsite'),
                    'value' => $this->raw((string) data_get($event->metadata, 'offsite_disk', 'unknown')),
                ],
                [
                    'label' => __('operator.dashboard.activity.details.retention'),
                    'value' => ((int) data_get($event->metadata, 'retention_days', 0)) > 0
                        ? trans_choice('operator.dashboard.activity.values.retention_days', (int) data_get($event->metadata, 'retention_days'), [
                            'count' => ReaderNumber::count((int) data_get($event->metadata, 'retention_days')),
                        ])
                        : __('operator.dashboard.activity.values.keep_everything'),
                ],
                [
                    'label' => __('operator.dashboard.activity.details.event_type'),
                    'value' => __('operator.dashboard.activity.values.settings_change'),
                ],
            ],
            'operator_settings.backup.triggered' => [
                [
                    'label' => __('operator.dashboard.activity.details.offsite'),
                    'value' => $this->raw((string) data_get($event->metadata, 'offsite_disk', 'unknown')),
                ],
                [
                    'label' => __('operator.dashboard.activity.details.event_type'),
                    'value' => __('operator.dashboard.activity.values.backup_run'),
                ],
            ],
            default => [],
        };
    }

    private function readinessConfirmationLabel(AuditEvent $event): string
    {
        return match (data_get($event->metadata, 'key')) {
            'scheduler' => __('operator.dashboard.activity.labels.scheduler_confirmation'),
            'backups_restore' => __('operator.dashboard.activity.labels.backups_confirmation'),
            default => __('operator.dashboard.activity.labels.readiness_confirmation'),
        };
    }

    private function readinessConfirmationItem(AuditEvent $event): string
    {
        return match (data_get($event->metadata, 'key')) {
            'scheduler' => __('operator.readiness.checks.scheduler.label'),
            'backups_restore' => __('operator.readiness.checks.backups_restore.label'),
            default => __('operator.dashboard.activity.values.instance_readiness'),
        };
    }

    private function readinessConfirmationHasNote(AuditEvent $event): bool
    {
        return trim((string) data_get($event->metadata, 'note', '')) !== '';
    }

    /** @return array{raw: string} */
    private function raw(string $value): array
    {
        return ['raw' => $value];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{key: string, parameters: array<string, string>}
     */
    private function feedback(string $key, array $parameters = []): array
    {
        return [
            'key' => $key,
            'parameters' => array_map(static fn (mixed $value): string => (string) $value, $parameters),
        ];
    }
}
