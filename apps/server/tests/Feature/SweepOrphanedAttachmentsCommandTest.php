<?php

// The retention sweep for conversation message attachments (ADR 0007):
// wayfindr:sweep-orphaned-attachments removes (A) abandoned/failed uploads that
// never became part of a message, and (B) orphaned storage objects left when a
// database FK cascade dropped a row without deleting its binary. Together with
// the model's deleting hook this closes the storage-leak gap.

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('attachments');
});

function conversationWithVisitor(): array
{
    $site = Site::factory()->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->create(['visitor_id' => $visitor->id]);

    return [$conversation, $visitor];
}

function seedFile(ConversationMessageAttachment $attachment, string $content = 'bytes'): void
{
    Storage::disk('attachments')->put($attachment->storage_key, $content);
}

function backdateFile(string $key, int $hours): void
{
    touch(Storage::disk('attachments')->path($key), now()->subHours($hours)->getTimestamp());
}

// --- Phase A: abandoned / failed uploads ---------------------------------

test('an abandoned pending upload past the expiry is removed with its binary', function (): void {
    [$conversation, $visitor] = conversationWithVisitor();
    $attachment = ConversationMessageAttachment::factory()
        ->pendingFor($conversation, $visitor)
        ->create(['created_at' => now()->subHours(48)]);
    seedFile($attachment);

    $this->artisan('wayfindr:sweep-orphaned-attachments')->assertSuccessful();

    expect(ConversationMessageAttachment::whereKey($attachment->id)->exists())->toBeFalse();
    Storage::disk('attachments')->assertMissing($attachment->storage_key);
});

test('a failed unbound upload is removed regardless of age', function (): void {
    [$conversation, $visitor] = conversationWithVisitor();
    $attachment = ConversationMessageAttachment::factory()
        ->pendingFor($conversation, $visitor)
        ->create(['status' => ConversationMessageAttachment::STATUS_FAILED]);
    seedFile($attachment);

    $this->artisan('wayfindr:sweep-orphaned-attachments')->assertSuccessful();

    expect(ConversationMessageAttachment::whereKey($attachment->id)->exists())->toBeFalse();
    Storage::disk('attachments')->assertMissing($attachment->storage_key);
});

test('a fresh pending upload within the expiry window is kept', function (): void {
    [$conversation, $visitor] = conversationWithVisitor();
    $attachment = ConversationMessageAttachment::factory()
        ->pendingFor($conversation, $visitor)
        ->create();
    seedFile($attachment);

    $this->artisan('wayfindr:sweep-orphaned-attachments')->assertSuccessful();

    expect(ConversationMessageAttachment::whereKey($attachment->id)->exists())->toBeTrue();
    Storage::disk('attachments')->assertExists($attachment->storage_key);
});

test('a bound attachment is never swept, even when old', function (): void {
    [$conversation, $visitor] = conversationWithVisitor();
    $message = ConversationMessage::factory()->for($conversation)->create();
    $attachment = ConversationMessageAttachment::factory()
        ->forMessage($message)
        ->create(['created_at' => now()->subHours(72)]);
    seedFile($attachment);

    $this->artisan('wayfindr:sweep-orphaned-attachments')->assertSuccessful();

    expect(ConversationMessageAttachment::whereKey($attachment->id)->exists())->toBeTrue();
    Storage::disk('attachments')->assertExists($attachment->storage_key);
});

// --- Phase B: orphaned storage objects -----------------------------------

test('an orphaned file with no row past the grace window is deleted', function (): void {
    $key = 'aaa/bbb';
    Storage::disk('attachments')->put($key, 'orphan');
    backdateFile($key, 3);

    $this->artisan('wayfindr:sweep-orphaned-attachments')->assertSuccessful();

    Storage::disk('attachments')->assertMissing($key);
});

test('a fresh orphaned file within the grace window is kept', function (): void {
    $key = 'ccc/ddd';
    Storage::disk('attachments')->put($key, 'too-fresh');

    $this->artisan('wayfindr:sweep-orphaned-attachments')->assertSuccessful();

    Storage::disk('attachments')->assertExists($key);
});

test('deleting a conversation leaves an orphan file that the sweep then reaps', function (): void {
    [$conversation, $visitor] = conversationWithVisitor();
    $message = ConversationMessage::factory()->for($conversation)->create();
    $attachment = ConversationMessageAttachment::factory()->forMessage($message)->create();
    seedFile($attachment);
    $key = $attachment->storage_key;

    // A database FK cascade drops the row without loading the model, so the
    // deleting hook never runs and the binary is left behind.
    $conversation->delete();

    expect(ConversationMessageAttachment::whereKey($attachment->id)->exists())->toBeFalse();
    Storage::disk('attachments')->assertExists($key);

    backdateFile($key, 3);
    $this->artisan('wayfindr:sweep-orphaned-attachments')->assertSuccessful();

    Storage::disk('attachments')->assertMissing($key);
});

// --- Model hook + dry run -------------------------------------------------

test('deleting an attachment model removes its binary', function (): void {
    [$conversation, $visitor] = conversationWithVisitor();
    $attachment = ConversationMessageAttachment::factory()->pendingFor($conversation, $visitor)->create();
    seedFile($attachment);
    $key = $attachment->storage_key;

    $attachment->delete();

    Storage::disk('attachments')->assertMissing($key);
});

test('dry run reports but changes nothing', function (): void {
    [$conversation, $visitor] = conversationWithVisitor();
    $abandoned = ConversationMessageAttachment::factory()
        ->pendingFor($conversation, $visitor)
        ->create(['created_at' => now()->subHours(48)]);
    seedFile($abandoned);

    $orphanKey = 'eee/fff';
    Storage::disk('attachments')->put($orphanKey, 'orphan');
    backdateFile($orphanKey, 3);

    $this->artisan('wayfindr:sweep-orphaned-attachments --dry-run')
        ->expectsOutputToContain('Would remove')
        ->assertSuccessful();

    expect(ConversationMessageAttachment::whereKey($abandoned->id)->exists())->toBeTrue();
    Storage::disk('attachments')->assertExists($abandoned->storage_key);
    Storage::disk('attachments')->assertExists($orphanKey);
});
