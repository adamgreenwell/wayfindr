<?php

declare(strict_types=1);

use App\Jobs\GenerateConversationCopilotKnowledgeSuggestion;
use App\Jobs\GenerateConversationCopilotReplyDraft;
use App\Jobs\GenerateConversationCopilotSummary;
use App\Jobs\GenerateConversationCopilotTicketSuggestion;
use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Models\Article;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationCopilotKnowledgeSuggestion;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\ConversationCopilotSummary;
use App\Models\ConversationCopilotTicketSuggestion;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Support\Ai\AccountAiConnectionProbe;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Execution\AiGuardedSession;
use App\Support\Ai\Network\AiHttpResult;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

final class ManualUsageIntegrationClock implements AiUsageClock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');
    }
}

/** A synthetic processor exercises real accounting, without SDK/network calls. */
final class ManualUsageIntegrationSession implements AiGuardedSession
{
    public function __construct(private ResolvedAgentCopilotConnection $connection, private Closure $settle) {}

    public function connection(): ResolvedAgentCopilotConnection
    {
        return $this->connection;
    }

    public function maxOutputTokens(): int
    {
        return 2048;
    }

    public function dispatch(string $url, array $headers, string $json): AiHttpResult
    {
        throw new LogicException('The manual integration fixture must never send HTTP.');
    }

    public function request(string $method, string $url, array $headers, string $body = ''): AiHttpResult
    {
        throw new LogicException('The manual integration fixture must never send HTTP.');
    }

    public function recordResponse(AiHttpResult $receipt, ?int $inputTokens, ?int $outputTokens): void
    {
        throw new LogicException('The manual integration fixture has no network response.');
    }

    public function settle(string $reason = 'completed'): void
    {
        ($this->settle)($reason);
    }

    public function usage(): array
    {
        return ['input' => null, 'output' => null];
    }
}

function manualUsageSpecification(string $kind): array
{
    return match ($kind) {
        'summary' => [ConversationCopilotSummary::class, GenerateConversationCopilotSummary::class, 'copilot-summary', 'conversation_summary', 'A concise summary.', ' ', 'summary', 'empty_output'],
        'reply' => [ConversationCopilotReplyDraft::class, GenerateConversationCopilotReplyDraft::class, 'copilot-reply-draft', 'conversation_reply_draft', 'A reviewable reply.', ' ', 'draft', 'empty_output'],
        'knowledge' => [ConversationCopilotKnowledgeSuggestion::class, GenerateConversationCopilotKnowledgeSuggestion::class, 'copilot-knowledge-suggestion', 'conversation_knowledge_suggestion', '{"queries":["checkout"]}', 'invalid-json', 'article_ids', 'invalid_output'],
        'ticket' => [ConversationCopilotTicketSuggestion::class, GenerateConversationCopilotTicketSuggestion::class, 'copilot-ticket-suggestion', 'conversation_ticket_suggestion', '{"title":"Checkout needs help","priority":"high"}', 'invalid-json', 'title', 'invalid_output'],
    };
}

function manualUsageWorld(string $kind): array
{
    config(['wayfindr.ai.usage' => [
        'daily_requests' => 100, 'daily_tokens' => 1_000_000, 'concurrency' => 4, 'max_output_tokens' => 2048,
    ]]);
    app()->instance(AiUsageClock::class, new ManualUsageIntegrationClock);
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create();
    ConversationMessage::factory()->for($conversation)->create(['body' => 'Synthetic checkout question.']);
    Article::factory()->for($account)->published()->create(['title' => 'Checkout help']);
    AccountAiConnection::query()->forceCreate([
        'account_id' => $account->id, 'enabled' => true, 'uses_installation_default' => false,
        'active_revision' => (string) Str::uuid(),
        'active_configuration' => [
            'source' => 'account', 'provider' => 'openai-compatible', 'model' => 'synthetic-model',
            'endpoint' => 'https://models.example.test/v1', 'api_key' => 'synthetic-key', 'openrouter_provider' => '',
            'processing_policy_revision' => app(AccountAiConnectionProbe::class)->policyRevision(),
        ],
    ]);
    $spec = manualUsageSpecification($kind);

    return compact('account', 'agent', 'conversation', 'spec');
}

