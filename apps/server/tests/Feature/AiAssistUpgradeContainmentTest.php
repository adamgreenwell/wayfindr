<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Jobs\GenerateConversationCopilotReplyDraft;
use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Models\Conversation;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Support\Ai\AccountAiConnections;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Settings\OperatorSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());

test('upgrading the pre-Assist schema preserves operator Manual support and never backfills automatic consent', function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    config(['ai.providers.wayfindr.driver' => 'openai-compatible', 'ai.providers.wayfindr.models.text.default' => 'synthetic-installation-model',
        'ai.providers.wayfindr.key' => 'synthetic-installation-key', 'ai.providers.wayfindr.url' => 'https://models.example.test/v1']);
    app(OperatorSettings::class)->captureBaseline();
    // Pin the feature boundary; a future unrelated migration must never join this rehearsal.
    $migrations = array_map(fn (string $file): string => database_path('migrations/'.$file), [
        '2026_10_15_180000_create_account_ai_connections_table.php',
        '2026_10_15_200000_create_account_ai_usage_limits_table.php',
        '2026_10_15_200100_create_ai_usage_ledger.php',
        '2026_10_15_230000_add_ai_connection_test_failure_code.php',
        '2026_10_16_130000_create_ai_assist_policies.php',
        '2026_10_16_130100_add_policy_fences_to_conversation_copilot_artifacts.php',
        '2026_10_16_170000_add_ai_catalogue_revisions_to_accounts.php',
        '2026_10_16_180000_add_shared_ai_preparation_state.php',
        '2026_10_17_040000_add_grounding_to_conversation_reply_drafts.php',
        '2026_10_17_060000_create_private_draft_refinements.php',
        '2026_10_17_160000_create_ai_assist_feedback_table.php',
    ]);
    expect($migrations)->toHaveCount(11);
    foreach (array_reverse($migrations) as $path) {
        (require $path)->down();
    }
    // Seed while the feature tables/columns really are absent, not after an upgrade.
    expect(Schema::hasTable('account_ai_connections'))->toBeFalse()
        ->and(Schema::hasColumn('conversation_copilot_reply_drafts', 'connection_revision'))->toBeFalse();
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create(['subject' => 'Existing checkout request']);
    $message = ConversationMessage::factory()->for($conversation)->create(['body' => 'Existing visitor request.']);
    $generation = (string) Str::uuid();
    DB::table('conversation_copilot_reply_drafts')->insert(['conversation_id' => $conversation->id, 'requested_by_id' => $owner->id,
        'generation' => $generation, 'status' => 'ready', 'draft' => 'Historical manual suggestion.', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    foreach ($migrations as $path) {
        (require $path)->up();
    }
    expect($message->fresh()->body)->toBe('Existing visitor request.')
        ->and(ConversationCopilotReplyDraft::query()->sole()->draft)->toBe('Historical manual suggestion.')
        ->and(ConversationCopilotReplyDraft::query()->sole()->connection_revision)->toBeNull()
        ->and(AccountAiConnection::query()->count())->toBe(0)
        ->and(DB::table('account_ai_assist_policies')->count())->toBe(0)
        ->and(DB::table('site_ai_assist_policies')->count())->toBe(0)
        ->and(app(AiAssistPolicyResolver::class)->forSite($site->id)->mode)->toBe('manual')
        ->and(app(AgentCopilotConnectionResolver::class)->forAccount($account->id)->source)->toBe('installation');
    $this->actingAs($owner)->get(route('dashboard.account.ai-assist.show'))->assertOk()->assertSee('data-ai-legacy-notice', false);
    $this->get(route('dashboard.conversations.show', $conversation->support_code))->assertOk();
    $this->postJson(route('dashboard.conversations.assist.store', ['supportCode' => $conversation->support_code, 'task' => 'reply']), ['action' => 'open', 'workspace' => 'conversation'])->assertNotFound();
    expect(DB::table('ai_usage_attempts')->count())->toBe(0)
        ->and($conversation->messages()->count())->toBe(1);
    Queue::assertNothingPushed();
    $this->post(route('dashboard.conversations.copilot-reply-draft.store', $conversation->support_code), ['assist_action' => 'initial'])->assertRedirect();
    Queue::assertPushed(GenerateConversationCopilotReplyDraft::class, 1);
    expect(ConversationCopilotReplyDraft::query()->sole()->connection_revision)->not->toBeNull();
    // An unstamped historical suggestion never becomes current output automatically.
    $this->post(route('dashboard.conversations.messages.store', $conversation->support_code), ['body' => 'A separate human reply after upgrade.'])->assertRedirect();
    expect($conversation->messages()->count())->toBe(2);
    Http::assertNothingSent();
});

test('account containment fences pending and in-flight shared work while preserving support and usage responsibility', function (bool $duringDispatch, bool $disconnect): void {
    $w = assistPreparationWorld('conversation_reply_draft');
    $receipt = assistPreparationEnsure($w);
    $contain = function () use ($w, $disconnect): void {
        $connections = app(AccountAiConnections::class);
        if ($disconnect) {
            $connections->disconnect($w['owner'], $w['account']->id);
        } else {
            $connections->setEnabled($w['owner'], $w['account']->id, false);
        }
    };
    if (! $duringDispatch) {
        $contain();
    }
    $provider = assistPreparationRun($w, assistPreparationProvider(duringCall: $duringDispatch ? $contain : null));
    $this->actingAs($w['agent'])->getJson(route('dashboard.conversations.assist.show', ['supportCode' => $w['conversation']->support_code, 'task' => 'reply']))
        ->assertStatus(503)->assertJsonPath('can_use', false)->assertJsonPath('output', null);
    expect($provider->calls)->toBe($duringDispatch ? 1 : 0)
        ->and(app(AgentCopilotConnectionResolver::class)->forAccount($w['account']->id))->toBeNull()
        ->and($w['connection']->fresh()->enabled)->toBeFalse();
    if ($disconnect) {
        expect($w['connection']->fresh()->active_configuration)->toBeNull()
            ->and($w['connection']->fresh()->proposed_configuration)->toBeNull()
            ->and($w['connection']->fresh()->uses_installation_default)->toBeFalse();
    }
    $attempt = DB::table('ai_usage_attempts')->where('attempt_id', $receipt['generation'])->sole();
    expect($attempt->state)->toBe($duringDispatch ? 'failed' : 'cancelled')
        ->and((int) DB::table('ai_usage_periods')->where('scope_key', 'account:'.$w['account']->id)->value('charged_requests'))->toBe($duringDispatch ? 1 : 0)
        ->and((int) $attempt->debited_tokens)->toBe($duringDispatch ? 7 : 0);
    $this->actingAs($w['agent'])->get(route('dashboard.conversations.show', $w['conversation']->support_code))->assertOk();
    $this->post(route('dashboard.conversations.messages.store', $w['conversation']->support_code), ['body' => 'Manual support during containment.'])->assertRedirect();
    expect($w['conversation']->messages()->count())->toBe(2)->and($w['conversation']->tickets()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['pending' => false, 'in-flight' => true])->with(['disable' => false, 'disconnect' => true]);
