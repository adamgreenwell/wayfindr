<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;

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
}
