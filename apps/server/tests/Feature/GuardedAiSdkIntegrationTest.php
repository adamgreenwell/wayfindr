<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Article;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Support\Ai\AccountAiConnectionProbe;
use App\Support\Ai\AccountAiConnections;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\Execution\AiGuardedTransportFactory;
use App\Support\Ai\Execution\AiRequestAuthority;
use App\Support\Ai\GuardedAccountAiConnectionProbe;
use App\Support\Ai\LaravelAgentCopilotProvider;
use App\Support\Ai\Network\AiDnsResolver;
use App\Support\Ai\Network\AiNetworkPolicy;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use App\Support\Ai\Usage\DatabaseAiUsageClock;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\RequestInterface;

uses(RefreshDatabase::class);

function guardedSdkProbeResponse(string $driver, bool $reported): array
{
    $body = ['model' => 'exact-probe-model'] + match ($driver) {
        'openai' => ['id' => 'synthetic-response', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'OK']]]]],
        'gemini' => ['id' => 'synthetic-response', 'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'OK']]]]],
        'anthropic' => ['id' => 'synthetic-response', 'type' => 'message', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'OK']]],
        'ollama' => ['message' => ['role' => 'assistant', 'content' => 'OK'], 'done' => true, 'done_reason' => 'stop'],
        default => ['id' => 'synthetic-response', 'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'OK']]]],
    };
    if (! $reported) {
        return $body;
    }

    return $body + match ($driver) {
        'openai' => ['usage' => ['input_tokens' => 17, 'output_tokens' => 1]],
        'gemini' => ['usage' => ['total_input_tokens' => 17, 'total_output_tokens' => 1, 'total_thought_tokens' => 0]],
        'anthropic' => ['usage' => ['input_tokens' => 17, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0, 'output_tokens' => 1]],
        'ollama' => ['prompt_eval_count' => 17, 'eval_count' => 1],
        default => ['usage' => ['prompt_tokens' => 17, 'completion_tokens' => 1]],
    };
}

/** Real application bindings, accounting and authority; only DNS and wire are fake. */
function guardedSdkProbeWorld(string $driver, bool $reported): array
{
    config(['wayfindr.ai.usage' => ['daily_requests' => 10, 'daily_tokens' => 100_000, 'concurrency' => 2, 'max_output_tokens' => 64]]);
    $dns = new class implements AiDnsResolver
    {
        public int $calls = 0;

        public function resolve(string $host, float $deadline): array
        {
            $this->calls++;

            return ['8.8.8.8'];
        }
    };
    $wire = new class
    {
        public array $requests = [];
    };
    $policy = new AiNetworkPolicy($dns, ['mode' => 'public-only'], 'synthetic-network-key');
    app()->instance(AiNetworkPolicy::class, $policy);
    app()->instance(AiGuardedTransportFactory::class, new AiGuardedTransportFactory(
        $policy, app(AiUsageLedger::class), app(AiRequestAuthority::class),
        function (RequestInterface $request) use ($wire, $driver, $reported) {
            $wire->requests[] = $request;

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'],
                json_encode(guardedSdkProbeResponse($driver, $reported), JSON_THROW_ON_ERROR)));
        },
    ));
    $owner = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($owner->account)->create();
    $conversation = Conversation::factory()->for($site)->create();
    ConversationMessage::factory()->for($conversation)->create(['body' => 'PRIVATE_SUPPORT_MARKER <script>private()</script> private-person@example.test']);
    Article::factory()->for($owner->account)->published()->create(['body' => 'PRIVATE_ARTICLE_MARKER and private-password=secret']);
    $service = app(AccountAiConnections::class);
    $proposal = $service->propose($owner, (int) $owner->account_id, [
        'source' => 'account', 'provider' => $driver, 'model' => 'exact-probe-model',
        'endpoint' => 'https://models.example.test/v1', 'api_key' => 'synthetic-private-key',
        'openrouter_provider' => $driver === 'openrouter' ? 'synthetic-upstream' : '',
    ]);

    return compact('owner', 'service', 'proposal', 'dns', 'wire');
}

