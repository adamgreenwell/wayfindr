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
    /**
     * The essential steps, in guided order — mail first. Each names a readiness
     * item by source ('check' for a runtime probe, 'smoke' for a manual-proof
     * step) and key, plus an optional operator-config GUI route so the
     * diagnostic becomes actionable instead of copy-a-command.
     *
     * Background workers use the confirmable 'background_processes' smoke step
     * rather than the raw 'queue_worker' check: a queue driver being async only
     * proves config, not that a queue:work process is actually running, so
     * completion requires the operator's manual attestation (which also covers
     * the scheduler) instead of a driver-only "ready".
     *
     * @var list<array{source: string, key: string, configure?: string, configure_label?: string, configured_label?: string}>
     */
    private const ESSENTIAL_STEPS = [
        [
            'source' => 'check',
            'key' => 'mail_transport',
            'configure' => 'operator.settings.mail.edit',
            'configure_label' => 'Configure mail',
            'configured_label' => 'Manage mail settings',
        ],
        ['source' => 'check', 'key' => 'public_url'],
        ['source' => 'smoke', 'key' => 'background_processes'],
        ['source' => 'check', 'key' => 'backups_restore'],
    ];

    public function __invoke(Request $request, OperatorReadiness $readiness): View
    {
        $summary = $readiness->summary();
        $checksByKey = collect($summary['checks'])->keyBy('key');
        $smokeByKey = collect($summary['smoke_path'])->keyBy('key');

        $steps = collect(self::ESSENTIAL_STEPS)
            ->map(function (array $meta) use ($checksByKey, $smokeByKey): ?array {
                $item = $meta['source'] === 'smoke'
                    ? $smokeByKey->get($meta['key'])
                    : $checksByKey->get($meta['key']);

                if ($item === null) {
                    return null;
                }

                $isReady = $item['status'] === 'ready';

                return [
                    // Smoke steps carry no 'detail'; fall back to the summary so
                    // the shared card template renders uniformly.
                    'check' => [
                        ...$item,
                        'detail' => $item['detail'] ?? $item['summary'],
                        'commands' => $item['commands'] ?? [],
                    ],
                    // Carry the onboarding origin so the config page's back link
                    // and its save/test actions return here, not to the dashboard.
                    'configure_url' => isset($meta['configure']) ? route($meta['configure'], ['from' => 'onboarding']) : null,
                    // Frame the same button as "Configure" while a step needs
                    // attention and "Manage" once it is green.
                    'configure_label' => $isReady
                        ? ($meta['configured_label'] ?? null)
                        : ($meta['configure_label'] ?? null),
                ];
            })
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
