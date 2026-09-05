<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Webhooks\OutboundWebhookDestination;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use NotificationChannels\WebPush\PushSubscription;

/** Store only the signed-in agent's browser subscription. */
final class AgentPushSubscriptionController extends Controller
{
    private const MAX_SUBSCRIPTIONS_PER_AGENT = 10;

    public function status(Request $request): JsonResponse
    {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH, 'url', 'starts_with:https://'],
        ]);
        $subscription = PushSubscription::query()
            ->where('endpoint', $validated['endpoint'])
            ->first();

        if (! $subscription instanceof PushSubscription) {
            return response()->json(['status' => 'missing']);
        }

        return response()->json([
            'status' => $agent->ownsPushSubscription($subscription) ? 'owned' : 'foreign',
        ]);
    }

    public function store(Request $request, OutboundWebhookDestination $destination): JsonResponse
    {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);

        $validated = $request->validate([
            'endpoint' => [
                'required',
                'string',
                'max:'.PushSubscription::ENDPOINT_MAX_LENGTH,
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
            $stored = DB::transaction(function () use ($agent, $validated): string {
                $currentAgent = User::query()
                    ->whereKey($agent->id)
                    ->where('account_id', $agent->account_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $subscription = PushSubscription::query()
                    ->where('endpoint', $validated['endpoint'])
                    ->lockForUpdate()
                    ->first();

                if ($subscription instanceof PushSubscription
                    && ! $currentAgent->ownsPushSubscription($subscription)) {
                    return 'foreign';
                }

                if (! $subscription instanceof PushSubscription
                    && $currentAgent->pushSubscriptions()->count() >= self::MAX_SUBSCRIPTIONS_PER_AGENT) {
                    return 'limit';
                }

                $attributes = [
                    'public_key' => $validated['keys']['p256dh'],
                    'auth_token' => $validated['keys']['auth'],
                    'content_encoding' => $validated['content_encoding'] ?? 'aes128gcm',
                ];

                if ($subscription instanceof PushSubscription) {
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
            default => response()->json(['message' => __('profile.alerts.push_owned_elsewhere')], 409),
        };
    }

    public function destroy(Request $request): Response
    {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH, 'url', 'starts_with:https://'],
        ]);

        $agent->pushSubscriptions()
            ->where('endpoint', $validated['endpoint'])
            ->delete();

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
