<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\SiteAiAssistPolicy;
use App\Models\User;
use App\Support\Ai\Policy\AiAssistPolicies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function selectedSitePolicyWorld(): array
{
    Http::preventStrayRequests();
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    [$first, $second] = Site::factory()->count(2)->for($account)->create()->all();
    $service = app(AiAssistPolicies::class);
    $values = ['mode' => 'off', 'summary' => true, 'reply_draft' => true, 'relevant_articles' => false,
        'ticket_details' => true, 'article_context_consent' => false, 'max_context_characters' => null];
    $service->saveAccount($owner, (int) $account->id, $values, $service->accountToken((int) $account->id));
    $input = [...$values, 'account_policy_token' => $service->accountToken((int) $account->id),
        'site_ids' => [(string) $first->id], 'site_tokens' => [
            $first->id => $service->siteToken((int) $first->id), $second->id => $service->siteToken((int) $second->id),
        ]];

    return compact('account', 'owner', 'first', 'second', 'service', 'input');
}

test('a native subset policy form can correct an unrelated field without acquiring unselected tokens', function (string $unselected): void {
    $world = selectedSitePolicyWorld();
    $this->actingAs($world['owner'])->post(route('dashboard.account.ai-assist.policy.sites'), [
        ...$world['input'], 'max_context_characters' => 999,
    ])->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors('max_context_characters');
    $old = session()->getOldInput();
    expect($old['site_tokens'])->toBe([$world['first']->id => $world['input']['site_tokens'][$world['first']->id]],
        'Failure recovery preserves selected reviewed authority without inventing unselected tokens.');
    $page = $this->get(route('dashboard.account.ai-assist.show'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($page->getContent());
    $tokens = [];
    foreach ((new DOMXPath($document))->query('//input[starts-with(@name, "site_tokens[")]') as $control) {
        preg_match('/\Asite_tokens\[([0-9]+)\]\z/', $control->getAttribute('name'), $match);
        $tokens[(int) $match[1]] = $control->getAttribute('value');
    }
    expect($tokens[$world['first']->id])->toBe($world['input']['site_tokens'][$world['first']->id])
        ->and($tokens[$world['second']->id])->toBe('');
    if ($unselected === 'missing') {
        unset($tokens[$world['second']->id]);
    }
    $this->post(route('dashboard.account.ai-assist.policy.sites'), [
        ...$old, 'site_tokens' => $tokens, 'max_context_characters' => 1000,
    ])->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasNoErrors();
    expect(SiteAiAssistPolicy::query()->findOrFail($world['first']->id)->mode)->toBe('off',
        'A corrected selected-site form must succeed despite missing or blank unselected hidden tokens.')
        ->and(SiteAiAssistPolicy::query()->findOrFail($world['first']->id)->max_context_characters)->toBe(1000)
        ->and(SiteAiAssistPolicy::query()->findOrFail($world['second']->id)->mode)->toBe('manual');
})->with(['missing', 'blank']);

test('more than a thousand unselected accessible site tokens cannot block a bounded subset mutation', function (): void {
    $world = selectedSitePolicyWorld();
    $extra = Site::factory()->count(1001)->for($world['account'])->create();
    $tokens = $world['input']['site_tokens'];
    foreach ($extra as $site) {
        $tokens[$site->id] = $world['service']->siteToken((int) $site->id);
    }
    expect(count($tokens))->toBeGreaterThan(1000);
    $this->actingAs($world['owner'])->post(route('dashboard.account.ai-assist.policy.sites'), [
        ...$world['input'], 'site_tokens' => $tokens,
    ])->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasNoErrors();
    expect(SiteAiAssistPolicy::query()->findOrFail($world['first']->id)->mode)->toBe('off',
        'Only selected tokens count toward the bounded mutation scope.')
        ->and(SiteAiAssistPolicy::query()->findOrFail($world['second']->id)->mode)->toBe('manual')
        ->and(SiteAiAssistPolicy::query()->whereIn('site_id', $extra->modelKeys())->count())->toBe(0);
});

test('selected missing blank or malformed tokens still fail closed', function (string $invalid): void {
    $world = selectedSitePolicyWorld();
    $input = $world['input'];
    match ($invalid) {
        'missing' => $input['site_tokens'] = [$world['second']->id => $input['site_tokens'][$world['second']->id]],
        'blank' => $input['site_tokens'][$world['first']->id] = '',
        'null' => $input['site_tokens'][$world['first']->id] = null,
        'malformed' => $input['site_tokens'][$world['first']->id] = 'bad-token',
        'array' => $input['site_tokens'][$world['first']->id] = ['bad-token'],
        'scalar-map' => $input['site_tokens'] = 'bad-map',
    };
    $this->actingAs($world['owner'])->postJson(route('dashboard.account.ai-assist.policy.sites'), $input)
        ->assertUnprocessable()->assertJsonValidationErrors('site_tokens.'.$world['first']->id);
    expect(SiteAiAssistPolicy::query()->findOrFail($world['first']->id)->mode)->toBe('manual')
        ->and(SiteAiAssistPolicy::query()->findOrFail($world['second']->id)->mode)->toBe('manual');
})->with(['missing', 'blank', 'null', 'malformed', 'array', 'scalar-map']);

test('a stale selected token remains stale across a native validation failure', function (): void {
    $world = selectedSitePolicyWorld();
    $stale = str_repeat('a', 64);
    $this->actingAs($world['owner'])->post(route('dashboard.account.ai-assist.policy.sites'), [
        ...$world['input'], 'site_tokens' => [$world['first']->id => $stale], 'max_context_characters' => 999,
    ])->assertRedirect()->assertSessionHasErrors('max_context_characters');
    expect(session('_old_input.site_tokens.'.$world['first']->id))->toBe($stale,
        'A failed mutation must never replace stale selected authority with a fresh token.');
    $this->postJson(route('dashboard.account.ai-assist.policy.sites'), [
        ...$world['input'], 'site_tokens' => [$world['first']->id => $stale], 'max_context_characters' => 1000,
    ])->assertUnprocessable()->assertJsonValidationErrors('site_tokens');
    expect(SiteAiAssistPolicy::query()->findOrFail($world['first']->id)->mode)->toBe('manual');
});

test('more than a thousand selected sites are rejected before any subset changes', function (): void {
    $world = selectedSitePolicyWorld();
    $ids = [$world['first']->id, ...range(10000, 10999)];
    $tokens = array_fill_keys($ids, str_repeat('b', 64));
    $tokens[$world['first']->id] = $world['input']['site_tokens'][$world['first']->id];
    $this->actingAs($world['owner'])->postJson(route('dashboard.account.ai-assist.policy.sites'), [
        ...$world['input'], 'site_ids' => $ids, 'site_tokens' => $tokens,
    ])->assertUnprocessable()->assertJsonValidationErrors('site_ids');
    expect(SiteAiAssistPolicy::query()->findOrFail($world['first']->id)->mode)->toBe('manual')
        ->and(SiteAiAssistPolicy::query()->findOrFail($world['second']->id)->mode)->toBe('manual');
});
