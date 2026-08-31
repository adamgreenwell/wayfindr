<?php

declare(strict_types=1);

namespace App\Support\Mail\Signatures;

use Illuminate\Http\Request;

/**
 * Wayfindr's own scheme: HMAC-SHA256 over the raw body.
 *
 * The default, and the only one that existed before providers were named.
 * Kept because an install already running a re-signing proxy in front of this
 * endpoint -- which was the only way to use the channel at all -- must not
 * break on upgrade.
 */
final class WayfindrSignature implements VerifiesInboundMail
{
    public function verify(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('X-Wayfindr-Signature', '');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        // hash_equals, which takes the same time whatever the input. A plain
        // === leaks how much of a guess was right, one byte at a time.
        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }
}
