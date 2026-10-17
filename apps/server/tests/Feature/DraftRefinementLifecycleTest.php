<?php

declare(strict_types=1);

use App\Jobs\GenerateConversationDraftRefinement;
use App\Models\ConversationDraftRefinement;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\Execution\AiGuardedTransportFactory;
use App\Support\Ai\Execution\AiRequestAuthority;
use App\Support\Ai\Network\AiDnsResolver;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiNetworkPolicy;
use App\Support\Ai\Refinement\DraftRefinements;
use App\Support\Ai\Usage\AiUsageLedger;
use App\Support\Visitors\VisitorEraser;
use App\Support\Visitors\VisitorExporter;
use Carbon\Carbon;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());

require_once __DIR__.'/../Fixtures/draft-refinement.php';

function refinementLifecycleRun(array $receipt, ?AgentCopilotProvider $provider = null): AgentCopilotProvider
{
    $row = ConversationDraftRefinement::query()->where('generation', $receipt['generation'])->sole();
    $provider ??= refinementLifecycleProvider();
    app()->instance(AgentCopilotProvider::class, $provider);
    app()->call([new GenerateConversationDraftRefinement((int) $row->id, $row->generation), 'handle']);

    return $provider;
}

test('private refinement queues IDs stores only encrypted scrubbed input and has no shared preparation', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $input = refinementLifecycleInput('It may take 3 days. person@example.test api_key=SYNTHETIC-UNSENT-CREDENTIAL');
    $receipt = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], $input);
    $row = ConversationDraftRefinement::query()->sole();
    $raw = DB::table('conversation_draft_refinements')->sole();
    expect($receipt['state'])->toBe('pending')->and($receipt['preview'])->toBeNull()
        ->and($row->input['input'])->toContain('[EMAIL REDACTED]', '[REDACTED]')->not->toContain('SYNTHETIC-UNSENT-CREDENTIAL')
        ->and($raw->input)->not->toContain('It may take', 'SYNTHETIC-UNSENT', 'person@example.test')
        ->and($row->toArray())->not->toHaveKeys(['input', 'scope', 'preview'])
        ->and(DB::table('conversation_copilot_reply_drafts')->count())->toBe(0)
        ->and(DB::table('ai_assist_preparation_receipts')->count())->toBe(0);
    Queue::assertPushed(GenerateConversationDraftRefinement::class, function ($job) use ($input): bool {
        expect(serialize($job))->not->toContain('It may take', 'SYNTHETIC-UNSENT', $input->draftRevision);

        return true;
    });
    Http::assertNothingSent();
});

test('ready preview deletes input reauthorizes exact output once on Apply and never changes support records', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $service = app(DraftRefinements::class);
    $input = refinementLifecycleInput();
    $receipt = $service->request($w['agent'], $w['conversation'], $input);
    $messages = $w['conversation']->messages()->count();
    refinementLifecycleRun($receipt);
    $row = ConversationDraftRefinement::query()->sole();
    expect($row->status)->toBe('ready')->and($row->input)->toBeNull()
        ->and(DB::table('conversation_draft_refinements')->sole()->preview)->not->toContain('It might take')
        ->and(DB::table('conversation_draft_refinement_sends')->count())->toBe(0);
    $preview = $service->show($w['agent'], $w['conversation'], $receipt['generation'], $input->draftRevision);
    expect($preview['preview']['candidate'])->toBe('  It might take 3 days.  ');
    $applied = $service->show($w['agent'], $w['conversation'], $receipt['generation'], $input->draftRevision, true);
    expect($applied['preview'])->toBe($preview['preview'])->and($row->fresh()->status)->toBe('applied')
        ->and($row->fresh()->preview)->toBeNull()->and($row->fresh()->scope)->toBeNull()
        ->and($w['conversation']->messages()->count())->toBe($messages)->and($w['conversation']->tickets()->count())->toBe(0);
    expect(fn () => $service->show($w['agent'], $w['conversation'], $receipt['generation'], $input->draftRevision, true))->toThrow(AiNetworkException::class);
});

