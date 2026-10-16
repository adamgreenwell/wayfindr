<?php

declare(strict_types=1);

use App\Http\Controllers\AgentConversationAiPreparationController;
use App\Models\AiAssistPreparationReceipt;
use App\Models\ConversationMessage;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Preparation\AiAssistPreparation;
use App\Support\Ai\Usage\AiUsageLedger;
use App\Support\Visitors\VisitorEraser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());

$tasks = ['conversation_summary', 'conversation_reply_draft', 'conversation_knowledge_suggestion', 'conversation_ticket_suggestion'];

test('equivalent opens commit one usage hold before queueing and reuse Ready without another charge', function (string $purpose): void {
    $world = assistPreparationWorld($purpose);
    $first = assistPreparationEnsure($world);
    $other = assistPreparationEnsure($world, actor: $world['agent']);
    expect($first['state'])->toBe('preparing')->and($first['queued'])->toBeTrue()
        ->and($other['generation'])->toBe($first['generation'])->and($other['queued'])->toBeFalse()
        ->and(DB::table('ai_usage_attempts')->count())->toBe(1, 'Equivalent opens must reserve once before enqueueing.')
        ->and(AiAssistPreparationReceipt::query()->count())->toBe(1);
    Queue::assertPushed($world['job'], 1);
    $provider = assistPreparationRun($world);
    $ready = assistPreparationEnsure($world, actor: $world['agent']);
    expect($ready['state'])->toBe('ready')->and($ready['can_use'])->toBeTrue()->and($ready['queued'])->toBeFalse()
        ->and($provider->calls)->toBe(1)->and(DB::table('ai_usage_attempts')->sole()->state)->toBe('completed')
        ->and(AiAssistPreparationReceipt::query()->sole()->status)->toBe('ready');
    Queue::assertPushed($world['job'], 1);
    Http::assertNothingSent();
})->with($tasks);

test('failed expired and insufficient identities never retry automatically', function (string $state): void {
    $world = assistPreparationWorld();
    if ($state === 'insufficient') {
        $world['message']->delete();
    }
    assistPreparationEnsure($world);
    $artifact = $world['class']::query()->sole();
    if ($state === 'failed') {
        $artifact->forceFill(['status' => 'failed'])->save();
        app(AiAssistPreparation::class)->syncTerminal($artifact);
    } elseif ($state === 'expired') {
        assistPreparationAdvance($world, 301);
    }
    $before = DB::table('ai_usage_attempts')->count();
    $again = assistPreparationEnsure($world);
    expect($again['queued'])->toBeFalse('Unchanged terminal or expired work must never retry on open.')
        ->and($world['class']::query()->sole()->generation)->toBe($artifact->generation)
        ->and(DB::table('ai_usage_attempts')->count())->toBe($before)
        ->and($again['state'])->toBe($state === 'insufficient' ? 'insufficient_context' : 'unavailable');
})->with(['failed', 'expired', 'insufficient']);

test('returning A B A retains the earlier terminal fingerprint receipt', function (): void {
    $world = assistPreparationWorld();
    assistPreparationEnsure($world);
    $artifact = $world['class']::query()->sole();
    $artifact->forceFill(['status' => 'failed'])->save();
    app(AiAssistPreparation::class)->syncTerminal($artifact);
    $world['message']->update(['body' => 'Distinct synthetic input B.']);
    assistPreparationEnsure($world);
    $world['message']->update(['body' => 'Synthetic checkout request.']);
    $again = assistPreparationEnsure($world);
    expect($again['queued'])->toBeFalse('A-B-A input must reuse the remembered terminal receipt, never retry automatically.')
        ->and(DB::table('ai_usage_attempts')->count())->toBe(2)
        ->and(AiAssistPreparationReceipt::query()->count())->toBe(2);
    Queue::assertPushed($world['job'], 2);
});

