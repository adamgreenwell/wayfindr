<?php

declare(strict_types=1);

namespace App\Support\Mail\Signatures;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
 *
 * ## What the tuple can and cannot say
 *
 * Because the HMAC covers only `timestamp . token`, a captured tuple
 * authenticates ANY payload for as long as it stays fresh. Anybody who obtains
 * one delivery -- from a log, a proxy, a retry they can observe -- could
 * otherwise post arbitrary mail as anyone. That is forgery, not replay, and it
 * is what the claim below exists to stop.
 *
 * A claim records the FINGERPRINT of the delivery that made it. A later
 * delivery on the same token is accepted only if it is the same message, and
 * refused otherwise. There is no release, no retryable flag and no generation
 * counter: three review rounds added those to let a provider retry through,
 * and each one opened the hole the next had to close. They are unnecessary,
 * because a genuine retry carries the same message and therefore the same
 * fingerprint -- it was never the thing being refused.
 *
 * Re-PROCESSING an identical delivery is a different question, and it is
 * answered where idempotency already lives: {@see
 * \App\Support\Mail\InboundMailRouter::alreadyAccepted()}, backed by a unique
 * index rather than by a cache entry.
 */
final class MailgunSignature implements VerifiesInboundMail
{
    /**
     * How far out of date a delivery may be.
     *
     * Sized to the provider's retry schedule, which is the thing that decides
     * it. Mailgun re-POSTs a failed route at 10 minutes, then 15, 30, 60, 120
     * and 240, giving up after 8 hours -- so a window shorter than that
     * refuses the provider's own retries, and refuses them on the timestamp
     * before any of the machinery below is consulted.
     *
     * It was 300 seconds, and that was a real defect rather than a
     * conservative choice: every documented retry arrived stale, so the `422`
     * this endpoint answers for an incomplete upload -- the whole point of
     * which is that the provider tries again -- could never have worked. The
     * documentation said it did.
     *
     * A long window is safe here only because the fingerprint binds the whole
     * delivery. A captured tuple replayed inside it can carry nothing but the
     * message it was issued for, which routing already treats as a duplicate.
     * Shorten this and mail is lost; widen it without the fingerprint and mail
     * is forged.
     */
    private const MAXIMUM_AGE_SECONDS = 8 * 60 * 60;

    /**
     * How far AHEAD of us a sender's clock may be.
     *
     * Separate from the age above, and much smaller, because the two bounds
     * answer different questions. The past bound has to cover a retry
     * schedule. The future bound only has to cover clock drift between two
     * hosts, which is seconds when NTP is working and minutes when it is not.
     *
     * They were one symmetric check, and that left a hole: a tuple stamped an
     * hour ahead stayed acceptable for nine hours while its claim expired
     * after eight -- so for the last hour a captured tuple could be presented
     * with a forged body, find nothing to compare against, and take a brand
     * new claim. A future timestamp is not evidence of freshness, and there is
     * no reason to extend anything on the strength of one.
     */
    private const MAXIMUM_SKEW_SECONDS = 300;

    /**
     * A slot whose bytes the sender never successfully delivered.
     *
     * The one thing a retry is allowed to change. An upload PHP marks invalid
     * has no readable content by definition, so there is nothing to bind --
     * and the retry that carries the intact file is exactly the delivery this
     * endpoint asked for when it answered `422`.
     */
    private const UNUSABLE = 'unusable';

