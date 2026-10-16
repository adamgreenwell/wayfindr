<?php

declare(strict_types=1);

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Article;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Ai\AccountAiConnections;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\CopilotAgent;
use App\Support\Ai\Execution\AiExecutionContext;
use App\Support\Ai\Execution\AiGuardedTransportFactory;
use App\Support\Ai\Execution\AiProviderRequestRunner;
use App\Support\Ai\Execution\AiRequestAuthority;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\Network\AiDnsResolver;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiNetworkPolicy;
use App\Support\Ai\Policy\AiAssistPolicies;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function executionBoundaryWorld(bool $activate = true): array
{
    config(['wayfindr.ai.usage' => ['daily_requests' => 100, 'daily_tokens' => 1_000_000, 'concurrency' => 4, 'max_output_tokens' => 2_048]]);
    $clock = new class implements AiUsageClock
    {
        public CarbonImmutable $now;

        public bool $advanceAfterUnknown = false;

        public function __construct()
        {
            $this->now = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');
        }

        public function now(): CarbonImmutable
        {
            return $this->advanceAfterUnknown && DB::table('ai_usage_attempts')->where('state', 'unknown')->exists() ? $this->now->addSeconds(10) : $this->now;
        }
    };
    app()->instance(AiUsageClock::class, $clock);
    $dns = new class implements AiDnsResolver
    {
        public int $calls = 0;

        public ?Closure $during = null;

        public function resolve(string $host, float $deadline): array
        {
            $this->calls++;
            if ($this->during !== null) {
                ($this->during)();
            }

            return ['8.8.8.8'];
        }
    };
    $wire = new class
    {
        public array $requests = [];

        public int $status = 200;

        public array $headers = [];

        public ?array $usage = ['prompt_tokens' => 2, 'completion_tokens' => 1];

        public string $model = 'synthetic-model';

        public ?Closure $during = null;
    };
    $policy = new AiNetworkPolicy($dns, ['mode' => 'public-only'], 'synthetic-network-key');
    app()->instance(AiNetworkPolicy::class, $policy);
    app()->instance(AiGuardedTransportFactory::class, new AiGuardedTransportFactory($policy, app(AiUsageLedger::class), app(AiRequestAuthority::class),
        function ($request) use ($wire) {
            $wire->requests[] = $request;
            if ($wire->during !== null) {
                ($wire->during)();
            }
            $data = ['id' => 'synthetic', 'model' => $wire->model,
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']]];
            if ($wire->usage !== null) {
                $data['usage'] = $wire->usage;
            }

            return Create::promiseFor(new Response($wire->status, $wire->headers, json_encode($data, JSON_THROW_ON_ERROR)));
        }));
    $owner = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);
    $service = app(AccountAiConnections::class);
    $proposal = $service->propose($owner, $owner->account_id, ['source' => 'account', 'provider' => 'openai-compatible',
        'model' => 'synthetic-model', 'endpoint' => 'https://models.example.test/v1', 'api_key' => 'synthetic-private-key']);
    if ($activate) {
        expect($service->test($owner, $owner->account_id, $proposal->proposed_revision)->test_status)->toBe('ready');
        $service->activate($owner, $owner->account_id, $proposal->proposed_revision);
        $wire->requests = [];
        $dns->calls = 0;
    }

    return compact('owner', 'service', 'proposal', 'dns', 'wire', 'clock');
}

function executionBoundarySupport(array $world, string $purpose = 'conversation_summary', bool $explicitPolicy = false): array
{
    $site = Site::factory()->for($world['owner']->account)->create();
    $conversation = Conversation::factory()->for($site)->create();
    $message = ConversationMessage::factory()->for($conversation)->create(['body' => 'Synthetic customer question B.']);
    Article::factory()->for($world['owner']->account)->published()->create();
    if ($explicitPolicy) {
        $policies = app(AiAssistPolicies::class);
        $policies->saveAccount($world['owner'], $world['owner']->account_id, executionPolicyInput(),
            $policies->accountToken($world['owner']->account_id));
    }
    $connection = app(AgentCopilotConnectionResolver::class)->forAccount($world['owner']->account_id);
    $class = AiSupportUsage::ARTIFACTS[$purpose];
    $artifact = $class::query()->forceCreate(['conversation_id' => $conversation->id, 'requested_by_id' => $world['owner']->id,
        'generation' => (string) Str::uuid(), 'connection_revision' => $connection->revision,
        'policy_revision' => $explicitPolicy ? app(AiAssistPolicyResolver::class)->forSite($site->id)->revisionFor($purpose) : null,
        'status' => 'pending', 'requested_at' => now()]);
    app(AiSupportUsage::class)->reserveFor($artifact);
    $artifact->forceFill(['status' => 'running', 'started_at' => now()])->save();
    $artifact->load('conversation.site');
    $prompt = AiSupportUsage::promptFor($artifact)->withExecution($connection, app(AiSupportUsage::class)->contextFor($artifact));

    return compact('artifact', 'prompt', 'message', 'conversation', 'site', 'connection');
}

