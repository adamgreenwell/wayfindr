<?php

declare(strict_types=1);

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountAiAssistPolicy;
use App\Models\Article;
use App\Models\ConversationCopilotKnowledgeSuggestion;
use App\Models\ConversationMessage;
use App\Models\CustomRole;
use App\Models\SiteAiAssistPolicy;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\KnowledgeArticleSuggestionMatcher;
use App\Support\Ai\Preparation\AiAssistPreparation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());

function assistSourceInspectionWorld(bool $legacy, ?string $body = null): array
{
    $world = assistPreparationWorld('conversation_knowledge_suggestion', 'manual');
    config(['app.debug' => false]);
    if ($body !== null) {
        $world['article']->update(['body' => $body]);
    }
    $world['unselectedArticle'] = Article::factory()->for($world['account'])->published()->create([
        'title' => 'Unrelated invoicing guidance', 'body' => 'Different published account policy.',
    ]);
    if ($legacy) {
        SiteAiAssistPolicy::query()->where('account_id', $world['account']->id)->delete();
        AccountAiAssistPolicy::query()->whereKey($world['account']->id)->delete();
        $artifact = ConversationCopilotKnowledgeSuggestion::query()->forceCreate([
            'conversation_id' => $world['conversation']->id, 'requested_by_id' => $world['owner']->id,
            'generation' => (string) Str::uuid(), 'status' => 'ready', 'article_ids' => [$world['article']->id],
            'connection_revision' => app(AgentCopilotConnectionResolver::class)->forAccount($world['account']->id)->revision,
            'policy_revision' => null, 'source_last_message_id' => $world['message']->id, 'source_message_count' => 1,
            'requested_at' => now(), 'completed_at' => now(),
        ]);
        $provider = null;
    } else {
        assistPreparationEnsure($world, 'initial');
        $provider = assistPreparationRun($world);
        $artifact = $world['class']::query()->sole();
    }
    Queue::fake();
    $snippet = app(KnowledgeArticleSuggestionMatcher::class)->present($world['account'], [$world['article']->id])->sole()['snippet'];
    $url = route('dashboard.conversations.assist.sources.show', ['supportCode' => $world['conversation']->support_code,
        'article' => $world['article']->id, 'generation' => $artifact->generation,
        'article_revision' => KnowledgeArticleSuggestionMatcher::revision($world['article'], $snippet)]);

    return $world + compact('artifact', 'url', 'provider');
}

