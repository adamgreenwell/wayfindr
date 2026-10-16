<?php

declare(strict_types=1);

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Jobs\GenerateConversationCopilotKnowledgeSuggestion;
use App\Jobs\GenerateConversationCopilotReplyDraft;
use App\Jobs\GenerateConversationCopilotSummary;
use App\Jobs\GenerateConversationCopilotTicketSuggestion;
use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Models\Article;
use App\Models\Conversation;
use App\Models\ConversationCopilotKnowledgeSuggestion;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\ConversationCopilotSummary;
use App\Models\ConversationCopilotTicketSuggestion;
use App\Models\ConversationMessage;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\User;
use App\Support\Ai\AccountAiConnectionProbe;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Execution\AiGuardedSession;
use App\Support\Ai\KnowledgeArticleSuggestionMatcher;
use App\Support\Ai\Network\AiHttpResult;
use App\Support\Ai\Policy\AiAssistPolicies;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

final class PolicyManualSession implements AiGuardedSession
{
    public function __construct(private ResolvedAgentCopilotConnection $resolved, private Closure $settlement) {}

    public function connection(): ResolvedAgentCopilotConnection
    {
        return $this->resolved;
    }

    public function maxOutputTokens(): int
    {
        return 2048;
    }

    public function dispatch(string $url, array $headers, string $json): AiHttpResult
    {
        throw new LogicException('No network in policy fixtures.');
    }

    public function request(string $method, string $url, array $headers, string $body = ''): AiHttpResult
    {
        throw new LogicException('No network in policy fixtures.');
    }

    public function recordResponse(AiHttpResult $receipt, ?int $inputTokens, ?int $outputTokens): void
    {
        throw new LogicException('No network in policy fixtures.');
    }

    public function settle(string $reason = 'completed'): void
    {
        ($this->settlement)($reason);
    }

    public function usage(): array
    {
        return ['input' => 10, 'output' => 5];
    }
}

function policyManualSpec(string $kind): array
{
    return match ($kind) {
        'summary' => [ConversationCopilotSummary::class, GenerateConversationCopilotSummary::class, 'copilot-summary', 'conversation_summary', 'A policy-fenced summary.'],
        'reply' => [ConversationCopilotReplyDraft::class, GenerateConversationCopilotReplyDraft::class, 'copilot-reply-draft', 'conversation_reply_draft', 'A freshly authorized reply.'],
        'knowledge' => [ConversationCopilotKnowledgeSuggestion::class, GenerateConversationCopilotKnowledgeSuggestion::class, 'copilot-knowledge-suggestion', 'conversation_knowledge_suggestion', '{"queries":["checkout"]}'],
        'ticket' => [ConversationCopilotTicketSuggestion::class, GenerateConversationCopilotTicketSuggestion::class, 'copilot-ticket-suggestion', 'conversation_ticket_suggestion', '{"title":"Checkout assistance","priority":"high"}'],
    };
}

function policyManualWorld(string $kind, bool $explicit = true): array
{
    config(['wayfindr.ai.usage' => ['daily_requests' => 100, 'daily_tokens' => 1000000, 'concurrency' => 4, 'max_output_tokens' => 2048]]);
    app()->instance(AiUsageClock::class, new class implements AiUsageClock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-10-16 12:00:00', 'UTC');
        }
    });
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create();
    $message = ConversationMessage::factory()->for($conversation)->create(['body' => 'Synthetic checkout question.']);
    $article = Article::factory()->for($account)->published()->create(['title' => 'Checkout help', 'body' => 'Review the checkout button.']);
    AccountAiConnection::query()->forceCreate([
        'account_id' => $account->id, 'enabled' => true, 'uses_installation_default' => false,
        'active_revision' => (string) Str::uuid(),
        'active_configuration' => ['source' => 'account', 'provider' => 'openai-compatible', 'model' => 'synthetic-model',
            'endpoint' => 'https://models.example.test/v1', 'api_key' => 'synthetic-key', 'openrouter_provider' => '',
            'processing_policy_revision' => app(AccountAiConnectionProbe::class)->policyRevision()],
    ]);
    $spec = policyManualSpec($kind);
    $world = compact('account', 'agent', 'site', 'conversation', 'message', 'article', 'spec');
    if ($explicit) {
        policyManualEdit($world);
    }

    return $world;
}

