<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountAiAssistPolicy;
use App\Models\AccountAiConnection;
use App\Models\Site;
use App\Models\SiteAiAssistPolicy;
use App\Models\User;
use App\Support\Ai\AccountAiConnectionPresenter;
use App\Support\Ai\AccountAiConnectionProbe;
use App\Support\Ai\AccountAiConnections;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Execution\AiExecutionContext;
use App\Support\Ai\Policy\AiAssistPolicies;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Settings\OperatorSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['wayfindr.ai.max_context_characters' => 30000,
        'ai.providers.wayfindr.driver' => 'openai', 'ai.providers.wayfindr.models.text.default' => 'synthetic-model',
        'ai.providers.wayfindr.key' => 'never-browser-installation-key', 'ai.providers.wayfindr.url' => null,
        'ai.providers.wayfindr.openrouter.provider' => '',
        'wayfindr.ai.usage' => ['daily_requests' => 100, 'daily_tokens' => 50000, 'concurrency' => 2, 'max_output_tokens' => 2048],
    ]);
    app(OperatorSettings::class)->captureBaseline();
    app()->instance(AccountAiConnectionProbe::class, new class implements AccountAiConnectionProbe
    {
        public int $calls = 0;

        public string $policy = 'synthetic-policy-v1';

        public ?Closure $duringPolicy = null;

        public function policyRevision(): string
        {
            ($this->duringPolicy ?? fn () => null)();

            return $this->policy;
        }

        public function probe(ResolvedAgentCopilotConnection $connection, ?AiExecutionContext $context = null): AgentCopilotResult
        {
            $this->calls++;

            return new AgentCopilotResult('Synthetic acknowledgement', $connection->provider, $connection->model);
        }
    });
});

function assistPolicyOwner(): User
{
    return User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);
}

function assistPolicyValues(array $changes = []): array
{
    return ['mode' => 'manual', 'summary' => true, 'reply_draft' => true, 'relevant_articles' => false,
        'ticket_details' => true, 'article_context_consent' => false, 'max_context_characters' => null, ...$changes];
}

function assistPolicyConnection(User $owner): void
{
    $service = app(AccountAiConnections::class);
    $record = $service->propose($owner, (int) $owner->account_id, ['source' => 'installation']);
    $service->test($owner, (int) $owner->account_id, $record->proposed_revision);
    $service->activate($owner, (int) $owner->account_id, $record->proposed_revision);
}

function assistPolicySave(User $owner, array $changes = []): AccountAiAssistPolicy
{
    $service = app(AiAssistPolicies::class);

    return $service->saveAccount($owner, (int) $owner->account_id, assistPolicyValues($changes), $service->accountToken((int) $owner->account_id));
}

function assistPolicySiteSave(User $owner, Site $site, array $changes = []): void
{
    $service = app(AiAssistPolicies::class);
    $service->saveSites($owner, (int) $owner->account_id, [$site->id], assistPolicyValues($changes),
        $service->accountToken((int) $owner->account_id), [$site->id => $service->siteToken((int) $site->id)]);
}

test('unseeded upgrade preserves legacy manual tasks and local articles without passage consent', function (): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    $resolver = app(AiAssistPolicyResolver::class);
    $policy = $resolver->forSite((int) $site->id);
    expect(AccountAiAssistPolicy::query()->count())->toBe(0)->and(SiteAiAssistPolicy::query()->count())->toBe(0)
        ->and($policy->explicit)->toBeFalse()->and($policy->mode)->toBe('manual')
        ->and($policy->articleContextAllowed)->toBeFalse()
        ->and($resolver->isCurrent((int) $site->id, 'conversation_summary', null))->toBeTrue();
    foreach (['conversation_summary', 'conversation_reply_draft', 'conversation_knowledge_suggestion', 'conversation_ticket_suggestion'] as $purpose) {
        expect($policy->allows($purpose))->toBeTrue();
    }
    expect($policy->allows('unknown'))->toBeFalse()
        ->and($resolver->isCurrent(999999, 'conversation_summary', null))->toBeFalse();
});

