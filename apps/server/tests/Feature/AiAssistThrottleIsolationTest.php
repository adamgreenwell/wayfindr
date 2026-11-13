<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Jobs\GenerateConversationCopilotKnowledgeSuggestion;
use App\Jobs\GenerateConversationCopilotReplyDraft;
use App\Jobs\GenerateConversationCopilotSummary;
use App\Jobs\GenerateConversationCopilotTicketSuggestion;
use App\Models\Account;
use App\Models\Article;
use App\Models\Conversation;
use App\Models\ConversationCopilotKnowledgeSuggestion;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\ConversationCopilotSummary;
use App\Models\ConversationCopilotTicketSuggestion;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\KnowledgeArticleSuggestionMatcher;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function aiAssistThrottleWorld(): array
{
    config(['cache.default' => 'array', 'cache.limiter' => 'array']);
    Cache::store('array')->flush();
    $settings = app(OperatorSettings::class);
    $settings->set('ai.provider', 'ollama');
    $settings->set('ai.model', 'synthetic-model');
    $settings->set('ai.endpoint', 'http://localhost:11434');
    $settings->applyOverrides();
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create();
    $message = ConversationMessage::factory()->for($conversation)->create(['body' => 'Synthetic checkout question.']);
    $article = Article::factory()->for($account)->published()->create(['title' => 'Checkout help', 'body' => 'A safe checkout excerpt.']);
    Queue::fake();

    return compact('account', 'agent', 'site', 'conversation', 'message', 'article');
}

function aiAssistThrottleReady(array $world, string $kind): object
{
    [$class, $purpose, $content] = match ($kind) {
        'reply-draft' => [ConversationCopilotReplyDraft::class, 'conversation_reply_draft', ['draft' => 'Fresh reviewed reply.']],
        'knowledge-suggestion' => [ConversationCopilotKnowledgeSuggestion::class, 'conversation_knowledge_suggestion', ['article_ids' => [$world['article']->id]]],
        'ticket-suggestion' => [ConversationCopilotTicketSuggestion::class, 'conversation_ticket_suggestion', ['title' => 'Fresh ticket title', 'priority' => 'high', 'label_ids' => []]],
    };
    $artifact = $class::query()->create([
        'conversation_id' => $world['conversation']->id, 'requested_by_id' => $world['agent']->id,
        'generation' => (string) Str::uuid(), 'status' => 'ready', 'requested_at' => now(), 'completed_at' => now(),
        'source_last_message_id' => $world['message']->id, 'source_message_count' => 1,
        'connection_revision' => app(AgentCopilotConnectionResolver::class)->forAccount($world['account']->id)->revision,
        'policy_revision' => app(AiAssistPolicyResolver::class)->forSite($world['site']->id)->revisionFor($purpose),
        ...$content,
    ]);
    $artifact->forceFill(['source_context_fingerprint' => AiSupportUsage::sourceFingerprint($artifact)])->save();

    return $artifact;
}

test('more than six real status polls cannot consume the manual generation allowance', function (): void {
    $world = aiAssistThrottleWorld();
    $this->actingAs($world['agent']);
    $tasks = ['summary', 'reply-draft', 'knowledge-suggestion', 'ticket-suggestion'];
    for ($index = 0; $index < 8; $index++) {
        $this->get(route('dashboard.conversations.copilot-'.$tasks[$index % 4].'.show', $world['conversation']->support_code))
            ->assertOk()->assertHeader('X-RateLimit-Limit', '60')->assertHeader('X-RateLimit-Remaining', (string) (59 - $index));
    }
    $this->post(route('dashboard.conversations.copilot-summary.store', $world['conversation']->support_code))
        ->assertRedirect()->assertHeader('X-RateLimit-Limit', '6')->assertHeader('X-RateLimit-Remaining', '5')
        ->assertSessionHas('status', 'conversations.flash.ai_summary_queued');
    expect(ConversationCopilotSummary::query()->sole()->status)->toBe('pending');
    Queue::assertPushed(GenerateConversationCopilotSummary::class, 1);
});

test('exhausting status polling cannot consume the separate fresh Use allowance', function (): void {
    $world = aiAssistThrottleWorld();
    $artifacts = [];
    foreach (['reply-draft', 'knowledge-suggestion', 'ticket-suggestion'] as $kind) {
        $artifacts[$kind] = aiAssistThrottleReady($world, $kind);
    }
    $this->actingAs($world['agent']);
    $tasks = ['summary', 'reply-draft', 'knowledge-suggestion', 'ticket-suggestion'];
    for ($index = 0; $index < 60; $index++) {
        $this->get(route('dashboard.conversations.copilot-'.$tasks[$index % 4].'.show', $world['conversation']->support_code))
            ->assertOk()->assertHeader('X-RateLimit-Limit', '60')->assertHeader('X-RateLimit-Remaining', (string) (59 - $index));
    }
    $this->get(route('dashboard.conversations.copilot-summary.show', $world['conversation']->support_code))
        ->assertStatus(429)->assertHeader('X-RateLimit-Limit', '60')->assertHeader('X-RateLimit-Remaining', '0')->assertHeader('Retry-After');
    $remaining = 59;
    foreach ($artifacts as $kind => $artifact) {
        $query = ['supportCode' => $world['conversation']->support_code, 'generation' => $artifact->generation];
        if ($kind === 'knowledge-suggestion') {
            $selected = app(KnowledgeArticleSuggestionMatcher::class)->present($world['account'], [$world['article']->id])->sole();
            $query += ['article_id' => $world['article']->id, 'article_revision' => KnowledgeArticleSuggestionMatcher::revision($selected['article'], $selected['snippet'])];
        }
        $this->getJson(route('dashboard.conversations.copilot-'.$kind.'.use', $query))
            ->assertOk()->assertJsonPath('generation', $artifact->generation)
            ->assertHeader('X-RateLimit-Limit', '60')->assertHeader('X-RateLimit-Remaining', (string) $remaining--);
    }
    Queue::assertNothingPushed();
});

test('all four manual task routes retain one shared six per minute generation allowance', function (): void {
    $world = aiAssistThrottleWorld();
    $this->actingAs($world['agent']);
    $tasks = ['summary', 'reply-draft', 'knowledge-suggestion', 'ticket-suggestion'];
    for ($index = 0; $index < 6; $index++) {
        $this->post(route('dashboard.conversations.copilot-'.$tasks[$index % 4].'.store', $world['conversation']->support_code))
            ->assertRedirect()->assertHeader('X-RateLimit-Limit', '6')->assertHeader('X-RateLimit-Remaining', (string) (5 - $index));
    }
    $this->post(route('dashboard.conversations.copilot-knowledge-suggestion.store', $world['conversation']->support_code))
        ->assertStatus(429)->assertHeader('X-RateLimit-Limit', '6')->assertHeader('X-RateLimit-Remaining', '0')->assertHeader('Retry-After');
    foreach ([GenerateConversationCopilotSummary::class, GenerateConversationCopilotReplyDraft::class,
        GenerateConversationCopilotKnowledgeSuggestion::class, GenerateConversationCopilotTicketSuggestion::class] as $job) {
        Queue::assertPushed($job, 1);
    }
});