function executionBoundaryReason(Closure $operation, string $reason): void
{
    try {
        $operation();
        test()->fail('Expected guarded refusal.');
    } catch (AiNetworkException $exception) {
        expect($exception->reason)->toBe($reason)->and($exception->getPrevious())->toBeNull();
    }
}

test('wire admission rechecks each capability after DNS before sending private text', function (string $purpose): void {
    $world = executionBoundaryWorld();
    ['artifact' => $artifact, 'prompt' => $prompt] = executionBoundarySupport($world, $purpose);
    $role = CustomRole::factory()->for($world['owner']->account)->create(['permissions' => [AccountPermission::ViewConversations->value]]);
    $world['dns']->during = function () use ($world, $role, $purpose): void {
        $world['owner']->forceFill($purpose === 'conversation_summary' ? ['deactivated_at' => now()] : ['custom_role_id' => $role->id])->save();
    };
    executionBoundaryReason(fn () => app(AgentCopilotProvider::class)->generate($prompt), 'access_revoked');
    expect($world['dns']->calls)->toBe(1)->and($world['wire']->requests)->toHaveCount(0)
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $artifact->generation)->value('state'))->toBe('cancelled');
})->with(array_keys(AiSupportUsage::ARTIFACTS));

test('source activity and capability eligibility changes during DNS withdraw queued text', function (string $change): void {
    $world = executionBoundaryWorld();
    $purpose = $change === 'article' ? 'conversation_knowledge_suggestion' : ($change === 'ticket' ? 'conversation_ticket_suggestion' : 'conversation_summary');
    $support = executionBoundarySupport($world, $purpose);
    $world['dns']->during = function () use ($change, $support, $world): void {
        match ($change) {
            'article' => Article::query()->where('account_id', $world['owner']->account_id)->update(['published_at' => null]),
            'ticket' => Ticket::factory()->for($support['conversation'])->for($support['site'])->create(),
            default => ConversationMessage::factory()->for($support['conversation'])->create(['body' => 'New synthetic context.']),
        };
    };
    executionBoundaryReason(fn () => app(AgentCopilotProvider::class)->generate($support['prompt']), 'context_changed');
    expect($world['wire']->requests)->toHaveCount(0);
})->with(['message', 'article', 'ticket']);

test('actual prompt binding rejects forged text and ABA source edits before DNS', function (bool $aba): void {
    $world = executionBoundaryWorld();
    $support = executionBoundarySupport($world);
    $prompt = $support['prompt'];
    if ($aba) {
        $support['message']->update(['body' => 'Different synthetic source A.']);
        $artifact = $support['artifact']->fresh()->load('conversation.site');
        $prompt = AiSupportUsage::promptFor($artifact)->withExecution($support['connection'], $prompt->execution);
        $support['message']->update(['body' => 'Synthetic customer question B.']);
    } else {
        $prompt = (new AgentCopilotPrompt($prompt->purpose, 'Different instructions.', 'Different private text.'))->withExecution($support['connection'], $prompt->execution);
    }
    executionBoundaryReason(fn () => app(AgentCopilotProvider::class)->generate($prompt), 'context_changed');
    expect($world['dns']->calls)->toBe(0)->and($world['wire']->requests)->toHaveCount(0)
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $support['artifact']->generation)->value('state'))->toBe('cancelled');
})->with([true, false]);

test('global SDK and HTTP fakes cannot replace an authorized guarded account request', function (): void {
    $world = executionBoundaryWorld();
    $support = executionBoundarySupport($world);
    CopilotAgent::fake(['Unadmitted fake output.']);
    Http::fake(['*' => Http::response('Unadmitted HTTP output.')]);
    $result = app(AgentCopilotProvider::class)->generate($support['prompt']);
    expect($result->text)->toBe('OK')->and($world['wire']->requests)->toHaveCount(1);
    Http::assertNothingSent();
    $result->settle();
});

test('provider controlled model labels cannot enter result provenance', function (): void {
    $world = executionBoundaryWorld();
    $support = executionBoundarySupport($world);
    $world['wire']->model = 'echoed-synthetic-private-key';
    $result = app(AgentCopilotProvider::class)->generate($support['prompt']);
    expect($result->model)->toBe('synthetic-model');
    $result->settle();
});

