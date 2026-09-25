<?php

namespace App\Mail;

use App\Models\ConversationMessage;
use App\Models\Site;
use App\Support\Mail\ConversationReplyMailer;
use App\Support\Sites\WidgetLanguage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A support-side reply, as an ordinary email.
 *
 * Nothing about it exposes internal tooling or credential names. Somebody who
 * wrote to a support address gets an answer from that site's support identity,
 * and the headers that make a reply thread are the only machinery on it.
 *
 * It speaks the VISITOR's language, never the install's: the widget language
 * they were reading when they were promised a reply, or else the one the site
 * answers its visitors in. It is built in a queue worker, where there is no
 * request to ask and the ambient locale belongs to nobody.
 */
class ConversationReplyMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ConversationMessage $message,
        public readonly Site $site,
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
    ) {
        $this->locale(self::languageFor($message, $site));
    }

    /**
     * The language this reply is written to its reader in.
     */
    public static function languageFor(ConversationMessage $message, Site $site): string
    {
        $promised = $message->conversation?->metadata[ConversationReplyMailer::REPLY_LOCALE] ?? null;

        return WidgetLanguage::sanitize(is_string($promised) ? $promised : null)
            ?? WidgetLanguage::toSpeak($site);
    }

    public function envelope(): Envelope
    {
        // An explicit locale rather than the ambient one: the subject is also
        // read outside send() -- by anything that inspects the envelope.
        $subject = (string) ($this->message->conversation->subject
            ?: __('visitor_mail.reply.subject_fallback', [], $this->locale));

        return new Envelope(
            subject: str_starts_with(strtolower($subject), 're:') ? $subject : 'Re: '.$subject,
            // Replies come back to the address that received the original, so
            // the thread stays in Wayfindr rather than landing in whichever
            // mailbox the install happens to send from.
            replyTo: $this->site->inbound_address === null ? [] : [$this->site->inbound_address],
            using: [function ($sentMessage): void {
                $headers = $sentMessage->getHeaders();

                // Set explicitly, because the Message-ID is what a later reply
                // will be threaded against. It comes from the durable outbox
                // row, and the delivered mail has to use that identical value.
                $headers->remove('Message-ID');
                $headers->addIdHeader('Message-ID', trim($this->messageId, '<>'));

                if ($this->inReplyTo !== null) {
                    $headers->addIdHeader('In-Reply-To', trim($this->inReplyTo, '<>'));
                    $headers->addIdHeader('References', trim($this->inReplyTo, '<>'));
                }
            }],
        );
    }

    /**
     * Plain text, and so written with `{!! !!}` throughout: `{{ }}` would turn
     * an agent's "We've found it" into "We&#039;ve found it" in the visitor's
     * inbox, and a text/plain part has no markup to protect.
     *
     * The closing line says where the conversation continues. With an inbound
     * address, a reply by email threads back onto it. Without one -- a widget
     * conversation answered while the desk was away -- a reply to the sender
     * reaches no conversation at all, so the visitor is sent back to the chat
     * rather than invited to write into nowhere.
     */
    public function content(): Content
    {
        return new Content(
            text: 'mail.conversation-reply',
            // Not `$message`. The mailer hands every mail view its own
            // Illuminate\Mail\Message under that name, overwriting this class's
            // property of the same name -- so a view reading `$message->body`
            // threw on every send, and no reply was ever delivered.
            with: ['reply' => $this->message],
        );
    }

    /**
     * The files the agent attached to this reply.
     *
     * Without these the agent is told the reply was sent while the visitor
     * receives none of it -- and an attachment-only reply arrives as nothing
     * but a signature.
     *
     * Streamed from the private disk the upload pipeline wrote them to (ADR
     * 0007); the visitor never gets a URL into that store.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return $this->message->attachments
            ->map(fn ($attachment) => Attachment::fromStorageDisk(
                $attachment->storage_disk,
                $attachment->storage_key,
            )->as($attachment->original_filename)->withMime($attachment->mime_type))
            ->values()
            ->all();
    }
}
