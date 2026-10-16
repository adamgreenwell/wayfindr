<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Jobs\GenerateConversationCopilotKnowledgeSuggestion;
use App\Jobs\GenerateConversationCopilotReplyDraft;
use App\Jobs\GenerateConversationCopilotSummary;
use App\Jobs\GenerateConversationCopilotTicketSuggestion;
use App\Models\Account;
use App\Models\AccountAiAssistPolicy;
use App\Models\AccountAiConnection;
use App\Models\Article;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\SiteAiAssistPolicy;
use App\Models\TicketLabel;
use App\Models\User;
use App\Support\Ai\AccountAiConnectionProbe;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Execution\AiGuardedSession;
use App\Support\Ai\Execution\AiRequestAuthority;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\Network\AiHttpResult;
use App\Support\Ai\Preparation\AiAssistPreparation;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

final class AssistPreparationClock implements AiUsageClock
{
    public function __construct(public CarbonImmutable $time) {}

    public function now(): CarbonImmutable
    {
        return $this->time;
    }
}

function assistPreparationWorld(string $purpose = 'conversation_summary', string $mode = 'prepare'): array
{
    config(['wayfindr.ai.usage.daily_requests' => 100, 'wayfindr.ai.usage.daily_tokens' => 1000000,
        'wayfindr.ai.usage.concurrency' => 10, 'wayfindr.ai.usage.max_output_tokens' => 2048]);
    Http::preventStrayRequests();
    Queue::fake();
    $clock = new AssistPreparationClock(CarbonImmutable::parse('2026-10-16 12:00:00', 'UTC'));
    Carbon::setTestNow($clock->time);
    app()->instance(AiUsageClock::class, $clock);
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create(['subject' => 'Synthetic checkout help']);
    $message = ConversationMessage::factory()->for($conversation)->create(['body' => 'Synthetic checkout request.']);
    $article = Article::factory()->for($account)->published()->create(['title' => 'Checkout help', 'body' => 'Synthetic checkout guidance.']);
    $label = TicketLabel::factory()->for($account)->create(['name' => 'Checkout', 'slug' => 'checkout']);
    $connection = AccountAiConnection::query()->forceCreate(['account_id' => $account->id, 'enabled' => true,
        'uses_installation_default' => false, 'active_revision' => (string) Str::uuid(),
        'active_configuration' => ['source' => 'account', 'provider' => 'openai-compatible', 'model' => 'synthetic-A',
            'endpoint' => 'https://models.example.test/v1', 'api_key' => 'synthetic-not-a-secret',
            'processing_policy_revision' => app(AccountAiConnectionProbe::class)->policyRevision()]]);
    $values = ['mode' => $mode, 'summary' => true, 'reply_draft' => true, 'relevant_articles' => true,
        'ticket_details' => true, 'article_context_consent' => true, 'max_context_characters' => null];
    $policy = AccountAiAssistPolicy::query()->forceCreate(['account_id' => $account->id, 'revision' => (string) Str::uuid(), ...$values]);
    SiteAiAssistPolicy::query()->forceCreate(['site_id' => $site->id, 'account_id' => $account->id, 'revision' => (string) Str::uuid(), ...$values]);
    $class = AiSupportUsage::ARTIFACTS[$purpose];
    $workspace = $purpose === 'conversation_ticket_suggestion' ? 'conversion' : 'conversation';
    $job = match ($purpose) {
        'conversation_summary' => GenerateConversationCopilotSummary::class,
        'conversation_reply_draft' => GenerateConversationCopilotReplyDraft::class,
        'conversation_knowledge_suggestion' => GenerateConversationCopilotKnowledgeSuggestion::class,
        'conversation_ticket_suggestion' => GenerateConversationCopilotTicketSuggestion::class,
    };

    return compact('account', 'owner', 'agent', 'site', 'conversation', 'message', 'article', 'label', 'connection', 'policy', 'clock', 'purpose', 'workspace', 'class', 'job');
}

function assistPreparationEnsure(array $world, string $action = 'open', ?User $actor = null): array
{
    return app(AiAssistPreparation::class)->ensure($actor ?? $world['owner'], $world['conversation'], $world['purpose'], $action, $world['workspace']);
}

function assistPreparationAdvance(array $world, int $seconds): void
{
    $world['clock']->time = $world['clock']->time->addSeconds($seconds);
    Carbon::setTestNow($world['clock']->time);
}

final readonly class AssistPreparationSession implements AiGuardedSession
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
        throw new LogicException('Synthetic fixture never sends.');
    }

    public function request(string $method, string $url, array $headers, string $body = ''): AiHttpResult
    {
        throw new LogicException('Synthetic fixture never sends.');
    }

    public function recordResponse(AiHttpResult $receipt, ?int $inputTokens, ?int $outputTokens): void
    {
        throw new LogicException('Synthetic fixture never sends.');
    }

    public function settle(string $reason = 'completed'): void
    {
        ($this->settlement)($reason);
    }

    public function usage(): array
    {
        return ['input' => 3, 'output' => 4];
    }
}

function assistPreparationProvider(?Closure $duringCall = null): AgentCopilotProvider
{
    return new class($duringCall) implements AgentCopilotProvider
    {
        public int $calls = 0;

        public function __construct(private ?Closure $duringCall) {}

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('Synthetic preparation fixture never probes.');
        }

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->calls++;
            $context = $prompt->execution;
            $connection = $prompt->connection;
            if ($context === null || $connection === null) {
                throw new LogicException('Prepared fixture requires guarded execution.');
            }
            AiSupportUsage::assertActualPrompt($connection, $context, $prompt);
            $ledger = app(AiUsageLedger::class);
            $authority = app(AiRequestAuthority::class);
            $claim = $ledger->claim($connection, $context->purpose, $context->attemptId, fn () => $authority->assertAllowed($connection, $context));
            $receipt = $ledger->beginDispatch($connection, $context->purpose, $context->attemptId, $claim, fn () => $authority->assertAllowed($connection, $context));
            $ledger->markUnknown($context->attemptId, $receipt->dispatchNonce);
            $this->duringCall?->__invoke($prompt, $receipt);
            $text = match ($context->purpose) {
                'conversation_summary' => 'Synthetic checkout summary.',
                'conversation_reply_draft' => 'Synthetic reply, reviewed by a human before sending.',
                'conversation_knowledge_suggestion' => '{"queries":["checkout"]}',
                'conversation_ticket_suggestion' => '{"title":"Synthetic checkout follow-up","priority":"normal"}',
            };
            $session = new AssistPreparationSession($connection, fn ($reason) => $ledger->reconcile($context->attemptId, $receipt->dispatchNonce, 3, 4, $reason));

            return new AgentCopilotResult($text, 'synthetic-provider', 'synthetic-A', 3, 4, $session);
        }
    };
}

function assistPreparationRun(array $world, ?AgentCopilotProvider $provider = null): AgentCopilotProvider
{
    $provider ??= assistPreparationProvider();
    app()->instance(AgentCopilotProvider::class, $provider);
    $artifact = $world['class']::query()->sole();
    $job = new $world['job']($artifact->id, $artifact->generation);
    app()->call([$job, 'handle']);

    return $provider;
}
