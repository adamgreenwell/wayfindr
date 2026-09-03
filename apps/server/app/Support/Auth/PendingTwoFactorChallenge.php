<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\OidcConnection;
use App\Models\OidcIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class PendingTwoFactorChallenge
{
    public static function credentialFingerprint(User $user): string
    {
        return hash_hmac(
            'sha256',
            (string) $user->getAuthPassword(),
            (string) config('app.key'),
        );
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    public static function federatedCredentialIsCurrent(User $user, array $pending, bool $lock = false): bool
    {
        $keys = ['oidc_identity_id', 'oidc_connection_id', 'oidc_configuration_version'];
        $present = array_filter($keys, fn (string $key): bool => array_key_exists($key, $pending));

        if ($present === []) {
            return true;
        }

        if (count($present) !== count($keys)
            || ! is_numeric($pending['oidc_identity_id'])
            || ! is_numeric($pending['oidc_connection_id'] ?? null)
            || ! is_string($pending['oidc_configuration_version'] ?? null)) {
            return false;
        }

        $connectionQuery = OidcConnection::query()
            ->whereKey((int) $pending['oidc_connection_id'])
            ->where('account_id', $user->account_id)
            ->where('is_enabled', true);
        $connection = self::maybeLock($connectionQuery, $lock)->first();

        if ($connection === null
            || ! hash_equals($pending['oidc_configuration_version'], $connection->configuration_version)) {
            return false;
        }

        $identityQuery = OidcIdentity::query()
            ->whereKey((int) $pending['oidc_identity_id'])
            ->where('oidc_connection_id', $connection->id)
            ->where('user_id', $user->id);

        return self::maybeLock($identityQuery, $lock)->first() !== null;
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model @param Builder<TModel> $query @return Builder<TModel> */
    private static function maybeLock(Builder $query, bool $lock): Builder
    {
        return $lock ? $query->lockForUpdate() : $query;
    }
}
