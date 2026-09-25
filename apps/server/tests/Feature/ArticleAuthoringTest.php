<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Article;
use App\Models\CustomRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function articleWorld(): array
{
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    return compact('account', 'admin');
}

function articleAuthoringXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);

    return new DOMXPath($document);
}

/** An XPath predicate that matches one whole class name, not a substring of another. */
function articleAuthoringHasClass(string $class): string
{
    return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
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

test('article mutations reauthorize a stale custom role under the account lock', function (string $action): void {
    $account = Account::factory()->create();
    $knowledgeRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageKnowledge->value],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $knowledgeRole->id,
    ]);
    $article = Article::factory()->for($account)->create([
        'title' => 'Original title',
        'body' => 'Original body.',
    ]);

    $this->actingAs($manager);
    expect($manager->hasAccountPermission(AccountPermission::ManageKnowledge))->toBeTrue();
    User::query()->whereKey($manager->id)->update(['custom_role_id' => $revokedRole->id]);

    $response = match ($action) {
        'create' => $this->post(route('dashboard.account.articles.store'), [
            'title' => 'Late article',
            'body' => 'This write must not land.',
        ]),
        'update' => $this->put(route('dashboard.account.articles.update', $article), [
            'title' => 'Late title',
            'body' => 'This update must not land.',
        ]),
        'publish' => $this->post(route('dashboard.account.articles.publish', $article)),
        'delete' => $this->delete(route('dashboard.account.articles.destroy', $article)),
    };

    $action === 'create'
        ? $response->assertForbidden()
        : $response->assertNotFound();

    expect(Article::query()->count())->toBe(1)
        ->and($article->fresh()->title)->toBe('Original title')
        ->and($article->fresh()->body)->toBe('Original body.')
        ->and($article->fresh()->isPublished())->toBeFalse();
})->with(['create', 'update', 'publish', 'delete']);

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

test('an admin can reach articles from the account page', function (): void {
    // The feature was complete and unreachable: every link to the authoring
    // pages was on the authoring pages. A visitor-facing feature nobody can
    // find the door to is not shipped.
    $w = articleWorld();

    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.show'))
        ->assertOk()
        ->assertSee(route('dashboard.account.articles.index'), false);
});

test('an agent who cannot manage the account is not shown the door', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);

    $this->actingAs($agent)
        ->get(route('dashboard.account.show'))
        ->assertOk()
        ->assertDontSee(route('dashboard.account.articles.index'), false);
});

test('the article count is a count, not a state pill', function (): void {
    // `readiness-status` is the product's state pill: `ready` spends the accent,
    // `manual` is the amber hold. Around a count it painted every desk with
    // articles teal and every desk without them amber. The sibling lists
    // render the same count as a plain lede.
    $w = articleWorld();
    Article::factory()->for($w['account'])->published()->create(['title' => 'Published one']);
    Article::factory()->for($w['account'])->create(['title' => 'Draft one']);

    $page = articleAuthoringXPath((string) $this->actingAs($w['admin'])
        ->get(route('dashboard.account.articles.index'))->assertOk()->getContent());

    $header = '//section[@aria-labelledby="article-list-heading"]/div['.articleAuthoringHasClass('section-header').']';

    expect($page->query($header)->length)->toBe(1, 'the list header did not render; this guard is checking nothing');

    expect($page->query($header.'//*['.articleAuthoringHasClass('readiness-status').']')->length)
        ->toBe(0, 'the article count is painted as a state pill');

    $count = $page->query($header.'/span['.articleAuthoringHasClass('lede').']')->item(0);

    expect($count)->not->toBeNull('the article count is not the plain lede the sibling lists use')
        ->and(trim((string) $count?->textContent))->toBe('2 articles');

    // The pills that ARE states stay: published or draft, once per row.
    expect($page->query('//tbody//*['.articleAuthoringHasClass('readiness-status').']')->length)
        ->toBe(2, 'the per-row published/draft pills went with the count');
});

