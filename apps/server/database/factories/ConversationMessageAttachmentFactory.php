<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
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
            // Derive the denormalized conversation/owner columns from the
            // message's conversation so a bare create() is always
            // scope-consistent with production. Tests that build their own
            // message should prefer forMessage() to avoid the extra lookups.
            'conversation_id' => fn (array $attributes) => $this
                ->conversationFor($attributes['conversation_message_id'])?->id,
            'account_id' => fn (array $attributes) => $this
                ->conversationFor($attributes['conversation_message_id'])?->site?->account_id,
            'site_id' => fn (array $attributes) => $this
                ->conversationFor($attributes['conversation_message_id'])?->site_id,
            'uploaded_by_type' => null,
            'uploaded_by_id' => null,
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
     * Attach to an existing message, keeping conversation/account/site
     * consistent with the message's conversation.
     */
    public function forMessage(ConversationMessage $message): static
    {
        $conversation = $message->conversation()->with('site')->first();

        return $this->state([
            'conversation_message_id' => $message->id,
            'conversation_id' => $conversation?->id,
            'account_id' => $conversation?->site?->account_id,
            'site_id' => $conversation?->site_id,
        ]);
    }

    /**
     * A not-yet-sent upload: conversation-scoped, owned by its uploader, with no
     * message binding.
     */
    public function pendingFor(Conversation $conversation, Model $uploader): static
    {
        $conversation->loadMissing('site');

        return $this->state([
            'conversation_message_id' => null,
            'conversation_id' => $conversation->id,
            'account_id' => $conversation->site?->account_id,
            'site_id' => $conversation->site_id,
            'uploaded_by_type' => $uploader->getMorphClass(),
            'uploaded_by_id' => $uploader->getKey(),
            'status' => ConversationMessageAttachment::STATUS_READY,
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
