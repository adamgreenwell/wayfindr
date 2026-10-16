<?php

declare(strict_types=1);

use App\Models\AiAssistPreparationReceipt;
use App\Models\ConversationCopilotReplyDraft;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());

test('preparation metadata migration rolls back without changing existing suggestion content', function (): void {
    $world = assistPreparationWorld('conversation_reply_draft');
    $artifact = ConversationCopilotReplyDraft::query()->forceCreate([
        'conversation_id' => $world['conversation']->id, 'requested_by_id' => $world['owner']->id,
        'generation' => (string) Str::uuid(), 'status' => 'ready', 'draft' => 'Existing synthetic suggestion.', 'requested_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_10_16_180000_add_shared_ai_preparation_state.php');
    $migration->down();
    expect(Schema::hasTable('ai_assist_preparation_receipts'))->toBeFalse()
        ->and(Schema::hasColumn($artifact->getTable(), 'preparation_fingerprint'))->toBeFalse()
        ->and($artifact->fresh()->draft)->toBe('Existing synthetic suggestion.');
    $migration->up();
    expect($artifact->fresh()->generation)->toBe($artifact->generation)
        ->and($artifact->fresh()->draft)->toBe('Existing synthetic suggestion.')
        ->and($artifact->fresh()->preparation_fingerprint)->toBeNull()
        ->and($artifact->fresh()->previous_output)->toBeNull()
        ->and(AiAssistPreparationReceipt::query()->count())->toBe(0);
});