test('source inspection is read only for replying agents without knowledge management authority', function (bool $legacy): void {
    $world = assistSourceInspectionWorld($legacy);
    expect($world['agent']->hasAccountPermission(AccountPermission::ManageKnowledge))->toBeFalse();
    $before = DB::table('ai_usage_attempts')->count();
    $calls = $world['provider']?->calls;
    $this->actingAs($world['agent'])->get($world['url'])->assertOk()
        ->assertSee('Checkout help')->assertSee('Synthetic checkout guidance.')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertDontSee('name="body"', false)->assertDontSee('name="title"', false)
        ->assertDontSee('synthetic-not-a-secret')->assertDontSee('models.example.test');
    expect(DB::table('ai_usage_attempts')->count())->toBe($before)
        ->and($world['provider']?->calls)->toBe($calls);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['legacy' => true, 'prepared' => false]);

test('source inspection rejects an article that was not selected even inside the same account', function (bool $legacy): void {
    $world = assistSourceInspectionWorld($legacy);
    $other = $world['unselectedArticle'];
    $snippet = app(KnowledgeArticleSuggestionMatcher::class)->present($world['account'], [$other->id])->sole()['snippet'];
    $url = route('dashboard.conversations.assist.sources.show', ['supportCode' => $world['conversation']->support_code,
        'article' => $other->id, 'generation' => $world['artifact']->generation,
        'article_revision' => KnowledgeArticleSuggestionMatcher::revision($other, $snippet)]);
    $this->actingAs($world['agent'])->get($url)->assertNotFound();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['legacy' => true, 'prepared' => false]);

test('source inspection withdraws deleted unpublished foreign or revision changed articles', function (bool $legacy, string $change): void {
    $world = assistSourceInspectionWorld($legacy);
    match ($change) {
        'deleted' => $world['article']->delete(),
        'unpublished' => $world['article']->forceFill(['published_at' => null])->save(),
        'scheduled' => $world['article']->forceFill(['published_at' => now()->addDay()])->save(),
        'foreign' => $world['article']->forceFill(['account_id' => Account::factory()->create()->id])->save(),
        'edited' => $world['article']->forceFill(['body' => 'Changed checkout guidance.'])->save(),
    };
    $response = $this->actingAs($world['agent'])->get($world['url']);
    if ($legacy && $change === 'edited') {
        $response->assertConflict();
    } else {
        $response->assertNotFound();
    }
    $response->assertDontSee('Synthetic checkout guidance.')->assertDontSee('Changed checkout guidance.');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['legacy' => true, 'prepared' => false])->with(['deleted', 'unpublished', 'scheduled', 'foreign', 'edited']);

test('source inspection rechecks site reply policy and active connection authority', function (bool $legacy, string $change): void {
    $world = assistSourceInspectionWorld($legacy);
    if ($change === 'reply') {
        $role = CustomRole::factory()->for($world['account'])->create(['permissions' => [AccountPermission::ViewConversations->value]]);
        $world['agent']->forceFill(['account_role' => AccountRole::Agent, 'custom_role_id' => $role->id])->save();
    } elseif ($change === 'site') {
        $world['site']->supportAgents()->attach($world['owner']);
    } elseif ($change === 'connection') {
        $world['connection']->forceFill(['enabled' => false])->save();
    } else {
        $policy = AccountAiAssistPolicy::query()->find($world['account']->id) ?? new AccountAiAssistPolicy;
        $policy->forceFill([
            'account_id' => $world['account']->id, 'revision' => (string) Str::uuid(), 'mode' => 'off',
            'summary' => true, 'reply_draft' => true, 'relevant_articles' => false, 'ticket_details' => true,
            'article_context_consent' => false,
        ])->save();
    }
    $this->actingAs($world['agent']->fresh())->get($world['url'])->assertNotFound()->assertDontSee('Synthetic checkout guidance.');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['legacy' => true, 'prepared' => false])->with(['reply', 'site', 'connection', 'policy']);

test('source inspection is unavailable after relevant articles is disabled', function (): void {
    $world = assistSourceInspectionWorld(false);
    $world['policy']->forceFill(['relevant_articles' => false])->save();
    $this->actingAs($world['agent'])->get($world['url'])->assertNotFound()->assertDontSee('Synthetic checkout guidance.');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('source inspection never admits a different account actor', function (bool $legacy): void {
    $world = assistSourceInspectionWorld($legacy);
    $foreign = User::factory()->for(Account::factory()->create())->create(['account_role' => AccountRole::Owner]);
    $this->actingAs($foreign)->get($world['url'])->assertNotFound();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['legacy' => true, 'prepared' => false]);

test('prepared source links preserve the displayed generation while a refresh is preparing', function (): void {
    $world = assistSourceInspectionWorld(false);
    $first = app(AiAssistPreparation::class)->read($world['agent'], $world['conversation'], $world['purpose']);
    expect($first['output']['articles'][0]['source_url'])->toBe($world['url']);
    assistPreparationAdvance($world, 61);
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Another checkout question.']);
    $refresh = assistPreparationEnsure($world, 'refresh');
    expect($refresh['state'])->toBe('preparing')->and($refresh['can_use'])->toBeFalse()
        ->and($refresh['generation'])->not->toBe($world['artifact']->generation)
        ->and($refresh['output_generation'])->toBe($world['artifact']->generation)
        ->and($refresh['output']['articles'][0]['source_url'])->toBe($world['url']);
    Queue::fake();
    $before = DB::table('ai_usage_attempts')->count();
    $this->actingAs($world['agent'])->get($world['url'])->assertOk()->assertSee('Synthetic checkout guidance.');
    expect(DB::table('ai_usage_attempts')->count())->toBe($before)->and($world['provider']->calls)->toBe(1);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('authorized stale source inspection does not make stale output usable', function (): void {
    $world = assistSourceInspectionWorld(false);
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Later checkout activity.']);
    $state = app(AiAssistPreparation::class)->read($world['agent'], $world['conversation'], $world['purpose']);
    expect($state['state'])->toBe('stale')->and($state['can_use'])->toBeFalse();
    $this->actingAs($world['agent'])->get($world['url'])->assertOk();
    $after = app(AiAssistPreparation::class)->read($world['agent'], $world['conversation'], $world['purpose']);
    expect($after['can_use'])->toBeFalse()->and($world['provider']->calls)->toBe(1);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('source links refuse a replaced generation and altered displayed revision', function (bool $legacy, string $change): void {
    $world = assistSourceInspectionWorld($legacy);
    $url = preg_replace($change === 'generation' ? '/generation=[^&]+/' : '/article_revision=[^&]+/',
        $change === 'generation' ? 'generation='.Str::uuid() : 'article_revision='.str_repeat('0', 64), $world['url']);
    $this->actingAs($world['agent'])->get($url)->assertConflict();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['legacy' => true, 'prepared' => false])->with(['generation', 'revision']);

test('source inspection withdraws prepared evidence after an original selected message changes', function (string $change): void {
    $world = assistSourceInspectionWorld(false);
    match ($change) {
        'edit' => $world['message']->update(['body' => 'A changed original request.']),
        'delete' => $world['message']->delete(),
    };
    $this->actingAs($world['agent'])->get($world['url'])->assertNotFound()->assertDontSee('Synthetic checkout guidance.');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['edit', 'delete']);

test('source inspection renders article blocks without trusting authored HTML or links', function (): void {
    $world = assistPreparationWorld('conversation_knowledge_suggestion', 'manual');
    $world['article']->update(['body' => "# Checkout help\n\n<script>alert(1)</script> [Unsafe](javascript:alert) **Safe checkout text**"]);
    assistPreparationEnsure($world, 'initial');
    assistPreparationRun($world);
    Queue::fake();
    $state = app(AiAssistPreparation::class)->read($world['agent'], $world['conversation'], $world['purpose']);
    $this->actingAs($world['agent'])->get($state['output']['articles'][0]['source_url'])->assertOk()
        ->assertSee('Safe checkout text')->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('href="javascript:', false)
        ->assertDontSee('name="body"', false);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('source revision binds the full article when a same-second edit leaves the excerpt unchanged', function (bool $legacy): void {
    $prefix = str_repeat('Checkout instructions. ', 20);
    $world = assistSourceInspectionWorld($legacy, $prefix.'Original final instruction.');
    $matcher = app(KnowledgeArticleSuggestionMatcher::class);
    $before = $matcher->present($world['account'], [$world['article']->id])->sole();
    $revision = KnowledgeArticleSuggestionMatcher::revision($before['article'], $before['snippet']);
    $timestamp = $before['article']->getRawOriginal('updated_at');
    $world['article']->update(['body' => $prefix.'Changed final instruction.']);
    $after = $matcher->present($world['account'], [$world['article']->id])->sole();
    expect($after['snippet'])->toBe($before['snippet'], 'The regression must leave the displayed excerpt unchanged.')
        ->and($after['article']->getRawOriginal('updated_at'))->toBe($timestamp, 'The regression must use the same stored timestamp.')
        ->and(KnowledgeArticleSuggestionMatcher::revision($after['article'], $after['snippet']))->not->toBe($revision, 'The revision must identify the complete inspected source.');
    $response = $this->actingAs($world['agent'])->get($world['url']);
    if ($legacy) {
        $response->assertConflict();
    } else {
        $response->assertNotFound();
    }
    $response->assertDontSee('Changed final instruction.');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['legacy' => true, 'prepared' => false]);
