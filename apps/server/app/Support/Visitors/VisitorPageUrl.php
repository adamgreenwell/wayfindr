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

        $host = parse_url($reduced, PHP_URL_HOST);

        return is_string($host) && self::belongsToSite($host, $expectedHost) ? $reduced : null;
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
     * Is this the site's own host?
     *
     * A null expectation is not "anything goes" -- it means we have nothing to
     * check against, so nothing is trusted. A site with no configured domain
     * stores no page address at all, because we cannot tell its pages from
     * anybody else's and guessing is the failure this exists to prevent.
     */
    private static function belongsToSite(string $host, ?string $expectedHost): bool
    {
        if ($expectedHost === null) {
            return false;
        }

        $host = strtolower(trim($host));
        $expected = strtolower(trim((string) preg_replace('#^[a-z]+://#i', '', $expectedHost)));
        $expected = ltrim(explode('/', $expected)[0], '.');

        // A configured domain may carry a port -- `localhost:8000` and
        // `staging.example.test:8443` are both supported install shapes -- while
        // parse_url() hands back the hostname alone. Comparing them unstripped
        // rejects every legitimate page on exactly the installs that need a
        // port, and stores null instead.
        $expected = explode(':', $expected)[0];

        if ($expected === '' || $host === '') {
            return false;
        }

        // The apex, or a subdomain of it. `www.` and a marketing subdomain are
        // the same site; `evil-shop.test` is NOT `shop.test`, which is why this
        // matches on a dot boundary rather than as a suffix.
        return $host === $expected || str_ends_with($host, '.'.$expected);
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

        $length = mb_strlen($segment);

        // Nothing legible is this long in one segment.
        if ($length >= 40) {
            return true;
        }

        // Long, carries a digit, and has no word separators: a slug is words
        // joined by hyphens, a token is not.
        return $length >= 20
            && preg_match('/\d/', $segment) === 1
            && preg_match('/[-_]/', $segment) !== 1;
    }
}
