<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());

function assistRailDocument(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML($html);

    return new DOMXPath($document);
}

test('the translated Assist rail keeps its three tasks beside the ordinary composer without starting work', function (string $locale, string $summary, string $reply, string $sources): void {
    $world = assistPreparationWorld(mode: 'prepare');
    $world['owner']->forceFill(['locale' => $locale])->save();
    $response = $this->actingAs($world['owner'])->get(route('dashboard.conversations.show', $world['conversation']->support_code))->assertOk();
    $xpath = assistRailDocument($response->getContent());
    expect($xpath->query('//details[@data-assist-rail and @open]')->length)->toBe(1, 'The rail must remain a usable native disclosure without JavaScript.')
        ->and($xpath->query('//*[@data-reply-shell]//form[@data-reply-composer]')->length)->toBe(1)
        ->and($xpath->query('//details[@data-assist-rail]//form[@data-reply-composer]')->length)->toBe(0, 'Send and the ordinary composer must remain outside Assist.')
        ->and($xpath->query('//details[@data-assist-rail]//*[@data-ai-assist]')->length)->toBe(3)
        ->and($xpath->query('//*[@class="conversation-assist-main"]//*[@data-ai-assist]')->length)->toBe(0);
    foreach (['summary' => $summary, 'reply' => $reply, 'knowledge' => $sources] as $task => $heading) {
        expect(trim($xpath->query('//details[@data-assist-rail]//*[@data-assist-section="'.$task.'"]//h3')->item(0)->textContent))->toBe($heading);
    }
    expect(DB::table('ai_usage_attempts')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    ['en', 'Catch up', 'Suggested reply', 'Sources'],
    ['de', 'Zusammenfassung der Unterhaltung', 'Antwortvorschlag', 'Quellen'],
    ['it', 'Sintesi', 'Risposta suggerita', 'Fonti'],
]);

test('Manual keeps its deliberate grouped Prepare action in the rail and preserves a restored reply', function (): void {
    $world = assistPreparationWorld(mode: 'manual');
    $response = $this->actingAs($world['owner'])->withSession(['_old_input' => ['body' => 'Unfinished human reply.']])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))->assertOk();
    $xpath = assistRailDocument($response->getContent());
    expect($xpath->query('//details[@data-assist-rail]//button[@data-ai-prepare-all]')->length)->toBe(1)
        ->and($xpath->query('//textarea[@data-reply-body]')->item(0)->textContent)->toBe('Unfinished human reply.')
        ->and(DB::table('ai_usage_attempts')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('Off keeps the compact rail and ordinary reply usable without processing controls', function (): void {
    $world = assistPreparationWorld(mode: 'off');
    $response = $this->actingAs($world['owner'])->get(route('dashboard.conversations.show', $world['conversation']->support_code))->assertOk();
    $xpath = assistRailDocument($response->getContent());
    expect($xpath->query('//details[@data-assist-rail]')->length)->toBe(1)
        ->and($xpath->query('//details[@data-assist-rail]//*[@data-ai-prepare or @data-ai-prepare-all or @data-ai-use]')->length)->toBe(0)
        ->and($xpath->query('//form[@data-reply-composer]//button[@data-reply-submit and not(@disabled)]')->length)->toBe(1)
        ->and(DB::table('ai_usage_attempts')->count())->toBe(0);
    $response->assertSee(__('ai_assist.rail.disabled'))->assertDontSee('synthetic-not-a-secret');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('a Ready rail suggestion remains separate from the restored human draft and its Send action', function (): void {
    $world = assistPreparationWorld('conversation_reply_draft', 'manual');
    assistPreparationEnsure($world, 'initial');
    assistPreparationRun($world);
    $before = DB::table('ai_usage_attempts')->count();
    $response = $this->actingAs($world['owner'])->withSession(['_old_input' => ['body' => 'Human draft survives.']])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))->assertOk();
    $xpath = assistRailDocument($response->getContent());
    expect($xpath->query('//details[@data-assist-rail]//*[@data-ai-assist and @data-task="reply" and @data-state="ready"]')->length)->toBe(1)
        ->and($xpath->query('//textarea[@data-reply-body]')->item(0)->textContent)->toBe('Human draft survives.')
        ->and(trim($xpath->query('//details[@data-assist-rail]//button[@data-ai-use]')->item(0)->textContent))->toBe('Insert into reply')
        ->and($xpath->query('//details[@data-assist-rail]//button[@data-reply-submit]')->length)->toBe(0)
        ->and(DB::table('ai_usage_attempts')->count())->toBe($before);
    Http::assertNothingSent();
});
