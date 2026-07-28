<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Support\Attachments\Scanning\AttachmentScanner;
use App\Support\Settings\OperatorSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Operator malware-scanning configuration (ADR 0011 slice 2b). Toggle attachment
 * scanning between "accept with defense-in-depth" (no scanner) and ClamAV, set
 * the clamd socket, and choose the fail policy — all DB-backed overrides, live
 * with no restart. A "test reachability" button probes the configured scanner.
 *
 * The scanner is a security control: an unknown driver fails loud (uploads are
 * rejected), and the fail-closed default rejects uploads when a configured
 * scanner is unreachable rather than storing an unscanned file.
 */
class OperatorScanningSettingsController extends Controller
{
    private const CLAMAV = 'clamav';

    public function edit(Request $request, OperatorSettings $settings): View
    {
        $driver = strtolower(trim((string) $settings->effective('scanning.driver')));
        $from = $this->returnContext($request);

        return view('operator.settings.scanning', [
            'operator' => $request->user(),
            // Only 'none' and clamav are valid; anything else is a broken config
            // that fails loud on upload — normalize display to none.
            'driver' => $driver === self::CLAMAV ? self::CLAMAV : '',
            'socket' => (string) $settings->effective('scanning.socket'),
            'failClosed' => filter_var($settings->effective('scanning.fail_closed'), FILTER_VALIDATE_BOOL),
            'backUrl' => $from === 'onboarding' ? route('operator.onboarding') : route('operator.dashboard'),
            'backLabel' => $from === 'onboarding' ? 'Back to setup checklist' : 'Back to operator console',
            'returnTo' => $from,
        ]);
    }

    public function update(Request $request, OperatorSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            // '' (none) or clamav; ConvertEmptyStringsToNull turns a blank select
            // into null, which nullable accepts as "none".
            'driver' => ['nullable', Rule::in([self::CLAMAV])],
            'socket' => ['nullable', 'required_if:driver,'.self::CLAMAV, 'string', 'max:255'],
            'fail_closed' => ['nullable', 'boolean'],
        ]);

        $driver = ($validated['driver'] ?? '') === self::CLAMAV ? self::CLAMAV : '';
        $failClosed = $request->boolean('fail_closed');
        $agent = $request->user();

        DB::transaction(function () use ($settings, $validated, $driver, $failClosed, $agent): void {
            $settings->set('scanning.driver', $driver);
            $settings->set('scanning.fail_closed', $failClosed ? '1' : '0');

            // Only touch the socket when ClamAV is chosen, so switching scanning
            // off never blanks an env-provided socket.
            if ($driver === self::CLAMAV) {
                $settings->set('scanning.socket', trim((string) ($validated['socket'] ?? '')));
            }

            AuditEvent::query()->create([
                // Instance-wide config is not a tenant event (see slice 1b).
                'account_id' => null,
                'actor_type' => $agent->getMorphClass(),
                'actor_id' => $agent->id,
                'action' => 'operator_settings.scanning.updated',
                'metadata' => [
                    'driver' => $driver === self::CLAMAV ? self::CLAMAV : 'none',
                    'fail_closed' => $failClosed,
                ],
                'occurred_at' => now(),
            ]);
        });

        return redirect()
            ->route('operator.settings.scanning.edit', $this->returnParams($request))
            ->with('status', 'Scanning settings saved. Run a reachability test to confirm the scanner responds.');
    }

    public function test(Request $request): RedirectResponse
    {
        $returnParams = $this->returnParams($request);
        $driver = strtolower(trim((string) config('wayfindr.attachments.scanner.driver')));

        if (in_array($driver, ['', 'null', 'none'], true)) {
            return redirect()
                ->route('operator.settings.scanning.edit', $returnParams)
                ->with('error', 'No scanner is configured — uploads are accepted with defense-in-depth (type allowlist, private storage, forced download) but not virus-scanned. Choose ClamAV and save to enable scanning.');
        }

        try {
            $scanner = app(AttachmentScanner::class);
        } catch (Throwable $exception) {
            return redirect()
                ->route('operator.settings.scanning.edit', $returnParams)
                ->with('error', 'Scanner is misconfigured: '.$exception->getMessage());
        }

        if ($scanner->isAvailable()) {
            return redirect()
                ->route('operator.settings.scanning.edit', $returnParams)
                ->with('status', 'Scanner reachable: the '.$driver.' scanner responded. Uploads will be scanned before they are stored.');
        }

        return redirect()
            ->route('operator.settings.scanning.edit', $returnParams)
            ->with('error', 'The '.$driver.' scanner is configured but unreachable at '.config('wayfindr.attachments.scanner.clamav.socket').'. Confirm clamd is running and the socket is correct.');
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
