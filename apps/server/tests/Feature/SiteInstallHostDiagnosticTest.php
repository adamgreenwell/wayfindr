<?php

// Widget install host diagnostic: confirm the widget checks in from the host
// the site is configured for, so a stale public key pasted onto another host is
// caught instead of silently showing "Live".
//
// See issue #498 and docs/product/mvp-dogfood-readiness.md.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\SiteInstallHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function visitorWithLastPage(?string $url): Visitor
{
    $visitor = new Visitor;
    $visitor->metadata = $url === null ? [] : ['last_page_url' => $url];

    return $visitor;
}

test('host diagnostic verifies a matching host', function (): void {
    $diagnostic = SiteInstallHealth::hostDiagnostic(visitorWithLastPage('https://wayfindr.cc/pricing'), 'wayfindr.cc');

    expect($diagnostic['status'])->toBe('match')
        ->and($diagnostic['tone'])->toBe('ready')
        ->and($diagnostic['checked_in_host'])->toBe('wayfindr.cc')
        ->and($diagnostic['needs_attention'])->toBeFalse();
});

test('host diagnostic treats subdomains of the configured domain as a match', function (): void {
    $diagnostic = SiteInstallHealth::hostDiagnostic(visitorWithLastPage('https://app.wayfindr.cc/portal'), 'wayfindr.cc');

    expect($diagnostic['status'])->toBe('match');
});

test('host diagnostic flags a mismatched host as needing attention', function (): void {
    $diagnostic = SiteInstallHealth::hostDiagnostic(visitorWithLastPage('https://stale.example.test/'), 'wayfindr.cc');

    expect($diagnostic['status'])->toBe('mismatch')
        ->and($diagnostic['tone'])->toBe('attention')
        ->and($diagnostic['needs_attention'])->toBeTrue()
        ->and($diagnostic['detail'])->toContain('stale.example.test')
        ->and($diagnostic['detail'])->toContain('wayfindr.cc');
});

test('host diagnostic does not false-positive on a lookalike suffix', function (): void {
    // notwayfindr.cc must not be treated as a subdomain of wayfindr.cc.
    $diagnostic = SiteInstallHealth::hostDiagnostic(visitorWithLastPage('https://notwayfindr.cc/'), 'wayfindr.cc');

    expect($diagnostic['status'])->toBe('mismatch');
});

test('host diagnostic is unknown before any check-in', function (): void {
    expect(SiteInstallHealth::hostDiagnostic(visitorWithLastPage(null), 'wayfindr.cc')['status'])->toBe('unknown');
    expect(SiteInstallHealth::hostDiagnostic(null, 'wayfindr.cc')['status'])->toBe('unknown');
});

test('host diagnostic is unverified when the site has no configured domain', function (): void {
    $diagnostic = SiteInstallHealth::hostDiagnostic(visitorWithLastPage('https://wayfindr.cc/'), null);

    expect($diagnostic['status'])->toBe('unverified')
        ->and($diagnostic['checked_in_host'])->toBe('wayfindr.cc')
        ->and($diagnostic['needs_attention'])->toBeFalse();
});

test('site detail page surfaces a host mismatch to the operator', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Wayfindr Public Site',
        'domain' => 'wayfindr.cc',
    ]);

    Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-mismatch',
        'last_seen_at' => now()->subMinutes(2),
        'metadata' => ['last_page_url' => 'https://stale.example.test/page'],
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Host mismatch')
        ->assertSee('stale.example.test');
});
