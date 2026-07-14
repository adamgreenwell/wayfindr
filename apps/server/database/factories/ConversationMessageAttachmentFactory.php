<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConversationMessageAttachment>
 */
class ConversationMessageAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_message_id' => ConversationMessage::factory(),
            // Derive the denormalized owner columns from the message's
            // conversation so a bare create() is always scope-consistent with
            // production. Tests that build their own message should prefer
            // forMessage() to avoid the extra lookups.
            'account_id' => fn (array $attributes) => $this
                ->conversationFor($attributes['conversation_message_id'])?->site?->account_id,
            'site_id' => fn (array $attributes) => $this
                ->conversationFor($attributes['conversation_message_id'])?->site_id,
            'storage_disk' => 'attachments',
            'storage_key' => 'attachments/'.Str::lower((string) Str::ulid()).'/'.Str::lower((string) Str::ulid()),
            'original_filename' => 'screenshot.png',
            'mime_type' => 'image/png',
            'size_bytes' => 2048,
            'checksum' => hash('sha256', (string) Str::ulid()),
            'status' => ConversationMessageAttachment::STATUS_READY,
            'scan_status' => null,
            'scanned_at' => null,
        ];
    }

    /**
     * Attach to an existing message, keeping account/site consistent with the
     * message's conversation.
     */
    public function forMessage(ConversationMessage $message): static
    {
        $conversation = $message->conversation()->with('site')->first();

        return $this->state([
            'conversation_message_id' => $message->id,
            'account_id' => $conversation?->site?->account_id,
            'site_id' => $conversation?->site_id,
        ]);
    }

    public function quarantined(): static
    {
        return $this->state([
            'status' => ConversationMessageAttachment::STATUS_QUARANTINED,
            'scan_status' => 'pending',
        ]);
    }

    private function conversationFor(int $messageId): ?Conversation
    {
        return ConversationMessage::query()
            ->whereKey($messageId)
            ->with('conversation.site')
            ->first()?->conversation;
    }
}
