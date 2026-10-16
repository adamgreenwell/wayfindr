<?php

declare(strict_types=1);

use App\Jobs\GenerateConversationCopilotKnowledgeSuggestion;
use App\Jobs\GenerateConversationCopilotReplyDraft;
use App\Jobs\GenerateConversationCopilotSummary;
use App\Jobs\GenerateConversationCopilotTicketSuggestion;
use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Models\AiAssistPreparationReceipt;
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
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Execution\AiGuardedSession;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\Network\AiHttpResult;
use App\Support\Ai\Network\AiNetworkException;
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
    public CarbonImmutable $time;

    public ?Closure $during = null;

    public function __construct()
    {
        $this->time = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');
    }

    public function now(): CarbonImmutable
    {
        if ($this->during !== null) {
            $during = $this->during;
            $this->during = null;
            $during();
        }

        return $this->time;
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

test('a stale manual generation retires only its equivalent obsolete receipt before replacement', function (string $kind, string $state): void {
    $world = manualUsageWorld($kind);
    config()->set('wayfindr.ai.usage.queue_ttl_seconds', 86_400);
    [$oldJob, $artifact] = manualUsageQueue($world);
    $oldGeneration = $artifact->generation;
    $connection = app(AgentCopilotConnectionResolver::class)->forAccount($world['account']->id);
    $ledger = app(AiUsageLedger::class);
    $dispatch = null;
    if ($state !== 'reserved') {
        $claim = $ledger->claim($connection, $world['spec'][3], $oldGeneration);
        if ($state === 'dispatched') {
            $dispatch = $ledger->beginDispatch($connection, $world['spec'][3], $oldGeneration, $claim);
        }
    }
    $artifact->forceFill([
        'status' => $state === 'reserved' ? 'pending' : 'running',
        'requested_at' => app(AiUsageClock::class)->now()->subMinutes(6), 'started_at' => app(AiUsageClock::class)->now()->subMinutes(6),
    ])->save();
    app(AiUsageClock::class)->time = app(AiUsageClock::class)->now()->addSeconds(361);
    // An independent conversation's current reservation must remain intact.
    $other = $world;
    $other['conversation'] = Conversation::factory()->for($world['conversation']->site)->create();
    ConversationMessage::factory()->for($other['conversation'])->create(['body' => 'Independent synthetic question.']);
    manualUsagePost($other)->assertRedirect();
    $otherArtifact = $other['spec'][0]::query()->where('conversation_id', $other['conversation']->id)->sole();
    $otherBefore = DB::table('ai_usage_attempts')->where('attempt_id', $otherArtifact->generation)->first();
    manualUsagePost($world)->assertRedirect();
    $retired = DB::table('ai_usage_attempts')->where('attempt_id', $oldGeneration)->first();
    $replacement = $artifact->fresh();
    expect($retired->state)->toBe($state === 'dispatched' ? 'unknown' : 'cancelled', 'The stale equivalent receipt must retire without waiting for the configured queue TTL.')
        ->and($retired->reason)->toBe($state === 'dispatched' ? 'dispatch_expired' : 'superseded')
        ->and($replacement->generation === $oldGeneration)->toBe($state === 'dispatched')
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $otherArtifact->generation)->first())->toEqual($otherBefore);
    $providerCalls = 0;
    app()->instance(AgentCopilotProvider::class, manualUsageProvider(function () use (&$providerCalls): never {
        $providerCalls++;
        throw new LogicException('An obsolete generation must never transmit.');
    }));
    app()->call([$oldJob, 'handle']);
    expect($providerCalls)->toBe(0);
    if ($state === 'dispatched') {
        expect($replacement->status)->toBe('running', 'The expired claim remains visible until an admitted replacement; read classifies it unavailable.')
            ->and((bool) $retired->concurrency_held)->toBeFalse('Only the expired dispatch lease is released; its charge stays conservative.')
            ->and((int) $retired->debited_tokens)->toBe((int) $retired->reserved_tokens)
            ->and(CarbonImmutable::parse($retired->retry_not_before, 'UTC')->greaterThanOrEqualTo(app(AiUsageClock::class)->now()->addSeconds(60)))->toBeTrue();
        app(AiUsageClock::class)->time = app(AiUsageClock::class)->now()->addSeconds(61);
        manualUsagePost($world)->assertRedirect();
        $replacement = $artifact->fresh();
        $newReceipt = DB::table('ai_usage_attempts')->where('attempt_id', $replacement->generation)->first();
        expect($replacement->status)->toBe('pending', 'A stale dispatch must unblock after its bounded cooldown without the global expiry sweep.')
            ->and($newReceipt->state)->toBe('reserved')
            ->and((bool) DB::table('ai_usage_attempts')->where('attempt_id', $oldGeneration)->value('concurrency_held'))->toBeFalse();
        $ledger->reconcile($oldGeneration, $dispatch->dispatchNonce, 3, 4, 'invalid_output');
        $period = DB::table('ai_usage_periods')->where('scope_key', 'account:'.$world['account']->id)->sole();
        expect((int) $period->held_requests)->toBe(2)
            ->and((int) $period->held_tokens)->toBe((int) $newReceipt->reserved_tokens + (int) $otherBefore->reserved_tokens, 'A late old response must never refund the new generation reservation.')
            ->and((int) $period->charged_requests)->toBe(1)
            ->and((int) $period->charged_tokens)->toBe(7);
    } else {
        expect($replacement->status)->toBe('pending', 'Known-unsent stale work permits the same requested replacement.')
            ->and(DB::table('ai_usage_attempts')->where('attempt_id', $replacement->generation)->value('state'))->toBe('reserved');
    }
    Queue::assertPushed($world['spec'][1], 3);
})->with(['summary', 'reply', 'knowledge', 'ticket'])->with(['reserved', 'claimed', 'dispatched']);

