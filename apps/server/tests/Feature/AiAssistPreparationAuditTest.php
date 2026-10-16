<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Support\Visitors\VisitorEraser;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());

function preparationAuditTask(string $purpose): array
{
    return match ($purpose) {
        'conversation_summary' => ['ai_summary', 'summary_id'],
        'conversation_reply_draft' => ['ai_reply_draft', 'draft_id'],
        'conversation_knowledge_suggestion' => ['ai_knowledge_suggestion', 'suggestion_id'],
        'conversation_ticket_suggestion' => ['ai_ticket_suggestion', 'suggestion_id'],
    };
}

$tasks = ['conversation_summary', 'conversation_reply_draft', 'conversation_knowledge_suggestion', 'conversation_ticket_suggestion'];

test('admission failures retain one sanitized request and failure event per generation', function (string $purpose, string $failure): void {
    $world = assistPreparationWorld($purpose);
    if ($failure === 'usage') {
        config(['wayfindr.ai.usage.daily_requests' => 0]);
    } else {
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('PRIVATE SYNTHETIC FAILURE BODY MUST NOT BE RETAINED'));
    }
    $state = assistPreparationEnsure($world);
    expect($state['state'])->toBe('unavailable')->and($state['queued'])->toBeFalse();
    $artifact = $world['class']::query()->sole();
    [$event,$artifactKey] = preparationAuditTask($purpose);
    $events = AuditEvent::query()->where('account_id', $world['account']->id)->whereIn('action', ['conversation.'.$event.'.requested', 'conversation.'.$event.'.failed'])->orderBy('id')->get();
    expect($events)->toHaveCount(2, 'A terminal admission must preserve both its request and sanitized failure audit events.');
    $metadata = [$artifactKey => $artifact->id, 'generation' => $artifact->generation, 'connection_revision' => $artifact->connection_revision];
    expect($events[0]->action)->toBe('conversation.'.$event.'.requested')->and($events[0]->metadata)->toBe($metadata)
        ->and($events[1]->action)->toBe('conversation.'.$event.'.failed')->and($events[1]->metadata)->toBe($metadata + ['failure_code' => $failure]);
    foreach ($events as $audit) {
        expect($audit->actor_id)->toBe($world['owner']->id)->and($audit->actor_type)->toBe($world['owner']->getMorphClass())
            ->and($audit->subject_id)->toBe($world['conversation']->id)->and($audit->site_id)->toBe($world['site']->id)
            ->and($audit->occurred_at->equalTo($world['clock']->time))->toBeTrue();
    }
    expect(json_encode($events->pluck('metadata')))->not->toContain('PRIVATE SYNTHETIC')->not->toContain('Synthetic checkout request.')->not->toContain('Synthetic checkout help');
    assistPreparationEnsure($world, actor: $world['agent']);
    expect(AuditEvent::query()->whereIn('action', ['conversation.'.$event.'.requested', 'conversation.'.$event.'.failed'])->count())->toBe(2, 'Reusing a terminal receipt must not invent another attempt or failure audit.');
})->with($tasks)->with(['usage', 'queue']);

test('late queue failure never audits a competing replacement as failed', function (string $purpose): void {
    $world = assistPreparationWorld($purpose);
    $replacement = (string) Str::uuid();
    Bus::shouldReceive('dispatch')->once()->andReturnUsing(function () use ($world, $replacement): never {
        $world['class']::query()->sole()->forceFill(['generation' => $replacement])->save();
        throw new RuntimeException('Synthetic queue acknowledgement failure.');
    });
    assistPreparationEnsure($world);
    [$event] = preparationAuditTask($purpose);
    expect($world['class']::query()->sole()->generation)->toBe($replacement)
        ->and(AuditEvent::query()->where('action', 'conversation.'.$event.'.failed')->count())->toBe(0, 'A late queue failure must not claim that a replacement generation failed.');
    expect(AuditEvent::query()->where('action', 'conversation.'.$event.'.requested')->count())->toBe(1);
})->with($tasks);

test('queue acknowledgement failure cannot recreate an audit after visitor erasure', function (string $purpose): void {
    $world = assistPreparationWorld($purpose);
    Bus::shouldReceive('dispatch')->once()->andReturnUsing(function () use ($world): never {
        app(VisitorEraser::class)->erase($world['owner'], $world['conversation']->visitor);
        throw new RuntimeException('Synthetic queue acknowledgement failure after erasure.');
    });
    $refused = false;
    try {
        assistPreparationEnsure($world);
    } catch (AuthorizationException) {
        $refused = true;
    }
    [$event] = preparationAuditTask($purpose);
    expect($refused)->toBeTrue()->and($world['conversation']->fresh())->toBeNull()
        ->and(AuditEvent::query()->where('action', 'conversation.'.$event.'.failed')->count())->toBe(0, 'A late queue acknowledgement must not recreate erased audit references.');
})->with($tasks);
