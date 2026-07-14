<?php

namespace App\Support\Attachments;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Binds pending uploads to the message that sends them (ADR 0007). This is the
 * second half of the two-step upload: it only ever claims attachments that are
 * ready, still unbound, in this conversation, and uploaded by the same sender —
 * so a sender cannot attach another party's upload, bind across conversations,
 * or double-bind an attachment.
 *
 * Callers run this inside the message-send transaction: a bad reference throws
 * a validation error and rolls the whole send back.
 */
class AttachmentBinder
{
    /**
     * @param  array<int|string>  $attachmentIds
     * @return int the number of attachments bound
     */
    public function bind(Conversation $conversation, ConversationMessage $message, array $attachmentIds, Model $sender): int
    {
        $attachmentIds = array_values(array_unique(array_filter(array_map('intval', $attachmentIds))));

        if ($attachmentIds === []) {
            return 0;
        }

        $maxPerMessage = (int) config('wayfindr.attachments.max_per_message');

        if (count($attachmentIds) > $maxPerMessage) {
            throw ValidationException::withMessages([
                'attachment_ids' => "A message can include at most {$maxPerMessage} attachment(s).",
            ]);
        }

        // Lock the candidate rows for the duration of the send transaction so a
        // concurrent bind of the same id cannot race between this check and the
        // update below.
        $candidates = ConversationMessageAttachment::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('conversation_message_id')
            ->where('status', ConversationMessageAttachment::STATUS_READY)
            ->where('uploaded_by_type', $sender->getMorphClass())
            ->where('uploaded_by_id', $sender->getKey())
            ->whereIn('id', $attachmentIds)
            ->lockForUpdate()
            ->get();

        // Every referenced id must resolve to a bindable attachment. A missing
        // one means it was never uploaded here, belongs to someone else, is
        // already bound, or does not exist — all rejected identically so the
        // failure leaks nothing.
        if ($candidates->count() !== count($attachmentIds)) {
            throw ValidationException::withMessages([
                'attachment_ids' => 'One or more attachments are unavailable.',
            ]);
        }

        // The update re-asserts whereNull so it can only ever claim rows that
        // are still unbound. If a concurrent send bound one first, fewer rows
        // update than expected and the whole send rolls back — no silent
        // re-binding across messages.
        $bound = ConversationMessageAttachment::query()
            ->whereIn('id', $candidates->pluck('id')->all())
            ->whereNull('conversation_message_id')
            ->update(['conversation_message_id' => $message->id]);

        if ($bound !== $candidates->count()) {
            throw ValidationException::withMessages([
                'attachment_ids' => 'One or more attachments are unavailable.',
            ]);
        }

        return $bound;
    }
}
