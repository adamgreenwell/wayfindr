<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Support\AgentWebPushConfig;
use App\Support\Settings\OperatorSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Platform-operator VAPID configuration; the private key is always write-only. */
final class OperatorWebPushSettingsController extends Controller
{
    public function edit(Request $request, OperatorSettings $settings, AgentWebPushConfig $webPush): View
    {
        return view('operator.settings.webpush', [
            'operator' => $request->user(),
            'subject' => (string) $settings->effective('webpush.subject'),
            'publicKey' => (string) $settings->effective('webpush.public_key'),
            'privateKeyIsSet' => $settings->effectiveSecretStatus('webpush.private_key') === 'set',
            'privateKeyUnreadable' => $settings->secretStatus('webpush.private_key') === 'unreadable',
            'assessment' => $webPush->assessment(),
        ]);
    }

    public function update(
        Request $request,
        OperatorSettings $settings,
        AgentWebPushConfig $webPush,
    ): RedirectResponse {
        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'public_key' => ['nullable', 'string', 'max:1024'],
            'private_key' => ['nullable', 'string', 'max:1024'],
            'clear_keys' => ['nullable', 'boolean'],
        ]);

        $subject = trim((string) ($validated['subject'] ?? ''));
        $publicKey = trim((string) ($validated['public_key'] ?? ''));
        $privateProvided = trim((string) ($validated['private_key'] ?? '')) !== '';
        $clearKeys = (bool) ($validated['clear_keys'] ?? false);
        $privateUnreadable = $settings->secretStatus('webpush.private_key') === 'unreadable';
        $currentPublicKey = trim((string) config('webpush.vapid.public_key'));
        $currentPrivateKey = trim((string) config('webpush.vapid.private_key'));

        if ($clearKeys && $privateProvided) {
            throw ValidationException::withMessages([
                'clear_keys' => __('operator.webpush.validation.clear_conflict'),
            ]);
        }

        if (! $clearKeys && $privateUnreadable && ! $privateProvided) {
            throw ValidationException::withMessages([
                'private_key' => __('operator.webpush.private_unreadable'),
            ]);
        }

        if (! $clearKeys && $publicKey !== $currentPublicKey && $currentPrivateKey !== '' && ! $privateProvided) {
            throw ValidationException::withMessages([
                'private_key' => __('operator.webpush.validation.pair_required'),
            ]);
        }

        $effectivePrivateKey = $privateProvided
            ? trim((string) $validated['private_key'])
            : $currentPrivateKey;

        if (! $clearKeys && (($publicKey === '') !== ($effectivePrivateKey === ''))) {
            throw ValidationException::withMessages([
                'private_key' => __('operator.webpush.validation.pair_required'),
            ]);
        }

        $assessment = $clearKeys
            ? $webPush->assessValues('', '', '')
            : $webPush->assessValues($subject, $publicKey, $effectivePrivateKey);

        if ($assessment['status'] === 'invalid') {
            throw ValidationException::withMessages([
                'public_key' => __('operator.webpush.validation.invalid_vapid'),
            ]);
        }

        $agent = $request->user();

        DB::transaction(function () use (
            $agent,
            $assessment,
            $clearKeys,
            $privateProvided,
            $publicKey,
            $request,
            $settings,
            $subject,
        ): void {
            $settings->set('webpush.subject', $clearKeys ? '' : $subject);
            $settings->set('webpush.public_key', $clearKeys ? '' : $publicKey);

            if ($clearKeys) {
                $settings->set('webpush.private_key', '');
            } elseif ($privateProvided) {
                $settings->set('webpush.private_key', trim((string) $request->input('private_key')));
            }

            AuditEvent::query()->create([
                'account_id' => null,
                'actor_type' => $agent->getMorphClass(),
                'actor_id' => $agent->id,
                'action' => 'operator_settings.webpush.updated',
                'metadata' => [
                    'status' => $assessment['status'],
                    'private_key_changed' => $clearKeys ? 'cleared' : ($privateProvided ? 'updated' : 'unchanged'),
                ],
                'occurred_at' => now(),
            ]);
        });

        return redirect()
            ->route('operator.settings.webpush.edit')
            ->with('status', 'operator.webpush.flash.saved');
    }
}
