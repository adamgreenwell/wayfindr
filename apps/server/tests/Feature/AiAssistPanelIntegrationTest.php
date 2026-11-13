<?php

declare(strict_types=1);

use App\Enums\AccountPermission;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);
beforeEach(function (): void {
    $copy = getenv('ASSIST_PANEL_VIEW_COPY');
    if (is_string($copy) && $copy !== '') {
        $finder = app('view')->getFinder();
        $finder->prependLocation($copy);
        $finder->flush();
    }
});
afterEach(fn () => Carbon::setTestNow());

function assistPanelDocument(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8"?>'.$html, LIBXML_NONET);

    return new DOMXPath($document);
}

test('the localized panel integrates source inspection disclosures and one AI live region without sending a restored draft', function (string $locale, bool $manager): void {
    $world = assistPreparationWorld('conversation_knowledge_suggestion', 'manual');
    assistPreparationEnsure($world, 'initial');
    $provider = assistPreparationRun($world);
    Queue::fake();
    $actor = $world[$manager ? 'owner' : 'agent'];
    $actor->forceFill(['locale' => $locale])->save();
    expect($actor->hasAccountPermission(AccountPermission::ManageAiConnections))->toBe($manager);
    $before = DB::table('ai_usage_attempts')->count();
    $response = $this->actingAs($actor)->withSession(['_old_input' => ['body' => 'Unsent human-only draft.']])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))->assertOk();
    $xpath = assistPanelDocument($response->getContent());
    expect($xpath->query('//aside//*[@data-assist-live and @role="status" and @aria-live="polite"]')->length)->toBe(1, 'Assist must share one polite live region.')
        ->and($xpath->query('//details[@data-assist-rail]//*[@role="status" or @aria-live="polite"]')->length)->toBe(0)
        ->and($xpath->query('//details[@data-assist-rail]//*[@data-assist-freshness-task]')->length)->toBe(3)
        ->and($xpath->query('//section[@data-assist-draft-review and @hidden]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-assist-review-original]')->item(0)->textContent)->toBe('')
        ->and($xpath->query('//*[@data-assist-review-candidate]')->item(0)->textContent)->toBe('')
        ->and($xpath->query('//textarea[@data-reply-body]')->item(0)->textContent)->toBe('Unsent human-only draft.')
        ->and($xpath->query('//form[@data-reply-composer]//button[@data-reply-submit and not(@disabled)]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="conversation-message-'.$world['message']->id.'"]')->length)->toBe(1);
    $source = $xpath->query('//details[@data-assist-rail]//*[@data-ai-output]//a')->item(0);
    expect($source)->not->toBeNull()
        ->and($source->getAttribute('href'))->toContain('/assist/sources/'.$world['article']->id, 'generation=', 'article_revision=')
        ->and($source->getAttribute('href'))->not->toContain('Unsent', 'synthetic-not-a-secret');
    expect($source->getAttribute('target'))->toBe('_blank', 'Source inspection must preserve the original conversation document and draft.')
        ->and($source->getAttribute('rel'))->toBe('noopener')
        ->and($source->getAttribute('aria-label'))->toContain(__('ai_assist.rail.source_new_tab'))
        ->and($xpath->query('//*[@data-assist-review-original and @lang=""]')->length)->toBe(1, 'Unknown-language preview text must not inherit the dashboard locale.')
        ->and($xpath->query('//*[@data-assist-review-candidate and @lang=""]')->length)->toBe(1);
    foreach (['original', 'candidate'] as $part) {
        $region = $xpath->query('//*[@data-assist-review-'.$part.']')->item(0);
        expect($region->getAttribute('tabindex'))->toBe('0', 'Long review text must be keyboard scrollable.')
            ->and($region->getAttribute('role'))->toBe('region');
        $label = $xpath->query('//*[@id="'.$region->getAttribute('aria-labelledby').'"]');
        expect($label->length)->toBe(1)->and(trim($label->item(0)->textContent))->not->toBe('');
    }
    $connectionLinks = $xpath->query('//details[@data-assist-rail]//a[@href="'.route('dashboard.account.ai-assist.show').'"]')->length;
    expect($connectionLinks)->toBe($manager ? 1 : 0)
        ->and(DB::table('ai_usage_attempts')->count())->toBe($before)
        ->and($provider->calls)->toBe(1);
    $refinementCopy = json_decode($xpath->query('//*[@data-draft-refinement]')->item(0)->getAttribute('data-copy'), true, 8, JSON_THROW_ON_ERROR);
    expect($refinementCopy['browser_unavailable'])->toBe(__('ai_assist.refinement.browser_unavailable'));
    $response->assertSee(__('ai_assist.rail.how'))->assertSee(__('ai_assist.rail.refinement'))->assertSee('synthetic-A')
        ->assertDontSee('synthetic-not-a-secret')->assertDontSee('models.example.test');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['en', 'de', 'it'])->with(['manager' => true, 'replying agent' => false]);

test('Off keeps the review controls inactive and the ordinary composer usable', function (): void {
    $world = assistPreparationWorld(mode: 'off');
    $response = $this->actingAs($world['owner'])->get(route('dashboard.conversations.show', $world['conversation']->support_code))->assertOk();
    $xpath = assistPanelDocument($response->getContent());
    expect($xpath->query('//section[@data-assist-draft-review and @hidden]')->length)->toBe(1)
        ->and($xpath->query('//details[@data-assist-rail]//*[@data-ai-use or @data-ai-prepare or @data-ai-prepare-all]')->length)->toBe(0)
        ->and($xpath->query('//form[@data-reply-composer]//button[@data-reply-submit and not(@disabled)]')->length)->toBe(1)
        ->and(DB::table('ai_usage_attempts')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
