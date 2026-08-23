<?php

use App\Models\Account;
use App\Models\Article;
use App\Support\Knowledge\ArticleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The widget has never rendered server-authored markup: message bodies are
 * assigned with textContent, and the intake form is built element by element so
 * a configured label cannot inject any. Article bodies are the first thing an
 * operator writes that a visitor's browser lays out, on a page belonging to
 * somebody else's customer, so the same posture has to hold -- and it holds by
 * this class never producing HTML at all.
 */
function blocksOf(string $markdown): array
{
    return ArticleDocument::blocks($markdown);
}

test('a heading, a paragraph and a list become blocks, not markup', function (): void {
    $blocks = blocksOf("## Refunds\n\nWe refund within 14 days.\n\n- Keep the receipt\n- Bring photo ID");

    expect(array_column($blocks, 'type'))->toBe(['heading', 'paragraph', 'list'])
        ->and($blocks[0]['text'])->toBe('Refunds')
        ->and($blocks[2]['items'])->toHaveCount(2);

    // Nothing anywhere in the output is a string of markup.
    expect(json_encode($blocks))->not->toContain('<');
});

test('a link keeps its words and loses its destination when the scheme is not one we serve', function (): void {
    // The allowlist is the entire security boundary of this class.
    $hostile = [
        'javascript:alert(1)',
        'JaVaScRiPt:alert(1)',
        'data:text/html;base64,PHN2Zz4=',
        'vbscript:msgbox(1)',
        'file:///etc/passwd',
    ];

    foreach ($hostile as $url) {
        $spans = blocksOf("Try [this]({$url}) now")[0]['spans'];
        $link = collect($spans)->firstWhere('text', 'this');

        // Asserted on the keys directly. `toHaveKey('href', $message)` reads
        // its second argument as the expected VALUE, not as a failure message,
        // so `not->toHaveKey('href', 'some prose')` is true however dangerous
        // the href actually is -- an assertion that cannot fail.
        expect($link)->not->toBeNull("`{$url}` should still render its words")
            ->and(array_keys($link))->toBe(['text'], "`{$url}` must not survive as a destination")
            ->and($link['text'])->toBe('this');
    }
});

test('padding around a destination is removed rather than making it unreadable', function (): void {
    // What the stripping is FOR. `parse_url` reads no scheme at all from a URL
    // with leading spaces, so without it a perfectly ordinary link written with
    // a stray space silently stops being a link.
    $spans = blocksOf('See [here](  https://example.test/policy  ).')[0]['spans'];

    expect(collect($spans)->firstWhere('text', 'here')['href'] ?? null)->toBe('https://example.test/policy');
});

test('padding does not smuggle a scheme past the check', function (): void {
    // The other half: the same stripping that rescues a spaced-out https link
    // must not let a spaced-out javascript one through behind it.
    $spans = blocksOf('Try [this](  javascript:alert(1  ) now')[0]['spans'];

    expect(array_keys(collect($spans)->firstWhere('text', 'this')))->toBe(['text']);
});

test('the destinations a support article actually needs are kept', function (): void {
    foreach (['https://example.test/policy', 'http://example.test', 'mailto:help@example.test', 'HTTPS://example.test/x'] as $url) {
        $spans = blocksOf("See [here]({$url}).")[0]['spans'];

        expect(collect($spans)->firstWhere('text', 'here')['href'] ?? null)->toBe($url);
    }
});

test('emphasis keeps the spaces around it', function (): void {
    // Trimming each run welded its neighbours together: "within **14 days**"
    // read as "within14 days", in the rendered text and in what search matches.
    expect(ArticleDocument::text('We refund within **14 days** of delivery.'))
        ->toBe('We refund within 14 days of delivery.');
});

test('the searchable text is what a reader sees, not what the author typed', function (): void {
    $text = ArticleDocument::text("## Refunds\n\nEmail [support](mailto:help@example.test) or read the **policy**.");

    expect($text)->toBe("Refunds\nEmail support or read the policy.")
        ->and($text)->not->toContain('mailto:')
        ->and($text)->not->toContain('**')
        ->and($text)->not->toContain('##');
});

test('an unpublished article is not published, and a scheduled one is not yet', function (): void {
    $account = Account::factory()->create();

    $draft = Article::factory()->for($account)->create();
    $live = Article::factory()->for($account)->published()->create();
    $scheduled = Article::factory()->for($account)->create(['published_at' => now()->addDay()]);

    expect($draft->isPublished())->toBeFalse()
        ->and($live->isPublished())->toBeTrue()
        ->and($scheduled->isPublished())->toBeFalse('a future date is scheduled, not live');

    // The query and the check must agree, or the widget serves something the
    // dashboard calls a draft.
    expect(Article::query()->published()->pluck('id')->all())->toBe([$live->id]);
});

test('two accounts can both have an article called refunds', function (): void {
    $one = Account::factory()->create();
    $two = Account::factory()->create();

    Article::factory()->for($one)->create(['slug' => 'refunds']);
    Article::factory()->for($two)->create(['slug' => 'refunds']);

    expect(Article::query()->where('slug', 'refunds')->count())->toBe(2);
});
