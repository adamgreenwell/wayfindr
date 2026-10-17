<?php

use App\Enums\AccountRole;
use App\Jobs\GenerateConversationDraftRefinement;
use App\Models\AiAssistFeedback;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationDraftRefinement;
use App\Models\Site;
use App\Models\User;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Observability\AssistFeedback;
use App\Support\Ai\Observability\AssistReport;
use App\Support\Ai\Observability\SharedAssistAudit;
use App\Support\Ai\Preparation\AiAssistPreparation;
use App\Support\Ai\Refinement\DraftRefinements;
use App\Support\Ai\Usage\AiUsageLedger;
use App\Support\Visitors\VisitorEraser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());
function feedbackReady(string $purpose = 'conversation_reply_draft'): array
{
    $w = assistPreparationWorld($purpose);
    assistPreparationEnsure($w);
    assistPreparationRun($w);
    $w['generation'] = $w['class']::query()->sole()->generation;

    return $w;
}
function feedbackInput(array $w, string $event, array $extra = []): array
{
    return ['generation' => $w['generation'], 'event' => $event, ...$extra];
}
function feedbackRecord(array $w, string $event, array $extra = [], ?User $actor = null): array
{
    return app(AssistFeedback::class)->record($actor ?? $w['owner'], $w['conversation'], $w['purpose'], feedbackInput($w, $event, $extra));
}
function feedbackReceipt(array $w, ?User $actor = null): string
{
    $actor ??= $w['owner'];
    $state = app(AiAssistPreparation::class)->use($actor, $w['conversation'], $w['purpose'], $w['generation']);

    return app(AssistFeedback::class)->receipt($actor, $w['conversation'], $w['purpose'], $state['generation']);
}
$purposes = ['conversation_summary', 'conversation_reply_draft', 'conversation_knowledge_suggestion', 'conversation_ticket_suggestion'];
test('ready polling and authorization do not invent feedback; viewer retries remain distinct', function (string $purpose): void {
    $w = feedbackReady($purpose);
    $receipt = feedbackReceipt($w);
    expect(AiAssistFeedback::query()->count())->toBe(0);
    for ($i = 0; $i < 3; $i++) {
        feedbackRecord($w, 'viewed');
    }
    feedbackRecord($w, 'viewed', actor: $w['agent']);
    feedbackRecord($w, 'used', ['receipt' => $receipt]);
    feedbackRecord($w, 'used', ['receipt' => $receipt]);
    feedbackRecord($w, 'rejected');
    expect(AiAssistFeedback::query()->count())->toBe(2);
    $owner = AiAssistFeedback::query()->where('user_id', $w['owner']->id)->sole();
    expect($owner->viewed_at)->not->toBeNull()->and($owner->used_at)->not->toBeNull()->and($owner->rejected_at)->not->toBeNull()->and($owner->usefulness)->toBeNull();
    expect(AiAssistFeedback::query()->where('user_id', $w['agent']->id)->sole()->used_at)->toBeNull();
})->with($purposes);

test('use receipts are generation actor conversation and expiry bound', function (string $mutation): void {
    $w = feedbackReady();
    $receipt = feedbackReceipt($w);
    $actor = null;
    if ($mutation === 'actor') {
        $actor = $w['agent'];
    }
    if ($mutation === 'expired') {
        assistPreparationAdvance($w, 61);
    }
    if ($mutation === 'generation') {
        $w['class']::query()->sole()->forceFill(['generation' => (string) Str::uuid()])->save();
    }
    if ($mutation === 'source') {
        $w['message']->forceFill(['body' => 'Changed synthetic message'])->save();
    }
    if ($mutation === 'tamper') {
        $receipt[20] = $receipt[20] === 'a' ? 'b' : 'a';
    }
    if ($mutation === 'conversation') {
        $w['conversation'] = Conversation::factory()->for($w['site'])->create();
    }
    expect(fn () => feedbackRecord($w, 'used', ['receipt' => $receipt], $actor))->toThrow(AiNetworkException::class);
    expect(AiAssistFeedback::query()->count())->toBe(0);
})->with(['actor', 'expired', 'generation', 'source', 'tamper', 'conversation']);