test('two agents have independent private requests and cannot read or apply each others preview', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $service = app(DraftRefinements::class);
    $input = refinementLifecycleInput();
    $a = $service->request($w['agent'], $w['conversation'], $input);
    $b = $service->request($w['owner'], $w['conversation'], refinementLifecycleInput());
    refinementLifecycleRun($a);
    refinementLifecycleRun($b);
    expect(ConversationDraftRefinement::query()->count())->toBe(2)->and($a['generation'])->not->toBe($b['generation']);
    expect(fn () => $service->show($w['owner'], $w['conversation'], $a['generation'], $input->draftRevision))->toThrow(AiNetworkException::class);
    $service->cancel($w['owner'], $w['conversation'], $a['generation']);
    expect(ConversationDraftRefinement::query()->where('generation', $a['generation'])->sole()->status)->toBe('ready');
});

test('pending cancel clears every private payload and prevents provider execution', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $r = $s->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $s->cancel($w['agent'], $w['conversation'], $r['generation']);
    $provider = refinementLifecycleRun($r);
    $row = ConversationDraftRefinement::query()->sole();
    expect($provider->calls)->toBe(0)->and($row->status)->toBe('cancelled')->and($row->input)->toBeNull()
        ->and($row->scope)->toBeNull()->and($row->preview)->toBeNull();
});

test('cancellation and duplicate delivery cannot hide the active erasure claim', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $r = $s->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $observed = [];
    $provider = refinementLifecycleProvider(function () use ($w, $s, $r, &$observed): void {
        refinementLifecycleRun($r, refinementLifecycleProvider());
        $observed['claims_after_duplicate'] = DB::table('conversation_draft_refinement_sends')->count();
        $s->cancel($w['agent'], $w['conversation'], $r['generation']);
        $observed['input_after_cancel'] = ConversationDraftRefinement::query()->sole()->input;
        try {
            app(VisitorEraser::class)->erase($w['owner'], $w['conversation']->visitor);
        } catch (ValidationException $exception) {
            $observed['refusal'] = $exception->errors();
        }
    });
    refinementLifecycleRun($r, $provider);
    // Assert outside the worker's catch-all; an assertion thrown by provider code could be swallowed.
    expect($observed)->toMatchArray(['claims_after_duplicate' => 1, 'input_after_cancel' => null,
        'refusal' => ['confirmation' => [__('visitor_erasure.errors.copilot_running')]]])
        ->and(ConversationDraftRefinement::query()->sole()->status)->toBe('cancelled')
        ->and(DB::table('conversation_draft_refinement_sends')->count())->toBe(0);
});

test('deleting the owning agent clears private work while its in-flight call still holds erasure', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $r = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $observed = [];
    refinementLifecycleRun($r, refinementLifecycleProvider(function () use ($w, &$observed): void {
        $w['agent']->delete();
        $observed['private_count'] = ConversationDraftRefinement::query()->count();
        $observed['claim_count'] = DB::table('conversation_draft_refinement_sends')->count();
        try {
            app(VisitorEraser::class)->erase($w['owner'], $w['conversation']->visitor);
        } catch (ValidationException $exception) {
            $observed['refusal'] = $exception->errors();
        }
    }));
    expect($observed)->toMatchArray(['private_count' => 0, 'claim_count' => 1,
        'refusal' => ['confirmation' => [__('visitor_erasure.errors.copilot_running')]]])
        ->and(DB::table('conversation_draft_refinement_sends')->count())->toBe(0);
});

