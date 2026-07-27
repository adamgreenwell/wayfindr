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
     * The essential steps, in guided order — mail first. Each maps a readiness
     * check key to an optional operator-config GUI route so the diagnostic
     * becomes actionable instead of copy-a-command.
     *
     * @var list<array{key: string, configure?: string, configure_label?: string, configured_label?: string}>
     */
    private const ESSENTIAL_STEPS = [
        [
            'key' => 'mail_transport',
            'configure' => 'operator.settings.mail.edit',
            'configure_label' => 'Configure mail',
            'configured_label' => 'Manage mail settings',
        ],
        ['key' => 'public_url'],
        ['key' => 'queue_worker'],
        ['key' => 'scheduler'],
        ['key' => 'backups_restore'],
    ];

    public function __invoke(Request $request, OperatorReadiness $readiness): View
    {
        $summary = $readiness->summary();
        $checksByKey = collect($summary['checks'])->keyBy('key');

        $steps = collect(self::ESSENTIAL_STEPS)
            ->map(function (array $meta) use ($checksByKey): ?array {
                $check = $checksByKey->get($meta['key']);

                if ($check === null) {
                    return null;
                }

                $isReady = $check['status'] === 'ready';

                return [
                    'check' => $check,
                    'configure_url' => isset($meta['configure']) ? route($meta['configure']) : null,
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