test('all six real SDK proposal probes use guarded synthetic requests and reconcile raw usage faithfully', function (string $driver, bool $reported): void {
    $world = guardedSdkProbeWorld($driver, $reported);
    expect(app(AccountAiConnectionProbe::class))->toBeInstanceOf(GuardedAccountAiConnectionProbe::class)
        ->and(app(AgentCopilotProvider::class))->toBeInstanceOf(LaravelAgentCopilotProvider::class)
        ->and(app(AiUsageClock::class))->toBeInstanceOf(DatabaseAiUsageClock::class);
    $tested = $world['service']->test($world['owner'], (int) $world['owner']->account_id, $world['proposal']->proposed_revision);
    expect($tested->test_status)->toBe('ready')->and($tested->tested_revision)->toBe($world['proposal']->proposed_revision)
        ->and($tested->active_revision)->toBeNull()->and($world['dns']->calls)->toBe(1)->and($world['wire']->requests)->toHaveCount(1);
    $request = $world['wire']->requests[0];
    $json = (string) $request->getBody();
    $body = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    $route = match ($driver) {
        'openai' => 'responses', 'gemini' => 'interactions', 'anthropic' => 'messages', 'ollama' => 'api/chat', default => 'chat/completions'
    };
    $cap = match ($driver) {
        'openai' => $body['max_output_tokens'], 'gemini' => $body['generation_config']['max_output_tokens'], 'ollama' => $body['options']['num_predict'], default => $body['max_tokens']
    };
    $sample = match ($driver) {
        'openai' => $body['input'][1]['content'][0]['text'],
        'gemini' => $body['input'][0]['content'][0]['text'],
        'anthropic' => $body['messages'][0]['content'][0]['text'],
        default => $body['messages'][1]['content'],
    };
    expect($request->getMethod())->toBe('POST')->and((string) $request->getUri())->toBe('https://models.example.test/v1/'.$route)
        ->and($body['model'])->toBe('exact-probe-model')->and($cap)->toBe(64)
        ->and($sample)->toBe('Wayfindr synthetic connection check. No customer, visitor, conversation or article data is included.')
        ->and(array_key_exists('tools', $body))->toBeFalse()->and($body['stream'] ?? false)->toBeFalse()
        ->and($json)->not->toContain('PRIVATE_SUPPORT_MARKER', 'PRIVATE_ARTICLE_MARKER', '<script>', 'private-person@example.test',
            'private-password', 'synthetic-private-key', 'models.example.test');
    if (in_array($driver, ['openai', 'gemini'], true)) {
        expect($body['store'])->toBeFalse();
    }
    if ($driver === 'openrouter') {
        expect($body['provider'])->toBe(['order' => ['synthetic-upstream'], 'allow_fallbacks' => false, 'zdr' => true, 'require_parameters' => true]);
    }
    $header = match ($driver) {
        'anthropic' => 'x-api-key', 'gemini' => 'x-goog-api-key', default => 'Authorization'
    };
    expect($request->getHeaderLine($header))->toBe(in_array($driver, ['anthropic', 'gemini'], true) ? 'synthetic-private-key' : 'Bearer synthetic-private-key');
    $attempt = DB::table('ai_usage_attempts')->sole();
    $expectedDebit = $reported ? 18 : strlen($json) + 1_024 + 64;
    expect($attempt->state)->toBe($reported ? 'completed' : 'unknown')
        ->and($attempt->reported_input_tokens === null ? null : (int) $attempt->reported_input_tokens)->toBe($reported ? 17 : null)
        ->and($attempt->reported_output_tokens === null ? null : (int) $attempt->reported_output_tokens)->toBe($reported ? 1 : null)
        ->and((int) $attempt->debited_tokens)->toBe($expectedDebit)
        ->and((bool) $attempt->concurrency_held)->toBe(! $reported)
        ->and((int) $attempt->input_token_bound)->toBe(strlen($json) + 1_024)
        ->and(json_encode((array) $attempt, JSON_THROW_ON_ERROR))->not->toContain('synthetic-private-key', 'models.example.test', 'PRIVATE_SUPPORT_MARKER', 'PRIVATE_ARTICLE_MARKER');
    $summary = app(AiUsageLedger::class)->summary((int) $world['owner']->account_id);
    foreach (['installation', 'account'] as $scope) {
        expect($summary[$scope])->toBe(['held_requests' => 0, 'held_tokens' => 0, 'charged_requests' => 1, 'charged_tokens' => $expectedDebit, 'concurrency' => $reported ? 0 : 1]);
    }
})->with(['openai', 'gemini', 'anthropic', 'ollama', 'openai-compatible', 'openrouter'])->with([true, false]);
