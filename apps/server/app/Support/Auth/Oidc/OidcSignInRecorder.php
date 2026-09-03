<?php

declare(strict_types=1);

namespace App\Support\Auth\Oidc;

use App\Models\AuditEvent;
use App\Models\OidcConnection;
use App\Models\OidcIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class OidcSignInRecorder
{
    /**
     * Revalidates the provider binding at the exact point federation becomes
     * a completed Wayfindr sign-in, then records only that lifecycle fact.
     *
     * @param  array<string, mixed>  $context
     */
    public function complete(User $user, array $context): bool
    {
        $keys = ['oidc_identity_id', 'oidc_connection_id', 'oidc_configuration_version'];
        $present = array_filter($keys, fn (string $key): bool => array_key_exists($key, $context));

        if ($present === []) {
            return true;
        }

        if (count($present) !== count($keys)
            || ! is_numeric($context['oidc_identity_id'])
            || ! is_numeric($context['oidc_connection_id'] ?? null)
            || ! is_string($context['oidc_configuration_version'] ?? null)) {
            return false;
        }

        return DB::transaction(function () use ($user, $context): bool {
            $lockedUser = User::query()->lockForUpdate()->find($user->id);

            if ($lockedUser === null || $lockedUser->isDeactivated() || $lockedUser->isPlatformOperator()) {
                return false;
            }

            $connection = OidcConnection::query()
                ->whereKey((int) $context['oidc_connection_id'])
                ->where('account_id', $lockedUser->account_id)
                ->where('is_enabled', true)
                ->lockForUpdate()
                ->first();

            if ($connection === null
                || ! hash_equals($context['oidc_configuration_version'], $connection->configuration_version)) {
                return false;
            }

            $identity = OidcIdentity::query()
                ->whereKey((int) $context['oidc_identity_id'])
                ->where('oidc_connection_id', $connection->id)
                ->where('user_id', $lockedUser->id)
                ->lockForUpdate()
                ->first();

            if ($identity === null) {
                return false;
            }

            $identity->forceFill(['last_signed_in_at' => now()])->save();

            AuditEvent::query()->create([
                'account_id' => $lockedUser->account_id,
                'actor_type' => $lockedUser->getMorphClass(),
                'actor_id' => $lockedUser->id,
                'subject_type' => $identity->getMorphClass(),
                'subject_id' => $identity->id,
                'action' => 'agent.oidc_signed_in',
                'metadata' => [],
                'occurred_at' => now(),
            ]);

            return true;
        });
    }
}