test('assessment retries are idempotent and deliberate updates require the latest revision', function (): void {
    $w = feedbackReady();
    feedbackRecord($w, 'viewed');
    feedbackRecord($w, 'used', ['receipt' => feedbackReceipt($w)]);
    $input = ['usefulness' => 'useful', 'correction' => 'minor', 'expected_revision' => 0];
    $first = feedbackRecord($w, 'assessment', $input);
    $again = feedbackRecord($w, 'assessment', $input);
    expect($first)->toBe($again)->and($first['revision'])->toBe(1);
    expect(fn () => feedbackRecord($w, 'assessment', [...$input, 'usefulness' => 'not_useful']))->toThrow(AiNetworkException::class);
    $updated = feedbackRecord($w, 'assessment', [...$input, 'usefulness' => 'not_useful', 'correction' => 'material', 'expected_revision' => 1]);
    expect($updated['revision'])->toBe(2)->and(AiAssistFeedback::query()->count())->toBe(1)->and(AiAssistFeedback::query()->sole()->correction)->toBe('material');
});

test('unviewed or unused suggestions cannot claim a quality or correction assessment', function (): void {
    $w = feedbackReady();
    expect(fn () => feedbackRecord($w, 'assessment', ['usefulness' => 'useful', 'correction' => 'unassessed', 'expected_revision' => 0]))->toThrow(AiNetworkException::class);
    feedbackRecord($w, 'viewed');
    expect(fn () => feedbackRecord($w, 'assessment', ['usefulness' => 'useful', 'correction' => 'material', 'expected_revision' => 0]))->toThrow(AiNetworkException::class);
    expect(feedbackRecord($w, 'assessment', ['usefulness' => 'unsure', 'correction' => 'unassessed', 'expected_revision' => 0])['revision'])->toBe(1);
});

test('feedback JSON rejects free text extra tenancy unknown enums and missing authorization', function (array $input): void {
    $w = feedbackReady();
    $url = route('dashboard.conversations.assist.feedback', ['supportCode' => $w['conversation']->support_code, 'task' => 'reply']);
    $this->actingAs($w['owner'])->postJson($url, ['generation' => $w['generation'], ...$input])->assertUnprocessable();
    expect(AiAssistFeedback::query()->count())->toBe(0);
})->with([
    [['event' => 'viewed', 'account_id' => 1]], [['event' => 'viewed', 'prompt' => 'SYNTHETIC PRIVATE']],
    [['event' => 'used']], [['event' => 'sent']], [['event' => 'viewed', 'receipt' => 'unauthorized']],
    [['event' => 'assessment', 'usefulness' => 'excellent', 'correction' => 'material', 'expected_revision' => 0]],
    [['event' => 'assessment', 'usefulness' => 'useful', 'correction' => 'many edits', 'expected_revision' => 0]],
]);

test('feedback and reporting refuse cross-account and revoked actors', function (): void {
    $w = feedbackReady();
    $other = User::factory()->create();
    expect(fn () => feedbackRecord($w, 'viewed', actor: $other))->toThrow(AiNetworkException::class);
    $w['owner']->forceFill(['deactivated_at' => $w['clock']->time])->save();
    expect(fn () => feedbackRecord($w, 'viewed'))->toThrow(AiNetworkException::class);
    expect(AiAssistFeedback::query()->count())->toBe(0);
});

test('erasure removes feedback and late receipts or workers cannot recreate it', function (): void {
    $w = feedbackReady();
    $receipt = feedbackReceipt($w);
    feedbackRecord($w, 'viewed');
    $hint = $w['class']::query()->sole();
    app(VisitorEraser::class)->erase($w['owner'], $w['conversation']->visitor);
    expect(AiAssistFeedback::query()->count())->toBe(0);
    expect(fn () => feedbackRecord($w, 'used', ['receipt' => $receipt]))->toThrow(AiNetworkException::class);
    app(AiUsageLedger::class)->withAccountUsageLock($w['account']->id, fn () => app(SharedAssistAudit::class)->record($hint, 'conversation.ai_reply_draft.generated', ['model' => 'SYNTHETIC PRIVATE']));
    expect(AuditEvent::query()->where('metadata->generation', $w['generation'])->count())->toBe(0);
});

