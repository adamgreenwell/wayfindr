<?php

declare(strict_types=1);

namespace App\Support\Mail\Signatures;

/**
 * Which scheme this install's provider actually speaks.
 *
 * Chosen by configuration rather than sniffed from the request. Sniffing would
 * mean any caller could pick the weakest scheme on offer by shaping their
 * payload to look like it came from that provider -- an endpoint configured
 * for Mailgun would accept Postmark's Basic credentials from anyone who
 * guessed the secret's other use.
 */
final class InboundMailVerifiers
{
    /**
     * @return array<string, class-string<VerifiesInboundMail>>
     */
    public static function map(): array
    {
        return [
            'wayfindr' => WayfindrSignature::class,
            'mailgun' => MailgunSignature::class,
            'postmark' => PostmarkCredentials::class,
        ];
    }

    /**
     * Null for a name nobody implements, which the caller must treat as a
     * refusal. A typo in the configuration is not permission to accept
     * unverified mail.
     */
    public static function for(string $provider): ?VerifiesInboundMail
    {
        $class = self::map()[strtolower(trim($provider))] ?? null;

        return $class === null ? null : new $class;
    }
}
