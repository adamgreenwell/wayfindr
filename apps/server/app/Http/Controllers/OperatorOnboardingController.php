<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Support\OperatorReadiness;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The guided onboarding checklist (ADR 0011 slice 1c). After /setup an operator
 * lands here — a focused, mail-first walk to a runnable installation — rather
 * than the full diagnostic dashboard. Each essential step shows its live
 * readiness status with an inline action: a GUI Configure button where one
 * exists (mail), otherwise the recommended CLI command plus a "mark confirmed"
 * evidence form. The complete instance diagnostic stays on the operator console.
 */
class OperatorOnboardingController extends Controller
{
    public function __invoke(Request $request, OperatorReadiness $readiness): View
    {
        $summary = $readiness->summary();
        $checksByKey = collect($summary['checks'])->keyBy('key');

        // Essential steps in guided order — mail first. Background workers use a
        // dedicated attestation step (its own confirmation key) rather than the
        // driver-only queue_worker check, so an async queue alone — or a
        // scheduler-only proof — cannot report worker readiness as complete.
        $steps = collect([
            $this->step(
                $checksByKey->get('mail_transport'),
                configureRoute: 'operator.settings.mail.edit',
                configureLabel: 'Configure mail',
                configuredLabel: 'Manage mail settings',
            ),
            $this->step($checksByKey->get('public_url')),
            $this->step($readiness->backgroundWorkersStep()),
            $this->step($checksByKey->get('backups_restore')),
        ])
            ->filter()
            ->values();

        $readyCount = $steps->filter(fn (array $step): bool => $step['check']['status'] === 'ready')->count();

        return view('operator.onboarding', [
            'operator' => $request->user(),
            'steps' => $steps,
            'readyCount' => $readyCount,
            'totalCount' => $steps->count(),
            'site' => $this->firstSite($request),
            'confirmationRoute' => route('operator.readiness.confirmations.store'),
        ]);
    }

    /**
     * Normalize a readiness item (check or dedicated step) into a checklist
     * entry, attaching any operator-config GUI action. A blank item (a missing
     * check key) collapses to null and is filtered out.
     *
     * @param  array<string, mixed>|null  $item
     * @return array{check: array<string, mixed>, configure_url: string|null, configure_label: string|null}|null
     */
    private function step(
        ?array $item,
        ?string $configureRoute = null,
        ?string $configureLabel = null,
        ?string $configuredLabel = null,
    ): ?array {
        if ($item === null) {
            return null;
        }

        $isReady = ($item['status'] ?? null) === 'ready';

        return [
            'check' => [
                ...$item,
                // A step without its own detail (or commands) falls back cleanly
                // so the shared card template renders uniformly.
                'detail' => $item['detail'] ?? $item['summary'],
                'commands' => $item['commands'] ?? [],
            ],
            // Carry the onboarding origin so the config page's back link and its
            // save/test actions return here, not to the dashboard.
            'configure_url' => $configureRoute !== null ? route($configureRoute, ['from' => 'onboarding']) : null,
            // Frame the same button as "Configure" while a step needs attention
            // and "Manage" once it is green.
            'configure_label' => $isReady ? $configuredLabel : $configureLabel,
        ];
    }

    private function firstSite(Request $request): ?Site
    {
        $agent = $request->user();

        if ($agent === null) {
            return null;
        }

        // Platform-operator status does not bypass site visibility. Surface only a
        // site this operator can actually open (same scope as SitePolicy::view),
        // so the card never leaks a restricted site or links to a 404.
        return Site::query()->visibleToAgent($agent)->oldest('id')->first();
    }
}
