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
            'readiness' => $readiness->summary(),
            'systemIdentity' => $systemIdentity->summary(),
        ]);
    }

    /**
     * @return Collection<int, array{actor: string, body: string, label: string, occurred_at: Carbon|null}>
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
                'label' => $this->operatorActivityLabel($event),
                'occurred_at' => $event->occurred_at,
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function operatorActivityActions(): array
    {
        return [
            'operator_readiness.confirmed',
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
            default => 'Operator activity',
        };
    }

    private function operatorActivityBody(AuditEvent $event): string
    {
        return match (data_get($event->metadata, 'key')) {
            'scheduler' => 'Scheduler readiness proof was recorded.',
            'backups_restore' => 'Backups and restore readiness proof was recorded.',
            default => 'Instance readiness proof was recorded.',
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
}