test('a brand-new account gets the empty state, not a search box over nothing', function (): void {
    $w = articleWorld();

    $page = articleAuthoringXPath((string) $this->actingAs($w['admin'])
        ->get(route('dashboard.account.articles.index'))->assertOk()->getContent());

    $list = '//section[@aria-labelledby="article-list-heading"]';
    $emptyState = $page->query($list.'//div['.articleAuthoringHasClass('empty-state').']')->item(0);

    expect($emptyState)->not->toBeNull('an account with no articles gets a bare sentence rather than the empty state every sibling list uses');

    expect(trim((string) $page->query('./strong', $emptyState)->item(0)?->textContent))
        ->toBe(__('articles.empty.heading'), 'the empty state has no heading');

    $action = $page->query('./div['.articleAuthoringHasClass('empty-state-actions').']/a', $emptyState)->item(0);

    expect($action)->not->toBeNull('the empty state offers no way to write the first article')
        ->and($action?->getAttribute('href'))->toBe('#new-article-heading')
        ->and(trim((string) $action?->textContent))->toBe(__('articles.empty.action'));

    expect($page->query('//*[@id="new-article-heading"]')->length)
        ->toBe(1, 'the empty-state action points at an anchor that does not exist');

    expect($page->query('//input[@id="article_search"]')->length)
        ->toBe(0, 'a search box is offered over an account with nothing to search');

    expect($page->query($list.'/div['.articleAuthoringHasClass('section-header').']//*['.articleAuthoringHasClass('readiness-status').']')->length)
        ->toBe(0, 'having no articles yet is painted as an amber warning');
});

test('a search that matches nothing keeps the search box and says what was searched', function (): void {
    $w = articleWorld();
    Article::factory()->for($w['account'])->create(['title' => 'Refunds']);

    $page = articleAuthoringXPath((string) $this->actingAs($w['admin'])
        ->get(route('dashboard.account.articles.index', ['article_search' => 'zzz']))->assertOk()->getContent());

    $list = '//section[@aria-labelledby="article-list-heading"]';

    expect($page->query('//input[@id="article_search"]')->length)
        ->toBe(1, 'the search box went with the results, so a search that matched nothing cannot be changed');

    expect($page->query($list.'//*['.articleAuthoringHasClass('empty-state').']')->length)
        ->toBe(0, 'a search that matched nothing is presented as an account with no articles');

    expect(trim((string) $page->query($list.'//p['.articleAuthoringHasClass('empty').']')->item(0)?->textContent))
        ->toBe('No article title matches “zzz”.');
});

