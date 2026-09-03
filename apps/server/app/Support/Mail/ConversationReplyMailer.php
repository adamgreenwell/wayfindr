<?php

namespace App\Support\Mail;

use App\Mail\ConversationReplyMessage;
use App\Models\Conversation;
use App\Models\ConversationMessage;
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
        $conversation = $message->conversation;

        if (! $this->shouldSend($conversation)) {
            return false;
        }

        $site = $conversation->site;
        $email = $conversation->visitor?->email;

        if ($site?->inbound_address === null || $email === null) {
            return false;
        }

        // Minted and stored before the send, because a later reply threads
        // against this exact string. Generating it inside the mailable would
        // leave the row holding a different one -- and a thread that cannot
        // find its parent starts a second conversation.
        $messageId = '<'.Str::uuid()->toString().'@wayfindr>';

        $message->forceFill(['email_message_id' => $messageId])->save();

        Mail::to($email)->queue(new ConversationReplyMessage(
            $message,
            $site,
            $messageId,
            $this->parentMessageId($conversation),
        ));

        return true;
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
            ->skip(1)
            ->value('email_message_id');
    }
}