test('bounded report distinguishes adoption quality corrections usage and unknown rates', function (): void {
    $w = feedbackReady();
    feedbackRecord($w, 'viewed');
    feedbackRecord($w, 'used', ['receipt' => feedbackReceipt($w)]);
    feedbackRecord($w, 'rejected');
    feedbackRecord($w, 'assessment', ['usefulness' => 'useful', 'correction' => 'material', 'expected_revision' => 0]);
    feedbackRecord($w, 'viewed', actor: $w['agent']);
    feedbackRecord($w, 'assessment', ['usefulness' => 'unsure', 'correction' => 'unassessed', 'expected_revision' => 0], $w['agent']);
    $report = app(AssistReport::class)->read($w['owner']);
    expect($report['complete'])->toBeTrue()->and($report['feedback']['viewed'])->toBe(2)->and($report['feedback']['used'])->toBe(1)->and($report['feedback']['rejected'])->toBe(1)
        ->and($report['feedback']['use_rate'])->toBe(['numerator' => 1, 'denominator' => 2, 'rate' => 0.5])->and($report['feedback']['useful_rate']['denominator'])->toBe(1)
        ->and($report['feedback']['useful_rate']['rate'])->toBe(1)->and($report['feedback']['assessment_coverage']['rate'])->toBe(1)
        ->and($report['feedback']['material_correction_rate']['rate'])->toBe(1)->and($report['work']['generations'])->toBe(1)
        ->and($report['usage']['observed_attempts'])->toBe(1)->and($report['outcomes']['viewed_cohort']['conversations'])->toBe(1);
    $other = assistPreparationWorld();
    $empty = app(AssistReport::class)->read($other['owner']);
    expect($empty['feedback']['useful_rate']['rate'])->toBeNull()->and($empty['feedback']['viewed'])->toBe(0);
    $this->actingAs($w['owner'])->get(route('dashboard.account.ai-assist.report'))->assertOk()->assertSee('AI Assist evidence')->assertDontSee('Synthetic checkout request.');
    $this->actingAs($w['owner'])->getJson(route('dashboard.account.ai-assist.report', ['days' => 32]))->assertUnprocessable();
    $this->actingAs($w['agent'])->getJson(route('dashboard.account.ai-assist.report'))->assertForbidden();
});

test('shared lifecycle records configured opaque route and source provenance once per state', function (string $purpose): void {
    $w = feedbackReady($purpose);
    $events = AuditEvent::query()->where('metadata->generation', $w['generation'])->orderBy('id')->get();
    expect($events)->toHaveCount(3)->and($events->pluck('metadata.state')->all())->toBe(['requested', 'started', 'generated']);
    foreach ($events as $event) {
        expect($event->metadata['purpose'])->toBe($purpose)->and($event->metadata['policy_revision'])->toMatch('/^[a-f0-9]{64}$/')
            ->and($event->metadata['source_revision'])->toMatch('/^[a-f0-9]{64}$/')->and($event->metadata['model_tag'])->toMatch('/^[a-f0-9]{64}$/')
            ->and($event->metadata['provider'])->toBe('openai-compatible');
    }
    expect(json_encode($events->pluck('metadata')))->not->toContain('synthetic-A')->not->toContain('models.example.test')->not->toContain('Synthetic checkout request.')->not->toContain('synthetic-not-a-secret');
    $row = $w['class']::query()->sole();
    app(AiUsageLedger::class)->withAccountUsageLock($w['account']->id, fn () => app(SharedAssistAudit::class)->record($row, $events->last()->action, ['model' => 'PRIVATE RESPONSE STRING']));
    expect(AuditEvent::query()->where('metadata->generation', $w['generation'])->count())->toBe(3);
})->with($purposes);