test('a refused field is announced on the field itself', function (string $page, string $field, array $input): void {
    // The errors used to print at the top of the page, attached to nothing: a
    // screen-reader user landed on the reloaded form and no control said it
    // was invalid or why.
    $w = articleWorld();
    $article = Article::factory()->for($w['account'])->create(['title' => 'Original', 'body' => 'Original body.']);

    $request = $this->actingAs($w['admin'])->followingRedirects();

    $response = $page === 'index'
        ? $request->from(route('dashboard.account.articles.index'))->post(route('dashboard.account.articles.store'), $input)
        : $request->from(route('dashboard.account.articles.show', $article))->put(route('dashboard.account.articles.update', $article), $input);

    $html = articleAuthoringXPath((string) $response->assertOk()->getContent());

    $controls = ['title' => '//input[@id="article_title"]', 'body' => '//textarea[@id="article_body"]'];
    $control = $html->query($controls[$field])->item(0);

    expect($control)->not->toBeNull("the {$field} control did not render; this guard is checking nothing")
        ->and($control?->getAttribute('aria-invalid'))->toBe('true', "the refused {$field} is not marked invalid");

    // Every id it names must exist, and one of them must be the error.
    $described = array_values(array_filter(preg_split('/\s+/', (string) $control->getAttribute('aria-describedby')) ?: []));
    $errors = [];

    foreach ($described as $id) {
        $target = $html->query('//*[@id="'.$id.'"]')->item(0);

        expect($target)->not->toBeNull("the {$field} control is described by #{$id}, which does not exist");

        if (in_array('field-error', explode(' ', (string) $target->getAttribute('class')), true)) {
            $errors[] = $target;
        }
    }

    expect($errors)->toHaveCount(1, "the {$field} error is not bound to the {$field} control");
    expect(trim($errors[0]->textContent))->not->toBe('');

    // Beside the control, not detached at the top of the page.
    expect($errors[0]->parentNode->isSameNode($control->parentNode))
        ->toBeTrue("the {$field} error is printed away from its control");

    expect($html->query('//*['.articleAuthoringHasClass('field-error').']')->length)
        ->toBe(1, 'an error is also printed somewhere other than beside its control');

    // And only the control that failed says so.
    $other = $html->query($controls[$field === 'title' ? 'body' : 'title'])->item(0);

    expect($other?->hasAttribute('aria-invalid'))->toBeFalse('the control that passed is marked invalid too');
})->with([
    'the new-article title' => ['index', 'title', ['title' => '   ', 'body' => 'An answer.']],
    'the new-article body' => ['index', 'body', ['title' => 'Refunds', 'body' => "\x01\x02"]],
    'the edited title' => ['show', 'title', ['title' => '   ', 'body' => 'An answer.']],
    'the edited body' => ['show', 'body', ['title' => 'Refunds', 'body' => "\x01\x02"]],
]);

test('the markup hint is styled help text and describes the body field', function (): void {
    // `field-hint` has no rule anywhere, so the hint rendered at full body
    // weight beside the labels. `field-help` is the class the stylesheet has.
    $w = articleWorld();

    $page = articleAuthoringXPath((string) $this->actingAs($w['admin'])
        ->get(route('dashboard.account.articles.index'))->assertOk()->getContent());

    $hint = $page->query('//p[@id="article_body-help"]')->item(0);

    expect($hint)->not->toBeNull('the markup hint did not render');

    expect(in_array('field-help', explode(' ', (string) $hint?->getAttribute('class')), true))
        ->toBeTrue('the markup hint is not styled as field help');

    expect(in_array('article_body-help', explode(' ', (string) $page->query('//textarea[@id="article_body"]')->item(0)?->getAttribute('aria-describedby')), true))
        ->toBeTrue('the markup hint does not describe the body field');
});

test('the preview reads as the article, not as a muted note about the page', function (): void {
    // `.notice-copy` is the muted treatment for the page's own prose. On the
    // preview it put the whole article in secondary grey under a lede that
    // says this is exactly what a visitor sees.
    $w = articleWorld();
    $article = Article::factory()->for($w['account'])->create(['body' => "## Refunds\n\nWithin 14 days."]);

    $html = (string) $this->actingAs($w['admin'])
        ->get(route('dashboard.account.articles.show', $article))->assertOk()->getContent();

    $preview = articleAuthoringXPath($html)->query('//*['.articleAuthoringHasClass('article-preview').']')->item(0);

    expect($preview)->not->toBeNull('the preview did not render; this guard is checking nothing');

    expect(in_array('notice-copy', explode(' ', (string) $preview?->getAttribute('class')), true))
        ->toBeFalse('the preview inherits the muted .notice-copy treatment');

    expect(str_contains($html, '.article-preview {'))
        ->toBeTrue('the preview has no rule of its own, so the class carries nothing');
});

