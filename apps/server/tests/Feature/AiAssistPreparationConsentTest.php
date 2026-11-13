<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AccountAiAssistPolicy;
use App\Models\ConversationCopilotSummary;
use App\Models\Site;
use App\Models\SiteAiAssistPolicy;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\Policy\AiAssistPolicies;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());

function preparationConsentValues(): array
{
    return ['mode' => 'prepare', 'summary' => true, 'reply_draft' => true, 'relevant_articles' => true,
        'ticket_details' => true, 'article_context_consent' => true, 'max_context_characters' => null];
}

/** Exact pre-automation editor contract, using only public synthetic connection identity. */
function preparationLegacyEditorToken(array $world, bool $site = false): string
{
    $resolved = app(AgentCopilotConnectionResolver::class)->forAccount($world['account']->id);
    $processor = ['active_selection' => $world['connection']->active_revision, 'enabled' => $world['connection']->enabled,
        'installation_default' => $world['connection']->uses_installation_default, 'source' => $resolved->source,
        'available' => true, 'resolved_revision' => $resolved->revision];
    $account = AccountAiAssistPolicy::query()->find($world['account']->id);
    $values = $site ? ['site', $world['account']->id, $world['site']->id, $account?->revision, SiteAiAssistPolicy::query()->find($world['site']->id)?->revision, $processor]
        : ['account', $world['account']->id, $account?->revision, $processor];

    return hash_hmac('sha256', json_encode(['assist-policy-editor-v1', ...$values], JSON_THROW_ON_ERROR), (string) config('app.key'));
}

function preparationConsentMigration(): object
{
    $migration = require database_path('migrations/2026_10_16_180000_add_shared_ai_preparation_state.php');
    $migration->down();
    $migration->up();

    return $migration;
}