require_once __DIR__.'/../Fixtures/draft-refinement.php';
test('private feedback stays with its owning draft and requires actual Apply authorization for use', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $input = refinementLifecycleInput();
    $grant = $s->authorize($w['agent'], $w['conversation'], $input->draftRevision);
    $pending = $s->request($w['agent'], $w['conversation'], $input, $grant['generation']);
    $row = ConversationDraftRefinement::query()->sole();
    app()->instance(AgentCopilotProvider::class, refinementLifecycleProvider());
    app()->call([new GenerateConversationDraftRefinement($row->id, $row->generation), 'handle']);
    $w['generation'] = $row->generation;
    $w['purpose'] = AssistFeedback::PRIVATE_PURPOSE;
    expect(fn () => feedbackRecord($w, 'viewed'))->toThrow(AiNetworkException::class);
    feedbackRecord($w, 'viewed', actor: $w['agent']);
    $preview = $s->show($w['agent'], $w['conversation'], $row->generation, $input->draftRevision);
    expect($preview)->not->toHaveKey('feedback_receipt')->and(AiAssistFeedback::query()->sole()->used_at)->toBeNull();
    $applied = $s->show($w['agent'], $w['conversation'], $row->generation, $input->draftRevision, true);
    expect(AiAssistFeedback::query()->sole()->used_at)->toBeNull();
    feedbackRecord($w, 'used', ['receipt' => $applied['feedback_receipt']], $w['agent']);
    feedbackRecord($w, 'assessment', ['usefulness' => 'useful', 'correction' => 'none', 'expected_revision' => 0], $w['agent']);
    expect(ConversationDraftRefinement::query()->sole()->input)->toBeNull()->and(AiAssistFeedback::query()->count())->toBe(1);
    $w['message']->forceFill(['body' => 'Later synthetic source'])->save();
    expect(fn () => feedbackRecord($w, 'assessment', ['usefulness' => 'not_useful', 'correction' => 'material', 'expected_revision' => 1], $w['agent']))->toThrow(AiNetworkException::class);
});

test('private expiry cancellation and supersession cannot collect late feedback', function (string $terminal): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $input = refinementLifecycleInput();
    $grant = $s->authorize($w['agent'], $w['conversation'], $input->draftRevision);
    $s->request($w['agent'], $w['conversation'], $input, $grant['generation']);
    $row = ConversationDraftRefinement::query()->sole();
    app()->instance(AgentCopilotProvider::class, refinementLifecycleProvider());
    app()->call([new GenerateConversationDraftRefinement($row->id, $row->generation), 'handle']);
    $w['generation'] = $row->generation;
    $w['purpose'] = AssistFeedback::PRIVATE_PURPOSE;
    if ($terminal === 'expiry') {
        assistPreparationAdvance($w, 901);
    }
    if ($terminal === 'cancel') {
        $s->cancel($w['agent'], $w['conversation'], $row->generation);
    }
    if ($terminal === 'supersede') {
        assistPreparationAdvance($w, 61);
        $s->authorize($w['agent'], $w['conversation'], (string) Str::uuid());
    }
    expect(fn () => feedbackRecord($w, 'viewed', actor: $w['agent']))->toThrow(AiNetworkException::class);
    expect(AiAssistFeedback::query()->count())->toBe(0);
})->with(['expiry', 'cancel', 'supersede']);

test('reports and feedback use current visible-site authority rather than account-wide usage totals', function (): void {
    $w = feedbackReady();
    feedbackRecord($w, 'viewed');
    $admin = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);
    $w['site']->supportAgents()->attach($admin);
    $restricted = Site::factory()->for($w['account'])->create();
    $restricted->supportAgents()->attach($w['owner']);
    $hidden = Conversation::factory()->for($restricted)->create();
    $generation = (string) Str::uuid();
    AiAssistFeedback::query()->forceCreate(['account_id' => $w['account']->id, 'site_id' => $restricted->id, 'conversation_id' => $hidden->id, 'user_id' => $w['owner']->id, 'purpose' => $w['purpose'], 'generation' => $generation, 'source_revision' => str_repeat('a', 64), 'viewed_at' => $w['clock']->time, 'used_at' => $w['clock']->time]);
    AuditEvent::query()->forceCreate(['account_id' => $w['account']->id, 'site_id' => $restricted->id, 'subject_type' => $hidden->getMorphClass(), 'subject_id' => $hidden->id, 'action' => 'conversation.ai_reply_draft.generated', 'metadata' => ['purpose' => $w['purpose'], 'generation' => $generation, 'state' => 'generated'], 'occurred_at' => $w['clock']->time]);
    $data = (array) DB::table('ai_usage_attempts')->sole();
    unset($data['id']);
    $data['attempt_id'] = $generation;
    $data['debited_tokens'] = 999999;
    DB::table('ai_usage_attempts')->insert($data);
    $report = app(AssistReport::class)->read($admin);
    expect($report['feedback']['identities'])->toBe(1)->and($report['feedback']['used'])->toBe(0)->and($report['usage']['observed_attempts'])->toBe(1)->and($report['usage']['charged_token_bounds'])->toBeLessThan(999999);
    $this->actingAs($admin)->postJson(route('dashboard.conversations.assist.feedback', ['supportCode' => $hidden->support_code, 'task' => 'reply']), ['generation' => $generation, 'event' => 'viewed'])->assertNotFound();
    $w['site']->supportAgents()->detach($admin);
    $w['site']->supportAgents()->attach($w['owner']);
    expect(app(AssistReport::class)->read($admin)['feedback']['viewed'])->toBe(0);
});

