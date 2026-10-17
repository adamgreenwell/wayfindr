<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\ConversationMessage;
use App\Models\SiteAiAssistPolicy;
use App\Support\Ai\ConversationAssistPresentation;
use App\Support\Ai\Preparation\AiAssistPreparation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());

test('selected processor presentation whitelists scalars without endpoint credentials or revisions', function (): void {
    $world = assistPreparationWorld();
    $processor = app(ConversationAssistPresentation::class)->processor($world['conversation']);
    expect(array_keys($processor))->toBe(['provider', 'model', 'source', 'upstream', 'max_characters', 'article_context_allowed'])
        ->and($processor)->toBe(['provider' => 'openai-compatible', 'model' => 'synthetic-A', 'source' => 'account', 'upstream' => null, 'max_characters' => 20000, 'article_context_allowed' => true]);
    $serialized = json_encode($processor, JSON_THROW_ON_ERROR);
    expect($serialized)->not->toContain('synthetic-not-a-secret', 'models.example.test', 'revision', 'account_id', 'has_api_key');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('processor presentation uses the narrowest effective context and fails closed after disconnection', function (): void {
    $world = assistPreparationWorld();
    SiteAiAssistPolicy::query()->whereKey($world['site']->id)->update(['max_context_characters' => 1800]);
    expect(app(ConversationAssistPresentation::class)->processor($world['conversation'])['max_characters'])->toBe(1800);
    $world['connection']->forceFill(['enabled' => false])->save();
    expect(app(ConversationAssistPresentation::class)->processor($world['conversation']))->toBeNull();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('provenance uses conversation relative transcript order rather than global message identifiers', function (): void {
    $world = assistPreparationWorld();
    $world['message']->forceFill(['created_at' => now()->subMinutes(2)])->save();
    $foreign = Conversation::factory()->create();
    ConversationMessage::factory()->for($foreign)->count(3)->create();
    $second = ConversationMessage::factory()->for($world['conversation'])->create(['created_at' => now()->subMinute()]);
    ConversationMessage::factory()->for($world['conversation'])->create(['created_at' => now()]);
    $artifact = new ConversationCopilotReplyDraft(['conversation_id' => $world['conversation']->id,
        'source_last_message_id' => $second->id, 'source_message_count' => 2]);
    $provenance = app(ConversationAssistPresentation::class)->provenance($world['conversation'], $artifact);
    expect($provenance)->toBe(['source_position' => $second->id, 'source_ordinal' => 2, 'source_message_count' => 2,
        'source_url' => route('dashboard.conversations.show', $world['conversation']->support_code).'#conversation-message-'.$second->id]);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('provenance never resolves a missing or different conversation source', function (string $change): void {
    $world = assistPreparationWorld();
    $artifact = new ConversationCopilotReplyDraft(['conversation_id' => $world['conversation']->id,
        'source_last_message_id' => $world['message']->id, 'source_message_count' => 1]);
    if ($change === 'foreign') {
        $artifact->conversation_id = Conversation::factory()->create()->id;
    } elseif ($change === 'missing') {
        $world['message']->delete();
    } else {
        $artifact->source_last_message_id = null;
    }
    expect(app(ConversationAssistPresentation::class)->provenance($world['conversation'], $artifact))->toBeNull();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['foreign', 'missing', 'unsent']);

test('prepared provenance follows retained output rather than its queued replacement', function (string $purpose): void {
    $world = assistPreparationWorld($purpose);
    assistPreparationEnsure($world);
    $provider = assistPreparationRun($world);
    $first = app(AiAssistPreparation::class)->read($world['agent'], $world['conversation'], $purpose);
    assistPreparationAdvance($world, 61);
    $latest = ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Later checkout request.']);
    $refresh = assistPreparationEnsure($world, 'refresh');
    expect($refresh['state'])->toBe('preparing')->and($refresh['can_use'])->toBeFalse()
        ->and($refresh['generation'])->not->toBe($first['generation'])
        ->and($refresh['output_generation'])->toBe($first['output_generation'])
        ->and($refresh['source_position'])->toBe($first['source_position'])
        ->and($refresh['source_position'])->not->toBe($latest->id)
        ->and($refresh['source_message_count'])->toBe($first['source_message_count'])
        ->and($refresh['source_ordinal'])->toBe($first['source_ordinal'])
        ->and($refresh['source_url'])->toBe($first['source_url']);
    Queue::fake();
    $before = DB::table('ai_usage_attempts')->count();
    app(AiAssistPreparation::class)->read($world['agent'], $world['conversation'], $purpose);
    expect(DB::table('ai_usage_attempts')->count())->toBe($before)->and($provider->calls)->toBe(1);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['conversation_summary', 'conversation_reply_draft', 'conversation_knowledge_suggestion']);

test('withdrawing selected text removes output and all presented provenance', function (string $change): void {
    $world = assistPreparationWorld('conversation_reply_draft');
    assistPreparationEnsure($world);
    assistPreparationRun($world);
    match ($change) {
        'edited' => $world['message']->update(['body' => 'Changed original request.']),
        'deleted' => $world['message']->delete(),
    };
    Queue::fake();
    $state = app(AiAssistPreparation::class)->read($world['agent'], $world['conversation'], $world['purpose']);
    expect($state['output'])->toBeNull()->and($state['output_generation'])->toBeNull()
        ->and($state['source_position'])->toBeNull()->and($state['source_ordinal'])->toBeNull()
        ->and($state['source_message_count'])->toBeNull()->and($state['source_url'])->toBeNull()
        ->and($state['can_use'])->toBeFalse();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['edited', 'deleted']);