test('scoped sessions independently reject missing output caps before DNS', function (): void {
    $world = executionBoundaryWorld();
    $support = executionBoundarySupport($world);
    $session = app(AiProviderRequestRunner::class)->session($support['connection'], $support['prompt']->execution);
    executionBoundaryReason(fn () => $session->dispatch('https://models.example.test/v1/chat/completions', [],
        '{"model":"synthetic-model","messages":[{"role":"user","content":"hi"}]}'), 'invalid_request');
    expect($world['dns']->calls)->toBe(0)->and($world['wire']->requests)->toHaveCount(0);
});

test('provider retry advice keeps its full numeric or absolute deadline', function (string $header): void {
    $world = executionBoundaryWorld(false);
    $world['wire']->status = 429;
    $world['wire']->headers = ['Retry-After' => $header];
    $result = $world['service']->test($world['owner'], $world['owner']->account_id, $world['proposal']->proposed_revision);
    expect($result->test_status)->toBe('unavailable')->and($result->test_failure_code)->toBe('provider_rate_limited');
    $attempt = DB::table('ai_usage_attempts')->sole();
    expect(CarbonImmutable::parse($attempt->retry_not_before, 'UTC')->getTimestamp())->toBe($world['clock']->now->addMinutes(15)->getTimestamp());
    $world['service']->test($world['owner'], $world['owner']->account_id, $world['proposal']->proposed_revision);
    expect($world['wire']->requests)->toHaveCount(1);
})->with(['900', 'Thu, 15 Oct 2026 12:15:00 GMT']);

test('partial observed overruns increase conservative charges and never become free usage', function (array $usage): void {
    $world = executionBoundaryWorld(false);
    $world['wire']->usage = $usage;
    $world['service']->test($world['owner'], $world['owner']->account_id, $world['proposal']->proposed_revision);
    $attempt = DB::table('ai_usage_attempts')->sole();
    expect($attempt->state)->toBe('unknown')->and((int) $attempt->debited_tokens)->toBeGreaterThanOrEqual(10_000)
        ->and($attempt->retry_not_before)->not->toBeNull();
})->with([[['prompt_tokens' => 10_000]], [['completion_tokens' => 10_000]]]);

test('delayed advice parsing cannot shorten an absolute provider retry deadline', function (): void {
    $world = executionBoundaryWorld(false);
    $world['clock']->advanceAfterUnknown = true;
    $world['wire']->status = 429;
    $world['wire']->headers = ['Retry-After' => 'Thu, 15 Oct 2026 12:15:00 GMT'];
    $world['service']->test($world['owner'], $world['owner']->account_id, $world['proposal']->proposed_revision);
    expect(CarbonImmutable::parse(DB::table('ai_usage_attempts')->sole()->retry_not_before, 'UTC')->getTimestamp())
        ->toBe(CarbonImmutable::parse('2026-10-15 12:15:00', 'UTC')->getTimestamp());
});

test('a second authorized admin cannot bypass the same proposed connection cooldown', function (): void {
    $world = executionBoundaryWorld(false);
    $world['wire']->status = 429;
    $world['wire']->headers = ['Retry-After' => '900'];
    $world['service']->test($world['owner'], $world['owner']->account_id, $world['proposal']->proposed_revision);
    $other = User::factory()->for($world['owner']->account)->create(['account_role' => AccountRole::Admin]);
    $result = $world['service']->test($other, $other->account_id, $world['proposal']->proposed_revision);
    expect($result->test_failure_code)->toBe('usage_retry_cooldown')->and($world['wire']->requests)->toHaveCount(1)
        ->and(DB::table('ai_usage_attempts')->count())->toBe(1);
});

test('proposal execution identities cannot turn connection tests into support data calls', function (): void {
    $world = executionBoundaryWorld(false);
    $generation = (string) Str::uuid();
    $world['proposal']->forceFill(['test_status' => 'running', 'test_generation' => $generation])->save();
    $connection = app(AgentCopilotConnectionResolver::class)->candidate($world['owner']->account_id,
        $world['proposal']->proposed_revision, $world['proposal']->proposedConfiguration());
    $context = AiExecutionContext::probe($world['owner']->account_id, $world['owner']->id,
        $world['proposal']->proposed_revision, $generation);
    $prompt = (new AgentCopilotPrompt('configuration_probe', 'Different instructions.', 'Synthetic support text that does not belong in a probe.'))->withExecution($connection, $context);
    executionBoundaryReason(fn () => app(AgentCopilotProvider::class)->generate($prompt), 'probe_context_required');
    expect($world['dns']->calls)->toBe(0)->and($world['wire']->requests)->toHaveCount(0);
});

