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

    public static function sanitise(?string $url): ?string
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

        $rebuilt .= $parts['path'] ?? '';

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
}
