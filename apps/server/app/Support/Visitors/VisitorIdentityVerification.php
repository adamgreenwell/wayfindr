<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\Site;
use Illuminate\Contracts\Encryption\DecryptException;
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

    /**
     * What a site created from today onwards starts as.
     *
     * New sites verify; EXISTING ones do not, and that asymmetry is the whole
     * design. Turning verification on for a site whose host has already shipped
     * pages that send an unsigned identifier would stop identifying every one
     * of their customers, silently, on upgrade. A site being created now has no
     * such pages, so the safe default costs nobody anything.
     *
     * No secret is issued here on purpose. One minted at creation and never
     * shown is dead -- the plaintext exists for a single response, and there is
     * no response here that an operator is reading. So a new site starts
     * REQUIRED with nothing to verify against, which fails closed: an external
     * id is ignored until somebody issues a secret from the site's settings and
     * signs with it. For a site with no integration yet, that is exactly right.
     *
     * @return array{identity_verification: string}
     */
    public static function newSiteDefaults(): array
    {
        return ['identity_verification' => self::REQUIRED];
    }

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
        $secret = $this->secretFor($site);

        if ($secret === null) {
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
        $secret = $this->secretFor($site);

        if ($secret === null) {
            return null;
        }

        return hash_hmac('sha256', $externalId, $secret);
    }

    /**
     * The site's secret, or null when there is not a usable one.
     *
     * `identity_secret` carries the `encrypted` cast, so simply READING it
     * decrypts -- and a ciphertext this install has no key for throws
     * `DecryptException` rather than returning anything. That state is not
     * hypothetical: it is what a restore under a rotated `APP_KEY` leaves
     * behind, which is the situation the key-loss runbook in
     * docs/self-hosting/backup-restore.md exists for.
     *
     * Uncaught, it surfaced as a 500 from the PUBLIC widget bootstrap -- so the
     * panel failed to draw for every identified visitor on the site, while
     * anonymous ones were served normally. Every other encrypted read in this
     * codebase already catches this and degrades; this was the only one that
     * did not, and the only one on an unauthenticated endpoint.
     *
     * An unreadable secret is just another kind of "no": it cannot verify
     * anything, so nothing is identified, which is the same fail-closed
     * outcome as having no secret at all.
     */
    private function secretFor(Site $site): ?string
    {
        try {
            $secret = $site->identity_secret;
        } catch (DecryptException) {
            return null;
        }

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
