<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Decide whether an external id is the host's claim or the caller's.
 *
 * `visitors.external_id` reaches the server through a public endpoint that
 * authenticates nobody, so on its own it is an assertion by whoever is calling.
 * The product nonetheless displays it as the visitor's name (`VisitorLabel`
 * ranks it above `anonymous_id`), which means an agent reads a claim as a fact.
 *
 * A site that turns this on gives its host page a secret; the host computes
 * `hash_hmac('sha256', $externalId, $secret)` ON ITS OWN SERVER and passes the
 * result beside the id. Only a holder of the secret can produce that value for
 * an identifier, so a caller who is merely able to reach the endpoint cannot.
 *
 * The secret never goes to the browser. A hash computed in page JavaScript
 * would ship the secret to everyone who views source, which is the whole thing
 * this exists to prevent -- the documentation has to say so plainly, because it
 * is the obvious wrong way to implement it.
 */
final class VisitorIdentityVerification
{
    /** Verification is not in use; an external id is accepted unverified. */
    public const OFF = 'off';

    /** An external id is written only when its hash verifies. */
    public const REQUIRED = 'required';

    /** @var list<string> */
    public const MODES = [self::OFF, self::REQUIRED];

    public const SECRET_PREFIX = 'wfid_';

    /** @return array{plain: string, last_four: string} */
    public static function generateSecret(): array
    {
        // `Str::random` is `random_bytes` underneath, not `rand`.
        $random = Str::random(48);

        return [
            'plain' => self::SECRET_PREFIX.$random,
            'last_four' => substr($random, -4),
        ];
    }

    public function isRequiredFor(Site $site): bool
    {
        // A site with the mode set but no secret cannot verify anything, and
        // must not therefore accept everything. It refuses instead: treating a
        // missing secret as "off" would turn a misconfiguration into a silent
        // downgrade, which is the failure this whole feature exists to stop.
        return $site->identity_verification === self::REQUIRED;
    }

    /**
     * Whether this presented hash proves the host vouched for this identifier.
     *
     * Returns false for every kind of "no" -- unset secret, absent hash, wrong
     * hash, wrong type -- because the caller has exactly one decision to make
     * and distinguishing the reasons to it would be an oracle.
     */
    public function verifies(Site $site, string $externalId, mixed $presented): bool
    {
        $secret = $site->identity_secret;

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        if (! is_string($presented) || $presented === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $externalId, $secret);

        // `hash_equals`, not `===`. The comparison is against a value the
        // caller chooses and can submit repeatedly, which is the textbook shape
        // for a timing attack on a digest comparison.
        return hash_equals($expected, $presented);
    }

    /**
     * What the host has to compute. Kept here so the docs and the tests quote
     * one implementation rather than two that can drift.
     */
    public function expectedHash(Site $site, string $externalId): ?string
    {
        $secret = $site->identity_secret;

        if (! is_string($secret) || $secret === '') {
            return null;
        }

        return hash_hmac('sha256', $externalId, $secret);
    }
}
