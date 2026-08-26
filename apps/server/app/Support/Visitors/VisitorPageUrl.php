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
 * matters most, and fails silently. Operators who need specific parameters get
 * them back by naming them, which is a decision somebody made rather than a
 * pattern that happened not to match.
 */
final class VisitorPageUrl
{
    /**
     * Matches the column, which is a string rather than a URL type.
     */
    private const MAX_LENGTH = 2048;

    /**
     * @param  array<int, string>  $keepParameters  query parameters this site asked to keep
     */
    public static function sanitise(?string $url, array $keepParameters = []): ?string
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

        // Rebuilt from named parts rather than edited as a string, so anything
        // this class does not name is dropped by construction -- including
        // `user:pass@` credentials in the authority, which no support context
        // needs and which a regex over the whole URL would have to remember.
        $rebuilt = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }

        $rebuilt .= $parts['path'] ?? '';

        $kept = self::keptQuery($parts['query'] ?? '', $keepParameters);

        if ($kept !== '') {
            $rebuilt .= '?'.$kept;
        }

        // The fragment never reaches a server in a normal navigation, so a
        // widget reporting one is reporting something the host page chose to
        // put in front of us. Single-page apps use it for routing, which is a
        // location -- but they also use it for tokens, and we cannot tell which
        // this is. Dropped, and the path still answers "which page".
        return mb_substr($rebuilt, 0, self::MAX_LENGTH);
    }

    /**
     * @param  array<int, string>  $keepParameters
     */
    private static function keptQuery(string $query, array $keepParameters): string
    {
        if ($query === '' || $keepParameters === []) {
            return '';
        }

        parse_str($query, $parsed);

        $keep = array_flip(array_map('strtolower', $keepParameters));
        $kept = [];

        foreach ($parsed as $key => $value) {
            if (! is_string($value) || ! isset($keep[strtolower((string) $key)])) {
                continue;
            }

            $kept[(string) $key] = $value;
        }

        return $kept === [] ? '' : http_build_query($kept);
    }
}
