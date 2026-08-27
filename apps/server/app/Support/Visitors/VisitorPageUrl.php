<?php

declare(strict_types=1);

namespace App\Support\Visitors;

/**
 * The page a visitor was on, with the parts that are not a location removed.
 *
 * Wayfindr stores this to give an agent context — "they were on /pricing" is a
 * different conversation from "they just landed". The question it answers is
 * WHICH PAGE, and a path answers that.
 *
 * A query string answers something else. It is where host sites put
 * password-reset tokens, invitation codes, magic-link credentials and email
 * addresses, none of which anybody chose to send to their support tool. Before
 * this class the URL was stored exactly as the widget reported it, and shown to
 * agents in the visitor context panel — so on a site with `?reset_token=` in
 * its URLs, that token was on an agent's screen.
 *
 * Dropped rather than filtered, deliberately. Filtering means guessing which
 * parameter NAMES are sensitive, and the dangerous ones are frequently the
 * shortest: `?t=`, `?k=`, `?c=`. A name-based rule fails exactly where it
 * matters most, and fails silently.
 *
 * And dropped WHOLE, with no per-site allowlist. This class briefly took one,
 * because letting an operator keep `?plan=pro` looked like a reasonable
 * kindness. It cannot coexist with the guarantee that matters more: sanitising
 * also happens in a model `saving` hook so that no writer can put a query
 * string back, and that hook runs without knowing which site a row belongs to.
 * A kept parameter would be stripped again by the next ordinary save, and the
 * operator would watch their configuration vanish for no visible reason.
 *
 * An option nothing can honour is worse than no option, so there is none.
 */
final class VisitorPageUrl
{
    /**
     * Matches the column, which is a string rather than a URL type.
     */
    private const MAX_LENGTH = 2048;

    /**
     * The only schemes a visitor's page address may have.
     *
     * An allowlist because these values are rendered as clickable `href`s on
     * the agent ticket page. `javascript://evil.test/%0Aalert(document.domain)`
     * parses with a host and a path and is a perfectly ordinary-looking URL to
     * every check that is not this one -- and the widget endpoints are public,
     * so the value is attacker-controlled.
     *
     * Laravel's `url` rule does not help: it accepts any scheme.
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * What replaces a path segment that looks like a credential.
     *
     * Plain ASCII deliberately. An ellipsis reads better and is at the mercy of
     * whatever collation an operator's database happens to use -- this value is
     * a security-relevant redaction and must not depend on that. Brackets also
     * make it obvious that something was removed rather than that the page is
     * named "redacted".
     */
    private const REDACTED = '[redacted]';

    /**
     * At INGRESS: the address must belong to the site, or it is not stored.
     *
     * Separate from `reduce()` on purpose, and the separation is the point. The
     * host can only be judged where the site is known -- at the endpoint taking
     * the request. The model hook and the historical sweep run without one, so
     * if they shared a method with a nullable host they would either wipe every
     * stored address or quietly accept any host, depending which way the default
     * fell. Two names means a caller has to say which situation it is in.
     */
    public static function forSite(?string $url, ?string $expectedHost): ?string
    {
        $reduced = self::reduce($url);

        if ($reduced === null) {
            return null;
        }

        $parts = parse_url($reduced);

        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme'])) {
            return null;
        }