test('explicit refresh has a database clock cooldown and retains one readable prior output only during preparation', function (string $purpose): void {
    $world = assistPreparationWorld($purpose);
    assistPreparationEnsure($world);
    assistPreparationRun($world);
    $first = $world['class']::query()->sole();
    expect(assistPreparationEnsure($world, 'refresh')['queued'])->toBeFalse('Explicit refresh must respect the database clock cooldown.');
    assistPreparationAdvance($world, 61);
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'New synthetic activity.']);
    $refresh = assistPreparationEnsure($world, 'refresh');
    expect($refresh['queued'])->toBeTrue()->and($refresh['state'])->toBe('preparing')
        ->and($refresh['output'])->not->toBeNull()->and($refresh['can_use'])->toBeFalse()
        ->and($world['class']::query()->sole()->previous_output['generation'])->toBe($first->generation);
    assistPreparationRun($world);
    expect($world['class']::query()->sole()->previous_output)->toBeNull();
})->with($tasks);

test('a selected edit or deletion withdraws previous output while append-only refresh can retain it', function (string $change): void {
    $world = assistPreparationWorld();
    assistPreparationEnsure($world);
    assistPreparationRun($world);
    assistPreparationAdvance($world, 61);
    if ($change === 'edit') {
        $world['message']->update(['body' => 'Edited original source.']);
    } else {
        $world['message']->delete();
    }
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'New current context.']);
    $refresh = assistPreparationEnsure($world, 'refresh');
    expect($refresh['output'])->toBeNull('A revoked or deleted selected source must withdraw retained output.')
        ->and($world['class']::query()->sole()->previous_output)->toBeNull();
})->with(['edit', 'delete']);

test('a source change before dispatch or during provider wait fences generation and publication', function (string $purpose, bool $duringCall): void {
    $world = assistPreparationWorld($purpose);
    assistPreparationEnsure($world);
    if (! $duringCall) {
        $world['message']->update(['body' => 'Changed before dispatch.']);
    }
    $provider = assistPreparationProvider($duringCall ? fn () => $world['message']->update(['body' => 'Changed during provider wait.']) : null);
    assistPreparationRun($world, $provider);
    expect($world['class']::query()->sole()->status)->toBe('failed', 'Changed work must never publish Ready output.')
        ->and($provider->calls)->toBe($duringCall ? 1 : 0)
        ->and(AiAssistPreparationReceipt::query()->sole()->status)->toBe('failed');
    Http::assertNothingSent();
})->with($tasks)->with([false, true]);

function assistPreparationRoute(array $world, string $action = 'store'): string
{
    $task = array_search($world['purpose'], AgentConversationAiPreparationController::TASKS, true);

    return route('dashboard.conversations.assist.'.$action, ['supportCode' => $world['conversation']->support_code, 'task' => $task]);
}