test('superseded cleanup preserves a newer generation that wins before the ledger lock', function (): void {
    $world = manualUsageWorld('summary');
    [, $artifact] = manualUsageQueue($world);
    $old = DB::table('ai_usage_attempts')->sole();
    $artifact->forceFill(['generation' => (string) Str::uuid()])->save();
    $proposed = $artifact->fresh()->load('conversation.site');
    AiAssistPreparationReceipt::query()->where('generation', $old->attempt_id)->update(['generation' => $proposed->generation]);
    $newer = (string) Str::uuid();
    app(AiUsageClock::class)->during = function () use ($artifact, $newer): void {
        $artifact->forceFill(['generation' => $newer])->save();
    };
    $reason = null;
    try {
        app(AiSupportUsage::class)->reserveFor($proposed);
    } catch (AiNetworkException $exception) {
        $reason = $exception->reason;
    }
    expect($reason)->toBe('access_revoked')
        ->and(DB::table('ai_usage_attempts')->sole())->toEqual($old, 'Cleanup must prove the requesting replacement generation still owns the artifact under the ledger lock.')
        ->and($artifact->fresh()->generation)->toBe($newer)
        ->and($artifact->fresh()->status)->toBe('pending');
});

test('superseded cleanup leaves a current receipt and non-equivalent work untouched', function (): void {
    $world = manualUsageWorld('summary');
    [, $artifact] = manualUsageQueue($world);
    $old = DB::table('ai_usage_attempts')->sole();
    $connection = app(AgentCopilotConnectionResolver::class)->forAccount($world['account']->id);
    $ledger = app(AiUsageLedger::class);
    $predicateCalls = 0;
    $ledger->retireSuperseded($connection, $world['spec'][3], $artifact->generation, str_repeat('a', 64), function () use (&$predicateCalls): bool {
        $predicateCalls++;

        return true;
    });
    $ledger->retireSuperseded($connection, $world['spec'][3], $artifact->generation, $old->work_fingerprint, fn () => false);
    app(AiSupportUsage::class)->reserveFor($artifact);
    expect($predicateCalls)->toBe(0)
        ->and(DB::table('ai_usage_attempts')->sole())->toEqual($old)
        ->and((int) DB::table('ai_usage_periods')->where('scope_key', 'installation')->value('held_requests'))->toBe(1);
});