function manualUsagePost(array $world): TestResponse
{
    return test()->actingAs($world['agent'])->post(route('dashboard.conversations.'.$world['spec'][2].'.store', $world['conversation']->support_code));
}

function manualUsageQueue(array $world): array
{
    Queue::fake();
    manualUsagePost($world)->assertRedirect();
    $job = null;
    Queue::assertPushed($world['spec'][1], function ($queued) use (&$job): bool {
        $job = $queued;

        return true;
    });

    return [$job, $world['spec'][0]::query()->sole()];
}

function manualUsageProvider(Closure $generate): AgentCopilotProvider
{
    return new class($generate) implements AgentCopilotProvider
    {
        public function __construct(private Closure $generate) {}

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            return ($this->generate)($prompt);
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('Manual work must never probe a provider.');
        }
    };
}

function manualUsageResult(AgentCopilotPrompt $prompt, string $text, ?int $input, ?int $output, Closure $settled): AgentCopilotResult
{
    if ($prompt->execution === null || $prompt->connection === null) {
        throw new LogicException('The synthetic guarded processor requires an execution identity.');
    }
    $context = $prompt->execution;
    $connection = $prompt->connection;
    $ledger = app(AiUsageLedger::class);
    $claim = $ledger->claim($connection, $context->purpose, $context->attemptId);
    $dispatch = $ledger->beginDispatch($connection, $context->purpose, $context->attemptId, $claim);
    $ledger->markUnknown($context->attemptId, $dispatch->dispatchNonce);
    $session = new ManualUsageIntegrationSession($connection, function (string $reason) use ($ledger, $context, $dispatch, $input, $output, $settled): void {
        $settled($reason);
        $ledger->reconcile($context->attemptId, $dispatch->dispatchNonce, $input, $output, $reason);
    });

    return new AgentCopilotResult($text, 'synthetic-provider', 'synthetic-model', $input, $output, $session);
}

test('guarded manual requests reserve once and coalesce an already pending generation', function (string $kind): void {
    $world = manualUsageWorld($kind);
    [, $artifact] = manualUsageQueue($world);
    expect(DB::table('ai_usage_attempts')->count())->toBe(1, 'A guarded manual request must reserve before it can queue.');
    $attempt = DB::table('ai_usage_attempts')->sole();
    expect($attempt->attempt_id)->toBe($artifact->generation)
        ->and($attempt->purpose)->toBe($world['spec'][3])
        ->and($attempt->state)->toBe('reserved');
    manualUsagePost($world)->assertRedirect();
    Queue::assertPushed($world['spec'][1], 1);
    expect(DB::table('ai_usage_attempts')->count())->toBe(1)
        ->and($artifact->fresh()->generation)->toBe($artifact->generation);
})->with(['summary', 'reply', 'knowledge', 'ticket']);

test('guarded budget refusal cannot queue manual provider work', function (string $kind): void {
    $world = manualUsageWorld($kind);
    config()->set('wayfindr.ai.usage.daily_requests', 0);
    Queue::fake();
    manualUsagePost($world)->assertRedirect();
    Queue::assertNothingPushed();
    expect(DB::table('ai_usage_attempts')->count())->toBe(0)
        ->and($world['spec'][0]::query()->sole()->status)->toBe('failed');
})->with(['summary', 'reply', 'knowledge', 'ticket']);

test('queue failure releases only its captured generation after a competing replacement', function (string $kind): void {
    $world = manualUsageWorld($kind);
    $captured = null;
    $replacement = (string) Str::uuid();
    Bus::shouldReceive('dispatch')->once()->andReturnUsing(function () use ($world, &$captured, $replacement): never {
        $artifact = $world['spec'][0]::query()->sole();
        $captured = $artifact->generation;
        $artifact->forceFill(['generation' => $replacement])->save();
        throw new RuntimeException('synthetic-queue-failure');
    });
    manualUsagePost($world)->assertRedirect();
    $attempt = DB::table('ai_usage_attempts')->sole();
    expect($attempt->attempt_id)->toBe($captured)
        ->and($attempt->state)->toBe('cancelled')
        ->and($attempt->reason)->toBe('queue_failed')
        ->and($world['spec'][0]::query()->sole()->generation)->toBe($replacement)
        ->and($world['spec'][0]::query()->sole()->status)->toBe('pending')
        ->and((int) DB::table('ai_usage_periods')->where('scope_key', 'installation')->value('held_requests'))->toBe(0);
})->with(['summary', 'reply', 'knowledge', 'ticket']);

