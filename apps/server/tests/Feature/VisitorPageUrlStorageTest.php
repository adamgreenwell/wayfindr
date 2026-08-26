<?php

use App\Models\Account;
use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The unit test proves the function. This proves the WIRING -- that the two
 * places a visitor's page address enters the product actually run it through.
 *
 * Worth separating, because the sanitiser existed as a class for some minutes
 * before anything called it, and a green unit test says nothing about that.
 */
function visitorPageUrlSite(): Site
{
    $account = Account::factory()->create();

    return Site::factory()->for($account)->create(['public_key' => 'site_public_pages']);
}

test('bootstrap stores the page without its query string', function (): void {
    $site = visitorPageUrlSite();

    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-pages',
        'page_url' => 'https://shop.test/account/reset?reset_token=abc123&email=a@b.test',
    ])->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-pages')->firstOrFail();

    expect($visitor->metadata['last_page_url'])->toBe('https://shop.test/account/reset');

    $this->assertStringNotContainsString('reset_token', json_encode($visitor->metadata) ?: '');
    $this->assertStringNotContainsString('a@b.test', json_encode($visitor->metadata) ?: '');
});

test('starting a conversation stores it sanitised too', function (): void {
    // The second writer. Fixing only bootstrap would leave the more likely
    // path open: somebody asks for help FROM the page that is going wrong.
    $site = visitorPageUrlSite();

    $token = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-convo',
    ])->assertSuccessful()->json('data.visitor.token');

    $this->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-convo',
        'visitor_token' => $token,
        'body' => 'This reset link is not working',
        'page_url' => 'https://shop.test/account/reset?reset_token=xyz789',
    ])->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-convo')->firstOrFail();

    expect($visitor->metadata['last_page_url'])->toBe('https://shop.test/account/reset');

    $this->assertStringNotContainsString('xyz789', json_encode($visitor->metadata) ?: '');
});

test('a page with nothing to remove is stored unchanged', function (): void {
    // The sanitiser must not cost an agent the context the field exists for.
    $site = visitorPageUrlSite();

    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-plain',
        'page_url' => 'https://shop.test/docs/install/forge',
    ])->assertSuccessful();

    expect(Visitor::query()->where('anonymous_id', 'anon-plain')->firstOrFail()->metadata['last_page_url'])
        ->toBe('https://shop.test/docs/install/forge');
});

test('page addresses already stored whole are rewritten', function (): void {
    // The forward fix does nothing for rows that already exist, and those are
    // the reason this matters: a token stored last month is on an agent's
    // screen the next time somebody opens that visitor.
    $site = visitorPageUrlSite();

    // Written past the model, the way the old code would have left it.
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-historical']);

    DB::table('visitors')->where('id', $visitor->id)->update([
        'metadata' => json_encode([
            'last_page_url' => 'https://shop.test/account/reset?reset_token=old-token&email=a@b.test',
            'context' => ['plan' => 'pro'],
        ]),
    ]);

    // The migration under test, re-run against the row just planted.
    (require base_path('database/migrations/2026_08_26_000000_sanitise_stored_visitor_page_urls.php'))->up();

    $metadata = json_decode((string) DB::table('visitors')->where('id', $visitor->id)->value('metadata'), true);

    expect($metadata['last_page_url'])->toBe('https://shop.test/account/reset');

    // And it did not eat what it does not own.
    expect($metadata['context'])->toBe(['plan' => 'pro']);

    $this->assertStringNotContainsString('old-token', json_encode($metadata) ?: '');
});

test('the rewrite leaves a row it has nothing to do with alone', function (): void {
    $site = visitorPageUrlSite();
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-clean']);

    DB::table('visitors')->where('id', $visitor->id)->update([
        'metadata' => json_encode(['last_page_url' => 'https://shop.test/pricing']),
    ]);

    $before = DB::table('visitors')->where('id', $visitor->id)->first();

    (require base_path('database/migrations/2026_08_26_000000_sanitise_stored_visitor_page_urls.php'))->up();

    $after = DB::table('visitors')->where('id', $visitor->id)->first();

    expect(json_decode((string) $after->metadata, true)['last_page_url'])
        ->toBe('https://shop.test/pricing')
        ->and($after->updated_at)->toBe($before->updated_at, 'an untouched row was still written');
});