test('explicit enabled policy requires a saved tested connection rather than the inherited installation default', function (): void {
    $owner = assistPolicyOwner();
    Site::factory()->for($owner->account)->create();
    expect(fn () => assistPolicySave($owner))->toThrow(ValidationException::class)
        ->and(AccountAiAssistPolicy::query()->count())->toBe(0);
    $connection = app(AccountAiConnections::class)->propose($owner, (int) $owner->account_id, ['source' => 'installation']);
    app(AccountAiConnections::class)->test($owner, (int) $owner->account_id, $connection->proposed_revision);
    expect(fn () => assistPolicySave($owner))->toThrow(ValidationException::class)
        ->and(AccountAiAssistPolicy::query()->count())->toBe(0);
    app(AccountAiConnections::class)->activate($owner, (int) $owner->account_id, $connection->proposed_revision);
    expect(assistPolicySave($owner)->mode)->toBe('manual');
});

test('first Off opts in without calls initializes existing sites Manual and leaves later sites Off', function (): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    $resolver = app(AiAssistPolicyResolver::class);
    $legacy = app(AgentCopilotConnectionResolver::class)->forAccount((int) $owner->account_id);
    assistPolicySave($owner, ['mode' => 'off']);
    expect(SiteAiAssistPolicy::query()->find($site->id)->mode)->toBe('manual')
        ->and($resolver->forSite((int) $site->id)->mode)->toBe('off')
        ->and($resolver->isCurrent((int) $site->id, 'conversation_summary', null))->toBeFalse()
        ->and(app(AgentCopilotConnectionResolver::class)->isLegacyInstallationDefault($legacy))->toBeFalse()
        ->and(app(AccountAiConnectionProbe::class)->calls)->toBe(0);
    $newSite = Site::factory()->for($owner->account)->create();
    assistPolicyConnection($owner);
    assistPolicySave($owner);
    expect($resolver->forSite((int) $site->id)->mode)->toBe('manual')
        ->and($resolver->forSite((int) $newSite->id)->mode)->toBe('off')
        ->and(SiteAiAssistPolicy::query()->whereKey($newSite->id)->exists())->toBeFalse();
});

test('account and site modes cap Prepare and task masks intersect without changing connection receipts', function (): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    assistPolicyConnection($owner);
    $before = DB::table('account_ai_connections')->sole();
    assistPolicySave($owner, ['mode' => 'prepare', 'summary' => false]);
    $resolver = app(AiAssistPolicyResolver::class);
    expect($resolver->forSite((int) $site->id)->mode)->toBe('manual');
    assistPolicySiteSave($owner, $site, ['mode' => 'prepare', 'reply_draft' => false]);
    $policy = $resolver->forSite((int) $site->id);
    expect($policy->mode)->toBe('prepare')->and($policy->allows('conversation_summary'))->toBeFalse()
        ->and($policy->allows('conversation_reply_draft'))->toBeFalse()->and($policy->allows('conversation_ticket_suggestion'))->toBeTrue();
    assistPolicySave($owner);
    expect($resolver->forSite((int) $site->id)->mode)->toBe('manual');
    assistPolicySiteSave($owner, $site, ['mode' => 'off']);
    expect($resolver->forSite((int) $site->id)->isEnabled())->toBeFalse()
        ->and(DB::table('account_ai_connections')->sole()->active_revision)->toBe($before->active_revision)
        ->and(DB::table('account_ai_connections')->sole()->tested_policy_revision)->toBe($before->tested_policy_revision)
        ->and(app(AccountAiConnectionProbe::class)->calls)->toBe(1);
});

test('shared Relevant articles requires explicit consent and either account or site Off withdraws all article work', function (): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    assistPolicyConnection($owner);
    expect(fn () => assistPolicySave($owner, ['relevant_articles' => true]))->toThrow(ValidationException::class)
        ->and(AccountAiAssistPolicy::query()->count())->toBe(0);
    assistPolicySave($owner, ['relevant_articles' => true, 'article_context_consent' => true]);
    $resolver = app(AiAssistPolicyResolver::class);
    expect($resolver->forSite((int) $site->id)->articleContextAllowed)->toBeTrue();
    $revision = $resolver->forSite((int) $site->id)->revisionFor('conversation_knowledge_suggestion');
    expect(fn () => assistPolicySiteSave($owner, $site, ['relevant_articles' => true]))->toThrow(ValidationException::class);
    assistPolicySiteSave($owner, $site, ['relevant_articles' => false]);
    expect($resolver->forSite((int) $site->id)->allows('conversation_knowledge_suggestion'))->toBeFalse()
        ->and($resolver->forSite((int) $site->id)->articleContextAllowed)->toBeFalse()
        ->and($resolver->isCurrent((int) $site->id, 'conversation_knowledge_suggestion', $revision))->toBeFalse();
    assistPolicySiteSave($owner, $site, ['relevant_articles' => true, 'article_context_consent' => true]);
    assistPolicySave($owner, ['relevant_articles' => false]);
    expect($resolver->forSite((int) $site->id)->allows('conversation_knowledge_suggestion'))->toBeFalse()
        ->and($resolver->forSite((int) $site->id)->articleContextAllowed)->toBeFalse();
});

