<?php

use App\Support\Visitors\VisitorPageUrl;

/**
 * `reduce()` is the at-rest rule: everything that is not a page address is
 * stripped, and the host is left alone because a stored row does not know which
 * site it belongs to. The ingress rule that judges the host is `forSite()`, and
 * it has its own tests below.
 */
test('a reset token never survives being reported as a page', function (): void {
    // The reason this class exists. A visitor who opens the chat while on a
    // password-reset page reported the whole URL, it was stored forever, and it
    // was shown to an agent in the visitor context panel.
    $sanitised = VisitorPageUrl::reduce(
        'https://shop.test/account/reset?reset_token=abc123&email=someone@example.test'
    );

    expect($sanitised)->toBe('https://shop.test/account/reset');
});

test('the query string goes whether or not its names look dangerous', function (): void {
    // Deliberately NOT a name-based rule. The dangerous parameters are often
    // the shortest -- `?t=`, `?k=`, `?c=` -- so a pattern over names fails
    // exactly where it matters, and fails silently.
    expect(VisitorPageUrl::reduce('https://shop.test/go?t=eyJhbGciOi'))
        ->toBe('https://shop.test/go')
        ->and(VisitorPageUrl::reduce('https://shop.test/search?q=hello'))
        ->toBe('https://shop.test/search');
});

test('a fragment goes too, because we cannot tell routing from a token', function (): void {
    // A fragment never reaches a server in an ordinary navigation, so a widget
    // reporting one is reporting something the page chose to put in front of
    // us. Single-page apps route with it; they also carry tokens in it.
    expect(VisitorPageUrl::reduce('https://shop.test/pricing?plan=pro#access_token=xyz'))
        ->toBe('https://shop.test/pricing');
});

test('credentials in the authority are dropped by construction', function (): void {
    // Not matched and removed -- never copied across. The URL is rebuilt from
    // the parts this class names, so anything it does not name cannot survive.
    expect(VisitorPageUrl::reduce('https://user:hunter2@shop.test/admin'))
        ->toBe('https://shop.test/admin');
});

test('what is left still answers which page', function (): void {
    // The whole point is context for an agent, so the path has to survive --
    // "/pricing" is a different conversation from "/". Ports too, because a
    // staging install on :8443 is a different host from the same name on 443.
    expect(VisitorPageUrl::reduce('https://shop.test/docs/install/forge'))
        ->toBe('https://shop.test/docs/install/forge')
        ->and(VisitorPageUrl::reduce('https://shop.test:8443/pricing'))
        ->toBe('https://shop.test:8443/pricing');
});

test('a dangerous scheme is dropped even when it parses perfectly', function (): void {
    // These values are rendered as clickable `href`s on the agent ticket page,
    // and the widget endpoints are public -- so the URL is attacker-controlled
    // and an agent is the target.
    //
    // The earlier test below passed for the WRONG REASON and hid this:
    // `javascript:alert(1)` has no host, so it was rejected by the host check
    // rather than by any scheme rule. Give it one and it walks straight
    // through: `javascript://evil.test/%0Aalert(document.domain)` parses with a
    // scheme, a host and a path, and looks entirely ordinary to every check
    // that is not an allowlist.
    expect(VisitorPageUrl::reduce('javascript://evil.test/%0Aalert(document.domain)'))->toBeNull()
        ->and(VisitorPageUrl::reduce('data://text/html;base64,PHNjcmlwdD4='))->toBeNull()
        ->and(VisitorPageUrl::reduce('vbscript://x.test/foo'))->toBeNull()
        ->and(VisitorPageUrl::reduce('file://etc/passwd'))->toBeNull()
        ->and(VisitorPageUrl::reduce('FTP://files.test/x'))->toBeNull();

    // And the two that are a page address survive, in either case.
    expect(VisitorPageUrl::reduce('http://shop.test/ok'))->toBe('http://shop.test/ok')
        ->and(VisitorPageUrl::reduce('HTTPS://shop.test/ok'))->toBe('HTTPS://shop.test/ok');
});

test('an unparseable URL is dropped rather than kept', function (): void {
    // "Leave it alone if you cannot read it" keeps precisely the inputs least
    // likely to be an ordinary page address.
    expect(VisitorPageUrl::reduce('not a url'))->toBeNull()
        ->and(VisitorPageUrl::reduce('javascript:alert(1)'))->toBeNull()
        ->and(VisitorPageUrl::reduce('   '))->toBeNull()
        ->and(VisitorPageUrl::reduce(null))->toBeNull();
});

test('the result is capped to what the column holds', function (): void {
    // Built from many SHORT legible segments on purpose. A single 4000-character
    // segment is opaque by any reasonable test and gets redacted to one
    // character, which exercises the redaction rather than the cap -- the
    // earlier version of this test did exactly that and stopped measuring what
    // it claimed to.
    $long = 'https://shop.test/'.implode('/', array_fill(0, 400, 'pages'));

    expect(mb_strlen((string) VisitorPageUrl::reduce($long)))->toBe(2048);
});

test('an address from another host is not stored at all', function (): void {
    // The widget endpoints are public and so is the site key, so this value is
    // attacker-controlled -- and stored addresses render as clickable
    // target="_blank" links on the agent ticket page. An unchecked host is a
    // phishing channel pointed at an agent.
    expect(VisitorPageUrl::forSite('https://attacker.example/login', 'shop.test'))->toBeNull();

    // Matched on a dot boundary, not as a suffix: this is the lookalike the
    // rule exists for.
    expect(VisitorPageUrl::forSite('https://evil-shop.test/login', 'shop.test'))->toBeNull();
});

