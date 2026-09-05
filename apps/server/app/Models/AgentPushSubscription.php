<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Settings\OperatorSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use NotificationChannels\WebPush\PushSubscription;
use Throwable;

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

    public static function usesPrimaryDatabaseConnection(): bool
    {
        try {
            return (new self)->getConnection()->getName() === DB::connection()->getName();
        } catch (Throwable) {
            return false;
        }
    }

    public function usesCurrentVapidGeneration(): bool
    {
        if (! app(OperatorSettings::class)->valuesAreAuthoritative()) {
            return true;
        }

        return hash_equals(
            self::currentVapidPublicKeyHash(),
            (string) $this->vapid_public_key_hash,
        );
    }

    /** Whether one database-backed key authoritatively supersedes every generation. */
    public static function canPurgeOtherVapidGenerations(): bool
    {
        if (! app(OperatorSettings::class)->valuesAreAuthoritative()) {
            return false;
        }

        try {
            // An operator override is shared by every process. An environment
            // baseline is process-local, so two valid generations can coexist
            // during a rolling deployment and neither may delete the other.
            return OperatorSetting::query()
                ->where('key', 'webpush.public_key')
                ->whereNotNull('value')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public static function purgeStaleFor(User $agent): int
    {
        if (! self::canPurgeOtherVapidGenerations()) {
            return 0;
        }

        return self::withoutGlobalScope(self::CURRENT_VAPID_SCOPE)
            ->where('subscribable_type', $agent->getMorphClass())
            ->where('subscribable_id', $agent->getKey())
            ->where(function (Builder $query): void {
                $query->whereNull('vapid_public_key_hash')
                    ->orWhere('vapid_public_key_hash', '!=', self::currentVapidPublicKeyHash());
            })
            ->delete();
    }

    /** Remove one browser endpoint without silencing the agent's other browsers. */
    public static function revokeEndpointFor(User $agent, string $endpoint): void
    {
        if ($agent->account_id === null) {
            return;
        }

        if (! self::usesPrimaryDatabaseConnection()) {
            throw new LogicException('Push subscription mutations require the primary database connection.');
        }

        $accountId = (int) $agent->account_id;
        $userId = (int) $agent->getKey();

        DB::transaction(function () use ($accountId, $endpoint, $userId): void {
            Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $currentAgent = User::query()
                ->whereKey($userId)
                ->where('account_id', $accountId)
                ->lockForUpdate()
                ->firstOrFail();
            $owned = fn (): Builder => self::withoutGlobalScope(self::CURRENT_VAPID_SCOPE)
                ->where('subscribable_type', $currentAgent->getMorphClass())
                ->where('subscribable_id', $currentAgent->getKey());

            $owned()->where('endpoint', $endpoint)->delete();

            if ($owned()->exists()) {
                return;
            }

            $alertPreferences = $currentAgent->alert_preferences ?? [];

            if (($alertPreferences['push'] ?? false) === true) {
                $currentAgent->forceFill([
                    'alert_preferences' => array_merge($alertPreferences, ['push' => false]),
                ])->save();
            }
        });
    }
}