function policyManualEdit(array $world, array $changes = []): void
{
    $policies = app(AiAssistPolicies::class);
    $policies->saveAccount($world['agent'], $world['account']->id, [...[
        'mode' => 'manual', 'summary' => true, 'reply_draft' => true, 'relevant_articles' => true,
        'ticket_details' => true, 'article_context_consent' => true, 'max_context_characters' => null,
    ], ...$changes], $policies->accountToken($world['account']->id));
}

function policyManualQueue(array $world): array
{
    Queue::fake();
    test()->actingAs($world['agent'])->post(route('dashboard.conversations.'.$world['spec'][2].'.store', $world['conversation']->support_code))->assertRedirect();
    $job = null;
    Queue::assertPushed($world['spec'][1], function ($queued) use (&$job): bool {
        $job = $queued;

        return true;
    });

    return [$job, $world['spec'][0]::query()->sole()];
}

function policyManualRun(array $world, object $job, ?Closure $during = null): void
{
    $provider = new class($world, $during) implements AgentCopilotProvider
    {
        public function __construct(private array $world, private ?Closure $during) {}

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('No probe in support fixture.');
        }

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $context = $prompt->execution;
            $connection = $prompt->connection;
            if ($context === null || $connection === null) {
                throw new LogicException('Expected a guarded execution.');
            }
            $ledger = app(AiUsageLedger::class);
            $claim = $ledger->claim($connection, $context->purpose, $context->attemptId);
            $dispatch = $ledger->beginDispatch($connection, $context->purpose, $context->attemptId, $claim);
            $ledger->markUnknown($context->attemptId, $dispatch->dispatchNonce);
            if ($this->during !== null) {
                ($this->during)();
            }
            $session = new PolicyManualSession($connection, fn (string $reason) => $ledger->reconcile($context->attemptId, $dispatch->dispatchNonce, 10, 5, $reason));

            return new AgentCopilotResult($this->world['spec'][4], 'synthetic', 'synthetic', 10, 5, $session);
        }
    };
    app()->call([$job, 'handle'], ['provider' => $provider]);
}

function policyManualUseQuery(array $world, object $artifact): array
{
    $query = ['supportCode' => $world['conversation']->support_code, 'generation' => $artifact->generation];
    if ($world['spec'][2] === 'copilot-knowledge-suggestion') {
        $selected = app(KnowledgeArticleSuggestionMatcher::class)->present($world['account'], [$world['article']->id])->sole();
        $query += ['article_id' => $world['article']->id, 'article_revision' => KnowledgeArticleSuggestionMatcher::revision($selected['article'], $selected['snippet'])];
    }

    return $query;
}

test('manual task policy Off blocks queue and read without disturbing the conversation', function (string $kind): void {
    $world = policyManualWorld($kind);
    policyManualEdit($world, ['mode' => 'off']);
    Queue::fake();
    $this->actingAs($world['agent'])->post(route('dashboard.conversations.'.$world['spec'][2].'.store', $world['conversation']->support_code))->assertNotFound();
    $this->get(route('dashboard.conversations.'.$world['spec'][2].'.show', $world['conversation']->support_code))->assertNotFound();
    $this->get(route('dashboard.conversations.show', $world['conversation']->support_code))->assertOk()->assertDontSee('data-'.$world['spec'][2], false);
    Queue::assertNothingPushed();
    expect($world['spec'][0]::query()->count())->toBe(0);
})->with(['summary', 'reply', 'knowledge', 'ticket']);