test('context ceilings inherit and narrow while installation lowering changes effective freshness', function (): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    assistPolicyConnection($owner);
    assistPolicySave($owner, ['max_context_characters' => 12000]);
    assistPolicySiteSave($owner, $site, ['max_context_characters' => 9000]);
    $resolver = app(AiAssistPolicyResolver::class);
    $before = $resolver->forSite((int) $site->id)->revisionFor('conversation_summary');
    expect($resolver->forSite((int) $site->id)->maxContextCharacters)->toBe(9000);
    config(['wayfindr.ai.max_context_characters' => 5000]);
    expect($resolver->forSite((int) $site->id)->maxContextCharacters)->toBe(5000)
        ->and($resolver->isCurrent((int) $site->id, 'conversation_summary', $before))->toBeFalse();
    try {
        assistPolicySiteSave($owner, $site, ['max_context_characters' => 6000]);
        $this->fail('A site context ceiling must never widen its current installation or account ceiling.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('max_context_characters');
    }
});

test('invalid context bounds fail before persistence', function (mixed $limit): void {
    $owner = assistPolicyOwner();
    Site::factory()->for($owner->account)->create();
    expect(fn () => assistPolicySave($owner, ['mode' => 'off', 'max_context_characters' => $limit]))->toThrow(ValidationException::class)
        ->and(AccountAiAssistPolicy::query()->count())->toBe(0);
})->with([0, 999, 30001, -1, 12.5, '1e4', ['bad']]);

test('Off then On never resurrects old output and stale account editors are rejected', function (): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    assistPolicyConnection($owner);
    assistPolicySave($owner);
    $service = app(AiAssistPolicies::class);
    $resolver = app(AiAssistPolicyResolver::class);
    $oldToken = $service->accountToken((int) $owner->account_id);
    $oldRevision = $resolver->forSite((int) $site->id)->revisionFor('conversation_summary');
    assistPolicySave($owner, ['mode' => 'off']);
    assistPolicySave($owner);
    expect($resolver->isCurrent((int) $site->id, 'conversation_summary', $oldRevision))->toBeFalse('UUID policy stamps must prevent Off→On ABA resurrection.')
        ->and(fn () => $service->saveAccount($owner, (int) $owner->account_id, assistPolicyValues(), $oldToken))->toThrow(ValidationException::class);
    $current = AccountAiAssistPolicy::query()->find($owner->account_id);
    expect(assistPolicySave($owner)->revision)->toBe($current->revision, 'An identical policy save must not invalidate running work.');
});

test('bulk writes ignore unselected tokens but validate all selected sites before changing any', function (): void {
    $owner = assistPolicyOwner();
    [$first, $second] = Site::factory()->count(2)->for($owner->account)->create()->all();
    assistPolicyConnection($owner);
    assistPolicySave($owner);
    $service = app(AiAssistPolicies::class);
    $tokens = [$first->id => $service->siteToken((int) $first->id), $second->id => $service->siteToken((int) $second->id)];
    assistPolicySiteSave($owner, $second, ['mode' => 'off']);
    expect(fn () => $service->saveSites($owner, (int) $owner->account_id, [$first->id, $second->id], assistPolicyValues(['mode' => 'off']),
        $service->accountToken((int) $owner->account_id), $tokens))->toThrow(ValidationException::class)
        ->and(SiteAiAssistPolicy::query()->find($first->id)->mode)->toBe('manual', 'A stale selected site must roll back the whole bulk operation.');
    $service->saveSites($owner, (int) $owner->account_id, [$first->id], assistPolicyValues(['mode' => 'off']),
        $service->accountToken((int) $owner->account_id), $tokens);
    expect(SiteAiAssistPolicy::query()->find($first->id)->mode)->toBe('off');
});

