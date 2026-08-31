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

        return $this->claimToken($token, self::fingerprint($request));
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
    private function claimToken(string $token, string $fingerprint): bool
    {
        $key = self::claimKey($token);

        // `Cache::add()` because a first claim has to be atomic. A
        // get-then-put would let two concurrent replays both read "unclaimed"
        // and both proceed, which is exactly the shape an attacker would send.
        if (Cache::add($key, self::claim($fingerprint, false, 0), self::MAXIMUM_AGE_SECONDS * 2)) {
            return true;
        }

        $claim = Cache::get($key);

        // Spent, and not handed back: a replay.
        if (! is_array($claim) || ($claim['retryable'] ?? false) !== true) {
            return false;
        }

        // Handed back, but only the SAME message may pick it up. The signature
        // covers the timestamp and token and not the body, so a release that
        // asked no further questions would be a forgery window: anyone holding
        // the tuple could take the freed claim and post a different sender,
        // subject or body with it.
        //
        // Checked BEFORE the generation below, deliberately: a forged attempt
        // must not be able to burn the generation the genuine retry needs.
        if (! hash_equals((string) ($claim['fingerprint'] ?? ''), $fingerprint)) {
            return false;
        }

        $generation = (int) ($claim['generation'] ?? 0);

        // Exactly one retry may take any given generation, and `Cache::add()`
        // for the same reason the first claim uses it: the read above and the
        // write below are not one operation, so without this two concurrent
        // retries both see `retryable` and both proceed. Losing that race
        // returns false, so the loser gets 401 and never reaches the work --
        // which also means it can never release the claim the winner spent.
        if (! Cache::add($key.':generation:'.$generation, true, self::MAXIMUM_AGE_SECONDS * 2)) {
            return false;
        }

        Cache::put($key, self::claim($fingerprint, false, $generation + 1), self::MAXIMUM_AGE_SECONDS * 2);

        return true;
    }

    /**
     * @return array{fingerprint: string, retryable: bool, generation: int}
     */
    private static function claim(string $fingerprint, bool $retryable, int $generation): array
    {
        return ['fingerprint' => $fingerprint, 'retryable' => $retryable, 'generation' => $generation];
    }

    /**
     * What makes this the same message, for the purpose of a retry.
     *
     * Sender, recipients, subject, body and threading headers -- the fields
     * `InboundMessage` actually routes on. Change any of them and it is a
     * different delivery, which is precisely what a freed claim must not
     * authenticate.
     *
     * Attachments are deliberately NOT in it. The retry this exists to allow is
     * frequently one whose attachment CHANGED: the first attempt was refused
     * because the upload did not complete, and the retry carries the intact
     * file. Fingerprinting the bytes would refuse exactly the delivery the
     * release was built to save.
     */
    private static function fingerprint(Request $request): string
    {
        // Everything posted, MINUS a short list -- not a list of fields to
        // include.
        //
        // The first version named the fields to cover and missed four that
        // routing actually reads: `message_id` (the FIRST key
        // `InboundMessage` checks for the id, outranking the `Message-Id`
        // that was listed), `references`/`References`, bare `body`, and
        // `FromFull.Email`. Each omission was silently exploitable: change an
        // unlisted field on a freed claim and the fingerprint still matches,
        // so the delivery reclaims the token, misses
        // `alreadyAccepted()`'s message-id lookup, and threads itself into a
        // different conversation.
        //
        // An include-list has to enumerate every alias every provider might
        // send and every alias `InboundMessage` might read, and stay correct
        // as both change. A deny-list only has to name what a legitimate
        // retry is allowed to differ in, which is a short closed set that
        // changes when this file does.
        $material = $request->input();

        foreach ([
            // The tuple that authenticates the delivery rather than describes
            // it. Identical on a retry by definition, so including it would
            // be harmless -- and excluding it says which is which.
            'timestamp', 'token', 'signature',

            // The attachment payload, and only for the reason the docblock
            // gives: a retry after a 422 carries a DIFFERENT file.
            // `$request->input()` already excludes uploaded files, so this
            // drops the base64 arrays the JSON-shaped providers send and the
            // multipart bookkeeping that varies with them.
            'attachments', 'Attachments', 'attachment-count', 'content-id-map',
        ] as $volatile) {
            unset($material[$volatile]);
        }

        // Key order must not decide identity: a provider is free to reorder
        // fields between a delivery and its retry.
        self::sortDeep($material);

        return hash('sha256', json_encode($material, JSON_THROW_ON_ERROR));
    }

    /**
     * Sort an arbitrarily nested payload by key, in place.
     *
     * `message-headers` arrives as a list of [name, value] pairs, so nested
     * structure is normal here rather than exotic.
     */
    private static function sortDeep(array &$material): void
    {
        ksort($material);

        foreach ($material as &$value) {
            if (is_array($value)) {
                self::sortDeep($value);
            }
        }
    }

    /**
     * Hand the token back, BOUND to the delivery that spent it.
     *
     * Marked retryable rather than forgotten, and that distinction is the
     * whole of it. Forgetting opens a forgery window: the failure this
     * releases for may have happened AFTER the message was committed -- a
     * broadcast or listener throwing past the transaction -- and since
     * Mailgun's HMAC covers only the timestamp and token, anyone holding that
     * tuple could take the freed claim and post a different sender, subject or
     * body inside the remaining freshness window.
     *
     * Keeping the fingerprint means only the same message can pick it up,
     * which is the only thing a retry ever is.
     *
     * Derived from the request rather than remembered on the instance: the
     * verifier is resolved per delivery and keeping claim state on it would
     * make correctness depend on that staying true.
     */
    public function release(Request $request): void
    {
        $token = (string) $request->input('token', '');

        if ($token === '') {
            return;
        }

        $key = self::claimKey($token);
        $claim = Cache::get($key);

        // Only a claim this request actually made. Reading it back rather than
        // writing blind means a token some other delivery holds cannot be
        // freed by asking.
        if (! is_array($claim) || ! hash_equals((string) ($claim['fingerprint'] ?? ''), self::fingerprint($request))) {
            return;
        }

        // The generation is written back unchanged. It was already incremented
        // when this attempt claimed it, so the next retry contends on a fresh,
        // unclaimed generation key rather than one this attempt burned.
        Cache::put(
            $key,
            self::claim((string) $claim['fingerprint'], true, (int) ($claim['generation'] ?? 0)),
            self::MAXIMUM_AGE_SECONDS * 2,
        );
    }

    private static function claimKey(string $token): string
    {
        return 'wayfindr:inbound-mail:mailgun-token:'.hash('sha256', $token);
    }
}