test('GET page status and prefetch are read only and Manual requires its explicit initial action', function (string $purpose): void {
    $world = assistPreparationWorld($purpose, 'manual');
    $this->actingAs($world['owner'])->get(assistPreparationRoute($world, 'show'))->assertOk()->assertJsonPath('state', 'empty');
    $this->withHeader('Purpose', 'prefetch')->get(route('dashboard.conversations.show', $world['conversation']->support_code))->assertOk();
    expect(DB::table('ai_usage_attempts')->count())->toBe(0)->and(AiAssistPreparationReceipt::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    $payload = ['action' => 'open', 'workspace' => $world['workspace']];
    $this->postJson(assistPreparationRoute($world), $payload)->assertConflict();
    $this->flushHeaders();
    $this->postJson(assistPreparationRoute($world), $payload)->assertNotFound();
    $this->postJson(assistPreparationRoute($world), ['action' => 'initial', 'workspace' => $world['workspace']])->assertOk()->assertJsonPath('queued', true);
    Queue::assertPushed($world['job'], 1);
})->with($tasks);

test('Off rejects generation status and native Manual entry points', function (string $purpose): void {
    $world = assistPreparationWorld($purpose, 'off');
    $this->actingAs($world['owner'])->get(assistPreparationRoute($world, 'show'))->assertNotFound();
    $this->postJson(assistPreparationRoute($world), ['action' => 'open', 'workspace' => $world['workspace']])->assertNotFound();
    expect(DB::table('ai_usage_attempts')->count())->toBe(0)->and(AiAssistPreparationReceipt::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with($tasks);

test('budget refusal is terminal for opens and keeps ordinary support available', function (): void {
    $world = assistPreparationWorld();
    config(['wayfindr.ai.usage.daily_requests' => 0]);
    $first = assistPreparationEnsure($world);
    $again = assistPreparationEnsure($world);
    expect($first['state'])->toBe('unavailable')->and($again['generation'])->toBe($first['generation'])
        ->and(DB::table('ai_usage_attempts')->count())->toBe(0)
        ->and(AiAssistPreparationReceipt::query()->sole()->status)->toBe('failed');
    $this->actingAs($world['owner'])->get(route('dashboard.conversations.show', $world['conversation']->support_code))->assertOk();
    Queue::assertNothingPushed();
});

test('refresh cooldown follows the database clock despite application clock skew', function (): void {
    $world = assistPreparationWorld();
    assistPreparationEnsure($world);
    assistPreparationRun($world);
    Carbon::setTestNow($world['clock']->time->addYears(2));
    expect(assistPreparationEnsure($world, 'refresh')['queued'])->toBeFalse('Application wall time must not bypass the database-clock cooldown.');
    Queue::assertPushed($world['job'], 1);
});

test('long provider retry advice survives an intervening input fingerprint', function (): void {
    $world = assistPreparationWorld();
    assistPreparationEnsure($world);
    assistPreparationRun($world, assistPreparationProvider(function ($prompt, $receipt): never {
        app(AiUsageLedger::class)->reconcile($prompt->execution->attemptId, $receipt->dispatchNonce, null, null, 'provider_failed', 180);
        throw new AiNetworkException('provider_failed');
    }));
    $world['message']->update(['body' => 'Distinct synthetic B.']);
    assistPreparationEnsure($world);
    assistPreparationRun($world);
    $world['message']->update(['body' => 'Synthetic checkout request.']);
    assistPreparationAdvance($world, 61);
    $blocked = assistPreparationEnsure($world, 'refresh');
    expect($blocked['queued'])->toBeFalse('Provider retry advice must remain effective across A-B-A input.')
        ->and($blocked['retry_after'])->toBeGreaterThanOrEqual(119);
    assistPreparationAdvance($world, 120);
    expect(assistPreparationEnsure($world, 'refresh')['queued'])->toBeTrue();
});

test('Use rechecks generation current context and insertion authority', function (string $purpose, string $change): void {
    $world = assistPreparationWorld($purpose);
    assistPreparationEnsure($world);
    assistPreparationRun($world);
    $generation = $world['class']::query()->sole()->generation;
    $this->actingAs($world['owner'])->getJson(assistPreparationRoute($world, 'use').'?generation='.$generation)->assertOk()->assertJsonPath('can_use', true);
    match ($change) {
        'source' => $world['message']->update(['body' => 'Changed current source.']),
        'generation' => $generation = (string) Str::uuid(),
        'permission' => $world['owner']->forceFill(['deactivated_at' => now()])->save(),
    };
    $response = $this->getJson(assistPreparationRoute($world, 'use').'?generation='.$generation);
    if ($change === 'permission') {
        $response->assertRedirectToRoute('login');
    } else {
        expect(in_array($response->status(), [403, 404, 409], true))->toBeTrue('Stale or revoked Use must fail closed.');
    }
    Http::assertNothingSent();
})->with(['conversation_reply_draft', 'conversation_knowledge_suggestion', 'conversation_ticket_suggestion'])->with(['source', 'generation', 'permission']);

test('raw source edits hidden by scrubbing still fence prepared publication', function (string $purpose): void {
    $world = assistPreparationWorld($purpose);
    $world['message']->update(['body' => 'Contact alpha@example.test about checkout.']);
    assistPreparationEnsure($world);
    $before = AiSupportUsage::promptFor($world['class']::query()->sole())->input;
    assistPreparationRun($world, assistPreparationProvider(fn () => $world['message']->update(['body' => 'Contact bravo@example.test about checkout.'])));
    $artifact = $world['class']::query()->sole();
    expect(AiSupportUsage::promptFor($artifact)->input)->toBe($before)
        ->and($artifact->status)->toBe('failed', 'A selected raw source change must fence output even when sanitized provider input is identical.');
})->with($tasks);

test('superseding queued work cancels only its captured hold and old workers cannot publish', function (string $purpose): void {
    $world = assistPreparationWorld($purpose);
    assistPreparationEnsure($world);
    $first = $world['class']::query()->sole();
    $oldJob = new $world['job']($first->id, $first->generation);
    $world['message']->update(['body' => 'A newly selected context.']);
    $next = assistPreparationEnsure($world);
    $provider = assistPreparationProvider();
    app()->instance(AgentCopilotProvider::class, $provider);
    app()->call([$oldJob, 'handle']);
    expect($next['generation'])->not->toBe($first->generation)
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $first->generation)->value('state'))->toBe('cancelled')
        ->and($provider->calls)->toBe(0, 'A superseded queued worker must never reach the provider.')
        ->and($world['class']::query()->sole()->status)->toBe('pending')
        ->and(AiAssistPreparationReceipt::query()->where('generation', $first->generation)->value('status'))->toBe('failed');
})->with($tasks);

test('changed input cannot overwrite a running erasure-visible claim', function (): void {
    $world = assistPreparationWorld();
    assistPreparationEnsure($world);
    $first = $world['class']::query()->sole();
    $observed = [];
    assistPreparationRun($world, assistPreparationProvider(function () use ($world, &$observed): void {
        $started = $world['class']::query()->sole()->started_at;
        $world['message']->update(['body' => 'Changed while the provider is waiting.']);
        $observed['next'] = assistPreparationEnsure($world);
        $observed['running'] = $world['class']::query()->sole();
        $observed['start_preserved'] = $observed['running']->started_at->equalTo($started);
        $observed['erasure'] = null;
        try {
            app(VisitorEraser::class)->erase($world['owner'], $world['conversation']->visitor);
        } catch (ValidationException $exception) {
            $observed['erasure'] = $exception;
        }
    }));
    // Assert outside the provider callback: worker exception handling must not
    // swallow an assertion failure and accidentally make this test pass.
    expect($observed['next']['queued'] ?? null)->toBeFalse('Running external calls must not be superseded by an open.')
        ->and($observed['running']->generation ?? null)->toBe($first->generation)
        ->and($observed['running']->status ?? null)->toBe('running', 'Running status is an external-call claim and must survive changed-input opens.')
        ->and($observed['start_preserved'] ?? false)->toBeTrue()
        ->and($observed['erasure'] ?? null)->not->toBeNull('An external call must still hold visitor erasure.')
        ->and($observed['erasure']->errors()['confirmation'])->toContain(__('visitor_erasure.errors.copilot_running'));
    expect($world['conversation']->fresh())->not->toBeNull()->and($world['class']::query()->sole()->status)->toBe('failed');
    Queue::assertPushed($world['job'], 1);
});

test('duplicate worker deliveries publish and charge only once', function (string $purpose): void {
    $world = assistPreparationWorld($purpose);
    assistPreparationEnsure($world);
    $artifact = $world['class']::query()->sole();
    $job = new $world['job']($artifact->id, $artifact->generation);
    $provider = assistPreparationRun($world);
    app()->call([$job, 'handle']);
    expect($provider->calls)->toBe(1, 'Duplicate deliveries must claim one generation once.')
        ->and(DB::table('ai_usage_attempts')->count())->toBe(1)
        ->and((int) DB::table('ai_usage_periods')->where('scope_key', 'installation')->value('charged_requests'))->toBe(1);
})->with($tasks);

test('queue failure cannot cancel a competing replacement generation', function (): void {
    $world = assistPreparationWorld();
    $captured = null;
    $replacement = (string) Str::uuid();
    Bus::shouldReceive('dispatch')->once()->andReturnUsing(function () use ($world, &$captured, $replacement): never {
        $artifact = $world['class']::query()->sole();
        $captured = $artifact->generation;
        $artifact->forceFill(['generation' => $replacement])->save();
        throw new RuntimeException('Synthetic queue failure.');
    });
    assistPreparationEnsure($world);
    expect($world['class']::query()->sole()->generation)->toBe($replacement)
        ->and($world['class']::query()->sole()->status)->toBe('pending')
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $captured)->value('state'))->toBe('cancelled');
});