test('site writes require prior account opt-in and reject foreign or inaccessible scope', function (): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    $foreign = Site::factory()->create();
    expect(fn () => assistPolicySiteSave($owner, $site, ['mode' => 'off']))->toThrow(ValidationException::class);
    assistPolicySave($owner, ['mode' => 'off']);
    $service = app(AiAssistPolicies::class);
    expect(fn () => $service->saveSites($owner, (int) $owner->account_id, [$site->id, $foreign->id], assistPolicyValues(['mode' => 'off']),
        $service->accountToken((int) $owner->account_id), []))->toThrow(AuthorizationException::class);
    $other = User::factory()->for($owner->account)->create();
    $site->supportAgents()->sync([$other->id]);
    expect(fn () => assistPolicySiteSave($owner, $site, ['mode' => 'off']))->toThrow(AuthorizationException::class);
});

test('fresh actor authority defeats deactivated or wrong-account callers', function (): void {
    $owner = assistPolicyOwner();
    $service = app(AiAssistPolicies::class);
    $token = $service->accountToken((int) $owner->account_id);
    $owner->forceFill(['deactivated_at' => now()])->save();
    expect(fn () => $service->saveAccount($owner, (int) $owner->account_id, assistPolicyValues(['mode' => 'off']), $token))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->present($owner))->toThrow(AuthorizationException::class);
    $other = assistPolicyOwner();
    expect(fn () => $service->saveAccount($other, (int) $owner->account_id, assistPolicyValues(['mode' => 'off']), $token))->toThrow(AuthorizationException::class);
    $viewer = User::factory()->for($other->account)->create(['account_role' => AccountRole::Agent]);
    expect(fn () => $service->present($viewer))->toThrow(AuthorizationException::class);
});

test('account writes require fresh access to current and future enabled sites', function (): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    $other = User::factory()->for($owner->account)->create();
    $site->supportAgents()->sync([$other->id]);
    expect(fn () => assistPolicySave($owner, ['mode' => 'off']))->toThrow(AuthorizationException::class);
    $site->supportAgents()->sync([$owner->id]);
    assistPolicySave($owner, ['mode' => 'off']);
    assistPolicyConnection($owner);
    $site->supportAgents()->sync([$other->id]);
    expect(fn () => assistPolicySave($owner))->toThrow(AuthorizationException::class)
        ->and(AccountAiAssistPolicy::query()->find($owner->account_id)->mode)->toBe('off');
});

test('connection activation checks effective enabled sites rather than unrelated disabled sites', function (): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    $other = User::factory()->for($owner->account)->create();
    assistPolicySave($owner, ['mode' => 'off']);
    $site->supportAgents()->sync([$other->id]);
    assistPolicyConnection($owner);
    expect(app(AiAssistPolicies::class)->connectionReady((int) $owner->account_id))->toBeTrue();
});

test('Off site policy can be saved with an unavailable connection while effective enablement cannot', function (): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    assistPolicyConnection($owner);
    assistPolicySave($owner);
    app(AccountAiConnections::class)->setEnabled($owner, (int) $owner->account_id, false);
    assistPolicySiteSave($owner, $site, ['mode' => 'off']);
    expect(fn () => assistPolicySiteSave($owner, $site))->toThrow(ValidationException::class)
        ->and(SiteAiAssistPolicy::query()->find($site->id)->mode)->toBe('off');
});

test('corrupt stored policy fails closed without returning unbounded context', function (string $field, mixed $value): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    assistPolicyConnection($owner);
    assistPolicySave($owner);
    if ($field === 'revision' && DB::getDriverName() === 'pgsql') {
        $revision = AccountAiAssistPolicy::query()->findOrFail($owner->account_id)->revision;
        // PostgreSQL rejects malformed UUIDs before the resolver can read them.
        // A savepoint keeps the outer test transaction usable after rejection.
        expect(fn () => DB::transaction(fn () => DB::table('account_ai_assist_policies')
            ->where('account_id', $owner->account_id)->update([$field => $value])))
            ->toThrow(QueryException::class);
        expect(AccountAiAssistPolicy::query()->findOrFail($owner->account_id)->revision)->toBe($revision);

        return;
    }
    DB::table('account_ai_assist_policies')->where('account_id', $owner->account_id)->update([$field => $value]);
    $policy = app(AiAssistPolicyResolver::class)->forSite((int) $site->id);
    expect($policy->isEnabled())->toBeFalse()->and($policy->articleContextAllowed)->toBeFalse();
})->with([['mode', 'unexpected'], ['revision', 'invalid'], ['max_context_characters', -1], ['max_context_characters', 1000001]]);