        return self::belongsToSite($parts, $expectedHost) ? $reduced : null;
    }

    /**
     * AT REST: strip everything that is not a page address, host untouched.
     *
     * Used by the model hook and the historical sweep, which see a stored row
     * and not the site it belongs to. Re-checking the host here would delete
     * every address ever stored before the host rule existed.
     */
    public static function reduce(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        // Unparseable is not "leave it alone" -- an input this class cannot
        // reason about is the one most likely to carry something odd.
        if ($parts === false || ! isset($parts['host'], $parts['scheme'])) {
            return null;
        }

        // Checked BEFORE anything is rebuilt. Requiring a host is not a scheme
        // check: `javascript:alert(1)` has no host and was already rejected,
        // which made the guard look present while
        // `javascript://evil.test/%0Aalert(1)` walked past it with both.
        if (! in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        // Rebuilt from named parts rather than edited as a string, so anything
        // this class does not name is dropped by construction -- including
        // `user:pass@` credentials in the authority, which no support context
        // needs and which a regex over the whole URL would have to remember.
        $rebuilt = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }

        $rebuilt .= self::redactPath($parts['path'] ?? '');

        // The query string is not rebuilt at all -- it is simply never one of
        // the named parts.
        //
        // The fragment never reaches a server in a normal navigation, so a
        // widget reporting one is reporting something the host page chose to
        // put in front of us. Single-page apps use it for routing, which is a
        // location -- but they also use it for tokens, and we cannot tell which
        // this is. Dropped, and the path still answers "which page".
        return mb_substr($rebuilt, 0, self::MAX_LENGTH);
    }

    /**
     * Is this address on the site's own origin?
     *
     * A null expectation is not "anything goes" -- it means we have nothing to
     * check against, so nothing is trusted. A site with no configured domain
     * stores no page address at all, because we cannot tell its pages from
     * anybody else's and guessing is the failure this exists to prevent.
     *
     * Host AND port, because same host on a different port is a different
     * service. A site configured as `shop.test:8443` does not vouch for
     * `shop.test:9998`, which may be an admin panel, a database console or
     * anything else somebody left listening -- and the value this returns is
     * rendered as a clickable link on the agent ticket page.
     *
     * @param  array<string, mixed>  $parts  the reported URL, already reduced
     */
    private static function belongsToSite(array $parts, ?string $expectedHost): bool
    {
        if ($expectedHost === null) {
            return false;
        }

        $host = strtolower(trim((string) $parts['host']));

        // The configured value is operator input and arrives in whatever shape
        // they typed: a bare host, a host and port, a full URL, a leading dot.
        // Reduce it to an authority, then let the SAME parser that read the
        // reported URL read this one -- so `shop.test:8443` and `[::1]:8000`
        // split into host and port identically on both sides of the comparison
        // rather than by a hand-rolled split that gets IPv6 wrong.
        $configured = trim($expectedHost);

        // The scheme, kept rather than stripped and forgotten. An operator who
        // wrote `https://shop.test` said which service they meant, and
        // accepting `http://shop.test/login` for it treats two origins as one
        // -- they can be different servers, and the plain-text one is the one
        // an attacker on the network can answer for.
        //
        // Absent, no constraint: a bare `shop.test` is a host, not a claim
        // about transport, and most operators type it that way.
        $expectedScheme = preg_match('#^([a-z][a-z0-9+.-]*)://#i', $configured, $matches) === 1
            ? strtolower($matches[1])
            : null;

        if ($expectedScheme !== null && $expectedScheme !== strtolower((string) $parts['scheme'])) {
            return false;
        }

        $authority = trim((string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $configured));
        $authority = ltrim(explode('/', $authority)[0], '.');

        if ($authority === '' || $host === '') {
            return false;
        }

        $expected = parse_url('//'.$authority);

        // A configured authority this cannot parse -- `shop.test:not-a-port` --
        // is not one to trust. Failing closed here stores no address; failing
        // open would store an unchecked one.
        if (! is_array($expected) || ! isset($expected['host'])) {
            return false;
        }

        $expectedHostOnly = self::asciiHost($expected['host']);
        $host = self::asciiHost($host);

        if ($expectedHostOnly === '' || $host === '') {
            return false;
        }

        // The apex, or a subdomain of it. `www.` and a marketing subdomain are
        // the same site; `evil-shop.test` is NOT `shop.test`, which is why this
        // matches on a dot boundary rather than as a suffix.
        if ($host !== $expectedHostOnly && ! str_ends_with($host, '.'.$expectedHostOnly)) {
            return false;
        }

        // Compared as effective ports so the two ways of writing one origin
        // agree: a site configured `shop.test` matches `https://shop.test/` and
        // `https://shop.test:443/` both, and a site configured `shop.test:8443`
        // matches neither.
        // The default port follows the CONFIGURED scheme where there is one, so
        // `http://shop.test` means port 80 and is not satisfied by 443.
        $default = ($expectedScheme ?? strtolower((string) $parts['scheme'])) === 'https' ? 443 : 80;

        return ($parts['port'] ?? $default) === ($expected['port'] ?? $default);
    }

    /**
     * One representation of a host, so equal hosts compare equal.
     *
     * An operator configures `bücher.example` because that is what they own and
     * what they typed. The browser reports `xn--bcher-kva.example`, because
     * that is what the wire carries. They are the same host, and comparing them
     * as written rejected every page on the site -- silently, since a rejected
     * address is stored as null and reads as "we did not see one".
     *
     * `ext-intl` is declared in composer.json, so an install without it is one
     * that should not exist. The guard below is not a supported fallback: it
     * keeps ASCII hosts -- which need no conversion -- comparing correctly
     * rather than fataling on every widget request, on an install that has
     * already ignored its own dependency list. An IDN site in that state still
     * cannot be compared, which is why the requirement is declared rather than
     * worked around.
     */
    private static function asciiHost(string $host): string
    {
        $host = strtolower(trim($host));

        if ($host === '' || ! function_exists('idn_to_ascii')) {
            return $host;
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        // A host `intl` cannot convert is left as it was rather than emptied:
        // failing to normalise is not evidence of anything, and an ASCII host
        // needs no conversion in the first place.
        return is_string($ascii) && $ascii !== '' ? strtolower($ascii) : $host;
    }

    /**
     * Replace path segments that look like a credential rather than a page.
     *
     * A path is the answer to "which page" and also where this very product
     * puts a token -- `/reset-password/{token}` is a route in this repository --
     * so treating the whole path as safe leaves the secret in the one part that
     * survived the query and fragment being dropped.
     *
     * Crude on purpose, and a heuristic rather than a proof. It will sometimes
     * redact a long harmless slug, which is the right way round for a rule
     * whose failures are credentials.
     */
    private static function redactPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return implode('/', array_map(
            static fn (string $segment): string => self::looksOpaque($segment) ? self::REDACTED : $segment,
            explode('/', $path),
        ));
    }

    private static function looksOpaque(string $segment): bool
    {
        // A UUID is never a page name.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1) {
            return true;
        }

        // A separator used to end the test, on the reasoning that a slug is
        // words joined by hyphens. It is also how a credential is punctuated:
        // `/invite/ABC-123` and `/reset/abc_def123` walked straight past a rule
        // that treated any hyphen as proof of readability.
        //
        // So separators are removed rather than trusted, and the segment is
        // judged twice -- once as a whole with its punctuation stripped, and
        // once part by part.

        // Whole, unpunctuated: a run of capitals and digits is a code. Sites
        // write `/about`, not `/ABOUT`, while invitation and coupon codes are
        // capitals by convention -- and `ABC-123` is that code with a hyphen
        // in it. Lowercase is deliberately not included here, because
        // `billing-preferences` unpunctuated is a long lowercase run and a
        // perfectly ordinary page.
        $bare = (string) preg_replace('/[-_.]/', '', $segment);

        if (mb_strlen($bare) >= 5 && preg_match('/^[A-Z0-9]+$/', $bare) === 1) {
            return true;
        }

        // Part by part: one token-shaped piece condemns the segment. This is
        // what catches `abc_def123`, whose second half is a token however
        // ordinary the first half looks.
        foreach (preg_split('/[-_.]/', $segment) ?: [] as $part) {
            if (self::partLooksOpaque($part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One separator-free piece of a path segment.
     *
     * Crude on purpose, and a heuristic rather than a proof. It will sometimes
     * redact a long harmless slug, which is the right way round for a rule
     * whose failures are credentials -- and it is why an operator whose paths
     * carry secrets can turn page addresses off entirely rather than trusting
     * this.
     */
    private static function partLooksOpaque(string $part): bool
    {
        $length = mb_strlen($part);

        // Nothing legible is this long in one unbroken piece.
        if ($length >= 32) {
            return true;
        }

        // Carries a digit and is past the length of a version or a year:
        // `v2`, `2024` and `page3` survive, `A1B2C3` and `123456` do not.
        if ($length >= 6 && preg_match('/\d/', $part) === 1) {
            return true;
        }

        // Sixteen letters unbroken is past the words routes are named after.
        // `notifications`, `recommendations` and `personalization` all fit
        // under it; `internationalization` does not, and that is the price.
        if ($length >= 16) {
            return true;
        }

        return $length >= 5 && preg_match('/^[A-Z0-9]+$/', $part) === 1;
    }
}
