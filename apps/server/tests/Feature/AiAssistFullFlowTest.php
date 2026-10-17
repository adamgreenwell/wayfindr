<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Jobs\GenerateConversationCopilotReplyDraft;
use App\Jobs\GenerateConversationDraftRefinement;
use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Models\AiAssistFeedback;
use App\Models\Article;
use App\Models\Conversation;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\ConversationDraftRefinement;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Support\Ai\AccountAiConnectionPresenter;
use App\Support\Ai\AccountAiConnectionProbe;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Execution\AiExecutionContext;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Settings\OperatorSettings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
require_once __DIR__.'/../Fixtures/draft-refinement.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    config(['ai.providers.wayfindr.key' => '', 'wayfindr.ai.usage.daily_requests' => 100,
        'wayfindr.ai.usage.daily_tokens' => 1000000, 'wayfindr.ai.usage.concurrency' => 10,
        'wayfindr.ai.usage.max_output_tokens' => 2048]);
    app(OperatorSettings::class)->captureBaseline();
    $clock = new AssistPreparationClock(CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC'));
    Carbon::setTestNow($clock->time);
    app()->instance(AiUsageClock::class, $clock);
    app()->instance(AccountAiConnectionProbe::class, new class implements AccountAiConnectionProbe
    {
        public int $calls = 0;

        public function policyRevision(): string
        {
            return 'full-flow-synthetic-v1';
        }

        public function probe(ResolvedAgentCopilotConnection $connection, ?AiExecutionContext $context = null): AgentCopilotResult
        {
            $this->calls++;

            return new AgentCopilotResult('Synthetic acknowledgement', $connection->provider, $connection->model);
        }
    });
});

afterEach(fn () => Carbon::setTestNow());

/** Start without a connection or policy; opt in through the actual admin endpoints. */
function assistFullFlowWorld(string $suffix = 'a'): array
{
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create(['subject' => 'Checkout help']);
    ConversationMessage::factory()->for($conversation)->create(['body' => 'Please explain checkout.']);
    $article = Article::factory()->for($account)->published()->create([
        'title' => 'Checkout help', 'body' => 'Checkout guidance for account '.$suffix.'.',
    ]);
    $t = test()->actingAs($owner);
    $probeCalls = app(AccountAiConnectionProbe::class)->calls;
    $t->get(route('dashboard.account.ai-assist.show'))->assertOk();
    expect(app(AccountAiConnectionProbe::class)->calls)->toBe($probeCalls);
    $t->postJson(route('dashboard.account.ai-assist.enabled'), ['enabled' => false])->assertOk();
    $record = AccountAiConnection::query()->where('account_id', $account->id)->sole();
    $input = ['expected_revision' => null, 'editor_token' => app(AccountAiConnectionPresenter::class)->present($owner)['form']['editor_token'],
        'source' => 'account', 'name' => 'Synthetic connection '.$suffix, 'provider' => 'openai-compatible',
        'model' => 'model-'.$suffix, 'endpoint' => 'https://'.$suffix.'.example.test/v1',
        'api_key' => 'synthetic-key-'.$suffix, 'openrouter_provider' => ''];
    $t->postJson(route('dashboard.account.ai-assist.propose'), $input)->assertOk()->assertJsonPath('assist.state', 'untested');
    $input['expected_revision'] = $record->refresh()->proposed_revision;
    $input['editor_token'] = app(AccountAiConnectionPresenter::class)->present($owner)['form']['editor_token'];
    $input['api_key'] = '';
    $t->postJson(route('dashboard.account.ai-assist.test'), $input)->assertOk()->assertJsonPath('assist.test.can_save', true);
    expect($record->refresh()->active_revision)->toBeNull();
    $t->postJson(route('dashboard.account.ai-assist.activate'), $input)->assertOk()->assertJsonPath('assist.active.enabled', false);
    expect($record->refresh()->active_revision)->toBe($input['expected_revision']);
    Queue::assertNothingPushed();
    $t->postJson(route('dashboard.account.ai-assist.enabled'), ['enabled' => true,
        'editor_token' => app(AccountAiConnectionPresenter::class)->present($owner)['form']['editor_token']])->assertOk();
    $values = ['mode' => 'prepare', 'summary' => false, 'reply_draft' => true, 'relevant_articles' => true,
        'ticket_details' => false, 'article_context_consent' => true];
    $policy = app(AccountAiConnectionPresenter::class)->present($owner)['policy'];
    $t->postJson(route('dashboard.account.ai-assist.policy.account'), $values + ['policy_token' => $policy['account_token']])->assertOk();
    $policy = app(AccountAiConnectionPresenter::class)->present($owner)['policy'];
    $t->postJson(route('dashboard.account.ai-assist.policy.sites'), $values + ['account_policy_token' => $policy['account_token'],
        'site_ids' => [$site->id], 'site_tokens' => [$site->id => $policy['sites'][0]['token']]])->assertOk();
    Queue::assertNothingPushed();
    Http::assertNothingSent();

    return compact('account', 'owner', 'agent', 'site', 'conversation', 'article', 'record');
}