test('a subdomain of the site is the same site', function (): void {
    expect(VisitorPageUrl::forSite('https://www.shop.test/pricing', 'shop.test'))
        ->toBe('https://www.shop.test/pricing')
        ->and(VisitorPageUrl::forSite('https://shop.test/pricing', 'shop.test'))
        ->toBe('https://shop.test/pricing');
});

test('a site with no configured domain stores no address', function (): void {
    // Null is not "anything goes" -- it means there is nothing to check
    // against, and guessing is the failure this rule exists to prevent.
    expect(VisitorPageUrl::forSite('https://shop.test/pricing', null))->toBeNull()
        ->and(VisitorPageUrl::forSite('https://shop.test/pricing', ''))->toBeNull();
});

test('a token in the PATH is redacted, because this product puts one there', function (): void {
    // `/reset-password/{token}` is a route in this repository. Dropping the
    // query and fragment and calling the path safe leaves the secret in the one
    // part that survived.
    expect(VisitorPageUrl::reduce('https://shop.test/reset-password/9f2c8a1b4e6d7c3f0a5b2e8d1c4f7a9b'))
        ->toBe('https://shop.test/reset-password/[redacted]')
        ->and(VisitorPageUrl::reduce('https://shop.test/u/550e8400-e29b-41d4-a716-446655440000'))
        ->toBe('https://shop.test/u/[redacted]');
});

test('a page name survives the redaction', function (): void {
    // The rule is crude and will occasionally take a harmless slug, but it must
    // not take the ordinary ones -- the field exists to say which page.
    expect(VisitorPageUrl::reduce('https://shop.test/docs/how-to-configure-your-widget'))
        ->toBe('https://shop.test/docs/how-to-configure-your-widget')
        ->and(VisitorPageUrl::reduce('https://shop.test/blog/2024-my-post'))
        ->toBe('https://shop.test/blog/2024-my-post')
        ->and(VisitorPageUrl::reduce('https://shop.test/pricing'))
        ->toBe('https://shop.test/pricing');
});

test('a site configured with a port still matches its own pages', function (): void {
    // localhost:8000 and staging.example.test:8443 are both supported install
    // shapes. parse_url() hands back the hostname alone, so comparing it against
    // an unstripped configured value rejected every legitimate page on exactly
    // the installs that need a port -- and stored null instead.
    expect(VisitorPageUrl::forSite('http://localhost:8000/pricing', 'localhost:8000'))
        ->toBe('http://localhost:8000/pricing')
        ->and(VisitorPageUrl::forSite('https://staging.example.test:8443/docs', 'staging.example.test:8443'))
        ->toBe('https://staging.example.test:8443/docs');

    // And a port does not weaken the host rule.
    expect(VisitorPageUrl::forSite('https://attacker.example/login', 'localhost:8000'))->toBeNull();
});

test('the same host on a different port is a different service', function (): void {
    // The hole this closes: the configured port was stripped before comparing,
    // so a site on :8443 vouched for anything else listening on that machine.
    // Ports are where an admin panel, a database console or a debug server
    // ends up, and the value lands in an agent-clickable href.
    expect(VisitorPageUrl::forSite('https://shop.test:9998/admin', 'shop.test:8443'))->toBeNull()
        ->and(VisitorPageUrl::forSite('http://localhost:9200/_cat/indices', 'localhost:8000'))->toBeNull();

    // Both directions: a site that named a port is not on the default one.
    expect(VisitorPageUrl::forSite('https://shop.test/pricing', 'shop.test:8443'))->toBeNull();

    // And a site that named no port is not on some other one.
    expect(VisitorPageUrl::forSite('https://shop.test:9998/admin', 'shop.test'))->toBeNull();
});

test('the two ways of writing one origin agree', function (): void {
    // An explicit default port is the same origin as an omitted one, so a
    // widget reporting either matches a site configured as either.
    expect(VisitorPageUrl::forSite('https://shop.test:443/pricing', 'shop.test'))
        ->toBe('https://shop.test:443/pricing')
        ->and(VisitorPageUrl::forSite('https://shop.test/pricing', 'shop.test:443'))
        ->toBe('https://shop.test/pricing')
        ->and(VisitorPageUrl::forSite('http://shop.test:80/pricing', 'shop.test'))
        ->toBe('http://shop.test:80/pricing');

    // The default depends on the scheme, so :80 is NOT the https origin.
    expect(VisitorPageUrl::forSite('https://shop.test:80/pricing', 'shop.test'))->toBeNull();
});

test('the port rule applies to subdomains as well as the apex', function (): void {
    expect(VisitorPageUrl::forSite('https://www.shop.test:8443/pricing', 'shop.test:8443'))
        ->toBe('https://www.shop.test:8443/pricing')
        ->and(VisitorPageUrl::forSite('https://www.shop.test:9998/pricing', 'shop.test:8443'))
        ->toBeNull();
});

test('a configured authority that cannot be parsed stores nothing', function (): void {
    // Fail closed: an operator value we cannot read is not a licence to skip
    // the check. Storing null loses context; storing an unchecked address
    // is the bug this class exists to prevent.
    expect(VisitorPageUrl::forSite('https://shop.test/pricing', 'shop.test:not-a-port'))->toBeNull()
        ->and(VisitorPageUrl::forSite('https://shop.test/pricing', ':8443'))->toBeNull();
});