test('oversized reports disclose incompleteness instead of silently truncating rates', function (): void {
    $w = feedbackReady();
    $rows = [];
    for ($i = 0; $i <= AssistReport::MAX_ROWS; $i++) {
        $rows[] = ['account_id' => $w['account']->id, 'site_id' => $w['site']->id, 'conversation_id' => $w['conversation']->id, 'user_id' => $w['owner']->id, 'purpose' => $w['purpose'], 'generation' => (string) Str::uuid(), 'source_revision' => str_repeat('a', 64), 'created_at' => $w['clock']->time, 'updated_at' => $w['clock']->time];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('ai_assist_feedback')->insert($chunk);
    }
    $report = app(AssistReport::class)->read($w['owner']);
    expect($report['complete'])->toBeFalse()->and($report)->not->toHaveKey('feedback')->not->toHaveKey('usage');
});

test('guarded shared responses keep reported tokens when source changes before publication', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft');
    assistPreparationEnsure($w);
    assistPreparationRun($w, assistPreparationProvider(duringCall: fn () => $w['message']->forceFill(['body' => 'Changed source after guarded response'])->save()));
    $event = AuditEvent::query()->where('action', 'conversation.ai_reply_draft.failed')->sole();
    expect($event->metadata['prompt_tokens'])->toBe(3)->and($event->metadata['completion_tokens'])->toBe(4);
    expect($w['class']::query()->sole()->draft)->toBeNull();
});

test('accountless actors cannot distinguish a valid support code through feedback errors', function (): void {
    $w = feedbackReady();
    $actor = User::factory()->create(['account_id' => null, 'platform_role' => 'operator']);
    $this->actingAs($actor)->postJson(route('dashboard.conversations.assist.feedback', ['supportCode' => $w['conversation']->support_code, 'task' => 'reply']), feedbackInput($w, 'viewed'))->assertNotFound();
    $this->actingAs($actor)->postJson(route('dashboard.conversations.assist.feedback', ['supportCode' => 'WF-MISSING-CODE', 'task' => 'reply']), feedbackInput($w, 'viewed'))->assertNotFound();
    expect(AiAssistFeedback::query()->count())->toBe(0);
});

test('reports disclose use without an observed view and generations without retained attempt records', function (): void {
    $w = feedbackReady();
    feedbackRecord($w, 'used', ['receipt' => feedbackReceipt($w)]);
    DB::table('ai_usage_attempts')->where('attempt_id', $w['generation'])->delete();
    $report = app(AssistReport::class)->read($w['owner']);
    expect($report['feedback'])->toHaveKey('used_without_view', 1)->and($report['usage'])->toHaveKey('missing_attempt_records', 1)
        ->and($report['feedback']['use_rate']['rate'])->toBeNull();
});

test('absent reported token counts stay unknown rather than a measured zero', function (): void {
    $w = feedbackReady();
    DB::table('ai_usage_attempts')->where('attempt_id', $w['generation'])->update(['reported_input_tokens' => null, 'reported_output_tokens' => null]);
    $report = app(AssistReport::class)->read($w['owner']);
    expect($report['usage']['reported_input_tokens'])->toBeNull()->and($report['usage']['reported_output_tokens'])->toBeNull()
        ->and($report['usage']['reported_input_samples'])->toBe(0);
});