test('the automatic-preparation upgrade holds every earlier Prepare choice at Manual without changing other policies or content', function (): void {
    $prepare = assistPreparationWorld(mode: 'prepare');
    $manual = assistPreparationWorld(mode: 'manual');
    $off = assistPreparationWorld(mode: 'off');
    $manualBefore = [$manual['policy']->fresh()->getRawOriginal(), SiteAiAssistPolicy::query()->find($manual['site']->id)->getRawOriginal()];
    $offBefore = [$off['policy']->fresh()->getRawOriginal(), SiteAiAssistPolicy::query()->find($off['site']->id)->getRawOriginal()];
    $oldAccountRevision = $prepare['policy']->revision;
    $oldSiteRevision = SiteAiAssistPolicy::query()->find($prepare['site']->id)->revision;
    $more = Site::factory()->count(251)->for($prepare['account'])->create();
    $template = SiteAiAssistPolicy::query()->find($prepare['site']->id)->getRawOriginal();
    $rows = $more->map(fn ($site) => [...$template, 'site_id' => $site->id,
        'revision' => (string) Str::uuid(), 'preparation_consent_revision' => null])->all();
    SiteAiAssistPolicy::query()->insert($rows);
    $legacy = Account::factory()->create();
    Site::factory()->for($legacy)->create();
    $artifact = ConversationCopilotSummary::query()->forceCreate(['conversation_id' => $prepare['conversation']->id, 'requested_by_id' => $prepare['owner']->id,
        'generation' => (string) Str::uuid(), 'status' => 'ready', 'summary' => 'Existing private manual output.', 'requested_at' => now()]);
    $migration = preparationConsentMigration();
    expect(app(AiAssistPolicyResolver::class)->forSite($prepare['site']->id)->mode)->toBe('manual', 'A stored choice described as future configuration must never authorize automatic provider calls.')
        ->and($prepare['policy']->fresh()->revision)->toBe($oldAccountRevision)
        ->and($prepare['policy']->fresh()->getRawOriginal('preparation_consent_revision'))->toBeNull()
        ->and(SiteAiAssistPolicy::query()->find($prepare['site']->id)->revision)->toBe($oldSiteRevision)
        ->and(SiteAiAssistPolicy::query()->where('account_id', $prepare['account']->id)->whereNotNull('preparation_consent_revision')->count())->toBe(0, 'Every prior site choice must be held, including beyond the first configuration chunk.')
        ->and(SiteAiAssistPolicy::query()->where('account_id', $prepare['account']->id)->where('mode', 'prepare')->count())->toBe(252)
        ->and([$manual['policy']->fresh()->getRawOriginal(), SiteAiAssistPolicy::query()->find($manual['site']->id)->getRawOriginal()])->toBe($manualBefore)
        ->and([$off['policy']->fresh()->getRawOriginal(), SiteAiAssistPolicy::query()->find($off['site']->id)->getRawOriginal()])->toBe($offBefore)
        ->and(AccountAiAssistPolicy::query()->find($legacy->id))->toBeNull()
        ->and(SiteAiAssistPolicy::query()->where('account_id', $legacy->id)->count())->toBe(0)
        ->and($artifact->fresh()->summary)->toBe('Existing private manual output.');
    $this->actingAs($prepare['owner'])->postJson(route('dashboard.conversations.assist.store', ['supportCode' => $prepare['conversation']->support_code, 'task' => 'summary']), ['action' => 'open', 'workspace' => 'conversation'])->assertNotFound();
    expect(DB::table('ai_usage_attempts')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    $migration->down();
    expect(app(AiAssistPolicyResolver::class)->forSite($prepare['site']->id)->mode)->toBe('manual', 'Rollback must never silently re-enable earlier Prepare choices.');
    $migration->up();
});

test('manual-only disclosure account tokens cannot enable automatic Prepare even when policy rows were absent', function (bool $legacy): void {
    $world = assistPreparationWorld(mode: 'manual');
    if ($legacy) {
        SiteAiAssistPolicy::query()->where('account_id', $world['account']->id)->delete();
        $world['policy']->delete();
    }
    $policies = app(AiAssistPolicies::class);
    $old = preparationLegacyEditorToken($world);
    $before = AccountAiAssistPolicy::query()->find($world['account']->id)?->getRawOriginal();
    $error = null;
    try {
        $policies->saveAccount($world['owner'], $world['account']->id, preparationConsentValues(), $old);
    } catch (ValidationException $failure) {
        $error = $failure;
    }
    expect($error)->not->toBeNull('A form reviewed under the manual-only disclosure must not enable automatic Prepare.')
        ->and($error->errors())->toHaveKey('policy_token')
        ->and(AccountAiAssistPolicy::query()->find($world['account']->id)?->getRawOriginal())->toBe($before);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([true, false]);

test('fresh account review cannot launder a site token reviewed under the old disclosure', function (): void {
    $world = assistPreparationWorld(mode: 'manual');
    $policies = app(AiAssistPolicies::class);
    $policies->saveAccount($world['owner'], $world['account']->id, preparationConsentValues(), $policies->accountToken($world['account']->id));
    $old = preparationLegacyEditorToken($world, true);
    $before = SiteAiAssistPolicy::query()->find($world['site']->id)->getRawOriginal();
    $error = null;
    try {
        $policies->saveSites($world['owner'], $world['account']->id, [$world['site']->id], preparationConsentValues(), $policies->accountToken($world['account']->id), [$world['site']->id => $old]);
    } catch (ValidationException $failure) {
        $error = $failure;
    }
    expect($error)->not->toBeNull('Each selected site must be reviewed under the automatic-processing disclosure.')
        ->and($error->errors())->toHaveKey('site_tokens')
        ->and(SiteAiAssistPolicy::query()->find($world['site']->id)->getRawOriginal())->toBe($before);
});

test('freshly reviewed account and site choices can enable automatic preparation after the upgrade hold', function (): void {
    $world = assistPreparationWorld(mode: 'prepare');
    preparationConsentMigration();
    $policies = app(AiAssistPolicies::class);
    expect(app(AiAssistPolicyResolver::class)->forSite($world['site']->id)->mode)->toBe('manual');
    $policies->saveAccount($world['owner'], $world['account']->id, preparationConsentValues(), $policies->accountToken($world['account']->id));
    $policies->saveSites($world['owner'], $world['account']->id, [$world['site']->id], preparationConsentValues(), $policies->accountToken($world['account']->id), [$world['site']->id => $policies->siteToken($world['site']->id)]);
    expect(app(AiAssistPolicyResolver::class)->forSite($world['site']->id)->mode)->toBe('prepare', 'Fresh review must stamp the current revision even when the configured Prepare values are unchanged.');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(DB::table('ai_usage_attempts')->count())->toBe(0);
    $this->actingAs($world['owner'])->postJson(route('dashboard.conversations.assist.store', ['supportCode' => $world['conversation']->support_code, 'task' => 'summary']), ['action' => 'open', 'workspace' => 'conversation'])->assertOk()->assertJsonPath('queued', true);
    expect(DB::table('ai_usage_attempts')->count())->toBe(1);
    Queue::assertPushed($world['job'], 1);
    Http::assertNothingSent();
});

test('admin policy pages disclose actual automatic transmission and the upgrade hold in every locale', function (string $locale, string $automatic, string $hold): void {
    $world = assistPreparationWorld(mode: 'manual');
    $world['owner']->forceFill(['locale' => $locale])->save();
    $response = $this->actingAs($world['owner'])->get(route('dashboard.account.ai-assist.show'))->assertOk();
    expect(str_contains($response->getContent(), $automatic))->toBeTrue('Administrators must review the actual automatic-processing behavior.')
        ->and(str_contains($response->getContent(), $hold))->toBeTrue('Administrators must be told that earlier Prepare choices need renewed review.');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([
    ['en', 'automatically sends permitted, scrubbed conversation text', 'Earlier Prepare choices are kept in Manual'],
    ['de', 'sendet beim Öffnen', 'bei diesem Upgrade auf „Manuell“ gesetzt'],
    ['it', 'invia automaticamente', 'mantenute in Manuale'],
]);

test('an older writer cannot carry preparation consent across a changed policy revision', function (bool $account): void {
    $world = assistPreparationWorld(mode: 'prepare');
    $policies = app(AiAssistPolicies::class);
    $record = $account ? $world['policy'] : SiteAiAssistPolicy::query()->find($world['site']->id);
    $record->forceFill(['mode' => 'prepare', 'revision' => (string) Str::uuid()])->save();
    expect(app(AiAssistPolicyResolver::class)->forSite($world['site']->id)->mode)->toBe('manual', 'A revision written without current automatic-processing consent must stay effectively Manual.');
    $this->actingAs($world['owner'])->postJson(route('dashboard.conversations.assist.store', ['supportCode' => $world['conversation']->support_code, 'task' => 'summary']), ['action' => 'open', 'workspace' => 'conversation'])->assertNotFound();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    if ($account) {
        $policies->saveAccount($world['owner'], $world['account']->id, preparationConsentValues(), $policies->accountToken($world['account']->id));
    } else {
        $policies->saveSites($world['owner'], $world['account']->id, [$world['site']->id], preparationConsentValues(), $policies->accountToken($world['account']->id), [$world['site']->id => $policies->siteToken($world['site']->id)]);
    }
    expect(app(AiAssistPolicyResolver::class)->forSite($world['site']->id)->mode)->toBe('prepare');
})->with([true, false]);