function assistFullFlowRoute(array $w, string $action, array $extra = []): string
{
    return route('dashboard.conversations.assist.'.$action, ['supportCode' => $w['conversation']->support_code, 'task' => 'reply', ...$extra]);
}

function assistFullFlowPrepare(array $w, ?AgentCopilotProvider $provider = null): array
{
    $t = test()->actingAs($w['agent']);
    $t->get(route('dashboard.conversations.show', $w['conversation']->support_code))->assertOk();
    Queue::assertNothingPushed();
    $receipt = $t->postJson(assistFullFlowRoute($w, 'store'), ['action' => 'open', 'workspace' => 'conversation'])
        ->assertOk()->assertJsonPath('state', 'preparing')->json();
    $row = ConversationCopilotReplyDraft::query()->where('conversation_id', $w['conversation']->id)->sole();
    app()->instance(AgentCopilotProvider::class, $provider ?? assistPreparationProvider());
    app()->call([new GenerateConversationCopilotReplyDraft($row->id, $row->generation), 'handle']);

    return $receipt;
}

test('an admin opt-in leads to a source-linked private suggestion refinement and a separate human send', function (): void {
    $w = assistFullFlowWorld();
    $receipt = assistFullFlowPrepare($w);
    $this->actingAs($w['owner'])->postJson(assistFullFlowRoute($w, 'store'), ['action' => 'open', 'workspace' => 'conversation'])
        ->assertOk()->assertJsonPath('generation', $receipt['generation'])->assertJsonPath('queued', false);
    Queue::assertPushed(GenerateConversationCopilotReplyDraft::class, 1);
    $this->actingAs($w['agent']);
    $ready = $this->getJson(assistFullFlowRoute($w, 'show'))->assertOk()->assertJsonPath('state', 'ready')->json();
    $this->get($ready['output']['grounding']['sources'][0]['source_url'])->assertOk()->assertSee('Checkout guidance for account a.');
    expect($ready['output']['text'])->toContain('Checkout guidance for account a.')
        ->and($ready['output']['grounding']['sources'][0]['id'])->toBe($w['article']->id)
        ->and($w['conversation']->messages()->count())->toBe(1)
        ->and(AiAssistFeedback::query()->count())->toBe(0);
    $use = $this->getJson(assistFullFlowRoute($w, 'use', ['generation' => $receipt['generation']]))->assertOk()->json();
    // This endpoint authorizes client insertion; only browser evidence establishes DOM insertion.
    expect($use['output'])->toBe($ready['output'])->and($w['conversation']->messages()->count())->toBe(1);
    $this->postJson(assistFullFlowRoute($w, 'feedback'), ['generation' => $receipt['generation'], 'event' => 'viewed'])->assertOk();
    $this->postJson(assistFullFlowRoute($w, 'feedback'), ['generation' => $receipt['generation'], 'event' => 'used', 'receipt' => $use['feedback_receipt']])->assertOk();
    $revision = (string) Str::uuid();
    $code = $w['conversation']->support_code;
    $grant = $this->postJson(route('dashboard.conversations.draft-refinement.authorize', $code), ['draft_revision' => $revision])->assertCreated()->json();
    $identity = ['generation' => $grant['generation'], 'draft_revision' => $revision];
    $this->postJson(route('dashboard.conversations.draft-refinement.store', $code), $identity + [
        'refinement_draft' => $ready['output']['text'], 'action' => 'clarify',
    ])->assertStatus(202);
    $row = ConversationDraftRefinement::query()->where('generation', $grant['generation'])->sole();
    app()->instance(AgentCopilotProvider::class, refinementLifecycleProvider(output: fn () => json_encode([
        'candidate' => $ready['output']['text'], 'citations' => [], 'limitation' => null,
    ], JSON_THROW_ON_ERROR)));
    app()->call([new GenerateConversationDraftRefinement($row->id, $row->generation), 'handle']);
    $this->getJson(route('dashboard.conversations.draft-refinement.show', ['supportCode' => $code, ...$identity]))
        ->assertOk()->assertJsonPath('state', 'ready');
    $applied = $this->postJson(route('dashboard.conversations.draft-refinement.apply', $code), $identity)
        ->assertOk()->assertJsonPath('state', 'ready')->json();
    expect($row->refresh()->status)->toBe('applied')->and($row->input)->toBeNull()->and($row->preview)->toBeNull()
        ->and($w['conversation']->messages()->count())->toBe(1)->and($w['conversation']->tickets()->count())->toBe(0);
    $this->post(route('dashboard.conversations.messages.store', $code), ['body' => $applied['preview']['candidate']])->assertRedirect();
    expect($w['conversation']->messages()->count())->toBe(2)
        ->and($w['conversation']->messages()->latest('id')->first()->body)->toBe($applied['preview']['candidate']);
    Http::assertNothingSent();
});

