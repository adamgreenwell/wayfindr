<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Jobs\GenerateConversationDraftRefinement;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\ConversationDraftRefinement;
use App\Models\User;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Observability\DraftRefinementAudit;
use App\Support\Ai\Refinement\DraftRefinements;
use App\Support\Ai\Usage\AiUsageLedger;
use App\Support\Visitors\VisitorEraser;
use App\Support\Visitors\VisitorExporter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
require_once __DIR__.'/../Fixtures/draft-refinement.php';
uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());

function refinementAuditEvents(): Collection
{
    return AuditEvent::query()->where('action', 'like', 'conversation.ai_draft_refinement.%')->orderBy('id')->get();
}

function refinementAuditRun(array $receipt, ?AgentCopilotProvider $provider = null): void
{
    $row = ConversationDraftRefinement::query()->where('generation', $receipt['generation'])->first();
    if ($row !== null) {
        app()->instance(AgentCopilotProvider::class, $provider ?? refinementLifecycleProvider());
        app()->call([new GenerateConversationDraftRefinement((int) $row->id, $row->generation), 'handle']);
    }
}

test('admitted private lifecycle audits exact provenance timing and tokens without copying content', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $configuration = $w['connection']->active_configuration;
    $configuration['model'] = 'SYNTHETIC-CONFIG-MODEL-SECRET';
    $configuration['api_key'] = 'SYNTHETIC-CONNECTION-SECRET';
    $w['connection']->forceFill(['active_configuration' => $configuration])->save();
    $s = app(DraftRefinements::class);
    $input = refinementLifecycleInput('It may take 3 days. PRIVATE-ORIGINAL-MARKER staff@example.test');
    $grant = $s->authorize($w['agent'], $w['conversation'], $input->draftRevision);
    expect(refinementAuditEvents())->toHaveCount(0);
    $r = $s->request($w['agent'], $w['conversation'], $input, $grant['generation']);
    assistPreparationAdvance($w, 2);
    refinementAuditRun($r, refinementLifecycleProvider(fn () => assistPreparationAdvance($w, 3)));
    $s->show($w['agent'], $w['conversation'], $r['generation'], $input->draftRevision);
    $s->show($w['agent'], $w['conversation'], $r['generation'], $input->draftRevision);
    $s->show($w['agent'], $w['conversation'], $r['generation'], $input->draftRevision, true);
    $s->cancel($w['agent'], $w['conversation'], $r['generation']);
    $events = refinementAuditEvents();
    expect($events)->toHaveCount(4)->and($events->pluck('metadata.state')->all())->toBe(['requested', 'started', 'ready', 'applied']);
    foreach ($events as $event) {
        expect($event->account_id)->toBe($w['account']->id)->and($event->site_id)->toBe($w['site']->id)
            ->and($event->actor_id)->toBe($w['agent']->id)->and($event->subject_id)->toBe($w['conversation']->id)
            ->and($event->metadata['generation'])->toBe($r['generation'])->and($event->metadata['operation'])->toBe('clarify')
            ->and($event->metadata['provider'])->toBe('openai-compatible')->and($event->metadata['source'])->toBe('account')
            ->and($event->metadata['model_tag'])->toMatch('/\A[a-f0-9]{64}\z/')
            ->and($event->metadata['route_tag'])->toMatch('/\A[a-f0-9]{64}\z/')
            ->and(array_keys($event->metadata))->toBe(['version', 'purpose', 'refinement_id', 'generation', 'state', 'operation',
                'connection_revision', 'policy_revision', 'work_fingerprint', 'source', 'provider', 'model_tag', 'route_tag',
                'elapsed_ms', 'queue_ms', 'reported_input_tokens', 'reported_output_tokens', 'failure_code']);
    }
    expect($events[2]->metadata)->toMatchArray(['elapsed_ms' => 5000, 'queue_ms' => 2000, 'reported_input_tokens' => 3, 'reported_output_tokens' => 4]);
    expect(json_encode($events->pluck('metadata')))->not->toContain('PRIVATE-ORIGINAL', 'staff@example.test', 'It might take',
        'Synthetic checkout guidance', 'SYNTHETIC-CONFIG-MODEL', 'SYNTHETIC-CONNECTION', 'models.example.test');
    expect(ConversationDraftRefinement::query()->sole()->status)->toBe('applied');
});

