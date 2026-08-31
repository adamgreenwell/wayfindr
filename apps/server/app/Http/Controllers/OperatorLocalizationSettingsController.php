<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Support\DashboardLanguage;
use App\Support\DashboardTimezone;
use App\Support\Settings\OperatorSettings;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The install's own language and timezone (#795, ADR 0011).
 *
 * Mail, storage, scanning and backups all became browser-configurable
 * precisely so an operator would not need shell access. Language stayed an
 * environment variable, and timezone did not exist at all -- not by any
 * argument, only because nobody had moved them.
 *
 * These are the install's DEFAULTS, not an imposition. An agent who has chosen
 * their own language or clock keeps it; this answers for everyone who has not,
 * which on a fresh install is everyone. That makes it the setting a first-run
 * operator most wants and the one hardest to discover as a defect later --
 * nobody reports "the dashboard is in the wrong language", they just find the
 * product foreign.
 */
class OperatorLocalizationSettingsController extends Controller
{
    public function edit(Request $request, OperatorSettings $settings): View
    {
        $from = $this->returnContext($request);

        return view('operator.settings.localization', [
            'operator' => $request->user(),
            // Normalised on the way out as well as in. A value that arrived
            // from env, or from a tzdata release that has since renamed it,
            // should render as the fallback the dashboard will actually use
            // rather than pre-selecting an option the form cannot offer.
            'language' => DashboardLanguage::normalise($settings->effective('localization.language'))
                ?? DashboardLanguage::FALLBACK,
            'timezone' => DashboardTimezone::normalise($settings->effective('localization.timezone'))
                ?? DashboardTimezone::FALLBACK,
            'languageChoices' => DashboardLanguage::options(),
            'timezoneChoices' => DashboardTimezone::choices(),
            'backUrl' => $from === 'onboarding' ? route('operator.onboarding') : route('operator.dashboard'),
            'backLabel' => $from === 'onboarding' ? 'Back to setup checklist' : 'Back to operator console',
            'returnTo' => $from,
        ]);
    }

    public function update(Request $request, OperatorSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'language' => ['required', 'string', Rule::in(array_keys(DashboardLanguage::SUPPORTED))],
            // Checked against the platform's own zone database rather than a
            // list kept here, which would be wrong the first time tzdata added
            // or renamed one.
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);

        $language = DashboardLanguage::normalise($validated['language']) ?? DashboardLanguage::FALLBACK;
        $timezone = DashboardTimezone::normalise($validated['timezone']) ?? DashboardTimezone::FALLBACK;
        $agent = $request->user();

        DB::transaction(function () use ($settings, $language, $timezone, $agent): void {
            $settings->set('localization.language', $language);
            $settings->set('localization.timezone', $timezone);

            AuditEvent::query()->create([
                // Instance-wide config is not a tenant event (see slice 1b).
                'account_id' => null,
                'actor_type' => $agent->getMorphClass(),
                'actor_id' => $agent->id,
                'action' => 'operator_settings.localization.updated',
                'metadata' => ['language' => $language, 'timezone' => $timezone],
                'occurred_at' => now(),
            ]);
        });

        return redirect()
            ->route('operator.settings.localization.edit', $this->returnParams($request))
            ->with('status', 'Language and region saved. Agents who have not chosen their own now read this.');
    }

    private function returnContext(Request $request): ?string
    {
        return $request->input('from') === 'onboarding' ? 'onboarding' : null;
    }

    /** @return array<string, string> */
    private function returnParams(Request $request): array
    {
        $from = $this->returnContext($request);

        return $from !== null ? ['from' => $from] : [];
    }
}