test('shared articles Off never queries article content on support task boundaries', function (): void {
    $world = policyManualWorld('knowledge');
    policyManualEdit($world, ['relevant_articles' => false, 'article_context_consent' => false]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (preg_match('/\barticles\b/i', $query->sql)) {
            $queries[] = $query->sql;
        }
    });
    $this->actingAs($world['agent'])->post(route('dashboard.conversations.copilot-knowledge-suggestion.store', $world['conversation']->support_code))->assertNotFound();
    $this->get(route('dashboard.conversations.copilot-knowledge-suggestion.show', $world['conversation']->support_code))->assertNotFound();
    $this->get(route('dashboard.conversations.show', $world['conversation']->support_code))->assertOk()->assertDontSee('data-copilot-knowledge-suggestion', false);
    expect($queries)->toBe([], 'Disabled shared articles must be checked before any article exists, matching, or presentation query.');
});

test('manual generation stamps policy and actual source and returns fresh Use data', function (string $kind): void {
    $world = policyManualWorld($kind);
    [$job, $artifact] = policyManualQueue($world);
    expect($artifact->policy_revision)->toBe(app(AiAssistPolicyResolver::class)->forSite($world['site']->id)->revisionFor($world['spec'][3]));
    policyManualRun($world, $job);
    $artifact->refresh();
    expect($artifact->status)->toBe('ready')->and($artifact->source_context_fingerprint)->toMatch('/^[a-f0-9]{64}$/');
    $receipt = DB::table('ai_usage_attempts')->sole();
    expect($receipt->state)->toBe('completed')->and($receipt->debited_tokens)->toBe(15);
    if ($kind !== 'summary') {
        $response = $this->actingAs($world['agent'])->getJson(route('dashboard.conversations.'.$world['spec'][2].'.use', policyManualUseQuery($world, $artifact)))->assertOk()->assertJsonPath('generation', $artifact->generation)->assertJsonPath('policy_revision', $artifact->policy_revision);
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
        if ($kind === 'reply') {
            $response->assertJsonPath('text', 'A freshly authorized reply.');
        }
        if ($kind === 'ticket') {
            $response->assertJsonPath('title', 'Checkout assistance')->assertJsonPath('priority', 'high');
        }
    }
})->with(['summary', 'reply', 'knowledge', 'ticket']);

test('queued policy revocation prevents provider execution', function (string $kind): void {
    $world = policyManualWorld($kind);
    [$job, $artifact] = policyManualQueue($world);
    policyManualEdit($world, ['mode' => 'off']);
    $called = false;
    $provider = new class($called) implements AgentCopilotProvider
    {
        public function __construct(private bool &$called) {}

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->called = true;
            throw new LogicException('Revoked work must never call a provider.');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('No probe.');
        }
    };
    app()->call([$job, 'handle'], ['provider' => $provider]);
    expect($called)->toBeFalse()->and($artifact->fresh()->status)->toBe('failed')->and($artifact->fresh()->failure_code)->toBe('policy_changed');
    expect(DB::table('ai_usage_attempts')->sole()->state)->toBe('cancelled');
})->with(['summary', 'reply', 'knowledge', 'ticket']);

test('policy revocation during a valid provider response withholds publication and retains charged cooldown', function (string $kind): void {
    $world = policyManualWorld($kind);
    [$job, $artifact] = policyManualQueue($world);
    policyManualRun($world, $job, fn () => policyManualEdit($world, ['mode' => 'off']));
    expect($artifact->fresh()->status)->toBe('failed')->and($artifact->fresh()->failure_code)->toBe('policy_changed');
    $receipt = DB::table('ai_usage_attempts')->sole();
    expect($receipt->state)->toBe('failed')->and($receipt->debited_tokens)->toBe(15)
        ->and(CarbonImmutable::parse($receipt->retry_not_before)->greaterThanOrEqualTo(CarbonImmutable::parse('2026-10-16 12:01:00', 'UTC')))->toBeTrue();
})->with(['summary', 'reply', 'knowledge', 'ticket']);

