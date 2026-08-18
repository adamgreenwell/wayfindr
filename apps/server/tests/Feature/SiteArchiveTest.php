<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Every widget-facing entry point that resolves a site by its public key.
 *
 * Each is exercised twice against the same request body - once while the site
 * is servable and once after archiving - because an archived-site 404 proves
 * nothing on its own: a malformed payload would 404 or 422 either way. The
 * servable pass must return neither, which is what shows the request actually
 * reached the site lookup.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function widgetEntryPoints(Site $site, Conversation $conversation): array
{
    $key = $site->public_key;
    $anon = 'anon-archive-probe';
    $code = $conversation->support_code;
    $base = ['site_public_key' => $key, 'anonymous_id' => $anon];

    return [
        'bootstrap' => ['postJson', '/api/widget/bootstrap', $base + [
            'page_url' => 'https://docs.example.test/install',
        ]],
        'broadcast auth' => ['postJson', '/api/widget/broadcasting/auth', $base + [
            'socket_id' => '123.456',
            'channel_name' => 'private-conversations.'.$code,
        ]],
        'create conversation' => ['postJson', '/api/conversations', $base + [
            'subject' => 'Archived probe',
        ]],
        'cobrowse status' => ['getJson', "/api/conversations/{$code}/cobrowse?".http_build_query($base), []],
        'cobrowse consent' => ['postJson', "/api/conversations/{$code}/cobrowse-consent", $base + [
            'granted' => true,
        ]],
        'cobrowse telemetry' => ['postJson', "/api/conversations/{$code}/cobrowse-telemetry", $base + [
            'rtt_ms' => 40,
        ]],
        'cobrowse page state' => ['postJson', "/api/conversations/{$code}/cobrowse-page-state", $base + [
            'page_url' => 'https://docs.example.test/install',
            'viewport_width' => 1280,
            'viewport_height' => 800,
            'scroll_x' => 0,
            'scroll_y' => 0,
        ]],
        'cobrowse snapshot' => ['postJson', "/api/conversations/{$code}/cobrowse-snapshot", $base + [
            'page_url' => 'https://docs.example.test/install',
            'html' => '<p>hello</p>',
            'text' => 'hello',
            'node_count' => 1,
            'masked_count' => 0,
        ]],
        'cobrowse mutations' => ['postJson', "/api/conversations/{$code}/cobrowse-mutations", $base + [
            'page_url' => 'https://docs.example.test/install',
            'sequence' => 1,
            'mutations' => [['type' => 'text', 'path' => '0/1', 'text' => 'hi']],
        ]],
        'list messages' => ['getJson', "/api/conversations/{$code}/messages?".http_build_query($base), []],
        'send message' => ['postJson', "/api/conversations/{$code}/messages", $base + [
            'body' => 'Anyone there?',
        ]],
        'typing' => ['postJson', "/api/conversations/{$code}/typing", $base + [
            'is_typing' => true,
        ]],
    ];
}

test('archiving a site stops every widget entry point serving it', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_archive_probe']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-archive-probe']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['status' => 'open']);

    $entryPoints = widgetEntryPoints($site, $conversation);

    // Guard against a vacuous test: if a route is added and this list is not
    // updated, the count assertion is the thing that notices.
    expect($entryPoints)->toHaveCount(12);

    $servable = [];
    foreach ($entryPoints as $label => [$method, $url, $payload]) {
        $status = $this->{$method}($url, $payload)->getStatusCode();
        $servable[$label] = $status;
    }

    // 404 and 422 mean the probe never got as far as the site; 403 means it was
    // turned away for an unrelated reason. Any of the three would make the
    // archived-site assertion below pass without testing anything.
    foreach ($servable as $label => $status) {
        expect($status)->not->toBeIn([403, 404, 422],
            "{$label} returned {$status} while the site was servable, so this probe never reached the site lookup");
    }

    $site->forceFill(['archived_at' => now()])->save();

    foreach ($entryPoints as $label => [$method, $url, $payload]) {
        expect($this->{$method}($url, $payload)->getStatusCode())
            ->toBe(404, "{$label} still served an archived site");
    }
});

test('unarchiving a site puts every entry point back into service', function (): void {
    $site = Site::factory()->create([
        'public_key' => 'site_public_restore_probe',
        'archived_at' => now(),
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-archive-probe']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['status' => 'open']);

    $entryPoints = widgetEntryPoints($site, $conversation);

    foreach ($entryPoints as $label => [$method, $url, $payload]) {
        expect($this->{$method}($url, $payload)->getStatusCode())
            ->toBe(404, "{$label} served an archived site");
    }

    $site->forceFill(['archived_at' => null])->save();

    foreach ($entryPoints as $label => [$method, $url, $payload]) {
        $status = $this->{$method}($url, $payload)->getStatusCode();
        expect($status)->not->toBe(404, "{$label} stayed unavailable after the site was restored");
    }
});

test('archiving keeps every record and is reversible', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['name' => 'Retiring Site']);
    $visitor = Visitor::factory()->for($site)->create();
    Conversation::factory()->for($site)->for($visitor)->create(['status' => 'open']);

    $this->actingAs($admin)
        ->post("/dashboard/sites/{$site->id}/archive")
        ->assertRedirect(route('dashboard.sites.show', $site));

    expect($site->fresh()->isArchived())->toBeTrue();

    // Nothing may be lost: archiving is a state change, not a deletion.
    $this->assertDatabaseCount('conversations', 1);
    $this->assertDatabaseCount('visitors', 1);
    $this->assertDatabaseHas('audit_events', [
        'site_id' => $site->id,
        'action' => 'site.archived',
    ]);

    $this->actingAs($admin)
        ->post("/dashboard/sites/{$site->id}/unarchive")
        ->assertRedirect(route('dashboard.sites.show', $site));

    expect($site->fresh()->isArchived())->toBeFalse();
});

test('archived sites leave the default site list but can be listed on request', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    Site::factory()->for($account)->create(['name' => 'Live Site']);
    Site::factory()->for($account)->create(['name' => 'Retired Site', 'archived_at' => now()]);

    $this->actingAs($admin)
        ->get('/dashboard/sites')
        ->assertOk()
        ->assertSee('Live Site')
        ->assertDontSee('Retired Site');

    $this->actingAs($admin)
        ->get('/dashboard/sites?site_state=archived')
        ->assertOk()
        ->assertSee('Retired Site')
        ->assertDontSee('Live Site');
});

test('an agent cannot archive a site', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create();

    $this->actingAs($agent)
        ->post("/dashboard/sites/{$site->id}/archive")
        ->assertForbidden();

    expect($site->fresh()->isArchived())->toBeFalse();
});

test('archived sites do not count toward the operations snapshot', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    Site::factory()->for($account)->create(['name' => 'Live Site']);
    Site::factory()->for($account)->count(2)->create(['archived_at' => now()]);

    // A retired site has no install to fix and no work to chase, so the
    // at-a-glance header must not keep counting it.
    $this->actingAs($admin)
        ->get('/dashboard/sites')
        ->assertOk()
        ->assertSee('1 visible site')
        ->assertDontSee('3 visible sites');

    // And browsing the archived view does not change the operations header.
    $this->actingAs($admin)
        ->get('/dashboard/sites?site_state=archived')
        ->assertOk()
        ->assertSee('1 visible site');
});