test('policy source and draft revision changes withdraw ready previews', function (string $change): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $input = refinementLifecycleInput();
    $r = $s->request($w['agent'], $w['conversation'], $input);
    refinementLifecycleRun($r);
    $revision = $input->draftRevision;
    match ($change) {
        'source' => $w['article']->update(['body' => 'Changed checkout guidance.']),
        'activity' => $w['message']->update(['body' => 'Changed checkout request.']),
        'policy' => $w['policy']->forceFill(['relevant_articles' => false, 'article_context_consent' => false, 'revision' => (string) Str::uuid()])->save(),
        'connection' => $w['connection']->forceFill(['active_revision' => (string) Str::uuid()])->save(),
        'revision' => $revision = (string) Str::uuid(),
        'expiry' => assistPreparationAdvance($w, 900),
    };
    $out = $s->show($w['agent'], $w['conversation'], $r['generation'], $revision);
    expect($out['state'])->toBe('stale')->and($out['preview'])->toBeNull()->and($out['sources'])->toBe([])
        ->and(ConversationDraftRefinement::query()->sole()->input)->toBeNull()->and(ConversationDraftRefinement::query()->sole()->scope)->toBeNull();
})->with(['source', 'activity', 'policy', 'connection', 'revision', 'expiry']);

test('changes immediately before actual dispatch and during execution never publish old source work', function (bool $before): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $r = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $change = fn () => $w['article']->update(['body' => 'Withdrawn source guidance.']);
    $provider = refinementLifecycleProvider($before ? null : $change, beforeClaim: $before ? $change : null);
    refinementLifecycleRun($r, $provider);
    expect($provider->dispatches)->toBe($before ? 0 : 1)->and(ConversationDraftRefinement::query()->sole()->preview)->toBeNull()
        ->and(ConversationDraftRefinement::query()->sole()->input)->toBeNull();
})->with([true, false]);

test('expired queued work never calls a provider and expired storage is removed by cleanup', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $r = $s->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    assistPreparationAdvance($w, 900);
    $provider = refinementLifecycleRun($r);
    expect($provider->calls)->toBe(0)->and(ConversationDraftRefinement::query()->sole()->input)->toBeNull();
    expect($s->cleanup())->toBe(1)->and(ConversationDraftRefinement::query()->count())->toBe(0);
});

test('HTTP capture preserves exact spaces and never flashes invalid private input', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $url = route('dashboard.conversations.draft-refinement.store', $w['conversation']->support_code);
    $draft = "  It may take 3 days.\r\n\t ";
    $this->actingAs($w['agent'])->postJson($url, ['refinement_draft' => $draft, 'draft_revision' => (string) Str::uuid(), 'action' => 'clarify'])
        ->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('preview', null);
    expect(json_decode(ConversationDraftRefinement::query()->sole()->input['input'], true)['draft'])->toBe($draft);
    $this->postJson($url, ['refinement_draft' => 'SYNTHETIC-PRIVATE-INVALID', 'draft_revision' => 'bad', 'action' => 'clarify'])
        ->assertUnprocessable()->assertDontSee('SYNTHETIC-PRIVATE-INVALID');
    expect(session()->getOldInput())->toBe([]);
});

test('a fresh UUID cannot evade cooldown and account budget failure queues no private request', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $r = $s->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $s->cancel($w['agent'], $w['conversation'], $r['generation']);
    expect(fn () => $s->request($w['agent'], $w['conversation'], refinementLifecycleInput()))->toThrow(AiNetworkException::class);
    assistPreparationAdvance($w, 60);
    $s->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    expect(ConversationDraftRefinement::query()->count())->toBe(1);
    config(['wayfindr.ai.usage.daily_requests' => 1]);
    expect(fn () => $s->request($w['owner'], $w['conversation'], refinementLifecycleInput()))->toThrow(AiNetworkException::class);
    expect(ConversationDraftRefinement::query()->count())->toBe(1);
});

