<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\OperatorReadiness;
use App\Support\OperatorSystemIdentity;
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
    ): View {
        return view('operator.dashboard', [
            'operator' => $request->user(),
            'operatorActivity' => $this->operatorActivity(),
            'operatorActivityTotal' => $this->operatorActivityTotal(),
            'readiness' => $readiness->summary(),
            'systemIdentity' => $systemIdentity->summary(),
        ]);
    }

    /**
     * @return Collection<int, array{actor: string, body: string, details: array<int, array{label: string, value: string}>, label: string, occurred_at: Carbon|null}>
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
            'operator_settings.storage.updated',
            'operator_settings.scanning.updated',
        ];
    }

    private function operatorActivityActor(AuditEvent $event): string
    {
        if ($event->actor instanceof User) {
            return $event->actor->name;
        }

        return 'System';
    }

    private function operatorActivityLabel(AuditEvent $event): string
    {
        return match ($event->action) {
            'operator_readiness.confirmed' => $this->readinessConfirmationLabel($event),
            'operator_settings.mail.updated' => 'Mail settings updated',
            'operator_settings.storage.updated' => 'Storage settings updated',
            'operator_settings.scanning.updated' => 'Scanning settings updated',
            default => 'Operator activity',
        };
    }

    private function operatorActivityBody(AuditEvent $event): string
    {
        if ($event->action === 'operator_settings.mail.updated') {
            return sprintf(
                'Outbound mail settings were updated (transport: %s).',
                (string) data_get($event->metadata, 'mailer', 'unknown'),
            );
        }

        if ($event->action === 'operator_settings.storage.updated') {
            return sprintf(
                'Attachment storage was updated (disk: %s).',
                (string) data_get($event->metadata, 'disk', 'unknown'),
            );
        }

        if ($event->action === 'operator_settings.scanning.updated') {
            return sprintf(
                'Attachment scanning was updated (scanner: %s).',
                (string) data_get($event->metadata, 'driver', 'unknown'),
            );
        }

        return match (data_get($event->metadata, 'key')) {
            'scheduler' => 'Scheduler readiness proof was recorded.',
            'backups_restore' => 'Backups and restore readiness proof was recorded.',
            default => 'Instance readiness proof was recorded.',
        };
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function operatorActivityDetails(AuditEvent $event): array
    {
        return match ($event->action) {
            'operator_readiness.confirmed' => [
                [
                    'label' => 'Readiness item',
                    'value' => $this->readinessConfirmationItem($event),
                ],
                [
                    'label' => 'Evidence note',
                    'value' => $this->readinessConfirmationHasNote($event)
                        ? 'Evidence note recorded'
                        : 'No evidence note recorded',
                ],
                [
                    'label' => 'Event type',
                    'value' => 'Readiness confirmation',
                ],
            ],
            'operator_settings.mail.updated' => [
                [
                    'label' => 'Transport',
                    'value' => (string) data_get($event->metadata, 'mailer', 'unknown'),
                ],
                [
                    'label' => 'Password',
                    'value' => match (data_get($event->metadata, 'password_changed')) {
                        'updated' => 'Updated',
                        'removed' => 'Removed',
                        default => 'Unchanged',
                    },
                ],
                [
                    'label' => 'Event type',
                    'value' => 'Instance settings change',
                ],
            ],
            'operator_settings.storage.updated' => [
                [
                    'label' => 'Disk',
                    'value' => (string) data_get($event->metadata, 'disk', 'unknown'),
                ],
                [
                    'label' => 'Credentials',
                    'value' => match (true) {
                        in_array('updated', [
                            data_get($event->metadata, 'key_changed'),
                            data_get($event->metadata, 'secret_changed'),
                        ], true) => 'Updated',
                        in_array('cleared', [
                            data_get($event->metadata, 'key_changed'),
                            data_get($event->metadata, 'secret_changed'),
                        ], true) => 'Cleared',
                        default => 'Unchanged',
                    },
                ],
                [
                    'label' => 'Event type',
                    'value' => 'Instance settings change',
                ],
            ],
            'operator_settings.scanning.updated' => [
                [
                    'label' => 'Scanner',
                    'value' => (string) data_get($event->metadata, 'driver', 'unknown'),
                ],
                [
                    'label' => 'Unreachable policy',
                    'value' => data_get($event->metadata, 'fail_closed') ? 'Fail closed (reject)' : 'Fail open (accept)',
                ],
                [
                    'label' => 'Event type',
                    'value' => 'Instance settings change',
                ],
            ],
            default => [],
        };
    }

    private function readinessConfirmationLabel(AuditEvent $event): string
    {
        return match (data_get($event->metadata, 'key')) {
            'scheduler' => 'Scheduler confirmation',
            'backups_restore' => 'Backups and restore confirmation',
            default => 'Readiness confirmation',
        };
    }

    private function readinessConfirmationItem(AuditEvent $event): string
    {
        return match (data_get($event->metadata, 'key')) {
            'scheduler' => 'Scheduler',
            'backups_restore' => 'Backups and restore',
            default => 'Instance readiness',
        };
    }

    private function readinessConfirmationHasNote(AuditEvent $event): bool
    {
        return trim((string) data_get($event->metadata, 'note', '')) !== '';
    }
}
