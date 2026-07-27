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
        // Determine the password's status without decrypting it into the view:
        // a bad ciphertext (e.g. after an APP_KEY change) must not 500 the form,
        // or the operator can't reach the UI to re-enter or clear it. The
        // *effective* status also counts an env/MAIL_URL credential as set, so
        // the form never implies "no password" when one is working from env.
        $passwordStatus = $settings->effectiveSecretStatus('mail.password');

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
            // Only a non-empty, readable stored password counts as "saved" (an
            // explicit empty override means the server takes no password).
            'passwordIsSet' => $passwordStatus === 'set',
            'passwordUnreadable' => $passwordStatus === 'unreadable',
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
                // Instance-wide config is NOT a tenant event: leaving account_id
                // null keeps it out of the operator's own account audit trail
                // (where other account admins would see it) and off the account's
                // cascade-on-delete, so the record survives account deletion. It
                // surfaces through the operator activity feed instead.
                'account_id' => null,
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
        $assessment = $this->assessDelivery($mailer);

        // log/array/null — or a failover/roundrobin chain of only those — never
        // leave the server. A send would "succeed" without delivering, so don't
        // report a false delivery; guide the operator to configure SMTP.
        if ($assessment === 'non_delivering') {
            return redirect()
                ->route('operator.settings.mail.edit')
                ->with('error', 'Mail transport is still "'.($mailer === '' ? 'not set' : $mailer).'" — a test message would not be delivered. Choose SMTP above and save, then test.');
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

        // A failover/roundrobin chain that includes a local sink may have silently
        // fallen back to it if the real transport was down — say so rather than
        // promising delivery.
        $message = $assessment === 'may_fall_back'
            ? 'Test message sent via the ['.$mailer.'] chain. If the primary transport was unavailable it may have fallen back to a local log instead of delivering — confirm it actually arrived in the inbox.'
            : 'Test email sent to '.$validated['to'].' via ['.$mailer.']. Check the inbox.';

        return redirect()
            ->route('operator.settings.mail.edit')
            ->with('status', $message);
    }

    /**
     * How honestly a send-test can claim delivery for the active mailer:
     *  - 'non_delivering': log/array/null (or a failover/roundrobin chain of only
     *    those) — the message never leaves the server, so the test is refused.
     *  - 'may_fall_back': a failover/roundrobin chain that includes a local sink —
     *    a real send is attempted, but a silent fallback to the sink is possible.
     *  - 'deliverable': an ordinary outbound transport.
     */
    private function assessDelivery(string $mailer): string
    {
        $nonDelivering = ['', 'log', 'array', 'null'];
        $leaves = $this->resolveLeafTransports(strtolower($mailer));
        $sinks = array_filter($leaves, fn (string $transport): bool => in_array($transport, $nonDelivering, true));

        if (count($sinks) === count($leaves)) {
            return 'non_delivering';
        }

        return $sinks === [] ? 'deliverable' : 'may_fall_back';
    }

    /**
     * The leaf transport types a mailer resolves to, expanding failover/
     * roundrobin chains recursively — which is where an ordinary transport can
     * silently fall back to a local sink (log/array) and report success. A
     * nested composite (a failover whose member is itself a failover) is
     * followed to its leaves; a self-referential chain is guarded by the
     * visited set, so a cycle terminates instead of recursing forever.
     *
     * @param  list<string>  $visited  composite mailer names already expanded on this path
     * @return list<string>
     */
    private function resolveLeafTransports(string $mailer, array $visited = []): array
    {
        $transport = strtolower((string) config("mail.mailers.{$mailer}.transport", $mailer));

        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return [$transport];
        }

        // A composite that references itself (directly or via a cycle) can't be
        // expanded further — treat it as an opaque leaf rather than looping.
        if (in_array($mailer, $visited, true)) {
            return [$transport];
        }

        $visited[] = $mailer;

        $chain = array_values(array_filter(
            (array) config("mail.mailers.{$mailer}.mailers", []),
            'is_string',
        ));

        $leaves = [];
        foreach ($chain as $member) {
            foreach ($this->resolveLeafTransports(strtolower($member), $visited) as $leaf) {
                $leaves[] = $leaf;
            }
        }

        return $leaves === [] ? [$transport] : $leaves;
    }

    /** The trimmed submitted value as an explicit override — '' for a blank field, never null. */
    private function explicit(?string $value): string
    {
        return trim((string) $value);
    }
}
