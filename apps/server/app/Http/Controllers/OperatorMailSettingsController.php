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
        // When the operator arrived from the guided onboarding checklist, keep
        // that origin so the back link and the save/test actions return there
        // instead of ejecting them to the dashboard.
        $from = $this->returnContext($request);

        return view('operator.settings.mail', [
            'operator' => $request->user(),
            'mailer' => $mailer,
            'backUrl' => $from === 'onboarding' ? route('operator.onboarding') : route('operator.dashboard'),
            'backLabel' => $from === 'onboarding' ? 'Back to setup checklist' : 'Back to operator console',
            'returnTo' => $from,
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
            ->route('operator.settings.mail.edit', $this->returnParams($request))
            ->with('status', 'Mail settings saved. Send a test email to confirm delivery.');
    }

    public function test(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'email'],
        ]);

        $returnParams = $this->returnParams($request);
        $mailer = (string) config('mail.default');
        $assessment = $this->assessDelivery($mailer);

        // log/array/null — or a failover/roundrobin chain of only those — never
        // leave the server. A send would "succeed" without delivering, so don't
        // report a false delivery; guide the operator to configure SMTP.
        if ($assessment === 'non_delivering') {
            return redirect()
                ->route('operator.settings.mail.edit', $returnParams)
                ->with('error', 'Mail transport is still "'.($mailer === '' ? 'not set' : $mailer).'" — a test message would not be delivered. Choose SMTP above and save, then test.');
        }

        // Uses the current config — the operator's saved overrides are already
        // applied on this request, so the test exercises exactly what will send.
        try {
            Mail::to($validated['to'])->send(new WayfindrMailTestMessage);
        } catch (Throwable $exception) {
            return redirect()
                ->route('operator.settings.mail.edit', $returnParams)
                ->with('error', 'Test email failed via ['.$mailer.']: '.$exception->getMessage());
        }

        // A failover/roundrobin chain that includes a local sink may have silently
        // fallen back to it if the real transport was down — say so rather than
        // promising delivery.
        $message = $assessment === 'may_fall_back'
            ? 'Test message sent via the ['.$mailer.'] chain. If the primary transport was unavailable it may have fallen back to a local log instead of delivering — confirm it actually arrived in the inbox.'
            : 'Test email sent to '.$validated['to'].' via ['.$mailer.']. Check the inbox.';

        return redirect()
            ->route('operator.settings.mail.edit', $returnParams)
            ->with('status', $message);
    }

    /**
     * The allow-listed onboarding return context, or null. Keeps an operator who
     * reached the mail form from the guided checklist from being ejected to the
     * dashboard by the back link or the save/test redirects.
     */
    private function returnContext(Request $request): ?string
    {
        return $request->input('from') === 'onboarding' ? 'onboarding' : null;
    }

    /**
     * Route params that carry the onboarding origin back through a redirect.
     *
     * @return array<string, string>
     */
    private function returnParams(Request $request): array
    {
        $from = $this->returnContext($request);

        return $from !== null ? ['from' => $from] : [];
    }

    private function assessDelivery(string $mailer): string
    {
        return $this->assessMailer(strtolower($mailer), []);
    }

    /**
     * Recursively assess how honestly a send-test can claim delivery for a
     * mailer, accounting for composite ORDER (a flat leaf list can't):
     *  - 'non_delivering': the message can't leave the server — a leaf log/array/
     *    null, a composite of only sinks, OR a failover whose first reliably-
     *    succeeding transport is a local sink. Laravel's failover tries members
     *    in order and stops at the first success; array/log always succeed, so a
     *    chain like [array, smtp] never reaches smtp.
     *  - 'may_fall_back': a real transport is attempted but a silent fallback to
     *    a sink is possible — a failover with a real transport BEFORE a sink, or
     *    a roundrobin (random per-send pick) that might land on a sink.
     *  - 'deliverable': an ordinary transport with no sink fallback.
     *
     * @param  list<string>  $visited  composite mailer names already on this path
     */
    private function assessMailer(string $mailer, array $visited): string
    {
        $transport = strtolower((string) config("mail.mailers.{$mailer}.transport", $mailer));

        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return in_array($transport, ['', 'log', 'array', 'null'], true) ? 'non_delivering' : 'deliverable';
        }

        // A self-referential composite can't be resolved further; treat it as an
        // opaque real transport rather than looping (a genuine send would error
        // and be caught).
        if (in_array($mailer, $visited, true)) {
            return 'deliverable';
        }

        $visited[] = $mailer;

        $members = array_values(array_filter(
            (array) config("mail.mailers.{$mailer}.mailers", []),
            'is_string',
        ));

        if ($members === []) {
            return 'deliverable';
        }

        $assessments = array_map(fn (string $member): string => $this->assessMailer(strtolower($member), $visited), $members);

        if ($transport === 'roundrobin') {
            // Random pick per send: a sink anywhere means it might not deliver.
            if (! in_array('deliverable', $assessments, true) && ! in_array('may_fall_back', $assessments, true)) {
                return 'non_delivering'; // every member is a guaranteed sink
            }

            return in_array('non_delivering', $assessments, true) || in_array('may_fall_back', $assessments, true)
                ? 'may_fall_back'
                : 'deliverable';
        }

        // failover: tried in order, stops at the first success. A sink always
        // succeeds, so the first sink is terminal — everything after it is dead.
        $realChanceSeen = false;
        $anyMayFallBack = false;

        foreach ($assessments as $assessment) {
            if ($assessment === 'non_delivering') {
                // First reliably-succeeding transport is a sink; nothing later runs.
                return $realChanceSeen ? 'may_fall_back' : 'non_delivering';
            }

            $realChanceSeen = true;
            $anyMayFallBack = $anyMayFallBack || $assessment === 'may_fall_back';
        }

        return $anyMayFallBack ? 'may_fall_back' : 'deliverable';
    }

    /** The trimmed submitted value as an explicit override — '' for a blank field, never null. */
    private function explicit(?string $value): string
    {
        return trim((string) $value);
    }
}