test('metadata grants rejected admission and duplicate cancellation are not extra work or votes', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $input = refinementLifecycleInput();
    $g = $s->authorize($w['agent'], $w['conversation'], $input->draftRevision);
    $s->cancel($w['agent'], $w['conversation'], $g['generation']);
    expect(refinementAuditEvents())->toHaveCount(0);
    $g = $s->authorize($w['agent'], $w['conversation'], $input->draftRevision);
    config(['wayfindr.ai.usage.daily_requests' => 0]);
    expect(fn () => $s->request($w['agent'], $w['conversation'], $input, $g['generation']))->toThrow(AiNetworkException::class);
    expect(refinementAuditEvents())->toHaveCount(0);
    config(['wayfindr.ai.usage.daily_requests' => 100]);
    $r = $s->request($w['agent'], $w['conversation'], $input, $g['generation']);
    $s->cancel($w['agent'], $w['conversation'], $r['generation']);
    $s->cancel($w['agent'], $w['conversation'], $r['generation']);
    refinementAuditRun($r);
    expect(refinementAuditEvents()->pluck('metadata.state')->all())->toBe(['requested', 'cancelled']);
    Queue::assertPushed(GenerateConversationDraftRefinement::class, 1);
});

test('provider failure audits only a closed reason and unknown usage', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $r = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    refinementAuditRun($r, refinementLifecycleProvider(output: fn () => throw new RuntimeException('PRIVATE-PROVIDER-ERROR api_key=SECRET')));
    $events = refinementAuditEvents();
    expect($events->pluck('metadata.state')->all())->toBe(['requested', 'started', 'failed']);
    expect($events->last()->metadata)->toMatchArray(['failure_code' => 'provider_failed', 'reported_input_tokens' => null, 'reported_output_tokens' => null]);
    expect(json_encode($events->pluck('metadata')))->not->toContain('PRIVATE-PROVIDER', 'api_key', 'SECRET');
});

test('provider reported strings and invalid token counts never become audit fields', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $r = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $provider = new class(refinementLifecycleProvider()) implements AgentCopilotProvider
    {
        public function __construct(private AgentCopilotProvider $delegate) {}

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('No synthetic probes.');
        }

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $result = $this->delegate->generate($prompt);

            return new AgentCopilotResult($result->text, 'PRIVATE-REPORTED-PROVIDER', 'PRIVATE-REPORTED-MODEL', -1, PHP_INT_MAX,
                new AssistPreparationSession($prompt->connection, fn ($reason) => $result->settle($reason)));
        }
    };
    refinementAuditRun($r, $provider);
    $ready = refinementAuditEvents()->last();
    expect($ready->metadata['state'])->toBe('ready')->and($ready->metadata['provider'])->toBe('openai-compatible')
        ->and($ready->metadata['reported_input_tokens'])->toBeNull()->and($ready->metadata['reported_output_tokens'])->toBeNull();
    expect(json_encode(refinementAuditEvents()->pluck('metadata')))->not->toContain('PRIVATE-REPORTED');
});

test('late old work and an old audit hint cannot attribute a replacement as ready', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $old = $s->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $hint = ConversationDraftRefinement::query()->sole();
    assistPreparationAdvance($w, 60);
    $new = $s->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    app(AiUsageLedger::class)->withAccountUsageLock($w['account']->id, fn () => app(DraftRefinementAudit::class)->record($hint, 'ready'));
    app()->call([new GenerateConversationDraftRefinement((int) $hint->id, $old['generation']), 'handle']);
    expect(refinementAuditEvents()->pluck('metadata.state')->all())->toBe(['requested', 'superseded', 'requested']);
    expect(ConversationDraftRefinement::query()->sole()->generation)->toBe($new['generation']);
});