test('one worker resolves A then B then A without sharing sources credentials or output', function (): void {
    $a = assistFullFlowWorld();
    $b = assistFullFlowWorld('b');
    $observed = [];
    $provider = assistPreparationProvider(function ($prompt) use (&$observed): void {
        $observed[] = [$prompt->connection->accountId, $prompt->connection->model, $prompt->connection->apiKey(), $prompt->input];
    });
    foreach ([$a, $b, $a] as $w) {
        $this->actingAs($w['agent']);
        $receipt = $this->postJson(assistFullFlowRoute($w, 'store'), ['action' => 'open', 'workspace' => 'conversation'])->assertOk()->json();
        if ($receipt['queued']) {
            $row = ConversationCopilotReplyDraft::query()->where('conversation_id', $w['conversation']->id)->sole();
            app()->instance(AgentCopilotProvider::class, $provider);
            app()->call([new GenerateConversationCopilotReplyDraft($row->id, $row->generation), 'handle']);
        }
        $suffix = $w['account']->id === $a['account']->id ? 'a' : 'b';
        $output = $this->getJson(assistFullFlowRoute($w, 'show'))->assertOk()->assertJsonPath('state', 'ready')->json('output');
        expect($output['text'])->toContain('Checkout guidance for account '.$suffix.'.')
            ->not->toContain('Checkout guidance for account '.($suffix === 'a' ? 'b' : 'a').'.')
            ->and($output['grounding']['sources'][0]['id'])->toBe($w['article']->id);
    }
    expect($provider->calls)->toBe(2)->and($observed[0][0])->toBe($a['account']->id)->and($observed[1][0])->toBe($b['account']->id)
        ->and($observed[0][1])->toBe('model-a')->and($observed[1][1])->toBe('model-b')
        ->and($observed[0][2])->toBe('synthetic-key-a')->and($observed[1][2])->toBe('synthetic-key-b')
        ->and($observed[0][3])->not->toContain('Checkout guidance for account b.')
        ->and($observed[1][3])->not->toContain('Checkout guidance for account a.');
    $this->actingAs($b['agent'])->getJson(assistFullFlowRoute($a, 'show'))->assertNotFound()->assertDontSee('Checkout guidance');
    Http::assertNothingSent();
});

