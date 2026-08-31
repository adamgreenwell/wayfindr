<?php

declare(strict_types=1);

namespace App\Support\Mail\Signatures;

use Illuminate\Http\Request;

/**
 * Mailgun signs a timestamp and a token, not the body.
 *
 * `signature = HMAC-SHA256(timestamp . token, signing_key)`, with all three
 * sent in the payload -- flat on a route-forwarded message, nested under
 * `signature` on the newer webhook shape. Both are accepted because both are
 * things Mailgun sends.
 *
 * The secret here is Mailgun's HTTP webhook signing key, from the sending
 * domain's security settings. It is NOT the API key, and telling an operator
 * apart from that is worth the sentence in the documentation.
 */
final class MailgunSignature implements VerifiesInboundMail
{
    /**
     * How far out of date a delivery may be.
     *
     * Without this a captured delivery replays for ever: the body, token and
     * signature stay valid together, so anybody who sees one can post it back
     * indefinitely. Mailgun sends the timestamp precisely so the receiver can
     * bound that, and its own guidance is to check it.
     */
    private const MAXIMUM_AGE_SECONDS = 300;

    public function verify(Request $request, string $secret): bool
    {
        $nested = $request->input('signature');
        $source = is_array($nested) ? $nested : $request->all();

        $timestamp = (string) ($source['timestamp'] ?? '');
        $token = (string) ($source['token'] ?? '');
        $signature = (string) ($source['signature'] ?? '');

        if ($timestamp === '' || $token === '' || $signature === '') {
            return false;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        // Future timestamps are rejected as firmly as old ones: a clock far
        // ahead is not evidence of freshness, and accepting one would let a
        // forged timestamp buy an unbounded replay window.
        if (abs(time() - (int) $timestamp) > self::MAXIMUM_AGE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.$token, $secret), $signature);
    }
}