test('worker preflight revocation releases its reservation without calling a provider', function (string $kind): void {
    $world = manualUsageWorld($kind);
    [$job, $artifact] = manualUsageQueue($world);
    $world['agent']->forceFill(['deactivated_at' => now()])->save();
    app()->instance(AgentCopilotProvider::class, manualUsageProvider(fn () => throw new LogicException('Revoked work must not call a provider.')));
    app()->call([$job, 'handle']);
    expect($artifact->fresh()->failure_code)->toBe('access_revoked')
        ->and(DB::table('ai_usage_attempts')->sole()->state)->toBe('cancelled')
        ->and((int) DB::table('ai_usage_periods')->where('scope_key', 'installation')->value('held_requests'))->toBe(0);
})->with(['summary', 'reply', 'knowledge', 'ticket']);

test('validated manual publication owns the installation mutex before account locks and preserves missing usage', function (string $kind): void {
    $world = manualUsageWorld($kind);
    [$job, $artifact] = manualUsageQueue($world);
    $baseline = DB::transactionLevel();
    $settlements = [];
    $depths = [];
    $statuses = [];
    $execution = null;
    $publicationStarted = false;
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$publicationStarted, &$queries): void {
        if ($publicationStarted) {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        }
    });
    app()->instance(AgentCopilotProvider::class, manualUsageProvider(function (AgentCopilotPrompt $prompt) use ($world, $artifact, &$execution, &$depths, &$statuses, &$settlements, &$publicationStarted): AgentCopilotResult {
        $execution = $prompt->execution;
        $result = manualUsageResult($prompt, $world['spec'][4], null, null, function (string $reason) use ($artifact, &$depths, &$statuses, &$settlements): void {
            $depths[] = DB::transactionLevel();
            $statuses[] = $artifact->fresh()->status;
            $settlements[] = $reason;
        });
        $publicationStarted = true;

        return $result;
    }));
    app()->call([$job, 'handle']);
    $mutex = collect($queries)->search(fn ($query) => str_starts_with(strtolower($query['sql']), 'select') && str_contains($query['sql'], 'ai_usage_scopes') && in_array('installation', $query['bindings'], true));
    $account = collect($queries)->search(fn ($query) => preg_match('/from ["`]?accounts["`]? /', $query['sql']) === 1);
    expect([$execution?->artifactId, $execution?->generation])->toBe([$artifact->id, $artifact->generation], 'The worker must pass the exact reserved execution identity.')
        ->and($settlements)->toBe(['completed'])
        ->and($mutex !== false && $account !== false && $mutex < $account)->toBeTrue('Publication must acquire the installation mutex before any Account lock.')
        ->and($depths)->toBe([$baseline + 1], 'Settlement must share the ledger-owned publication transaction.')
        ->and($statuses)->toBe(['ready'])
        ->and($artifact->fresh()->status)->toBe('ready')
        ->and($artifact->fresh()->prompt_tokens)->toBeNull()
        ->and($artifact->fresh()->completion_tokens)->toBeNull()
        ->and(DB::table('ai_usage_attempts')->sole()->state)->toBe('unknown');
    $audit = AuditEvent::query()->where('action', 'like', 'conversation.ai_%')->where('action', 'like', '%.generated')->sole();
    expect($audit->metadata['prompt_tokens'])->toBeNull()->and($audit->metadata['completion_tokens'])->toBeNull();
})->with(['summary', 'reply', 'knowledge', 'ticket']);

