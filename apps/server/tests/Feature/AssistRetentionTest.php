<?php

use App\Models\AiAssistFeedback;
use App\Models\AuditEvent;
use App\Support\Ai\Usage\AiUsageLedger;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());
function assistRetentionWorld(): array
{
    $w = assistPreparationWorld('conversation_reply_draft');
    assistPreparationEnsure($w);
    assistPreparationRun($w);
    $w['generation'] = $w['class']::query()->sole()->generation;
    AiAssistFeedback::query()->forceCreate(['account_id' => $w['account']->id, 'site_id' => $w['site']->id, 'conversation_id' => $w['conversation']->id, 'user_id' => $w['owner']->id, 'source_revision' => str_repeat('a', 64), 'purpose' => $w['purpose'], 'generation' => $w['generation'], 'viewed_at' => $w['clock']->time]);

    return $w;
}
test('retention is disabled by default and dry run never removes metadata', function (): void {
    $w = assistRetentionWorld();
    assistPreparationAdvance($w, 100 * 86400);
    $this->artisan('wayfindr:prune-assist-observability')->assertSuccessful();
    expect(AiAssistFeedback::query()->count())->toBe(1)->and(DB::table('ai_usage_attempts')->count())->toBe(1)->and(AuditEvent::query()->where('metadata->generation', $w['generation'])->count())->toBe(3);
    config(['wayfindr.ai.observability.feedback_retention_days' => 30, 'wayfindr.ai.observability.audit_retention_days' => 30, 'wayfindr.ai.observability.usage_retention_days' => 90]);
    $this->artisan('wayfindr:prune-assist-observability --dry-run')->assertSuccessful();
    expect(AiAssistFeedback::query()->count())->toBe(1)->and(DB::table('ai_usage_attempts')->count())->toBe(1);
    $this->artisan('wayfindr:prune-assist-observability')->assertSuccessful();
    expect(AiAssistFeedback::query()->count())->toBe(0)->and(DB::table('ai_usage_attempts')->count())->toBe(0)->and(AuditEvent::query()->where('metadata->generation', $w['generation'])->count())->toBe(0)
        ->and($w['conversation']->fresh())->not->toBeNull()->and($w['class']::query()->count())->toBe(1);
});
test('invalid retention fails before any store is modified', function ($days): void {
    $w = assistRetentionWorld();
    assistPreparationAdvance($w, 100 * 86400);
    config(['wayfindr.ai.observability.feedback_retention_days' => 1, 'wayfindr.ai.observability.usage_retention_days' => $days]);
    $this->artisan('wayfindr:prune-assist-observability')->assertFailed();
    expect(AiAssistFeedback::query()->count())->toBe(1);
})->with([1, 31, 3651, -1, 'oops']);
test('usage cleanup preserves live holds cooldowns unknown transports and current day charges', function (): void {
    $w = assistRetentionWorld();
    $old = DB::table('ai_usage_attempts')->sole();
    assistPreparationAdvance($w, 100 * 86400);
    foreach (['live_hold', 'live_retry', 'live_dispatch', 'pending', 'unknown'] as $kind) {
        $data = (array) $old;
        unset($data['id']);
        $data['attempt_id'] = (string) Str::uuid();
        if ($kind === 'live_hold') {
            $data['concurrency_held'] = true;
        }
        if ($kind === 'live_retry') {
            $data['retry_not_before'] = $w['clock']->time->addHour();
        }
        if ($kind === 'live_dispatch') {
            $data['dispatch_expires_at'] = $w['clock']->time->addHour();
        }
        if ($kind === 'pending') {
            $data['state'] = 'reserved';
        }
        if ($kind === 'unknown') {
            $data['state'] = 'unknown';
            $data['dispatch_expires_at'] = $w['clock']->time->addHour();
        }
        DB::table('ai_usage_attempts')->insert($data);
    }
    $summary = app(AiUsageLedger::class)->summary($w['account']->id);
    expect(app(AiUsageLedger::class)->pruneHistory(90))->toBe(1)->and(DB::table('ai_usage_attempts')->count())->toBe(5)
        ->and(app(AiUsageLedger::class)->summary($w['account']->id))->toBe($summary);
});
test('feedback cleanup is bounded and cannot prune unrelated audit actions', function (): void {
    $w = assistRetentionWorld();
    $old = $w['clock']->time->subDays(100);
    $rows = [];
    for ($i = 0; $i < 501; $i++) {
        $rows[] = ['account_id' => $w['account']->id, 'site_id' => $w['site']->id, 'conversation_id' => $w['conversation']->id, 'user_id' => $w['owner']->id, 'source_revision' => str_repeat('a', 64), 'purpose' => $w['purpose'], 'generation' => (string) Str::uuid(), 'created_at' => $old, 'updated_at' => $old];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('ai_assist_feedback')->insert($chunk);
    }
    $event = AuditEvent::query()->forceCreate(['account_id' => $w['account']->id, 'action' => 'conversation.closed', 'occurred_at' => $old]);
    config(['wayfindr.ai.observability.feedback_retention_days' => 30, 'wayfindr.ai.observability.audit_retention_days' => 30]);
    $this->artisan('wayfindr:prune-assist-observability')->assertSuccessful();
    expect(AiAssistFeedback::query()->count())->toBe(2)->and($event->fresh())->not->toBeNull();
    $this->artisan('wayfindr:prune-assist-observability')->assertSuccessful();
    expect(AiAssistFeedback::query()->count())->toBe(1);
});
