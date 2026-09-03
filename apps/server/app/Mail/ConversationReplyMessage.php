<?php

namespace App\Mail;

use App\Models\ConversationMessage;
use App\Models\Site;
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
    ) {}

    public function envelope(): Envelope
    {
        $subject = (string) ($this->message->conversation->subject ?: 'Your support request');

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

    public function content(): Content
    {
        return new Content(text: 'mail.conversation-reply');
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