test('a deleted source withdraws a prepared reply while an ordinary manual reply still sends', function (): void {
    $w = assistFullFlowWorld();
    $receipt = assistFullFlowPrepare($w);
    $this->getJson(assistFullFlowRoute($w, 'show'))->assertOk()->assertJsonPath('state', 'ready');
    $w['article']->delete();
    $this->getJson(assistFullFlowRoute($w, 'use', ['generation' => $receipt['generation']]))
        ->assertConflict()->assertJsonPath('output', null);
    $this->post(route('dashboard.conversations.messages.store', $w['conversation']->support_code), ['body' => 'Human written response.'])->assertRedirect();
    expect($w['conversation']->messages()->count())->toBe(2);
    Http::assertNothingSent();
});

test('disabled processing and exhausted allowance preserve ordinary support without queueing a provider call', function (string $failure): void {
    $w = assistFullFlowWorld();
    if ($failure === 'disabled') {
        $this->actingAs($w['owner'])->postJson(route('dashboard.account.ai-assist.enabled'), ['enabled' => false])->assertOk();
    } else {
        $this->actingAs($w['owner'])->putJson(route('dashboard.account.ai-assist.limits'), ['daily_requests' => 0,
            'daily_tokens' => null, 'concurrency' => null, 'max_output_tokens' => null,
            'limits_token' => app(AccountAiConnectionPresenter::class)->present($w['owner'])['limits']['limits_token']])->assertOk();
    }
    $response = $this->actingAs($w['agent'])->postJson(assistFullFlowRoute($w, 'store'), ['action' => 'open', 'workspace' => 'conversation']);
    if ($failure === 'disabled') {
        $response->assertStatus(503)->assertJsonPath('can_use', false);
    } else {
        $response->assertOk()->assertJsonPath('queued', false)->assertJsonPath('can_use', false);
    }
    Queue::assertNotPushed(GenerateConversationCopilotReplyDraft::class);
    $this->post(route('dashboard.conversations.messages.store', $w['conversation']->support_code), ['body' => 'Manual support stays available.'])->assertRedirect();
    expect($w['conversation']->messages()->count())->toBe(2);
    Http::assertNothingSent();
})->with(['disabled', 'budget']);

test('a failed queue admission is terminal on unchanged opens and never blocks a manual reply', function (): void {
    $w = assistFullFlowWorld();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Synthetic queue outage.'));
    $this->actingAs($w['agent'])->postJson(assistFullFlowRoute($w, 'store'), ['action' => 'open', 'workspace' => 'conversation'])
        ->assertOk()->assertJsonPath('state', 'unavailable')->assertJsonPath('queued', false);
    $this->postJson(assistFullFlowRoute($w, 'store'), ['action' => 'open', 'workspace' => 'conversation'])
        ->assertOk()->assertJsonPath('state', 'unavailable')->assertJsonPath('queued', false);
    expect(DB::table('ai_usage_attempts')->sole()->state)->toBe('cancelled');
    // The ordinary message path may dispatch its own non-AI notifications.
    Bus::clearResolvedInstance('bus');
    app()->forgetInstance('bus');
    $this->post(route('dashboard.conversations.messages.store', $w['conversation']->support_code), ['body' => 'Manual reply during queue outage.'])->assertRedirect();
    expect($w['conversation']->messages()->count())->toBe(2);
    Http::assertNothingSent();
});