test('a superseded unknown dispatch keeps its original longer cooldown and charge', function (): void {
    $world = manualUsageWorld('summary');
    [, $artifact] = manualUsageQueue($world);
    $oldGeneration = $artifact->generation;
    $connection = app(AgentCopilotConnectionResolver::class)->forAccount($world['account']->id);
    $ledger = app(AiUsageLedger::class);
    $claim = $ledger->claim($connection, $world['spec'][3], $oldGeneration);
    $dispatch = $ledger->beginDispatch($connection, $world['spec'][3], $oldGeneration, $claim);
    $ledger->reconcile($oldGeneration, $dispatch->dispatchNonce, null, null, 'provider_failed', 900);
    $old = DB::table('ai_usage_attempts')->sole();
    $artifact->forceFill(['status' => 'running', 'started_at' => app(AiUsageClock::class)->now()->subMinutes(6)])->save();
    manualUsagePost($world)->assertRedirect();
    $retired = DB::table('ai_usage_attempts')->sole();
    expect($retired->terminal_at)->toBe($old->terminal_at)
        ->and($retired->retry_not_before)->toBe($old->retry_not_before)
        ->and($retired->debited_tokens)->toBe($old->debited_tokens)
        ->and((bool) $retired->concurrency_held)->toBeTrue();
    app(AiUsageClock::class)->time = app(AiUsageClock::class)->now()->addSeconds(61);
    manualUsagePost($world)->assertRedirect();
    Queue::assertPushed($world['spec'][1], 1);
    expect(DB::table('ai_usage_attempts')->sole()->retry_not_before)->toBe($old->retry_not_before)
        ->and((bool) DB::table('ai_usage_attempts')->sole()->concurrency_held)->toBeFalse();
    app(AiUsageClock::class)->time = app(AiUsageClock::class)->now()->addSeconds(840);
    manualUsagePost($world)->assertRedirect();
    Queue::assertPushed($world['spec'][1], 2);
    expect($artifact->fresh()->status)->toBe('pending')
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $oldGeneration)->value('debited_tokens'))->toBe($old->debited_tokens);
});

test('superseded cleanup never touches a nonlatest equivalent receipt', function (): void {
    $world = manualUsageWorld('summary');
    [, $artifact] = manualUsageQueue($world);
    $connection = app(AgentCopilotConnectionResolver::class)->forAccount($world['account']->id);
    $ledger = app(AiUsageLedger::class);
    $claim = $ledger->claim($connection, $world['spec'][3], $artifact->generation);
    $dispatch = $ledger->beginDispatch($connection, $world['spec'][3], $artifact->generation, $claim);
    $ledger->reconcile($artifact->generation, $dispatch->dispatchNonce, 3, 4);
    $old = DB::table('ai_usage_attempts')->sole();
    $new = $ledger->reserve($connection, $world['spec'][3], (string) Str::uuid(),
        (int) $old->input_token_bound, (int) $old->max_output_tokens, $old->work_fingerprint,
        $world['agent']->id, $artifact->generation);
    $predicateCalls = 0;
    $ledger->retireSuperseded($connection, $world['spec'][3], $artifact->generation, $old->work_fingerprint, function () use (&$predicateCalls): bool {
        $predicateCalls++;

        return true;
    });
    expect($predicateCalls)->toBe(0, 'A stale latest-receipt hint must be rechecked inside the ledger lock.')
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $artifact->generation)->first())->toEqual($old)
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $new->attemptId)->value('state'))->toBe('reserved');
});

test('known-unsent supersession preserves an existing retry boundary', function (): void {
    $world = manualUsageWorld('summary');
    [, $artifact] = manualUsageQueue($world);
    $oldGeneration = $artifact->generation;
    $deadline = app(AiUsageClock::class)->now()->addSeconds(600)->format('Y-m-d H:i:s');
    DB::table('ai_usage_attempts')->where('attempt_id', $oldGeneration)->update(['retry_not_before' => $deadline]);
    $artifact->forceFill(['requested_at' => app(AiUsageClock::class)->now()->subMinutes(6)])->save();
    manualUsagePost($world)->assertRedirect();
    Queue::assertPushed($world['spec'][1], 1);
    $retired = DB::table('ai_usage_attempts')->sole();
    expect($retired->state)->toBe('reserved', 'A refused early refresh must preserve the existing queued hold.')
        ->and($retired->retry_not_before)->toBe($deadline);
    app(AiUsageClock::class)->time = app(AiUsageClock::class)->now()->addSeconds(601);
    manualUsagePost($world)->assertRedirect();
    expect(DB::table('ai_usage_attempts')->where('attempt_id', $oldGeneration)->value('state'))->toBe('cancelled')
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $oldGeneration)->value('retry_not_before'))->toBe($deadline)
        ->and((int) DB::table('ai_usage_periods')->where('scope_key', 'installation')->value('held_requests'))->toBe(1);
    Queue::assertPushed($world['spec'][1], 2);
});
