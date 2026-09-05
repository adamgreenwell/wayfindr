<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use NotificationChannels\WebPush\PushSubscription;

/** Keep browser endpoints bound to the VAPID public key that created them. */
final class AgentPushSubscription extends PushSubscription
{
    public const CURRENT_VAPID_SCOPE = 'current_vapid_generation';

    /** @var list<string> */
    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'vapid_public_key_hash',
    ];

    protected static function booted(): void
    {
        self::addGlobalScope(
            self::CURRENT_VAPID_SCOPE,
            fn (Builder $query): Builder => $query->where(
                $query->qualifyColumn('vapid_public_key_hash'),
                self::currentVapidPublicKeyHash(),
            ),
        );

        self::saving(function (self $subscription): void {
            $subscription->vapid_public_key_hash = self::currentVapidPublicKeyHash();
        });
    }

    public static function currentVapidPublicKeyHash(): string
    {
        return hash('sha256', trim((string) config('webpush.vapid.public_key')));
    }

    public function usesCurrentVapidGeneration(): bool
    {
        return hash_equals(
            self::currentVapidPublicKeyHash(),
            (string) $this->vapid_public_key_hash,
        );
    }

    public static function purgeStaleFor(User $agent): int
    {
        return self::withoutGlobalScope(self::CURRENT_VAPID_SCOPE)
            ->where('subscribable_type', $agent->getMorphClass())
            ->where('subscribable_id', $agent->getKey())
            ->where(function (Builder $query): void {
                $query->whereNull('vapid_public_key_hash')
                    ->orWhere('vapid_public_key_hash', '!=', self::currentVapidPublicKeyHash());
            })
            ->delete();
    }
}
