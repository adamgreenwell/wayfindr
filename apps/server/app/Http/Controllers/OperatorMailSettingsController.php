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
        return view('operator.settings.mail', [
            'operator' => $request->user(),
            'mailer' => (string) $settings->effective('mail.mailer'),
            'host' => (string) $settings->effective('mail.host'),
            'port' => (string) $settings->effective('mail.port'),
            'username' => (string) $settings->effective('mail.username'),
            'encryption' => (string) $settings->effective('mail.scheme'),
            'fromAddress' => (string) $settings->effective('mail.from_address'),
            'fromName' => (string) $settings->effective('mail.from_name'),
            'passwordIsSet' => $settings->isSet('mail.password'),
        ]);
    }

    public function update(Request $request, OperatorSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'mailer' => ['required', Rule::in(['log', 'smtp'])],
            'host' => ['nullable', 'required_if:mailer,smtp', 'string', 'max:255'],
            'port' => ['nullable', 'required_if:mailer,smtp', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
            'encryption' => ['nullable', Rule::in(['smtp', 'smtps'])],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'clear_password' => ['nullable', 'boolean'],
        ]);

        $agent = $request->user();
        $passwordProvided = ($validated['password'] ?? '') !== '';
        $clearPassword = (bool) ($validated['clear_password'] ?? false);

        // Commit the settings and the audit event atomically; the cache version
        // bump is deferred to after this commit (OperatorSettings).
        DB::transaction(function () use ($settings, $validated, $agent, $passwordProvided, $clearPassword): void {
            $settings->set('mail.mailer', $validated['mailer']);
            $settings->set('mail.host', $this->nullIfBlank($validated['host'] ?? null));
            $settings->set('mail.port', isset($validated['port']) ? (string) $validated['port'] : null);
            $settings->set('mail.username', $this->nullIfBlank($validated['username'] ?? null));
            $settings->set('mail.scheme', $this->nullIfBlank($validated['encryption'] ?? null));
            $settings->set('mail.from_address', $validated['from_address']);
            $settings->set('mail.from_name', $this->nullIfBlank($validated['from_name'] ?? null));

            // Password is write-only: change it only when a new one is supplied,
            // or remove it on an explicit request. An empty field leaves it as is.
            if ($clearPassword) {
                $settings->set('mail.password', null);
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
                    'password_changed' => $clearPassword ? 'cleared' : ($passwordProvided ? 'updated' : 'unchanged'),
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

        // Uses the current config — the operator's saved overrides are already
        // applied on this request, so the test exercises exactly what will send.
        try {
            Mail::to($validated['to'])->send(new WayfindrMailTestMessage);
        } catch (Throwable $exception) {
            return redirect()
                ->route('operator.settings.mail.edit')
                ->with('error', 'Test email failed via ['.config('mail.default').']: '.$exception->getMessage());
        }

        return redirect()
            ->route('operator.settings.mail.edit')
            ->with('status', 'Test email sent to '.$validated['to'].' via ['.config('mail.default').']. Check the inbox.');
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return ($value === null || $value === '') ? null : $value;
    }
}
