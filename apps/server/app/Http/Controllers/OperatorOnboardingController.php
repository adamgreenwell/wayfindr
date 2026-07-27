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
     * Operator-config GUI actions per readiness key. A key here gains an inline
     * Configure button (framed "Manage" once green); keys without one fall back
     * to the recommended CLI command and the mark-confirmed evidence flow.
     *
     * @var array<string, array{route: string, label: string, configured: string}>
     */
    private const CONFIGURE_ACTIONS = [
        'mail_transport' => [
            'route' => 'operator.settings.mail.edit',
            'label' => 'Configure mail',
            'configured' => 'Manage mail settings',
        ],
    ];

    public function __invoke(Request $request, OperatorReadiness $readiness): View
    {
        // Compute ONLY the checklist's items (mail, public URL, background
        // workers, backups) — not the full diagnostic suite — so the focused
        // page never runs or blocks on unrelated probes like the S3 attachment
        // disk or the ClamAV scanner.
        $steps = collect($readiness->onboardingChecklist())
            ->map(fn (array $item): array => $this->step($item))
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
     * Normalize a readiness item into a checklist entry, attaching any
     * operator-config GUI action registered for its key.
     *
     * @param  array<string, mixed>  $item
     * @return array{check: array<string, mixed>, configure_url: string|null, configure_label: string|null}
     */
    private function step(array $item): array
    {
        $configure = self::CONFIGURE_ACTIONS[$item['key']] ?? null;
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
            'configure_url' => $configure !== null ? route($configure['route'], ['from' => 'onboarding']) : null,
            // Frame the button as "Configure" while a step needs attention and
            // "Manage" once it is green.
            'configure_label' => $configure !== null ? ($isReady ? $configure['configured'] : $configure['label']) : null,
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