test('the publish control sits in the visibility header, not in borrowed site-settings markup', function (): void {
    $w = articleWorld();
    $article = Article::factory()->for($w['account'])->create();

    $page = articleAuthoringXPath((string) $this->actingAs($w['admin'])
        ->get(route('dashboard.account.articles.show', $article))->assertOk()->getContent());

    $publish = route('dashboard.account.articles.publish', $article);

    expect($page->query('//section[@aria-labelledby="article-state-heading"]/div['.articleAuthoringHasClass('section-header').']'
        .'/div['.articleAuthoringHasClass('section-actions').']/form[@action="'.$publish.'"]/button')->length)
        ->toBe(1, 'the publish button is not in the visibility header beside the state pill');

    expect($page->query('//*[contains(@class, "desk-closure")]')->length)
        ->toBe(0, 'the article page still borrows the site-settings desk-closure layout');
});

test('a refused new article gives the author back the body they wrote', function (): void {
    // A title of only spaces satisfies the browser's `required` and fails on
    // the server. The title came back; the body -- the whole article -- did not.
    $w = articleWorld();
    $body = "## Refunds\n\nWe refund within 14 days.\n\n- Keep the receipt.";

    $html = articleAuthoringXPath((string) $this->actingAs($w['admin'])
        ->followingRedirects()
        ->from(route('dashboard.account.articles.index'))
        ->post(route('dashboard.account.articles.store'), ['title' => '   ', 'body' => $body])
        ->assertOk()->getContent());

    expect(Article::query()->count())->toBe(0, 'the article was saved; this is not the refused re-render');

    expect($html->query('//input[@id="article_title"]')->item(0)?->getAttribute('aria-invalid'))
        ->toBe('true', 'the title was not refused; this guard is checking nothing');

    $textarea = $html->query('//textarea[@id="article_body"]')->item(0);

    expect($textarea)->not->toBeNull('the body control did not render');
    expect($textarea?->textContent)->toBe($body, 'the refused form threw away the article body the author wrote');
});

test('the preview sets no space between a link or emphasis and the punctuation after it', function (): void {
    // Spans are inline. Whitespace left between them in the partial is a
    // rendered space, so "[14 days](...)." previewed as "14 days ." -- not the
    // article the widget shows.
    $w = articleWorld();
    $article = Article::factory()->for($w['account'])->create([
        'body' => "We refund within [14 days](https://example.test/refunds). Ask **now**, or run `make`!\n\n- Keep the **receipt**.",
    ]);

    $html = articleAuthoringXPath((string) $this->actingAs($w['admin'])
        ->get(route('dashboard.account.articles.show', $article))->assertOk()->getContent());

    $preview = '//*['.articleAuthoringHasClass('article-preview').']';
    $paragraph = $html->query($preview.'/p')->item(0);
    $item = $html->query($preview.'/ul/li')->item(0);

    expect($paragraph)->not->toBeNull('the preview paragraph did not render; this guard is checking nothing')
        ->and($item)->not->toBeNull('the preview list item did not render; this guard is checking nothing');

    // Every span kind is present, so every branch of the partial is exercised.
    foreach (['a', 'strong', 'code'] as $element) {
        expect($html->query('./'.$element, $paragraph)->length)->toBe(1, "the paragraph has no <{$element}> span");
    }

    // As a browser lays it out: any run of whitespace is one space.
    $rendered = fn (DOMNode $node): string => trim((string) preg_replace('/\s+/u', ' ', $node->textContent));

    expect($rendered($paragraph))->toBe(
        'We refund within 14 days. Ask now, or run make!',
        'the preview puts a space between a span and the punctuation that follows it',
    );
    expect($rendered($item))->toBe(
        'Keep the receipt.',
        'a list item in the preview puts a space between a span and the punctuation that follows it',
    );
});

test('an article of nothing but blank formatted runs is refused as empty', function (): void {
    $w = articleWorld();

    $this->actingAs($w['admin'])
        ->post(route('dashboard.account.articles.store'), ['title' => 'Blank', 'body' => '** ** ** **'])
        ->assertSessionHasErrors('body');

    expect(Article::query()->where('title', 'Blank')->exists())
        ->toBeFalse('an article whose every run is blank was saved, and renders as an empty panel');
});