test('erasure scrubs existing private audit metadata and queued completion cannot recreate it', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $r = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $hint = ConversationDraftRefinement::query()->sole();
    app(VisitorEraser::class)->erase($w['owner'], $w['conversation']->visitor);
    app()->call([new GenerateConversationDraftRefinement((int) $hint->id, $r['generation']), 'handle']);
    app(AiUsageLedger::class)->withAccountUsageLock($w['account']->id, fn () => app(DraftRefinementAudit::class)->record($hint, 'failed'));
    expect(refinementAuditEvents())->toHaveCount(1)->and(refinementAuditEvents()->sole()->metadata)->toBe(['erased' => true]);
    expect(ConversationDraftRefinement::query()->count())->toBe(0);
});

test('expiry removes unreadable ciphertext and records one content-free terminal event', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $r = $s->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    DB::table('conversation_draft_refinements')->update(['scope' => 'unreadable expired ciphertext']);
    assistPreparationAdvance($w, 900);
    expect($s->cleanup())->toBe(1)->and($s->cleanup())->toBe(0);
    $events = refinementAuditEvents();
    expect($events->pluck('metadata.state')->all())->toBe(['requested', 'expired']);
    expect($events->last()->metadata)->toMatchArray(['model_tag' => null, 'route_tag' => null, 'elapsed_ms' => 900000]);
    expect(ConversationDraftRefinement::query()->count())->toBe(0);
});

test('duplicate workers and source withdrawal produce one start ready and stale event without adoption claims', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $input = refinementLifecycleInput();
    $r = $s->request($w['agent'], $w['conversation'], $input);
    refinementAuditRun($r);
    refinementAuditRun($r);
    $w['article']->update(['body' => 'Changed published guidance.']);
    $s->show($w['agent'], $w['conversation'], $r['generation'], $input->draftRevision);
    $s->show($w['agent'], $w['conversation'], $r['generation'], $input->draftRevision);
    expect(refinementAuditEvents()->pluck('metadata.state')->all())->toBe(['requested', 'started', 'ready', 'stale']);
    expect(AuditEvent::query()->where('action', 'like', '%.used')->orWhere('action', 'like', '%.viewed')->orWhere('action', 'like', '%.rejected')->count())->toBe(0);
});

test('audit records use current account site and permission visibility without exposing metadata', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $route = route('dashboard.account.audit.index');
    $this->actingAs($w['owner'])->get($route)->assertOk()->assertSee(__('account_audit.actions.conversation_ai_draft_refinement_requested'))
        ->assertDontSee('work_fingerprint')->assertDontSee('model_tag');
    $this->actingAs($w['agent'])->get($route)->assertForbidden();
    $other = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);
    $this->actingAs($other)->get($route)->assertOk()->assertDontSee(__('account_audit.actions.conversation_ai_draft_refinement_requested'));
    $w['site']->supportAgents()->attach($w['agent']);
    $this->actingAs($w['owner'])->get($route)->assertOk()->assertDontSee(__('account_audit.actions.conversation_ai_draft_refinement_requested'));
});

test('visitor export includes content-free audit evidence while private input and preview stay excluded', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $r = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput('It may take 3 days. PRIVATE-UNSENT-EXPORT-MARKER'));
    refinementAuditRun($r, refinementLifecycleProvider(output: fn () => '{"candidate":"It might take 3 days. PRIVATE-PREVIEW-EXPORT-MARKER","citations":[],"limitation":null}'));
    $export = app(VisitorExporter::class)->export($w['owner'], $w['conversation']->visitor);
    try {
        $zip = new ZipArchive;
        expect($zip->open($export['path']))->toBeTrue();
        $audit = $zip->getFromName('audit.json');
        expect($audit)->toContain($r['generation'], 'conversation.ai_draft_refinement.requested');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            expect($zip->getFromIndex($i))->not->toContain('PRIVATE-UNSENT-EXPORT', 'PRIVATE-PREVIEW-EXPORT');
        }
        $zip->close();
    } finally {
        unlink($export['path']);
    }
});

