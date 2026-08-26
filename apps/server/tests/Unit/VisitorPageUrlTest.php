<?php

use App\Support\Visitors\VisitorPageUrl;

test('a reset token never survives being reported as a page', function (): void {
    // The reason this class exists. A visitor who opens the chat while on a
    // password-reset page reported the whole URL, it was stored forever, and it
    // was shown to an agent in the visitor context panel.
    $sanitised = VisitorPageUrl::sanitise(
        'https://shop.test/account/reset?reset_token=abc123&email=someone@example.test'
    );

    expect($sanitised)->toBe('https://shop.test/account/reset');
});

test('the query string goes whether or not its names look dangerous', function (): void {
    // Deliberately NOT a name-based rule. The dangerous parameters are often
    // the shortest -- `?t=`, `?k=`, `?c=` -- so a pattern over names fails
    // exactly where it matters, and fails silently.
    expect(VisitorPageUrl::sanitise('https://shop.test/go?t=eyJhbGciOi'))
        ->toBe('https://shop.test/go')
        ->and(VisitorPageUrl::sanitise('https://shop.test/search?q=hello'))
        ->toBe('https://shop.test/search');
});

test('a fragment goes too, because we cannot tell routing from a token', function (): void {
    // A fragment never reaches a server in an ordinary navigation, so a widget
    // reporting one is reporting something the page chose to put in front of
    // us. Single-page apps route with it; they also carry tokens in it.
    expect(VisitorPageUrl::sanitise('https://shop.test/pricing?plan=pro#access_token=xyz'))
        ->toBe('https://shop.test/pricing');
});

test('credentials in the authority are dropped by construction', function (): void {
    // Not matched and removed -- never copied across. The URL is rebuilt from
    // the parts this class names, so anything it does not name cannot survive.
    expect(VisitorPageUrl::sanitise('https://user:hunter2@shop.test/admin'))
        ->toBe('https://shop.test/admin');
});

test('what is left still answers which page', function (): void {
    // The whole point is context for an agent, so the path has to survive --
    // "/pricing" is a different conversation from "/". Ports too, because a
    // staging install on :8443 is a different host from the same name on 443.
    expect(VisitorPageUrl::sanitise('https://shop.test/docs/install/forge'))
        ->toBe('https://shop.test/docs/install/forge')
        ->and(VisitorPageUrl::sanitise('https://shop.test:8443/pricing'))
        ->toBe('https://shop.test:8443/pricing');
});

test('an unparseable URL is dropped rather than kept', function (): void {
    // "Leave it alone if you cannot read it" keeps precisely the inputs least
    // likely to be an ordinary page address.
    expect(VisitorPageUrl::sanitise('not a url'))->toBeNull()
        ->and(VisitorPageUrl::sanitise('javascript:alert(1)'))->toBeNull()
        ->and(VisitorPageUrl::sanitise('   '))->toBeNull()
        ->and(VisitorPageUrl::sanitise(null))->toBeNull();
});

test('a site can name parameters back, and gets only those', function (): void {
    // An operator whose URLs carry a plan or a campaign gets that context back
    // by naming it -- a decision somebody made, rather than a pattern that
    // happened not to match. Everything unnamed still goes.
    $kept = VisitorPageUrl::sanitise(
        'https://shop.test/pricing?plan=pro&utm_source=email&session=abc',
        ['plan', 'utm_source'],
    );

    expect($kept)->toContain('plan=pro')
        ->and($kept)->toContain('utm_source=email');

    expect($kept)->not->toContain('session');
    expect($kept)->not->toContain('abc');
});

test('naming a parameter is case-insensitive but does not widen anything else', function (): void {
    expect(VisitorPageUrl::sanitise('https://shop.test/p?Plan=pro&token=x', ['plan']))
        ->toBe('https://shop.test/p?Plan=pro');
});

test('an array parameter is not kept even when named', function (): void {
    // `?plan[]=a&plan[]=b` parses to an array. http_build_query would happily
    // re-encode it, but the value shape is no longer the scalar the allowlist
    // was reasoning about, so it is skipped rather than guessed at.
    expect(VisitorPageUrl::sanitise('https://shop.test/p?plan[]=a&plan[]=b', ['plan']))
        ->toBe('https://shop.test/p');
});

test('the result is capped to what the column holds', function (): void {
    $long = 'https://shop.test/'.str_repeat('a', 4000);

    expect(mb_strlen((string) VisitorPageUrl::sanitise($long)))->toBe(2048);
});
