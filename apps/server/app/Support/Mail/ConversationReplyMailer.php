<?php

namespace App\Support\Mail;

use App\Mail\ConversationReplyMessage;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sends a support-side reply on to a visitor who arrived by email.
 *
 * Only for conversations that came in that way. A visitor sitting in the widget
 * is already being told in the widget, and mailing them as well would be the
 * product talking over itself.
 */
final class ConversationReplyMailer
{
    public function send(ConversationMessage $message): bool
    {
        return DB::transaction(function () use ($message): bool {
            // Replays and concurrent API requests all converge on this row.
            // With the shipped database queue, the job insert and delivery
            // marker commit together; with another queue, a failed push rolls
            // the marker back so a later replay can try again.
            $lockedMessage = ConversationMessage::query()
                ->whereKey($message->id)
                ->lockForUpdate()
                ->first();

            if ($lockedMessage === null) {
                return false;
            }

            if ($lockedMessage->email_message_id !== null) {
                $message->forceFill(['email_message_id' => $lockedMessage->email_message_id]);

                return false;
            }

            $lockedMessage->loadMissing(['attachments', 'conversation.site', 'conversation.visitor']);
            $conversation = $lockedMessage->conversation;

            if (! $this->shouldSend($conversation)) {
                return false;
            }

            $site = $conversation->site;
            $email = $conversation->visitor?->email;

            if ($site?->inbound_address === null || $email === null) {
                return false;
            }

            // Minted once for both the job and the row. A later reply threads
            // against this exact string; generating it inside the mailable
            // would leave the row holding a different one.
            $messageId = '<'.Str::uuid()->toString().'@wayfindr>';

            Mail::to($email)->queue(new ConversationReplyMessage(
                $lockedMessage,
                $site,
                $messageId,
                $this->parentMessageId($conversation),
            ));

            $lockedMessage->forceFill(['email_message_id' => $messageId])->save();
            $message->forceFill(['email_message_id' => $messageId]);

            return true;
        });
    }

    /**
     * Only conversations that arrived by email get answered by email.
     */
    private function shouldSend(?Conversation $conversation): bool
    {
        return $conversation !== null
            && ($conversation->metadata['channel'] ?? null) === 'email';
    }

    /**
     * The newest stored Message-ID that is not this reply, so the thread hangs
     * off what the visitor last saw rather than off the start of it.
     */
    private function parentMessageId(Conversation $conversation): ?string
    {
        return $conversation->messages()
            ->whereNotNull('email_message_id')
            ->orderByDesc('id')
            ->value('email_message_id');
    }
}
