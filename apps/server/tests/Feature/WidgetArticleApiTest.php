<?php

use App\Models\Account;
use App\Models\Article;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function articleSite(string $key = 'site_public_kb'): Site
{
    return Site::factory()->for(Account::factory())->create(['public_key' => $key]);
}

test('a visitor is offered only what has been published', function (): void {
    $site = articleSite();
    Article::factory()->for($site->account)->published()->create(['title' => 'How refunds work']);
    Article::factory()->for($site->account)->create(['title' => 'Draft nobody finished']);
    Article::factory()->for($site->account)->create([
        'title' => 'Scheduled for tomorrow',
        'published_at' => now()->addDay(),
    ]);

    $titles = $this->getJson(route('widget.articles.index', ['site_public_key' => 'site_public_kb']))
        ->assertOk()
        ->json('data.articles.*.title');

    expect($titles)->toBe(['How refunds work']);
});

test('one account never answers another account\'s visitor', function (): void {
    $site = articleSite();
    $stranger = Account::factory()->create();
    Article::factory()->for($stranger)->published()->create(['title' => 'Somebody else\'s answer']);
    Article::factory()->for($site->account)->published()->create(['title' => 'Our answer']);

    expect($this->getJson(route('widget.articles.index', ['site_public_key' => 'site_public_kb']))
        ->assertOk()
        ->json('data.articles.*.title'))->toBe(['Our answer']);
});

test('search matches the title and the words inside the answer', function (): void {
    $site = articleSite();
    Article::factory()->for($site->account)->published()->create(['title' => 'Refunds', 'body' => 'Within 14 days.']);
    Article::factory()->for($site->account)->published()->create(['title' => 'Shipping', 'body' => 'Sent by courier.']);

    $find = fn (string $q) => $this->getJson(route('widget.articles.index', [
        'site_public_key' => 'site_public_kb', 'q' => $q,
    ]))->assertOk()->json('data.articles.*.title');

    expect($find('refund'))->toBe(['Refunds'])
        ->and($find('courier'))->toBe(['Shipping'])
        ->and($find('nothing here'))->toBe([]);
});

test('a wildcard typed into search is matched as the character it looks like', function (): void {
    // LIKE reads % as "anything". Unescaped, a search for "50%" quietly returns
    // every article, which reads as a broken search rather than a dangerous one
    // -- which is why it survives review.
    $site = articleSite();
    Article::factory()->for($site->account)->published()->create(['title' => 'Fifty', 'body' => 'A 50% restocking fee.']);
    Article::factory()->for($site->account)->published()->create(['title' => 'Other', 'body' => 'Nothing relevant.']);

    expect($this->getJson(route('widget.articles.index', ['site_public_key' => 'site_public_kb', 'q' => '50%']))
        ->assertOk()->json('data.articles.*.title'))->toBe(['Fifty']);

    // Searching for a bare "%" finds the article that literally contains one,
    // and ONLY that one. Unescaped it would match both, which is the whole
    // failure: a search that silently returns everything.
    expect($this->getJson(route('widget.articles.index', ['site_public_key' => 'site_public_kb', 'q' => '%']))
        ->assertOk()->json('data.articles.*.title'))->toBe(['Fifty']);
});

test('an article is served as blocks, never as markup', function (): void {
    $site = articleSite();
    $article = Article::factory()->for($site->account)->published()->create([
        'slug' => 'refunds',
        'body' => "## Refunds\n\nEmail [us](mailto:help@example.test) or see [bad](javascript:alert(1)).",
    ]);

    $blocks = $this->getJson(route('widget.articles.show', ['slug' => 'refunds', 'site_public_key' => 'site_public_kb']))
        ->assertOk()
        ->json('data.article.blocks');

    expect(array_column($blocks, 'type'))->toBe(['heading', 'paragraph'])
        ->and(json_encode($blocks))->not->toContain('<')
        ->and(json_encode($blocks))->not->toContain('javascript:');

    // The rejected link keeps its words.
    expect(collect($blocks[1]['spans'])->firstWhere('text', 'bad'))->toBe(['text' => 'bad']);
    expect($article->fresh()->slug)->toBe('refunds');
});

test('a draft cannot be read by guessing its address', function (): void {
    $site = articleSite();
    Article::factory()->for($site->account)->create(['slug' => 'secret-draft']);

    $this->getJson(route('widget.articles.show', ['slug' => 'secret-draft', 'site_public_key' => 'site_public_kb']))
        ->assertNotFound();
});

test('an archived site stops answering, exactly as it stops serving everything else', function (): void {
    $site = articleSite();
    Article::factory()->for($site->account)->published()->create();
    $site->forceFill(['archived_at' => now()])->save();

    $this->getJson(route('widget.articles.index', ['site_public_key' => 'site_public_kb']))->assertNotFound();
});