test('completion after the bounded erasure window cannot recreate a private audit footprint', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $r = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    refinementAuditRun($r, refinementLifecycleProvider(function () use ($w): void {
        assistPreparationAdvance($w, 180);
        app(VisitorEraser::class)->erase($w['owner'], $w['conversation']->visitor);
    }));
    expect(refinementAuditEvents())->toHaveCount(2);
    foreach (refinementAuditEvents() as $event) {
        expect($event->metadata)->toBe(['erased' => true]);
    }
    expect(ConversationDraftRefinement::query()->count())->toBe(0);
});

test('a lost queue acknowledgement retains only one requested and sanitized failure audit', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('PRIVATE-QUEUE-EXCEPTION'));
    expect(fn () => app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput()))
        ->toThrow(AiNetworkException::class);
    expect(refinementAuditEvents()->pluck('metadata.state')->all())->toBe(['requested', 'failed']);
    expect(refinementAuditEvents()->last()->metadata['failure_code'])->toBe('queue_failed');
    expect(json_encode(refinementAuditEvents()->pluck('metadata')))->not->toContain('PRIVATE-QUEUE');
});

test('per-run expiry work is bounded and content-free grants do not inflate lifecycle counts', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $s->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $template = DB::table('conversation_draft_refinements')->sole();
    $rows = [];
    foreach (User::factory()->for($w['account'])->count(500)->create() as $actor) {
        $row = (array) $template;
        unset($row['id']);
        $rows[] = [...$row, 'requested_by_id' => $actor->id, 'generation' => (string) Str::uuid(), 'draft_revision' => (string) Str::uuid(),
            'status' => 'authorized', 'operation' => '[]', 'work_fingerprint' => '', 'input' => null, 'scope' => null, 'preview' => null];
    }
    DB::table('conversation_draft_refinements')->insert($rows);
    assistPreparationAdvance($w, 900);
    expect($s->cleanup())->toBe(500)->and(ConversationDraftRefinement::query()->count())->toBe(1);
    expect($s->cleanup())->toBe(1)->and(ConversationDraftRefinement::query()->count())->toBe(0);
    expect(refinementAuditEvents()->pluck('metadata.state')->all())->toBe(['requested', 'expired']);
});

test('rejected or stale guarded responses retain reported usage in their terminal audit', function (string $outcome): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $r = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $provider = match ($outcome) {
        'parse' => refinementLifecycleProvider(output: fn () => 'PRIVATE-MALFORMED-RESPONSE'),
        'safety' => refinementLifecycleProvider(output: fn () => '{"candidate":"It takes 5 days.","citations":[],"limitation":null}'),
        'stale' => refinementLifecycleProvider(fn () => $w['article']->update(['body' => 'Changed before publication.'])),
    };
    refinementAuditRun($r, $provider);
    $event = refinementAuditEvents()->last();
    expect($event->metadata['state'])->toBe($outcome === 'stale' ? 'stale' : 'failed')
        ->and($event->metadata['reported_input_tokens'])->toBe(3)->and($event->metadata['reported_output_tokens'])->toBe(4);
    $attempt = DB::table('ai_usage_attempts')->where('attempt_id', $r['generation'])->sole();
    expect((int) $attempt->reported_input_tokens)->toBe(3)->and((int) $attempt->reported_output_tokens)->toBe(4);
    expect(json_encode($event->metadata))->not->toContain('PRIVATE-MALFORMED', 'It takes 5 days', 'Changed before publication');
})->with(['parse', 'safety', 'stale']);

test('unguarded response claims remain unknown and never copy response text into failure audit', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $r = $s->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $row = ConversationDraftRefinement::query()->sole();
    $s->fail((int) $row->id, $r['generation'], 'invalid_output', new AgentCopilotResult('PRIVATE-UNGUARDED-TEXT', 'PRIVATE-PROVIDER', 'PRIVATE-MODEL', 3, 4));
    expect(refinementAuditEvents()->last()->metadata)->toMatchArray(['reported_input_tokens' => null, 'reported_output_tokens' => null]);
    expect(json_encode(refinementAuditEvents()->pluck('metadata')))->not->toContain('PRIVATE-UNGUARDED', 'PRIVATE-PROVIDER', 'PRIVATE-MODEL');
});