function executionPolicyInput(array $changes = []): array
{
    return [
        'mode' => 'manual', 'summary' => true, 'reply_draft' => true,
        'relevant_articles' => true, 'ticket_details' => true,
        'article_context_consent' => true, 'max_context_characters' => null,
        ...$changes,
    ];
}

test('policy revocation during DNS prevents every support capability from transmitting', function (string $purpose): void {
    $world = executionBoundaryWorld();
    $support = executionBoundarySupport($world, $purpose, true);
    $world['dns']->during = function () use ($world): void {
        $policies = app(AiAssistPolicies::class);
        $policies->saveAccount($world['owner'], $world['owner']->account_id, executionPolicyInput(['mode' => 'off']),
            $policies->accountToken($world['owner']->account_id));
    };
    executionBoundaryReason(fn () => app(AgentCopilotProvider::class)->generate($support['prompt']), 'access_revoked');
    expect($world['dns']->calls)->toBe(1, 'The revocation must occur between DNS and wire admission.')
        ->and($world['wire']->requests)->toHaveCount(0, 'A committed Off policy must prevent transmission.')
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $support['artifact']->generation)->value('state'))->toBe('cancelled');
})->with(array_keys(AiSupportUsage::ARTIFACTS));

test('an Off On policy cycle cannot revive an in-flight support identity', function (): void {
    $world = executionBoundaryWorld();
    $support = executionBoundarySupport($world, 'conversation_summary', true);
    $world['dns']->during = function () use ($world): void {
        $policies = app(AiAssistPolicies::class);
        foreach (['off', 'manual'] as $mode) {
            $policies->saveAccount($world['owner'], $world['owner']->account_id, executionPolicyInput(['mode' => $mode]),
                $policies->accountToken($world['owner']->account_id));
        }
    };
    executionBoundaryReason(fn () => app(AgentCopilotProvider::class)->generate($support['prompt']), 'access_revoked');
    expect(app(AiAssistPolicyResolver::class)->forSite($support['site']->id)->allows('conversation_summary'))->toBeTrue()
        ->and($world['wire']->requests)->toHaveCount(0, 'Equivalent flags after an Off On cycle are a new authority revision.');
});

test('Relevant articles revocation short circuits article queries at wire admission', function (): void {
    $world = executionBoundaryWorld();
    $support = executionBoundarySupport($world, 'conversation_knowledge_suggestion', true);
    $articleQueries = [];
    $world['dns']->during = function () use ($world, &$articleQueries): void {
        $policies = app(AiAssistPolicies::class);
        $policies->saveAccount($world['owner'], $world['owner']->account_id, executionPolicyInput(['relevant_articles' => false]),
            $policies->accountToken($world['owner']->account_id));
        DB::listen(function ($query) use (&$articleQueries): void {
            if (preg_match('/\barticles\b/i', $query->sql)) {
                $articleQueries[] = $query->sql;
            }
        });
    };
    executionBoundaryReason(fn () => app(AgentCopilotProvider::class)->generate($support['prompt']), 'access_revoked');
    expect($articleQueries)->toBe([], 'Off must precede even AI article existence checks.')
        ->and($world['wire']->requests)->toHaveCount(0);
});

test('account context ceilings bind selected prompt source and actual execution', function (): void {
    $world = executionBoundaryWorld();
    $support = executionBoundarySupport($world, 'conversation_summary', true);
    $policies = app(AiAssistPolicies::class);
    $policies->saveAccount($world['owner'], $world['owner']->account_id, executionPolicyInput(['max_context_characters' => 1000]),
        $policies->accountToken($world['owner']->account_id));
    $support['message']->update(['body' => str_repeat('Bounded synthetic text. ', 500)]);
    $artifact = $support['artifact']->fresh()->load('conversation.site');
    $policy = app(AiAssistPolicyResolver::class)->forSite($support['site']->id);
    $artifact->forceFill(['generation' => (string) Str::uuid(), 'policy_revision' => $policy->revisionFor('conversation_summary'), 'status' => 'pending'])->save();
    app(AiSupportUsage::class)->reserveFor($artifact);
    $artifact->update(['status' => 'running']);
    $prompt = AiSupportUsage::promptFor($artifact)->withExecution($support['connection'], app(AiSupportUsage::class)->contextFor($artifact));
    expect(mb_strlen($prompt->input))->toBeLessThanOrEqual(1000)
        ->and($prompt->execution->maxContextCharacters)->toBe(1000)
        ->and(AiSupportUsage::sourceFingerprint($artifact, $prompt))->toBe(AiSupportUsage::sourceFingerprint($artifact));
    app(AgentCopilotProvider::class)->generate($prompt)->settle();
    expect($world['wire']->requests)->toHaveCount(1);
});
