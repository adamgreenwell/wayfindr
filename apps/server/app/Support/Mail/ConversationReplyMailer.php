<?php

namespace App\Support\Mail;

use App\Jobs\SendConversationReplyDelivery;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationReplyDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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
        $delivery = DB::transaction(function () use ($message): ?ConversationReplyDelivery {
            // Replays and concurrent API requests converge on this row before
            // they create or requeue the one durable outbox record.
            $lockedMessage = ConversationMessage::query()
                ->whereKey($message->id)
                ->lockForUpdate()
                ->first();

            if ($lockedMessage === null) {
                return null;
            }

            if ($lockedMessage->email_message_id !== null) {
                $message->forceFill(['email_message_id' => $lockedMessage->email_message_id]);

                $existing = $lockedMessage->replyDelivery()->first();

                // Messages from before the outbox migration already used this
                // column as their successful handoff marker. Do not turn them
                // into duplicate mail during an upgrade.
                if ($existing === null) {
                    return null;
                }

                if ($existing->accepted_at !== null) {
                    return null;
                }

                $existing->forceFill(['failed_at' => null])->save();

                return $existing;
            }

            $lockedMessage->loadMissing(['attachments', 'conversation.site', 'conversation.visitor']);
            $conversation = $lockedMessage->conversation;

            if (! $this->shouldSend($conversation)) {
                return null;
            }

            $site = $conversation->site;
            $email = $conversation->visitor?->email;

            if ($site?->inbound_address === null || $email === null) {
                return null;
            }

            // Minted once for both the job and the row. A later reply threads
            // against this exact string; generating it inside the mailable
            // would leave the row holding a different one.
            $messageId = '<'.Str::uuid()->toString().'@wayfindr>';

            $delivery = $lockedMessage->replyDelivery()->create([
                'recipient' => $email,
                'message_id' => $messageId,
                'in_reply_to' => $this->parentMessageId($conversation),
            ]);

            $lockedMessage->forceFill(['email_message_id' => $messageId])->save();
            $message->forceFill(['email_message_id' => $messageId]);

            return $delivery;
        });

        if ($delivery === null) {
            return false;
        }

        // The outbox commits first. If Redis is unavailable or this process
        // exits here, the scheduler (or an idempotent API replay) finds the
        // pending row and dispatches this same unique job later.
        try {
            SendConversationReplyDelivery::dispatchPending($delivery->id);
        } catch (Throwable $exception) {
            // The committed outbox row is the acceptance boundary for every
            // caller, including the human-agent form. Surfacing a 500 here
            // invites a resubmit and therefore a second real reply. The
            // scheduler will retry this same row on its next pass.
            Log::error('Conversation reply stored, but its immediate queue handoff failed.', [
                'conversation_reply_delivery_id' => $delivery->id,
                'exception' => $exception->getMessage(),
            ]);
        }

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
            ->value('email_message_id');
    }
}