test('presentation lists only accessible sites and exposes deliberate connection prerequisite without credentials', function (): void {
    $owner = assistPolicyOwner();
    [$first, $second] = Site::factory()->count(2)->for($owner->account)->create()->all();
    $other = User::factory()->for($owner->account)->create();
    $second->supportAgents()->sync([$other->id]);
    $presented = app(AccountAiConnectionPresenter::class)->present($owner);
    expect($presented['policy']['sites'])->toHaveCount(1)
        ->and($presented['policy']['sites'][0]['site_id'])->toBe($first->id)
        ->and($presented['policy']['can_update_account'])->toBeFalse()
        ->and($presented['policy']['connection_ready'])->toBeFalse()
        ->and(json_encode($presented))->not->toContain('never-browser-installation-key');
});

test('policy HTTP mutations use server account scope and localize validation without external requests', function (): void {
    $owner = assistPolicyOwner();
    $foreign = Account::factory()->create();
    Site::factory()->for($owner->account)->create();
    $service = app(AiAssistPolicies::class);
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.policy.account'), [
        ...assistPolicyValues(['mode' => 'off']), 'policy_token' => $service->accountToken((int) $owner->account_id), 'account_id' => $foreign->id,
    ])->assertOk()->assertJsonPath('assist.policy.explicit', true)->assertJsonPath('assist.policy.account.mode', 'off');
    expect(AccountAiAssistPolicy::query()->whereKey($foreign->id)->exists())->toBeFalse()
        ->and(app(AccountAiConnectionProbe::class)->calls)->toBe(0);
});

test('policy failure old input is type-safe scoped and cannot dirty or leak into the connection form', function (): void {
    $owner = assistPolicyOwner();
    $this->actingAs($owner)->post(route('dashboard.account.ai-assist.policy.account'), [
        'mode' => ['unexpected'], 'policy_token' => ['bad'], 'summary' => ['bad'],
        'max_context_characters' => ['bad'], 'api_key' => 'must-never-flash', 'endpoint' => 'https://user:secret@example.test/?key=secret',
        'editor_token' => str_repeat('f', 64), 'name' => 'not-a-policy-field',
    ])->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors('mode');
    expect(session('_old_input'))->toBe(['_policy_form' => 'account', 'policy_token' => '']);
    $this->get(route('dashboard.account.ai-assist.show'))->assertOk();
});