test('Relevant articles Off performs no article lookup across capture execution read and Apply', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $w['policy']->forceFill(['relevant_articles' => false, 'article_context_consent' => false])->save();
    $s = app(DraftRefinements::class);
    DB::enableQueryLog();
    try {
        $input = refinementLifecycleInput();
        $r = $s->request($w['agent'], $w['conversation'], $input);
        refinementLifecycleRun($r);
        $out = $s->show($w['agent'], $w['conversation'], $r['generation'], $input->draftRevision, true);
        expect($out['state'])->toBe('ready')->and($out['sources'])->toBe([]);
        $queries = array_column(DB::getQueryLog(), 'query');
        expect(array_filter($queries, fn (string $sql): bool => preg_match('/\b(?:from|join)\s+["`]?articles\b/i', $sql) === 1))->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('unsafe malformed and uncertain provider outputs clear input and cannot become a preview', function (string $output): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $r = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    refinementLifecycleRun($r, refinementLifecycleProvider(output: fn () => $output));
    $row = ConversationDraftRefinement::query()->sole();
    expect($row->status)->toBe('failed')->and($row->failure_code)->toBe('invalid_output')
        ->and($row->input)->toBeNull()->and($row->scope)->toBeNull()->and($row->preview)->toBeNull();
})->with([
    'unstructured' => 'SYNTHETIC-UNSAFE-RESPONSE',
    'number changed' => '{"candidate":"It may take 5 days.","citations":[],"limitation":null}',
    'qualification dropped' => '{"candidate":"It takes 3 days.","citations":[],"limitation":null}',
    'invented action' => '{"candidate":"It may take 3 days. Your refund has been processed.","citations":[],"limitation":null}',
    'invented citation' => '{"candidate":"It may take 3 days.","citations":["src-aaaaaaaaaaaaaaaaaaaaaaaa"],"limitation":null}',
    'uncertain' => '{"candidate":"It may take 3 days.","citations":[],"limitation":"Missing facts prevent a faithful rewrite."}',
]);

test('provider failure clears encrypted content and stores no raw exception text', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $r = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    refinementLifecycleRun($r, refinementLifecycleProvider(output: fn () => throw new RuntimeException('SYNTHETIC-RAW-PROVIDER-SECRET')));
    $row = ConversationDraftRefinement::query()->sole();
    expect($row->status)->toBe('failed')->and($row->failure_code)->toBe('provider_failed')
        ->and($row->input)->toBeNull()->and($row->scope)->toBeNull()->and($row->preview)->toBeNull()
        ->and(json_encode(DB::table('conversation_draft_refinements')->sole()))->not->toContain('SYNTHETIC-RAW-PROVIDER-SECRET');
});

test('upstream cooldown metadata cannot be bypassed by pruning expired private text', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $r = $s->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $s->cancel($w['agent'], $w['conversation'], $r['generation']);
    DB::table('ai_usage_attempts')->where('attempt_id', $r['generation'])->update(['retry_not_before' => $w['clock']->time->addHour()]);
    assistPreparationAdvance($w, 900);
    $s->cleanup();
    expect(ConversationDraftRefinement::query()->count())->toBe(0);
    expect(fn () => $s->request($w['agent'], $w['conversation'], refinementLifecycleInput()))->toThrow(AiNetworkException::class);
});

test('an old worker cannot overwrite or clear a superseding private request', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $r = $s->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    refinementLifecycleRun($r, refinementLifecycleProvider(function () use ($w, $s, $r): void {
        assistPreparationAdvance($w, 60);
        $new = $s->request($w['agent'], $w['conversation'], refinementLifecycleInput('A new private draft.'));
        expect($new['generation'])->not->toBe($r['generation']);
    }));
    $row = ConversationDraftRefinement::query()->sole();
    expect($row->generation)->not->toBe($r['generation'])->and($row->status)->toBe('pending')
        ->and($row->input['input'])->toContain('A new private draft.')->and($row->preview)->toBeNull();
});

test('fresh in-flight claims survive expired private storage cleanup and age out independently', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $s = app(DraftRefinements::class);
    $r = $s->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    assistPreparationAdvance($w, 900);
    DB::table('conversation_draft_refinement_sends')->insert(['account_id' => $w['account']->id,
        'conversation_id' => $w['conversation']->id, 'generation' => $r['generation'], 'started_at' => $w['clock']->time]);
    $s->cleanup();
    expect(ConversationDraftRefinement::query()->count())->toBe(0)->and(DB::table('conversation_draft_refinement_sends')->count())->toBe(1);
    expect(fn () => app(VisitorEraser::class)->erase($w['owner'], $w['conversation']->visitor))->toThrow(ValidationException::class);
    assistPreparationAdvance($w, 180);
    $s->cleanup();
    expect(DB::table('conversation_draft_refinement_sends')->count())->toBe(0);
});

