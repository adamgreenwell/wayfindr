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
 * Sends a support-side reply on to a visitor by email.
 *
 * Two kinds of conversation get one:
 *
 *   - one that ARRIVED by email, which is answered the way it was asked;
 *   - one opened in the widget while the desk was AWAY. The widget demanded an
 *     address then because it is the only way back to somebody, and told them
 *     we would reply when we were back. Without this that promise was kept only
 *     for somebody who happened to reopen the same browser tab.
 *
 * Any other widget conversation is not mailed. A visitor sitting in the widget
 * is already being told in the widget, and mailing them as well would be the
 * product talking over itself.
 *
 * Only replies reach this class -- the agent composer and the API reply
 * endpoint call it with the message they just stored. There is no internal-note
 * path into it: conversations carry no private messages, and ticket notes live
 * in ticket activity, which nothing here reads.
 */
final class ConversationReplyMailer
{
    /** Conversation metadata: opened while away, with an address to answer. */
    public const REPLY_BY_EMAIL = 'reply_by_email';

    /** Conversation metadata: the widget language that promise was made in. */
    public const REPLY_LOCALE = 'reply_locale';

    public function __construct(private readonly OutboundMail $outboundMail) {}

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

            $email = $conversation === null ? null : $this->recipient($conversation);

            if ($email === null) {
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
     * Where a reply sent now would be emailed, or null when it would not be.
     *
     * The one rule, read by send() and by the agent page: an agent told that a
     * reply is emailed must be told by the same code that emails it.
     */
    public function recipient(Conversation $conversation): ?string
    {
        $email = $this->visitorEmail($conversation);

        if ($email === null) {
            return null;
        }

        if ($this->arrivedByEmail($conversation)) {
            // Unchanged for this channel: it is answered from the address the
            // visitor wrote to, and without one there is nothing to answer from.
            return $conversation->site?->inbound_address === null ? null : $email;
        }

        if ($this->promisedWhileAway($conversation)) {
            // No inbound address is needed here. The email then carries no
            // Reply-To and tells the visitor to come back to the chat instead
            // (mail/conversation-reply). What IS needed is a transport that
            // delivers: `log` accepts the message and sends it nowhere, and an
            // outbox row marked accepted would tell the agent it went.
            return $this->outboundMail->delivers() ? $email : null;
        }

        return null;
    }

    /**
     * What the agent is told about email on a conversation opened while away.
     *
     * Only that case. A conversation that arrived by email says so in every
     * message it holds; a widget conversation looks like any other unless the
     * page says otherwise.
     *
     * @return array{state: 'emailed'|'not_emailed', address: string}|null
     */
    public function awayNotice(Conversation $conversation): ?array
    {
        if ($this->arrivedByEmail($conversation) || ! $this->promisedWhileAway($conversation)) {
            return null;
        }

        $email = $this->visitorEmail($conversation);

        if ($email === null) {
            return null;
        }

        return [
            'state' => $this->recipient($conversation) === null ? 'not_emailed' : 'emailed',
            'address' => $email,
        ];
    }

    private function arrivedByEmail(Conversation $conversation): bool
    {
        return ($conversation->metadata['channel'] ?? null) === 'email';
    }

    private function promisedWhileAway(Conversation $conversation): bool
    {
        return ($conversation->metadata[self::REPLY_BY_EMAIL] ?? null) === true;
    }

    private function visitorEmail(Conversation $conversation): ?string
    {
        $email = $conversation->visitor?->email;

        return is_string($email) && trim($email) !== '' ? trim($email) : null;
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