test('failed account policy tokens never acquire fresh authority while correcting another field', function (mixed $token, bool $present): void {
    $owner = assistPolicyOwner();
    Site::factory()->for($owner->account)->create();
    assistPolicySave($owner, ['mode' => 'off']);
    $before = AccountAiAssistPolicy::query()->find($owner->account_id)->revision;
    $input = [...assistPolicyValues(['mode' => 'off', 'max_context_characters' => 999]), ...($present ? ['policy_token' => $token] : [])];
    $this->actingAs($owner)->post(route('dashboard.account.ai-assist.policy.account'), $input)
        ->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors('policy_token');
    expect(session('_old_input'))->toHaveKey('policy_token')
        ->and(session('_old_input.policy_token'))->toBe('', 'A missing or malformed policy token must remain an explicit failed-authority sentinel.');
    $oldToken = session('_old_input.policy_token');
    $response = $this->get(route('dashboard.account.ai-assist.show'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $hidden = (new DOMXPath($document))->query('//input[@name="policy_token"]');
    expect($hidden->length)->toBe(1)->and($hidden->item(0)->getAttribute('value'))
        ->toBe('', 'A failed browser token must not be replaced by fresh hidden authority.');
    $this->postJson(route('dashboard.account.ai-assist.policy.account'), [...assistPolicyValues(['mode' => 'off']),
        'policy_token' => $oldToken])->assertUnprocessable()->assertJsonValidationErrors('policy_token');
    expect(AccountAiAssistPolicy::query()->find($owner->account_id)->revision)->toBe($before);
})->with([[null, false], [null, true], ['', true], ['invalid', true], [['bad'], true]]);

test('failed selected site tokens retain failed authority instead of falling back to fresh form tokens', function (mixed $accountToken, mixed $siteToken, string $missing): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    assistPolicySave($owner, ['mode' => 'off']);
    $service = app(AiAssistPolicies::class);
    $before = SiteAiAssistPolicy::query()->find($site->id)->revision;
    $input = [...assistPolicyValues(['mode' => 'off', 'max_context_characters' => 1000]), 'site_ids' => [$site->id]];
    if ($missing !== 'account') {
        $input['account_policy_token'] = $accountToken === 'current' ? $service->accountToken((int) $owner->account_id) : $accountToken;
    }
    if ($missing !== 'sites') {
        $input['site_tokens'] = [$site->id => $siteToken === 'current' ? $service->siteToken((int) $site->id) : $siteToken];
    }
    $this->actingAs($owner)->post(route('dashboard.account.ai-assist.policy.sites'), $input)
        ->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors();
    $old = session('_old_input');
    expect($old)->toHaveKey('account_policy_token')->toHaveKey('site_tokens')
        ->and($old['site_tokens'])->toHaveKey($site->id);
    if ($accountToken !== 'current' || $missing === 'account') {
        $expectedAccount = is_string($accountToken) && preg_match('/\A[a-f0-9]{64}\z/', $accountToken) === 1 ? $accountToken : '';
        expect($old['account_policy_token'])->toBe($expectedAccount);
        $this->assertArrayHasKey('account_policy_token', session('errors')->getBag('default')->messages());
    }
    if ($siteToken !== 'current' || $missing === 'sites') {
        $expectedSite = is_string($siteToken) && preg_match('/\A[a-f0-9]{64}\z/', $siteToken) === 1 ? $siteToken : '';
        expect($old['site_tokens'][$site->id])->toBe($expectedSite, 'A selected site with failed authority must not receive a fresh token during redisplay.');
    }
    $response = $this->get(route('dashboard.account.ai-assist.show'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//input[@name="account_policy_token"]')->item(0)->getAttribute('value'))->toBe($old['account_policy_token'])
        ->and($xpath->query('//input[@name="site_tokens['.$site->id.']"]')->item(0)->getAttribute('value'))
        ->toBe($old['site_tokens'][$site->id], 'Failed selected-site tokens must remain exact in the rendered native form.');
    $this->postJson(route('dashboard.account.ai-assist.policy.sites'), [...assistPolicyValues(['mode' => 'off']),
        'account_policy_token' => $old['account_policy_token'], 'site_ids' => [$site->id], 'site_tokens' => $old['site_tokens']])
        ->assertUnprocessable();
    expect(SiteAiAssistPolicy::query()->find($site->id)->revision)->toBe($before);
})->with([
    [null, 'current', 'account'], [null, 'current', 'none'], [['bad'], 'current', 'none'],
    ['current', null, 'sites'], ['current', null, 'none'], ['current', ['bad'], 'none'],
    [str_repeat('a', 64), 'current', 'none'], ['current', str_repeat('b', 64), 'none'],
]);

test('valid native and JSON bulk policy forms preserve nested site arrays and save selected sites only', function (): void {
    $owner = assistPolicyOwner();
    [$first, $second] = Site::factory()->count(2)->for($owner->account)->create()->all();
    assistPolicySave($owner, ['mode' => 'off']);
    $service = app(AiAssistPolicies::class);
    $input = [
        ...assistPolicyValues(['mode' => 'off']),
        'account_policy_token' => $service->accountToken((int) $owner->account_id),
        'site_ids' => [(string) $first->id],
        // Native forms submit every rendered hidden token, selected or not.
        'site_tokens' => [(string) $first->id => $service->siteToken((int) $first->id),
            (string) $second->id => $service->siteToken((int) $second->id)],
    ];
    $this->actingAs($owner)->post(route('dashboard.account.ai-assist.policy.sites'), $input)
        ->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasNoErrors();
    expect(SiteAiAssistPolicy::query()->find($first->id)->mode)->toBe('off', 'A valid native bulk form must save its selected site.')
        ->and(SiteAiAssistPolicy::query()->find($second->id)->mode)->toBe('manual', 'Unselected hidden site tokens must not expand the mutation scope.');
    $input['site_ids'] = [(string) $first->id, (string) $second->id];
    $input['site_tokens'][$first->id] = $service->siteToken((int) $first->id);
    $this->postJson(route('dashboard.account.ai-assist.policy.sites'), $input)
        ->assertOk()->assertJsonPath('assist.policy.sites.1.mode', 'off');
    expect(SiteAiAssistPolicy::query()->find($first->id)->mode)->toBe('off')
        ->and(SiteAiAssistPolicy::query()->find($second->id)->mode)->toBe('off', 'A valid JSON bulk form must preserve and validate its nested site arrays.');
});

function assistPolicySelectProcessor(User $owner, string $model, bool $testOnly = false): void
{
    $service = app(AccountAiConnections::class);
    $record = $service->propose($owner, (int) $owner->account_id, [
        'source' => 'account', 'provider' => 'openai-compatible', 'model' => $model,
        'endpoint' => 'https://models.example.test/v1', 'api_key' => 'synthetic-private-key',
    ]);
    $service->test($owner, (int) $owner->account_id, $record->proposed_revision);
    if (! $testOnly) {
        $service->activate($owner, (int) $owner->account_id, $record->proposed_revision);
    }
}

test('stale account and site policy forms cannot enable a newly selected processor', function (string $scope): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    assistPolicySelectProcessor($owner, 'processor-A');
    assistPolicySave($owner);
    if ($scope === 'account') {
        assistPolicySave($owner, ['mode' => 'off']);
    } else {
        assistPolicySiteSave($owner, $site, ['mode' => 'off']);
    }
    $service = app(AiAssistPolicies::class);
    $reviewed = app(AccountAiConnectionPresenter::class)->present($owner);
    expect($reviewed['active']['model'])->toBe('processor-A');
    assistPolicySelectProcessor($owner, 'processor-B');
    try {
        if ($scope === 'account') {
            $service->saveAccount($owner, (int) $owner->account_id, assistPolicyValues(), $reviewed['policy']['account_token']);
        } else {
            $service->saveSites($owner, (int) $owner->account_id, [$site->id], assistPolicyValues(),
                $service->accountToken((int) $owner->account_id), [$site->id => $reviewed['policy']['sites'][0]['token']]);
        }
        $this->fail('A policy editor that disclosed processor A must not enable processor B.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($scope === 'account' ? 'policy_token' : 'site_tokens');
    }
    expect(app(AiAssistPolicyResolver::class)->forSite((int) $site->id)->mode)->toBe('off');
    if ($scope === 'account') {
        assistPolicySave($owner);
    } else {
        assistPolicySiteSave($owner, $site);
    }
    expect(app(AiAssistPolicyResolver::class)->forSite((int) $site->id)->mode)->toBe('manual')
        ->and(app(AgentCopilotConnectionResolver::class)->forAccount((int) $owner->account_id)->model)->toBe('processor-B');
})->with(['account', 'sites']);

test('unactivated proposals and their tests preserve policy authority for the active processor', function (): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    assistPolicySelectProcessor($owner, 'processor-A');
    assistPolicySave($owner, ['mode' => 'off']);
    $service = app(AiAssistPolicies::class);
    $accountToken = $service->accountToken((int) $owner->account_id);
    $siteToken = $service->siteToken((int) $site->id);
    app(AccountAiConnections::class)->propose($owner, (int) $owner->account_id, [
        'source' => 'account', 'provider' => 'openai-compatible', 'model' => 'processor-B',
        'endpoint' => 'https://models.example.test/v1', 'api_key' => 'synthetic-key-B',
    ]);
    expect($service->accountToken((int) $owner->account_id))->toBe($accountToken)
        ->and($service->siteToken((int) $site->id))->toBe($siteToken);
    $record = AccountAiConnection::query()->where('account_id', $owner->account_id)->sole();
    app(AccountAiConnections::class)->test($owner, (int) $owner->account_id, $record->proposed_revision);
    $presented = app(AccountAiConnectionPresenter::class)->present($owner);
    expect($presented['form']['model'])->toBe('processor-B')->and($presented['active']['model'])->toBe('processor-A')
        ->and($presented['policy']['account_token'])->toBe($accountToken)
        ->and($presented['policy']['sites'][0]['token'])->toBe($siteToken);
    $service->saveAccount($owner, (int) $owner->account_id, assistPolicyValues(), $accountToken);
    expect(app(AgentCopilotConnectionResolver::class)->forAccount((int) $owner->account_id)->model)->toBe('processor-A');
});

test('processor availability and installation or processing policy changes invalidate reviewed policy tokens', function (string $change): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    assistPolicyConnection($owner);
    assistPolicySave($owner, ['mode' => 'off']);
    $service = app(AiAssistPolicies::class);
    $accountToken = $service->accountToken((int) $owner->account_id);
    $siteToken = $service->siteToken((int) $site->id);
    match ($change) {
        'disabled' => app(AccountAiConnections::class)->setEnabled($owner, (int) $owner->account_id, false),
        'disconnected' => app(AccountAiConnections::class)->disconnect($owner, (int) $owner->account_id),
        'installation' => (function (): void {
            config(['ai.providers.wayfindr.models.text.default' => 'new-installation-model']);
            app(OperatorSettings::class)->captureBaseline();
        })(),
        'processing-policy' => app(AccountAiConnectionProbe::class)->policy = 'synthetic-policy-v2',
    };
    expect($service->accountToken((int) $owner->account_id))->not->toBe($accountToken)
        ->and($service->siteToken((int) $site->id))->not->toBe($siteToken)
        ->and(fn () => $service->saveAccount($owner, (int) $owner->account_id, assistPolicyValues(['mode' => 'off']), $accountToken))
        ->toThrow(ValidationException::class);
})->with(['disabled', 'disconnected', 'installation', 'processing-policy']);

test('policy disclosure and tokens capture the same processor after usage collection without reversing ledger locks', function (): void {
    $owner = assistPolicyOwner();
    Site::factory()->for($owner->account)->create();
    assistPolicySelectProcessor($owner, 'processor-A');
    assistPolicySave($owner, ['mode' => 'off']);
    $baselineTransactionLevel = DB::transactionLevel();
    $mutated = false;
    $usageEnteredAt = null;
    DB::listen(function ($query) use ($owner, &$mutated, &$usageEnteredAt): void {
        if (! $mutated && str_contains($query->sql, 'ai_usage_scopes')) {
            $mutated = true;
            $usageEnteredAt = DB::transactionLevel();
            assistPolicySelectProcessor($owner, 'processor-B');
        }
    });
    $presented = app(AccountAiConnectionPresenter::class)->present($owner);
    $policies = app(AiAssistPolicies::class);
    expect($mutated)->toBeTrue('The processor transition must occur during the separated usage read.')
        ->and($usageEnteredAt)->toBe($baselineTransactionLevel + 1, 'Usage must enter its own installation-first transaction outside the Account presentation lock.')
        ->and($presented['active']['model'])->toBe('processor-B', 'The processor caption must share the same snapshot as the policy editor tokens.')
        ->and($presented['policy']['account_token'])->toBe($policies->accountToken((int) $owner->account_id))
        ->and($presented['policy']['sites'][0]['token'])->toBe($policies->siteToken($presented['policy']['sites'][0]['site_id']))
        ->and($presented['policy']['connection_ready'])->toBeTrue();
});

test('usable installation disclosure prefers the captured resolved processor over historical snapshot labels', function (): void {
    $owner = assistPolicyOwner();
    Site::factory()->for($owner->account)->create();
    assistPolicyConnection($owner);
    $record = AccountAiConnection::query()->where('account_id', $owner->account_id)->sole();
    $record->forceFill(['active_configuration' => [...$record->activeConfiguration(), 'provider' => 'historical-provider', 'model' => 'historical-model']])->save();
    $current = app(AgentCopilotConnectionResolver::class)->forAccount((int) $owner->account_id);
    $presented = app(AccountAiConnectionPresenter::class)->present($owner);
    expect($current)->not->toBeNull()->and($presented['active']['provider'])->toBe($current->provider)
        ->and($presented['active']['model'])->toBe($current->model)->and($presented['active']['source'])->toBe($current->source);
});

test('an operator change after active resolution cannot pair an old processor caption with a new policy token', function (): void {
    $owner = assistPolicyOwner();
    $site = Site::factory()->for($owner->account)->create();
    assistPolicyConnection($owner);
    assistPolicySave($owner, ['mode' => 'off']);
    $policies = app(AiAssistPolicies::class);
    $accountToken = $policies->accountToken((int) $owner->account_id);
    $siteToken = $policies->siteToken((int) $site->id);
    $reads = 0;
    $probe = app(AccountAiConnectionProbe::class);
    $probe->duringPolicy = function () use (&$reads): void {
        // The first two reads resolve active processing. The third assesses
        // the editable proposal after the active object has been captured.
        if (++$reads === 3) {
            config(['ai.providers.wayfindr.models.text.default' => 'new-operator-processor']);
            app(OperatorSettings::class)->captureBaseline();
        }
    };
    $presented = app(AccountAiConnectionPresenter::class)->present($owner);
    $probe->duringPolicy = null;
    expect($reads)->toBeGreaterThanOrEqual(3)
        ->and($presented['active']['model'])->toBe('synthetic-model')
        ->and($presented['policy']['account_token'])->toBe($accountToken, 'A captured processor caption must retain its matching policy token.')
        ->and($presented['policy']['sites'][0]['token'])->toBe($siteToken)
        ->and($policies->accountToken((int) $owner->account_id))->not->toBe($accountToken);
});
