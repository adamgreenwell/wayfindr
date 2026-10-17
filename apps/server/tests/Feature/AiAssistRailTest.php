<?php

declare(strict_types=1);

use App\Models\AccountAiAssistPolicy;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\SiteAiAssistPolicy;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $copy = getenv('ASSIST_RAIL_VIEW_COPY');
    if (is_string($copy) && $copy !== '') {
        $finder = app('view')->getFinder();
        $finder->prependLocation($copy);
        $finder->flush();
    }
});
afterEach(fn () => Carbon::setTestNow());

function assistRailDocument(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8"?>'.$html, LIBXML_NONET);

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
        ->and($xpath->query('//details[@data-assist-rail]//*[@data-assist-section]/*[@data-assist-resting]')->length)->toBe(3, 'Rail sections need resting states outside removable cards.')
        ->and($xpath->query('//details[@data-assist-rail]//*[@data-ai-assist]//*[@data-assist-resting]')->length)->toBe(0)
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

test('individually disabled tasks show Disabled while other rail tasks remain available', function (string $task, string $column): void {
    $world = assistPreparationWorld(mode: 'manual');
    $world['policy']->forceFill([$column => false])->save();
    $response = $this->actingAs($world['owner'])->get(route('dashboard.conversations.show', $world['conversation']->support_code))->assertOk();
    $xpath = assistRailDocument($response->getContent());
    $section = '//details[@data-assist-rail]//*[@data-assist-section="'.$task.'"]';
    expect($xpath->query($section.'//*[@data-ai-assist]')->length)->toBe(0)
        ->and(trim($xpath->query($section.'/*[@data-assist-resting]/p[@class="lede"]')->item(0)->textContent))->toBe(__('ai_assist.rail.disabled'), 'Admin-disabled tasks must show Disabled, not transient Unavailable.')
        ->and($xpath->query('//details[@data-assist-rail]//*[@data-ai-assist]')->length)->toBe(2)
        ->and($xpath->query('//form[@data-reply-composer]//button[@data-reply-submit and not(@disabled)]')->length)->toBe(1)
        ->and(DB::table('ai_usage_attempts')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([['summary', 'summary'], ['reply', 'reply_draft'], ['knowledge', 'relevant_articles']]);

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

test('policy lookup failure keeps ordinary replying available with an unavailable rail', function (): void {
    $world = assistPreparationWorld(mode: 'prepare');
    $lookups = 0;
    app()->beforeResolving(AiAssistPolicyResolver::class, function () use (&$lookups): void {
        $lookups++;
        throw new RuntimeException('Synthetic policy lookup failure.');
    });
    $this->withExceptionHandling();
    $response = $this->actingAs($world['owner'])->get(route('dashboard.conversations.show', $world['conversation']->support_code));
    expect($response->status())->toBe(200, 'Policy failure must keep ordinary replying available.');
    expect($lookups)->toBeGreaterThan(0, 'The policy failure must actually be injected.');
    $xpath = assistRailDocument($response->getContent());
    expect($xpath->query('//form[@data-reply-composer]//button[@data-reply-submit and not(@disabled)]')->length)->toBe(1)
        ->and($xpath->query('//details[@data-assist-rail]//*[@data-ai-prepare or @data-ai-prepare-all or @data-ai-use]')->length)->toBe(0)
        ->and(DB::table('ai_usage_attempts')->count())->toBe(0);
    $response->assertSee(__('ai_assist.rail.unavailable'))->assertDontSee('Synthetic policy lookup failure.');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('native status polling keeps selected rail headings and action copy in legacy and prepared views', function (bool $legacy, string $locale): void {
    $world = assistPreparationWorld(mode: 'manual');
    $world['owner']->forceFill(['locale' => $locale])->save();
    if ($legacy) {
        SiteAiAssistPolicy::query()->where('account_id', $world['account']->id)->delete();
        AccountAiAssistPolicy::query()->whereKey($world['account']->id)->delete();
        ConversationCopilotReplyDraft::query()->forceCreate([
            'conversation_id' => $world['conversation']->id, 'requested_by_id' => $world['owner']->id,
            'generation' => (string) Str::uuid(), 'status' => 'ready', 'draft' => 'Synthetic legacy reply.',
            'connection_revision' => app(AgentCopilotConnectionResolver::class)->forAccount($world['account']->id)->revision,
            'policy_revision' => null, 'source_context_fingerprint' => hash('sha256', 'synthetic legacy context'),
            'source_last_message_id' => $world['message']->id, 'source_message_count' => 1,
            'requested_at' => now(), 'completed_at' => now(),
        ]);
    }
    foreach (['summary' => 'copilot-summary', 'knowledge' => 'copilot-knowledge-suggestion', 'reply' => 'copilot-reply-draft'] as $task => $route) {
        $response = $this->actingAs($world['owner'])->get(route('dashboard.conversations.'.$route.'.show', $world['conversation']->support_code))->assertOk();
        $xpath = assistRailDocument($response->getContent());
        $heading = $xpath->query('(//h2 | //h3 | //div[@class="reply-copilot-heading"]/strong)[1]')->item(0);
        expect($heading)->not->toBeNull()
            ->and(trim($heading->textContent))->toBe(__('ai_assist.rail.sections.'.$task), 'Rail heading changed during native polling.');
        if ($legacy && $task === 'reply') {
            $use = $xpath->query('//button[@data-copilot-reply-draft-use]')->item(0);
            expect($use)->not->toBeNull()
                ->and(trim($use->textContent))->toBe(__('ai_assist.rail.insert'), 'Rail insertion label changed during native polling.');
        } elseif ($task === 'reply') {
            $panel = $xpath->query('//*[@data-ai-assist]')->item(0);
            $copy = json_decode($panel->getAttribute('data-copy'), true, flags: JSON_THROW_ON_ERROR);
            expect($copy['use'])->toBe(__('ai_assist.rail.insert'), 'Rail insertion label changed during native polling.');
        }
    }
    expect(DB::table('ai_usage_attempts')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['legacy' => true, 'prepared' => false])->with(['en', 'de', 'it']);