test('Off then On cannot revive a previous ready policy generation', function (string $kind): void {
    $world = policyManualWorld($kind);
    [$job, $artifact] = policyManualQueue($world);
    policyManualRun($world, $job);
    $old = $artifact->fresh()->policy_revision;
    policyManualEdit($world, ['mode' => 'off']);
    policyManualEdit($world);
    expect(app(AiAssistPolicyResolver::class)->isCurrent($world['site']->id, $world['spec'][3], $old))->toBeFalse();
    $this->actingAs($world['agent'])->get(route('dashboard.conversations.'.$world['spec'][2].'.show', $world['conversation']->support_code))->assertOk()->assertDontSee('data-state="ready"', false);
    if ($kind !== 'summary') {
        $this->getJson(route('dashboard.conversations.'.$world['spec'][2].'.use', policyManualUseQuery($world, $artifact)))->assertConflict();
    }
})->with(['summary', 'reply', 'knowledge', 'ticket']);

test('Use rejects changed deleted and newly appended transcript context', function (string $kind, string $change): void {
    $world = policyManualWorld($kind);
    [$job, $artifact] = policyManualQueue($world);
    policyManualRun($world, $job);
    $query = policyManualUseQuery($world, $artifact);
    match ($change) {
        'edit' => $world['message']->update(['body' => 'A different current question.']),
        'delete' => $world['message']->delete(),
        'append' => ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'A new question arrived.']),
    };
    $this->actingAs($world['agent'])->getJson(route('dashboard.conversations.'.$world['spec'][2].'.use', $query))->assertConflict();
})->with(['reply', 'knowledge', 'ticket'])->with(['edit', 'delete', 'append']);

test('Use refuses an unstamped ready artifact or a different generation', function (string $kind): void {
    $world = policyManualWorld($kind);
    [$job, $artifact] = policyManualQueue($world);
    policyManualRun($world, $job);
    $query = policyManualUseQuery($world, $artifact);
    $this->actingAs($world['agent'])->getJson(route('dashboard.conversations.'.$world['spec'][2].'.use', [...$query, 'generation' => (string) Str::uuid()]))->assertConflict();
    $artifact->refresh()->forceFill(['source_context_fingerprint' => null])->save();
    $this->getJson(route('dashboard.conversations.'.$world['spec'][2].'.use', $query))->assertConflict();
})->with(['reply', 'knowledge', 'ticket']);

test('article Use binds the reviewed published same-account article revision', function (string $change): void {
    $world = policyManualWorld('knowledge');
    [$job, $artifact] = policyManualQueue($world);
    policyManualRun($world, $job);
    $query = policyManualUseQuery($world, $artifact);
    if ($change === 'edit') {
        $world['article']->update(['title' => 'Changed title', 'body' => 'New unreviewed instructions.']);
    }
    if ($change === 'unpublish') {
        $world['article']->update(['published_at' => null]);
    }
    if ($change === 'other-account') {
        $foreign = Article::factory()->for(Account::factory())->published()->create();
        $query['article_id'] = $foreign->id;
    }
    $this->actingAs($world['agent'])->getJson(route('dashboard.conversations.copilot-knowledge-suggestion.use', $query))->assertStatus($change === 'other-account' ? 404 : 409);
})->with(['edit', 'unpublish', 'other-account']);

test('a site created after deliberate opt-in starts Off and does not queue support AI', function (): void {
    $world = policyManualWorld('reply');
    $site = Site::factory()->for($world['account'])->create();
    $conversation = Conversation::factory()->for($site)->create();
    ConversationMessage::factory()->for($conversation)->create(['body' => 'A new site question.']);
    Queue::fake();
    $this->actingAs($world['agent'])->post(route('dashboard.conversations.copilot-reply-draft.store', $conversation->support_code))->assertNotFound();
    expect(app(AiAssistPolicyResolver::class)->forSite($site->id)->mode)->toBe('off');
    Queue::assertNothingPushed();
});