    public function verify(Request $request, string $secret): bool
    {
        // Scalars before casting. The nested webhook shape sends `signature`
        // as an object, and `(string)` on an array raises a warning this
        // codebase promotes to an exception -- so the deliberate 401 this
        // class documents for that shape came back as a 500, and the provider
        // kept retrying it.
        foreach (['timestamp', 'token', 'signature'] as $field) {
            if (! is_scalar($request->input($field))) {
                return false;
            }
        }

        $timestamp = (string) $request->input('timestamp', '');
        $token = (string) $request->input('token', '');
        $signature = (string) $request->input('signature', '');

        if ($timestamp === '' || $token === '' || $signature === '') {
            return false;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        // Future timestamps are rejected far more sharply than old ones: a
        // clock ahead is not evidence of freshness, and every second granted
        // there is a second the claim below has to outlive.
        $age = time() - (int) $timestamp;

        if ($age > self::MAXIMUM_AGE_SECONDS || $age < -self::MAXIMUM_SKEW_SECONDS) {
            return false;
        }

        if (! hash_equals(hash_hmac('sha256', $timestamp.$token, $secret), $signature)) {
            return false;
        }

        return $this->claimToken($token, self::fingerprint($request), (int) $timestamp);
    }

    /**
     * A token is good for one message, however many times it is delivered.
     *
     * `Cache::add()` because the first claim has to be atomic. A get-then-put
     * would let two concurrent forgeries both read "unclaimed" and both
     * proceed, which is exactly the shape an attacker would send.
     *
     * A second delivery on a claimed token is compared, not counted. Same
     * message, accepted -- that is a retry, and refusing it is how mail gets
     * lost. Different message, refused -- that is the forgery the HMAC cannot
     * catch on its own.
     *
     * @param  array{mail: string, attachments: array<string, string>}  $fingerprint
     */
    private function claimToken(string $token, array $fingerprint, int $timestamp): bool
    {
        $key = self::claimKey($token);

        // Measured from the SIGNED timestamp, not from now, so a claim can
        // never expire while the tuple that made it is still being accepted.
        // A fixed TTL leaves that gap open for exactly as long as the sender's
        // clock is ahead of ours, and what falls through it is a forged body
        // taking a fresh claim against nothing.
        $ttl = ($timestamp + self::MAXIMUM_AGE_SECONDS) - time() + 60;

        if (Cache::add($key, $fingerprint, $ttl)) {
            return true;
        }

        $claim = Cache::get($key);

        if (! is_array($claim)) {
            return false;
        }

        if (! self::matches($claim, $fingerprint)) {
            return false;
        }

        // An exact match needs nothing further: a delivery identical to the
        // one that made the claim leaves the claim identical too, so there is
        // nothing to write and nothing to race for.
        if ($claim == $fingerprint) {
            return true;
        }

        // Otherwise this delivery is filling a slot the claim left open, and
        // exactly one may. Two concurrent deliveries would both read the
        // wildcard and both pass before either wrote -- so an attacker holding
        // the tuple could race the genuine retry and substitute its bytes.
        // `Cache::add()` on the transition itself, which is the only atomic
        // primitive this needs and the same one the first claim uses.
        if (! Cache::add($key.':narrowed', true, $ttl)) {
            return false;
        }

        // Re-bound to what was actually presented, so a slot unlocked by one
        // incomplete upload narrows to concrete bytes as soon as a good file
        // arrives, instead of staying open for the rest of the window.
        Cache::put($key, $fingerprint, $ttl);

        return true;
    }

    /**
     * Whether a delivery is the same message the claim was made for.
     *
     * The wildcard is DIRECTIONAL. Only the stored side may say `unusable`, so
     * presenting a broken file cannot unlock a slot the genuine delivery
     * filled -- which would otherwise hand an attacker the carve-out on
     * demand, since an oversized upload is something anyone can send.
     *
     * @param  array<string, mixed>  $claim
     * @param  array{mail: string, attachments: array<string, string>}  $fingerprint
     */
    private static function matches(array $claim, array $fingerprint): bool
    {
        if (! is_string($claim['mail'] ?? null) || ! hash_equals($claim['mail'], $fingerprint['mail'])) {
            return false;
        }

        $claimed = is_array($claim['attachments'] ?? null) ? $claim['attachments'] : [];
        $offered = $fingerprint['attachments'];

        // The SET of slots must match, not merely each slot that is present.
        // Otherwise a forgery adds files rather than replacing them.
        if (array_keys($claimed) !== array_keys($offered)) {
            return false;
        }

        foreach ($claimed as $slot => $digest) {
            if ($digest === self::UNUSABLE) {
                continue;
            }

            if (! is_string($digest) || ! hash_equals($digest, $offered[$slot])) {
                return false;
            }
        }

        return true;
    }

    /**
     * What makes this the same message.
     *
     * Two parts, because "one slot may vary" cannot be expressed inside a
     * single hash.
     *
     * `mail` is everything posted except the tuple that authenticates it and
     * the attachment payload, key-sorted so a provider may reorder fields
     * between a delivery and its retry. A deny-list rather than a list of
     * fields to include: an include-list has to name every alias every
     * provider might send and every alias `InboundMessage` might read, and an
     * earlier version of this missed four of them -- `message_id`,
     * `references`, bare `body` and `FromFull.Email` -- each silently
     * exploitable, since an unlisted field can be changed on a claimed token.
     *
     * `attachments` is a slot-keyed map of digests, covering BOTH wire shapes.
     * Multipart uploads never appear in `$request->input()` at all, so they
     * have to be read from `allFiles()` or they are bound by nothing; the
     * JSON-shaped providers put theirs in the payload, where the deny-list
     * would otherwise drop them. The slot key carries the sender's declared
     * filename, so a forgery cannot rename a file it is not allowed to
     * replace.
     *
     * @return array{mail: string, attachments: array<string, string>}
     */
    private static function fingerprint(Request $request): array
    {
        $material = $request->input();

        foreach ([
            // The tuple authenticates the delivery rather than describing it.
            'timestamp', 'token', 'signature',
            // Bound in the attachment map below instead, where one slot can be
            // allowed to vary and the rest cannot.
            'attachments', 'Attachments', 'attachment-count', 'content-id-map',
        ] as $volatile) {
            unset($material[$volatile]);
        }

        self::sortDeep($material);

        return [
            'mail' => hash('sha256', json_encode($material, JSON_THROW_ON_ERROR)),
            'attachments' => self::attachmentDigests($request),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function attachmentDigests(Request $request): array
    {
        $digests = [];

        foreach ($request->allFiles() as $field => $files) {
            foreach (is_array($files) ? $files : [$files] as $index => $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $slot = $field.'['.$index.']|'.$file->getClientOriginalName();

                // Gated on `isValid()` and never on the path. An upload PHP
                // rejected has an empty pathname, and `getRealPath()` on an
                // empty string returns the CURRENT DIRECTORY rather than
                // false -- so hashing "the file" would quietly hash a folder.
                $digests[$slot] = self::digest($file);
            }
        }

        foreach (['attachments', 'Attachments'] as $field) {
            $payload = $request->input($field);

            if (! is_array($payload)) {
                continue;
            }

            foreach ($payload as $index => $attachment) {
                $name = is_array($attachment) ? ($attachment['name'] ?? $attachment['Name'] ?? '') : '';
                $content = is_array($attachment) ? ($attachment['content'] ?? $attachment['Content'] ?? '') : $attachment;

                $digests[$field.'['.$index.']|'.(is_scalar($name) ? (string) $name : '')] =
                    hash('sha256', is_scalar($content) ? (string) $content : '');
            }
        }

        ksort($digests);

        return $digests;
    }

    /**
     * One uploaded file's digest, or the wildcard when there are no bytes.
     *
     * Readability is checked rather than assumed. `isValid()` says PHP
     * finished receiving the upload; it does not say the bytes are still
     * there when we come to read them, and `hash_file()` on a file that has
     * gone raises a warning -- which this codebase turns into an exception, so
     * an infrastructure fault would answer 500 in the middle of verifying a
     * signature rather than the 422 the controller has waiting for it.
     *
     * A file we cannot read is a file that did not arrive usably, which is
     * what the wildcard means. It is not an attacker primitive: the sender
     * chooses the name and the bytes, not whether this host's filesystem
     * answers.
     */
    private static function digest(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            return self::UNUSABLE;
        }

        $path = $file->getPathname();

        if (! is_file($path) || ! is_readable($path)) {
            return self::UNUSABLE;
        }

        $digest = @hash_file('sha256', $path);

        return $digest === false ? self::UNUSABLE : $digest;
    }

    /**
     * Sort an arbitrarily nested payload by key, in place.
     *
     * `message-headers` arrives as a list of [name, value] pairs, so nested
     * structure is normal here rather than exotic.
     *
     * @param  array<mixed>  $material
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

    private static function claimKey(string $token): string
    {
        return 'wayfindr:inbound-mail:mailgun-token:'.hash('sha256', $token);
    }
}