test('expired unchanged pending work needs explicit retry and can replace its unsent hold immediately', function (): void {
    $world = assistPreparationWorld();
    assistPreparationEnsure($world);
    $old = $world['class']::query()->sole()->generation;
    assistPreparationAdvance($world, 301);
    expect(assistPreparationEnsure($world)['queued'])->toBeFalse();
    $retry = assistPreparationEnsure($world, 'refresh');
    expect($retry['queued'])->toBeTrue('Explicit expired-pending retry must retire its unsent hold without a new artificial cooldown.')
        ->and($retry['generation'])->not->toBe($old)
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $old)->value('state'))->toBe('cancelled')
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $retry['generation'])->value('state'))->toBe('reserved');
});

test('explicit refresh still coalesces a fresh pending generation after its initial cooldown', function (): void {
    $world = assistPreparationWorld();
    $first = assistPreparationEnsure($world);
    assistPreparationAdvance($world, 61);
    $repeat = assistPreparationEnsure($world, 'refresh');
    expect($repeat['queued'])->toBeFalse()->and($repeat['generation'])->toBe($first['generation']);
    Queue::assertPushed($world['job'], 1);
});

test('a missing receipt store fails closed before reservation and queueing', function (): void {
    $world = assistPreparationWorld();
    Schema::drop('ai_assist_preparation_receipts');
    $this->actingAs($world['owner'])->postJson(assistPreparationRoute($world), ['action' => 'open', 'workspace' => 'conversation'])->assertStatus(503);
    expect(DB::table('ai_usage_attempts')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a raw edit after context capture is refused at the actual guarded delivery boundary', function (): void {
    $world = assistPreparationWorld('conversation_reply_draft');
    $world['message']->update(['body' => 'Contact alpha@example.test about checkout.']);
    assistPreparationEnsure($world);
    $artifact = $world['class']::query()->sole();
    $artifact->forceFill(['status' => 'running', 'started_at' => $world['clock']->time])->save();
    $usage = app(AiSupportUsage::class);
    $connection = app(AgentCopilotConnectionResolver::class)->forAccount($world['account']->id);
    $prompt = AiSupportUsage::promptFor($artifact)->withExecution($connection, $usage->contextFor($artifact));
    $world['message']->update(['body' => 'Contact bravo@example.test about checkout.']);
    $failure = null;
    try {
        assistPreparationProvider()->generate($prompt);
    } catch (AiNetworkException $exception) {
        $failure = $exception;
    }
    expect($failure)->not->toBeNull('A raw edit after context capture must be refused before delivery.')
        ->and(DB::table('ai_usage_attempts')->sole()->state)->toBe('cancelled')
        ->and((int) DB::table('ai_usage_periods')->where('scope_key', 'installation')->value('charged_requests'))->toBe(0, 'Refused source revisions must never cross dispatch accounting.');
});

test('failed refresh discards the retained previous slot rather than resurfacing old output', function (string $purpose): void {
    $world = assistPreparationWorld($purpose);
    assistPreparationEnsure($world);
    assistPreparationRun($world);
    assistPreparationAdvance($world, 61);
    assistPreparationEnsure($world, 'refresh');
    expect($world['class']::query()->sole()->previous_output)->not->toBeNull();
    assistPreparationRun($world, assistPreparationProvider(fn () => throw new RuntimeException('Synthetic provider failure.')));
    $state = app(AiAssistPreparation::class)->read($world['owner'], $world['conversation'], $purpose);
    expect($state['state'])->toBe('unavailable')->and($state['output'])->toBeNull()
        ->and($world['class']::query()->sole()->previous_output)->toBeNull('A terminal refresh must discard its previous output slot.');
})->with($tasks);

test('retrying an expired refresh preserves its one authorized prior slot and active receipt', function (): void {
    $world = assistPreparationWorld();
    assistPreparationEnsure($world);
    assistPreparationRun($world);
    assistPreparationAdvance($world, 61);
    assistPreparationEnsure($world, 'refresh');
    assistPreparationAdvance($world, 301);
    $retry = assistPreparationEnsure($world, 'refresh');
    expect($retry['queued'])->toBeTrue('Retiring expired refresh must admit its replacement.')->and($retry['state'])->toBe('preparing', 'Retiring expired refresh must retain an active receipt.')
        ->and($retry['output'])->not->toBeNull('Retiring an expired refresh must retain its already authorized prior output.')
        ->and(AiAssistPreparationReceipt::query()->sole()->status)->toBe('pending');
});