test('Use requires freshly granted insertion authority and the current processor revision', function (string $kind, string $change): void {
    $world = policyManualWorld($kind);
    [$job, $artifact] = policyManualQueue($world);
    policyManualRun($world, $job);
    $query = policyManualUseQuery($world, $artifact);
    if ($change === 'permission') {
        $role = CustomRole::factory()->for($world['account'])->create(['permissions' => [AccountPermission::ViewConversations->value]]);
        $world['agent']->forceFill(['account_role' => AccountRole::Agent, 'custom_role_id' => $role->id])->save();
        $world['site']->supportAgents()->attach($world['agent']);
    } else {
        AccountAiConnection::query()->where('account_id', $world['account']->id)->update(['active_revision' => (string) Str::uuid()]);
    }
    $this->actingAs($world['agent'])->getJson(route('dashboard.conversations.'.$world['spec'][2].'.use', $query))->assertStatus($change === 'permission' ? 404 : 409);
})->with(['reply', 'knowledge', 'ticket'])->with(['permission', 'connection']);

function policyManualAssertVisibleStale(string $html, string $kind): void
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    try {
        $document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $notice = $xpath->query('//*[@data-'.$kind.'-stale]')->item(0);
        expect($notice)->not->toBeNull('The visible panel must explain stale source context.')
            ->and($notice->hasAttribute('hidden'))->toBeFalse('A same-ID source edit must reveal the stale notice.');
        if ($kind !== 'copilot-summary') {
            $selector = $kind === 'copilot-knowledge-suggestion' ? 'copilot-knowledge-use' : $kind.'-use';
            $buttons = $xpath->query('//*[@data-'.$selector.']');
            expect($buttons->length)->toBeGreaterThan(0);
            foreach ($buttons as $button) {
                expect($button->hasAttribute('disabled'))->toBeTrue('Stale visible output cannot offer insertion.');
            }
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

test('included source edits are visibly stale even with the same latest message ID', function (string $kind, string $change): void {
    $world = policyManualWorld($kind);
    $latest = ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Retained current checkout context.']);
    [$job, $artifact] = policyManualQueue($world);
    $edit = fn () => $world['message']->update(['body' => 'Revised older included checkout context.']);
    policyManualRun($world, $job, $change === 'during-provider' ? $edit : null);
    if ($change === 'edit') {
        $edit();
    }
    if ($change === 'delete') {
        $world['message']->delete();
    }
    $artifact->refresh();
    expect($artifact->status)->toBe('ready')
        ->and($world['conversation']->messages()->max('id'))->toBe($latest->id)
        ->and($artifact->source_last_message_id)->toBe($latest->id)
        ->and($artifact->isStaleComparedTo($latest->id))->toBeTrue('A same-ID edit/deletion must be caught by the source fingerprint.');
    $fragment = $this->actingAs($world['agent'])->get(route('dashboard.conversations.'.$world['spec'][2].'.show', $world['conversation']->support_code))->assertOk();
    policyManualAssertVisibleStale($fragment->getContent(), $world['spec'][2]);
    $page = $this->get(route('dashboard.conversations.show', $world['conversation']->support_code))->assertOk();
    policyManualAssertVisibleStale($page->getContent(), $world['spec'][2]);
})->with(['summary', 'reply', 'knowledge', 'ticket'])->with(['edit', 'delete', 'during-provider']);

test('unstamped legacy ready artifacts preserve their readable ID based freshness', function (string $kind): void {
    $world = policyManualWorld($kind);
    [$job, $artifact] = policyManualQueue($world);
    policyManualRun($world, $job);
    $artifact->refresh()->forceFill(['source_context_fingerprint' => null])->save();
    $world['message']->update(['body' => 'An old artifact has no source proof.']);
    expect($artifact->isStaleComparedTo($world['message']->id))->toBeFalse()
        ->and($artifact->isStaleComparedTo($world['message']->id + 1))->toBeTrue();
})->with(['summary', 'reply', 'knowledge', 'ticket']);