test('invalid manual output retains a failure cooldown even with fully reported usage', function (string $kind): void {
    $world = manualUsageWorld($kind);
    [$job, $artifact] = manualUsageQueue($world);
    app()->instance(AgentCopilotProvider::class, manualUsageProvider(fn (AgentCopilotPrompt $prompt) => manualUsageResult($prompt, $world['spec'][5], 3, 4, fn () => null)));
    app()->call([$job, 'handle']);
    $attempt = DB::table('ai_usage_attempts')->sole();
    expect($artifact->fresh()->failure_code)->toBe($world['spec'][7])
        ->and($attempt->state)->toBe('failed')
        ->and($attempt->reason)->toBe('invalid_output')
        ->and((int) $attempt->debited_tokens)->toBe(7)
        ->and($attempt->retry_not_before)->not->toBeNull();
    manualUsagePost($world)->assertRedirect();
    Queue::assertPushed($world['spec'][1], 1);
    expect(DB::table('ai_usage_attempts')->count())->toBe(1, 'A manual retry must not bypass the failed-output cooldown.');
})->with(['summary', 'reply', 'knowledge', 'ticket']);

test('usage persistence failure withholds otherwise valid manual output', function (string $kind): void {
    $world = manualUsageWorld($kind);
    [$job, $artifact] = manualUsageQueue($world);
    app()->instance(AgentCopilotProvider::class, manualUsageProvider(fn (AgentCopilotPrompt $prompt) => manualUsageResult($prompt, $world['spec'][4], 3, 4, fn () => throw new RuntimeException('synthetic-usage-store-failure'))));
    app()->call([$job, 'handle']);
    expect($artifact->fresh()->status)->toBe('failed')
        ->and($artifact->fresh()->getAttribute($world['spec'][6]))->toBeNull()
        ->and(DB::table('ai_usage_attempts')->sole()->state)->toBe('unknown');
})->with(['summary', 'reply', 'knowledge', 'ticket']);

test('withheld manual publication atomically classifies known usage without another debit', function (string $kind, string $change): void {
    $world = manualUsageWorld($kind);
    [$job, $artifact] = manualUsageQueue($world);
    $baseline = DB::transactionLevel();
    $settlements = [];
    $depths = [];
    app()->instance(AgentCopilotProvider::class, manualUsageProvider(function (AgentCopilotPrompt $prompt) use ($world, $change, &$settlements, &$depths): AgentCopilotResult {
        $result = manualUsageResult($prompt, $world['spec'][4], 3, 4, function (string $reason) use (&$settlements, &$depths): void {
            $settlements[] = $reason;
            $depths[] = DB::transactionLevel();
        });
        if ($change === 'access') {
            $world['agent']->forceFill(['deactivated_at' => now()])->save();
        } else {
            AccountAiConnection::query()->where('account_id', $world['account']->id)->sole()
                ->forceFill(['active_revision' => (string) Str::uuid()])->save();
        }

        return $result;
    }));
    app()->call([$job, 'handle']);
    $attempt = DB::table('ai_usage_attempts')->sole();
    expect($artifact->fresh()->failure_code)->toBe($change === 'access' ? 'access_revoked' : 'connection_changed')
        ->and($artifact->fresh()->getAttribute($world['spec'][6]))->toBeNull()
        ->and($settlements)->toBe(['invalid_output'], 'Withheld publication must classify the provider outcome as failed in the same transaction.')
        ->and($depths)->toBe([$baseline + 1], 'Publication classification must share the ledger-owned transaction.')
        ->and($attempt->state)->toBe('failed')
        ->and($attempt->reason)->toBe('invalid_output')
        ->and((int) $attempt->debited_tokens)->toBe(7)
        ->and(CarbonImmutable::parse($attempt->retry_not_before, 'UTC')->greaterThanOrEqualTo(app(AiUsageClock::class)->now()->addSeconds(60)))->toBeTrue()
        ->and(DB::table('ai_usage_periods')->pluck('charged_requests')->map(fn ($value) => (int) $value)->all())->toBe([1, 1])
        ->and(DB::table('ai_usage_periods')->pluck('charged_tokens')->map(fn ($value) => (int) $value)->all())->toBe([7, 7])
        ->and(DB::table('ai_usage_scopes')->pluck('concurrency_in_use')->map(fn ($value) => (int) $value)->all())->toBe([0, 0]);
})->with(['summary', 'reply', 'knowledge', 'ticket'])->with(['access', 'connection']);
