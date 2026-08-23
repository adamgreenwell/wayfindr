<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function articleWorld(): array
{
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    return compact('account', 'admin');
}

test('an article is created as a draft, never straight to visitors', function (): void {
    $w = articleWorld();

    $this->actingAs($w['admin'])
        ->post(route('dashboard.account.articles.store'), [
            'title' => 'How refunds work',
            'body' => "## Refunds\n\nWe refund within 14 days.",
        ])
        ->assertRedirect();

    $article = Article::query()->firstOrFail();

    expect($article->isPublished())->toBeFalse()
        ->and($article->slug)->toBe('how-refunds-work')
        ->and(Article::query()->published()->count())->toBe(0);
});

test('publishing is its own act, and reversible', function (): void {
    $w = articleWorld();
    $article = Article::factory()->for($w['account'])->create();

    $this->actingAs($w['admin'])->post(route('dashboard.account.articles.publish', $article))->assertRedirect();
    expect($article->fresh()->isPublished())->toBeTrue();

    $this->actingAs($w['admin'])->post(route('dashboard.account.articles.publish', $article))->assertRedirect();
    expect($article->fresh()->isPublished())->toBeFalse();
});

test('retitling a published article does not change what a link points at', function (): void {
    // An agent may already have sent the slug to a visitor. Renaming the title
    // is copy-editing; it must not quietly break that.
    $w = articleWorld();
    $article = Article::factory()->for($w['account'])->published()->create(['slug' => 'how-refunds-work']);

    $this->actingAs($w['admin'])
        ->put(route('dashboard.account.articles.update', $article), [
            'title' => 'Refunds and returns',
            'body' => 'Still the same answer.',
        ])
        ->assertRedirect();

    expect($article->fresh()->title)->toBe('Refunds and returns')
        ->and($article->fresh()->slug)->toBe('how-refunds-work');
});

test('two articles with the same title get distinct references', function (): void {
    $w = articleWorld();

    foreach ([1, 2, 3] as $ignored) {
        $this->actingAs($w['admin'])->post(route('dashboard.account.articles.store'), [
            'title' => 'Refunds',
            'body' => 'An answer.',
        ])->assertRedirect();
    }

    expect(Article::query()->orderBy('id')->pluck('slug')->all())->toBe(['refunds', 'refunds-2', 'refunds-3']);
});

test('a body that renders to nothing is refused', function (): void {
    // Syntax alone produces no blocks, so a visitor would open the article and
    // find an empty panel. Judged on what the reader gets, not what was typed.
    $w = articleWorld();

    // Control characters survive trimming and `required`, become a paragraph,
    // and are then stripped out of every span -- leaving a block that renders
    // as nothing. "##" on its own is NOT this case: it is not a heading
    // (no text follows) so it renders as the literal text "##", which is
    // ugly but readable, and refusing it would be refusing what was typed.
    $this->actingAs($w['admin'])
        ->post(route('dashboard.account.articles.store'), ['title' => 'Empty', 'body' => "\x01\x02"])
        ->assertSessionHasErrors('body');

    expect(Article::query()->count())->toBe(0);
});

test('another account\'s article is not found, rather than forbidden', function (): void {
    // 404 rather than 403: a 403 confirms the article exists.
    $w = articleWorld();
    $other = Article::factory()->for(Account::factory())->create();

    $this->actingAs($w['admin'])->get(route('dashboard.account.articles.show', $other))->assertNotFound();
    $this->actingAs($w['admin'])->post(route('dashboard.account.articles.publish', $other))->assertNotFound();
    $this->actingAs($w['admin'])->delete(route('dashboard.account.articles.destroy', $other))->assertNotFound();

    expect($other->fresh()->isPublished())->toBeFalse();
});

test('a plain agent cannot write what the whole desk says', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $article = Article::factory()->for($account)->create();

    $this->actingAs($agent)->get(route('dashboard.account.articles.index'))->assertForbidden();
    $this->actingAs($agent)->post(route('dashboard.account.articles.store'), [
        'title' => 'Mine', 'body' => 'Text.',
    ])->assertForbidden();
    $this->actingAs($agent)->post(route('dashboard.account.articles.publish', $article))->assertNotFound();

    expect(Article::query()->count())->toBe(1);
});

test('the list puts drafts first whatever the database thinks of nulls', function (): void {
    // Where NULLs sort is a driver difference, and the suite runs SQLite while
    // installs run PostgreSQL. The order is spelled out rather than inherited.
    $w = articleWorld();
    Article::factory()->for($w['account'])->published()->create(['title' => 'Published one']);
    Article::factory()->for($w['account'])->create(['title' => 'Draft one']);

    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.articles.index'))
        ->assertOk()
        ->assertSeeInOrder(['Draft one', 'Published one']);
});

test('the preview builds elements rather than printing markup', function (): void {
    $w = articleWorld();
    $article = Article::factory()->for($w['account'])->create([
        'body' => "## Refunds\n\nEmail [support](mailto:help@example.test) or see [bad](javascript:alert(1)).",
    ]);

    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.articles.show', $article))
        ->assertOk()
        ->assertSee('<a href="mailto:help@example.test"', false)
        // Not `assertDontSee('javascript:alert')`: the edit textarea shows the
        // author their own source, so that string is legitimately on the page.
        // What must not exist is a LINK to it.
        ->assertDontSee('href="javascript:', false)
        ->assertSee('bad');
});
