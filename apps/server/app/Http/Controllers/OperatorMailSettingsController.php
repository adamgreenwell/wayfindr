<?php

namespace App\Http\Controllers;

use App\Mail\WayfindrMailTestMessage;
use App\Models\AuditEvent;
use App\Support\Settings\OperatorSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Operator mail configuration (ADR 0011 slice 1). An SMTP settings form under
 * the platform-operator boundary that writes DB-backed overrides — live with no
 * restart — plus a "send test email" action. The stored password is never
 * echoed to the browser (write-only; the form shows only whether one is set).
 */
class OperatorMailSettingsController extends Controller
{
    public function edit(Request $request, OperatorSettings $settings): View
    {
        $mailer = (string) $settings->effective('mail.mailer');

        return view('operator.settings.mail', [
            'operator' => $request->user(),
            'mailer' => $mailer,
            // A transport configured outside this form's editable subset (ses,
            // postmark, sendmail, …) is offered as a preserved option so saving
            // other fields never silently switches delivery to log.
            'externalMailer' => in_array($mailer, ['log', 'smtp'], true) ? null : $mailer,
            'host' => (string) $settings->effective('mail.host'),
            'port' => (string) $settings->effective('mail.port'),
            'username' => (string) $settings->effective('mail.username'),
            'encryption' => (string) $settings->effective('mail.scheme'),
            'fromAddress' => (string) $settings->effective('mail.from_address'),
            'fromName' => (string) $settings->effective('mail.from_name'),
            // Only a non-empty stored password counts as "saved" (an explicit
            // empty override means the server takes no password).
            'passwordIsSet' => $settings->isSet('mail.password') && (string) $settings->get('mail.password') !== '',
        ]);
    }

    public function update(Request $request, OperatorSettings $settings): RedirectResponse
    {
        // Allow keeping a transport configured outside this form (ses, …); the
        // form can otherwise only choose log or smtp.
        $currentMailer = (string) $settings->effective('mail.mailer');
        $allowedMailers = array_values(array_unique(['log', 'smtp', $currentMailer !== '' ? $currentMailer : 'log']));

        $validated = $request->validate([
            'mailer' => ['required', Rule::in($allowedMailers)],
            'host' => ['nullable', 'required_if:mailer,smtp', 'string', 'max:255'],
            'port' => ['nullable', 'required_if:mailer,smtp', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
            'encryption' => ['nullable', Rule::in(['smtp', 'smtps'])],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'no_password' => ['nullable', 'boolean'],
        ]);

        $agent = $request->user();
        $noPassword = (bool) ($validated['no_password'] ?? false);
        $passwordProvided = ($validated['password'] ?? '') !== '';

        // Commit the settings and the audit event atomically; the cache version
        // bump is deferred to after this commit (OperatorSettings).
        DB::transaction(function () use ($settings, $validated, $agent, $noPassword, $passwordProvided): void {
            // The submitted value IS the config: a blank field is a DELIBERATE
            // empty override (no username, auto scheme, …), not a request to fall
            // back to the — possibly stale — env value. The form is pre-filled
            // from the effective value, so keeping a field re-saves it explicitly.
            $settings->set('mail.mailer', $validated['mailer']);
            $settings->set('mail.host', $this->explicit($validated['host'] ?? null));
            $settings->set('mail.port', isset($validated['port']) ? (string) $validated['port'] : '');
            $settings->set('mail.username', $this->explicit($validated['username'] ?? null));
            $settings->set('mail.scheme', $this->explicit($validated['encryption'] ?? null));
            $settings->set('mail.from_address', $validated['from_address']);
            $settings->set('mail.from_name', $this->explicit($validated['from_name'] ?? null));

            // Password is write-only. Set a new value when supplied; store an
            // explicit empty (no auth) when the operator says the server needs
            // none; otherwise leave the saved password untouched.
            if ($noPassword) {
                $settings->set('mail.password', '');
            } elseif ($passwordProvided) {
                $settings->set('mail.password', $validated['password']);
            }

            AuditEvent::query()->create([
                'account_id' => $agent->account_id,
                'actor_type' => $agent->getMorphClass(),
                'actor_id' => $agent->id,
                'action' => 'operator_settings.mail.updated',
                'metadata' => [
                    'mailer' => $validated['mailer'],
                    'host' => $validated['host'] ?? null,
                    'port' => $validated['port'] ?? null,
                    'from_address' => $validated['from_address'],
                    'password_changed' => $noPassword ? 'removed' : ($passwordProvided ? 'updated' : 'unchanged'),
                ],
                'occurred_at' => now(),
            ]);
        });

        return redirect()
            ->route('operator.settings.mail.edit')
            ->with('status', 'Mail settings saved. Send a test email to confirm delivery.');
    }

    public function test(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'email'],
        ]);

        $mailer = (string) config('mail.default');

        // In log mode the send would only write to a log file — never delivered.
        // Don't report a false success; guide the operator to configure SMTP.
        if ($mailer === 'log') {
            return redirect()
                ->route('operator.settings.mail.edit')
                ->with('error', 'Mail transport is still "Log only" — a test message would be written to the log, not delivered. Choose SMTP above and save, then test.');
        }

        // Uses the current config — the operator's saved overrides are already
        // applied on this request, so the test exercises exactly what will send.
        try {
            Mail::to($validated['to'])->send(new WayfindrMailTestMessage);
        } catch (Throwable $exception) {
            return redirect()
                ->route('operator.settings.mail.edit')
                ->with('error', 'Test email failed via ['.$mailer.']: '.$exception->getMessage());
        }

        return redirect()
            ->route('operator.settings.mail.edit')
            ->with('status', 'Test email sent to '.$validated['to'].' via ['.$mailer.']. Check the inbox.');
    }

    /** The trimmed submitted value as an explicit override — '' for a blank field, never null. */
    private function explicit(?string $value): string
    {
        return trim((string) $value);
    }
}
