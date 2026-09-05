<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AgentPushSubscription;
use App\Models\OperatorSetting;
use App\Models\User;
use App\Support\Settings\OperatorSettings;
use App\Support\Webhooks\OutboundWebhookDestination;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** Store only the signed-in agent's browser subscription. */
final class AgentPushSubscriptionController extends Controller
{
    private const MAX_SUBSCRIPTIONS_PER_AGENT = 10;

    public function status(Request $request): JsonResponse
    {
        $agent = $request->user();

        // The ownership guard also runs for accountless platform operators.
        // They cannot create a subscription, but they still need to identify
        // and locally remove one left behind by a different signed-in agent.
        abort_unless($agent instanceof User, 403);

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.AgentPushSubscription::ENDPOINT_MAX_LENGTH, 'url', 'starts_with:https://'],
        ]);
        $subscription = AgentPushSubscription::withoutGlobalScope(AgentPushSubscription::CURRENT_VAPID_SCOPE)
            ->where('endpoint', $validated['endpoint'])
            ->first();

        if (! $subscription instanceof AgentPushSubscription) {
            return response()->json(['status' => 'missing']);
        }

        if (! $subscription->usesCurrentVapidGeneration()) {
            $subscription->delete();

            return response()->json(['status' => 'missing']);
        }

        return response()->json([
            'status' => $agent->ownsPushSubscription($subscription) ? 'owned' : 'foreign',
        ]);
    }

    public function store(
        Request $request,
        OutboundWebhookDestination $destination,
        OperatorSettings $settings,
    ): JsonResponse {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);

        $validated = $request->validate([
            'endpoint' => [
                'required',
                'string',
                'max:'.AgentPushSubscription::ENDPOINT_MAX_LENGTH,
                'url',
                'starts_with:https://',
                function (string $attribute, mixed $value, Closure $fail) use ($destination): void {
                    if (! is_string($value)) {
                        return;
                    }

                    try {
                        $destination->inspect($value);
                    } catch (InvalidArgumentException) {
                        $fail(__('profile.alerts.push_invalid_endpoint'));
                    }
                },
            ],
            'application_server_key' => ['required', 'string', 'max:1024'],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[A-Za-z0-9_-]+\z/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! $this->isBase64UrlBytes($value, 65, "\x04")) {
                        $fail(__('validation.regex', ['attribute' => $attribute]));
                    }
                },
            ],
            'keys.auth' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[A-Za-z0-9_-]+\z/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! $this->isBase64UrlBytes($value, 16)) {
                        $fail(__('validation.regex', ['attribute' => $attribute]));
                    }
                },
            ],
            'content_encoding' => ['nullable', 'string', Rule::in(['aes128gcm', 'aesgcm'])],
        ]);

        try {
            $stored = DB::transaction(function () use ($agent, $settings, $validated): string {
                // Serialize against VAPID rotation and bypass the versioned
                // settings cache under the lock. A profile loaded before a key
                // change must not recreate an old-key subscription after the
                // rotation transaction has purged it.
                OperatorSetting::query()->insertOrIgnore([
                    'key' => 'webpush.public_key',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                OperatorSetting::query()
                    ->where('key', 'webpush.public_key')
                    ->sharedLock()
                    ->firstOrFail();
                $settings->refreshFromDatabase();
                $currentPublicKey = trim((string) config('webpush.vapid.public_key'));

                if ($currentPublicKey === ''
                    || ! hash_equals($currentPublicKey, $validated['application_server_key'])) {
                    return 'stale_key';
                }

                $currentAgent = User::query()
                    ->whereKey($agent->id)
                    ->where('account_id', $agent->account_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                AgentPushSubscription::purgeStaleFor($currentAgent);
                $subscription = AgentPushSubscription::withoutGlobalScope(AgentPushSubscription::CURRENT_VAPID_SCOPE)
                    ->where('endpoint', $validated['endpoint'])
                    ->lockForUpdate()
                    ->first();

                if ($subscription instanceof AgentPushSubscription
                    && ! $subscription->usesCurrentVapidGeneration()) {
                    $subscription->delete();
                    $subscription = null;
                }

                if ($subscription instanceof AgentPushSubscription
                    && ! $currentAgent->ownsPushSubscription($subscription)) {
                    return 'foreign';
                }

                if (! $subscription instanceof AgentPushSubscription
                    && $currentAgent->pushSubscriptions()->count() >= self::MAX_SUBSCRIPTIONS_PER_AGENT) {
                    return 'limit';
                }

                $attributes = [
                    'public_key' => $validated['keys']['p256dh'],
                    'auth_token' => $validated['keys']['auth'],
                    'content_encoding' => $validated['content_encoding'] ?? 'aes128gcm',
                ];

                if ($subscription instanceof AgentPushSubscription) {
                    $subscription->forceFill($attributes)->save();
                } else {
                    $currentAgent->pushSubscriptions()->create([
                        'endpoint' => $validated['endpoint'],
                        ...$attributes,
                    ]);
                }

                return 'stored';
            });
        } catch (UniqueConstraintViolationException) {
            // Two different signed-in agents can race to present the same
            // browser endpoint. The unique index chooses one owner; never use
            // the package helper's delete-and-reassign behavior here.
            $stored = 'foreign';
        }

        return match ($stored) {
            'stored' => response()->json(['stored' => true]),
            'limit' => response()->json(['message' => __('profile.alerts.push_limit')], 422),
            'stale_key' => response()->json(['message' => __('profile.alerts.push_configuration_changed')], 409),
            default => response()->json(['message' => __('profile.alerts.push_owned_elsewhere')], 409),
        };
    }

    public function destroy(Request $request): Response
    {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.AgentPushSubscription::ENDPOINT_MAX_LENGTH, 'url', 'starts_with:https://'],
        ]);

        $accountId = (int) $agent->account_id;
        $userId = (int) $agent->id;

        DB::transaction(function () use ($accountId, $userId, $validated): void {
            Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $currentAgent = User::query()
                ->whereKey($userId)
                ->where('account_id', $accountId)
                ->lockForUpdate()
                ->firstOrFail();

            AgentPushSubscription::withoutGlobalScope(AgentPushSubscription::CURRENT_VAPID_SCOPE)
                ->where('subscribable_type', $currentAgent->getMorphClass())
                ->where('subscribable_id', $currentAgent->getKey())
                ->where('endpoint', $validated['endpoint'])
                ->delete();

            if ($currentAgent->pushSubscriptions()->exists()) {
                return;
            }

            $alertPreferences = $currentAgent->alert_preferences ?? [];

            if (($alertPreferences['push'] ?? false) === true) {
                $currentAgent->forceFill([
                    'alert_preferences' => array_merge($alertPreferences, ['push' => false]),
                ])->save();
            }
        });

        return response()->noContent();
    }

    private function isBase64UrlBytes(string $value, int $length, ?string $prefix = null): bool
    {
        $padding = str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($value.$padding, '-_', '+/'), true);

        return is_string($decoded)
            && strlen($decoded) === $length
            && ($prefix === null || str_starts_with($decoded, $prefix));
    }
}