test('private preview is absent from a visitor export and disappears with conversation site or account deletion', function (string $parent): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $r = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    refinementLifecycleRun($r, refinementLifecycleProvider(output: fn () => json_encode([
        'candidate' => 'It might take 3 days. PRIVATE-PREVIEW-MARKER', 'citations' => [], 'limitation' => null], JSON_THROW_ON_ERROR)));
    expect(ConversationDraftRefinement::query()->sole()->status)->toBe('ready');
    $export = app(VisitorExporter::class)->export($w['owner'], $w['conversation']->visitor);
    try {
        $zip = new ZipArchive;
        expect($zip->open($export['path']))->toBeTrue();
        for ($index = 0; $index < $zip->numFiles; $index++) {
            expect($zip->getFromIndex($index))->not->toContain('PRIVATE-PREVIEW-MARKER', 'conversation_draft_refinements');
        }
        $zip->close();
    } finally {
        unlink($export['path']);
    }
    $w[$parent]->delete();
    expect(ConversationDraftRefinement::query()->count())->toBe(0)->and(DB::table('conversation_draft_refinement_sends')->count())->toBe(0);
})->with(['conversation', 'site', 'account']);

test('private HTTP endpoints refuse another agent without leaking preview content', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $input = refinementLifecycleInput();
    $r = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], $input);
    refinementLifecycleRun($r);
    $identity = ['generation' => $r['generation'], 'draft_revision' => $input->draftRevision];
    $this->actingAs($w['owner'])->getJson(route('dashboard.conversations.draft-refinement.show', $w['conversation']->support_code).'?'.http_build_query($identity))
        ->assertConflict()->assertDontSee('It might take')->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson(route('dashboard.conversations.draft-refinement.apply', $w['conversation']->support_code), $identity)
        ->assertConflict()->assertDontSee('It might take');
    expect(ConversationDraftRefinement::query()->sole()->status)->toBe('ready');
});

test('the real Laravel SDK adapter runs private work only through a reserved scoped transport', function (bool $changeDuringDns): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $wire = new class
    {
        public int $calls = 0;

        public array $body = [];
    };
    $dns = new class($w, $changeDuringDns) implements AiDnsResolver
    {
        public function __construct(private array $world, private bool $change) {}

        public function resolve(string $host, float $deadline): array
        {
            if ($this->change) {
                $this->world['article']->update(['body' => 'Changed during DNS admission.']);
            }

            return ['8.8.8.8'];
        }
    };
    $policy = new AiNetworkPolicy($dns, ['mode' => 'public-only'], 'synthetic-network-key');
    app()->instance(AiGuardedTransportFactory::class,
        new AiGuardedTransportFactory($policy, app(AiUsageLedger::class), app(AiRequestAuthority::class),
            function ($request) use ($wire) {
                $wire->calls++;
                $wire->body = json_decode((string) $request->getBody(), true, 16, JSON_THROW_ON_ERROR);
                $text = json_encode(['candidate' => 'It might take 3 days.', 'citations' => [], 'limitation' => null], JSON_THROW_ON_ERROR);

                return Create::promiseFor(new Response(200, [], json_encode([
                    'id' => 'synthetic-private', 'model' => 'synthetic-A', 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4],
                    'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
                ], JSON_THROW_ON_ERROR)));
            }));
    $r = app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $row = ConversationDraftRefinement::query()->sole();
    app()->call([new GenerateConversationDraftRefinement((int) $row->id, $r['generation']), 'handle']);
    expect($wire->calls)->toBe($changeDuringDns ? 0 : 1)->and($row->fresh()->input)->toBeNull()
        ->and($row->fresh()->status)->toBe($changeDuringDns ? 'failed' : 'ready');
    if (! $changeDuringDns) {
        expect(DB::table('ai_usage_attempts')->where('attempt_id', $r['generation'])->sole()->state)->toBe('completed')
            ->and($wire->body['max_tokens'])->toBeLessThanOrEqual(2048);
    }
    Http::assertNothingSent();
})->with([false, true]);
