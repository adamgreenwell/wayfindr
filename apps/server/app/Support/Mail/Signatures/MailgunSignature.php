<?php

declare(strict_types=1);

namespace App\Support\Mail\Signatures;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Mailgun signs a timestamp and a token, not the body.
 *
 * `signature = HMAC-SHA256(timestamp . token, signing_key)`, with all three
 * sent flat in the payload of a route-forwarded message.
 *
 * The newer nested webhook shape is deliberately NOT accepted. Verifying it
 * would succeed and then route nothing: its sender and body live under
 * `event-data`, which `InboundMessage` does not read, so every delivery would
 * answer `200 Ignored.` and quietly create no conversation. Refusing it is the
 * honest answer until something can parse it.
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
        $timestamp = (string) $request->input('timestamp', '');
        $token = (string) $request->input('token', '');
        $signature = (string) $request->input('signature', '');

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

        if (! hash_equals(hash_hmac('sha256', $timestamp.$token, $secret), $signature)) {
            return false;
        }

        return $this->claimToken($token);
    }

    /**
     * Each token is good once.
     *
     * The age bound alone is not enough, because Mailgun's HMAC covers the
     * timestamp and token and NOT the body. A valid tuple therefore
     * authenticates ANY payload for as long as it stays fresh: anybody who
     * obtains one delivery -- from a log, a proxy, a retry they can observe --
     * can post arbitrary mail as anyone until it expires. That is forgery, not
     * merely replay of the same message.
     *
     * `Cache::add()` because the claim has to be atomic. A get-then-put would
     * let two concurrent replays both read "unclaimed" and both proceed, which
     * is exactly the shape an attacker would send.
     *
     * Held a little longer than the freshness window, so a token cannot be
     * reclaimed in the gap between its entry expiring and its timestamp
     * ageing out.
     */
    private function claimToken(string $token): bool
    {
        return Cache::add(
            'wayfindr:inbound-mail:mailgun-token:'.hash('sha256', $token),
            true,
            self::MAXIMUM_AGE_SECONDS * 2,
        );
    }
}
