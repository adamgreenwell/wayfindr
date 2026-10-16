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
use Symfony\Component\Process\Process;

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

test('a legacy array subset policy form can correct an unrelated field without acquiring unselected tokens', function (string $unselected): void {
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
    foreach ((new DOMXPath($document))->query('//input[@data-ai-policy-site]') as $control) {
        [$id, $token] = explode(':', $control->getAttribute('value'), 2);
        $tokens[(int) $id] = $token;
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

/** Serialize actual successful rendered controls in their DOM order, as a native browser does. */
function selectedSiteNativeBody(string $html, array $selectedIds, string $mode = 'off', string $context = '1000'): string
{
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $form = $xpath->query('//form[contains(@action, "/policy/sites")]')->item(0);
    expect($form)->not->toBeNull();
    $pairs = [];
    foreach ($xpath->query('.//input', $form) as $control) {
        $name = $control->getAttribute('name');
        if ($name === '' || $control->hasAttribute('disabled')) {
            continue;
        }
        $type = $control->getAttribute('type');
        $value = $control->getAttribute('value');
        if ($type === 'radio' && $value !== $mode) {
            continue;
        }
        if ($type === 'checkbox') {
            if ($control->hasAttribute('data-ai-policy-site')) {
                $id = (int) explode(':', $value, 2)[0];
                if (! in_array($id, $selectedIds, true)) {
                    continue;
                }
            } elseif (! $control->hasAttribute('checked')) {
                continue;
            }
        }
        if ($name === 'max_context_characters') {
            $value = $context;
        }
        $pairs[] = rawurlencode($name).'='.rawurlencode($value);
    }
    expect(rawurldecode($pairs[count($pairs) - 1]))->toBe('site_selections_complete=1', 'The completion marker must be the last successful native control.');

    return implode('&', $pairs);
}

/** Exercise PHP's actual default parser cap, rather than Laravel's pre-parsed test arrays. */
function selectedSiteParseNative(string $body): array
{
    $process = new Process([PHP_BINARY, '-n', '-d', 'display_errors=0', '-d', 'log_errors=1', '-r',
        'parse_str(stream_get_contents(STDIN), $input); echo json_encode(["limit" => (int) ini_get("max_input_vars"), "input" => $input], JSON_THROW_ON_ERROR);']);
    $process->setInput($body)->setTimeout(10)->mustRun();
    $parsed = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($parsed['limit'])->toBe(1000, 'The child runs without php.ini overrides, using PHP default max_input_vars.');

    return $parsed['input'];
}

test('the rendered compact native form saves one of more than a thousand accessible sites through the default PHP parser', function (): void {
    $world = selectedSitePolicyWorld();
    Site::factory()->count(1002)->for($world['account'])->create();
    $html = $this->actingAs($world['owner'])->get(route('dashboard.account.ai-assist.show'))->assertOk()->getContent();
    $this->withCookie(config('session.cookie'), session()->getId());
    expect(substr_count($html, 'data-ai-policy-site aria-describedby'))->toBe(1004)
        ->and($html)->not->toContain('name="site_tokens[')->not->toContain('name="site_ids[]"');
    $body = selectedSiteNativeBody($html, [$world['first']->id]);
    expect(substr_count($body, '&'))->toBeLessThan(30, 'Unselected site count must not increase successful form controls.');
    $input = selectedSiteParseNative($body);
    expect($input['site_selections_complete'])->toBe('1')->and($input['mode'])->toBe('off')
        ->and($input['max_context_characters'])->toBe('1000')->and($input['site_selections'])->toHaveCount(2);
    $this->post(route('dashboard.account.ai-assist.policy.sites'), $input)->assertRedirect()->assertSessionHasNoErrors();
    expect(SiteAiAssistPolicy::query()->findOrFail($world['first']->id)->mode)->toBe('off')
        ->and(SiteAiAssistPolicy::query()->findOrFail($world['first']->id)->max_context_characters)->toBe(1000)
        ->and(SiteAiAssistPolicy::query()->findOrFail($world['second']->id)->mode)->toBe('manual');
});

test('PHP-truncated native batches fail closed and can recover by choosing fewer reviewed sites', function (string $batch): void {
    $world = selectedSitePolicyWorld();
    Site::factory()->count(1002)->for($world['account'])->create();
    $html = $this->actingAs($world['owner'])->get(route('dashboard.account.ai-assist.show'))->assertOk()->getContent();
    $this->withCookie(config('session.cookie'), session()->getId());
    $ids = Site::query()->where('account_id', $world['account']->id)->orderBy('id')->pluck('id')->all();
    if ($batch === 'marker-only') {
        $baseCount = substr_count(selectedSiteNativeBody($html, []), '&') + 1;
        $ids = array_slice($ids, 0, 1001 - $baseCount);
    }
    $input = selectedSiteParseNative(selectedSiteNativeBody($html, $ids));
    expect($input)->not->toHaveKey('site_selections_complete');
    if ($batch === 'marker-only') {
        expect($input['mode'])->toBe('off')->and($input['max_context_characters'])->toBe('1000');
    }
    $this->post(route('dashboard.account.ai-assist.policy.sites'), $input)->assertRedirect()
        ->assertSessionHasErrors('site_selections_complete');
    expect(SiteAiAssistPolicy::query()->count())->toBe(2, 'A truncated batch must not create any partial site policy rows.')
        ->and(SiteAiAssistPolicy::query()->findOrFail($world['first']->id)->mode)->toBe('manual');
    $old = session()->getOldInput();
    expect($old['site_tokens'][$world['first']->id])->toBe($world['input']['site_tokens'][$world['first']->id]);
    $retryHtml = $this->get(route('dashboard.account.ai-assist.show'))->assertOk()->assertSee('Choose fewer sites')->getContent();
    $retry = selectedSiteParseNative(selectedSiteNativeBody($retryHtml, [$world['first']->id]));
    expect($retry['site_selections'][1])->toBe($world['first']->id.':'.$old['site_tokens'][$world['first']->id]);
    $this->post(route('dashboard.account.ai-assist.policy.sites'), $retry)->assertRedirect()->assertSessionHasNoErrors();
    expect(SiteAiAssistPolicy::query()->findOrFail($world['first']->id)->mode)->toBe('off')
        ->and(SiteAiAssistPolicy::query()->findOrFail($world['second']->id)->mode)->toBe('manual');
})->with(['all-sites', 'marker-only']);

test('compact native pairs reject malformed missing or duplicate selected authority', function (array $pairs): void {
    $world = selectedSitePolicyWorld();
    $this->actingAs($world['owner'])->postJson(route('dashboard.account.ai-assist.policy.sites'), [
        ...$world['input'], 'site_selections' => ['', ...$pairs], 'site_selections_complete' => '1',
    ])->assertUnprocessable();
    expect(SiteAiAssistPolicy::query()->findOrFail($world['first']->id)->mode)->toBe('manual');
})->with([[['bad-pair']], [['1:']], [['1:bad-token']], [['1:'.str_repeat('a', 64), '1:'.str_repeat('a', 64)]], [[[1]]]]);
