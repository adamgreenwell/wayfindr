<?php

namespace App\Support\Mail;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\Attachments\AttachmentBinder;
use App\Support\Attachments\AttachmentUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An arriving email, turned into a message on the right conversation.
 *
 * Three lookups, in order of how much they can be trusted:
 *
 *   1. The SITE, from the address it was sent to. An address nobody configured
 *      is refused rather than guessed at -- a desk should not start answering
 *      mail it was never told about.
 *   2. The CONVERSATION, from In-Reply-To and References against the Message-ID
 *      of a message already stored. Never from the subject: two unrelated
 *      "Re: Order" threads would collapse into one, and a customer who edits
 *      the subject would start a second conversation about the thing they are
 *      already discussing.
 *   3. The VISITOR, from their address on that site. Email is the only identity
 *      an email carries, and `visitors` already indexes (site_id, email).
 */
final class InboundMailRouter
{
    public function __construct(
        private readonly AttachmentUploadService $attachments,
        private readonly AttachmentBinder $binder,
    ) {}

    public function route(InboundMessage $message): ?ConversationMessage
    {
        $site = $this->site($message);

        if ($site === null) {
            return null;
        }

        return DB::transaction(function () use ($site, $message): ConversationMessage {
            $visitor = $this->visitor($site, $message);
            $conversation = $this->conversation($site, $visitor, $message);

            $stored = $conversation->messages()->create([
                'sender_type' => Visitor::class,
                'sender_id' => $visitor->id,
                'type' => 'text',
                'body' => $this->body($message),
                'metadata' => ['channel' => 'email'],
                'email_message_id' => $message->messageId,
            ]);

            $skipped = $this->attach($conversation, $stored, $message, $visitor);

            if ($skipped !== []) {
                // Said in the transcript rather than swallowed. An agent reading
                // a message that references "the screenshot attached" needs to
                // know why there isn't one.
                $stored->forceFill([
                    'body' => trim($stored->body."\n\n".$this->skippedNotice($skipped)),
                ])->save();
            }

            // A reply reopens, exactly as a widget message does -- and it is
            // recorded, because a resolution that did not hold is the most
            // interesting thing a conversation can tell you (ADR 0015).
            $conversation->forceFill([
                'status' => 'open',
                'closed_at' => null,
                'last_message_at' => $stored->created_at,
            ])->save();

            return $stored;
        });
    }

    private function site(InboundMessage $message): ?Site
    {
        foreach ($message->recipients as $address) {
            $site = Site::query()
                ->whereNotNull('inbound_address')
                ->whereRaw('LOWER(inbound_address) = ?', [$address])
                ->first();

            // An archived site stops answering here for the same reason it
            // stops serving its widget: it is out of service, not deleted.
            if ($site !== null && ! $site->isArchived()) {
                return $site;
            }
        }

        Log::info('Inbound mail matched no site.', ['recipients' => $message->recipients]);

        return null;
    }

    private function visitor(Site $site, InboundMessage $message): Visitor
    {
        $visitor = $site->visitors()
            ->whereRaw('LOWER(email) = ?', [$message->fromEmail])
            ->first();

        if ($visitor !== null) {
            // A name only fills a gap. Somebody who told the widget their name
            // should not have it overwritten by whatever their mail client
            // happens to send.
            if ($visitor->name === null && $message->fromName !== null) {
                $visitor->forceFill(['name' => $message->fromName])->save();
            }

            $visitor->forceFill(['last_seen_at' => now()])->save();

            return $visitor;
        }

        return $site->visitors()->create([
            // No anonymous_id: this visitor has never loaded the widget, and
            // inventing one would put a fake browser session in the record.
            'name' => $message->fromName,
            'email' => $message->fromEmail,
            'last_seen_at' => now(),
        ]);
    }

    private function conversation(Site $site, Visitor $visitor, InboundMessage $message): Conversation
    {
        foreach ($message->threadCandidates() as $candidate) {
            $existing = ConversationMessage::query()
                ->where('email_message_id', $candidate)
                ->whereHas('conversation', fn ($query) => $query->where('site_id', $site->id))
                ->with('conversation')
                ->first();

            // Scoped to this visitor as well as this site: a Message-ID is
            // guessable, and threading a stranger's reply into somebody else's
            // conversation would show them a transcript that is not theirs.
            if ($existing?->conversation !== null && (int) $existing->conversation->visitor_id === (int) $visitor->id) {
                return $existing->conversation;
            }
        }

        return $site->conversations()->create([
            'visitor_id' => $visitor->id,
            'support_code' => Conversation::generateSupportCode(),
            'status' => 'open',
            'subject' => $message->subject,
            'metadata' => ['channel' => 'email'],
        ]);
    }

    private function body(InboundMessage $message): string
    {
        // An email with only attachments still has to say something, or the
        // transcript shows an empty bubble.
        return $message->body === '' ? '(no message text)' : $message->body;
    }

    /**
     * Store what can be stored, and report what could not.
     *
     * Every file goes through the same pipeline a widget upload does -- ADR
     * 0007, which sniffs the type from the bytes rather than trusting the
     * declared one. That matters most here: the Content-Type on an inbound part
     * is written by the sender.
     *
     * A refusal skips the FILE, never the message. Discarding a customer's
     * question because they attached something the allowlist refuses would lose
     * the thing they actually wrote.
     *
     * @return list<string>
     */
    private function attach(Conversation $conversation, ConversationMessage $stored, InboundMessage $message, Visitor $visitor): array
    {
        $skipped = [];

        foreach ($message->attachments as $attachment) {
            $decoded = base64_decode($attachment['content'], true);

            if ($decoded === false) {
                $skipped[] = $attachment['name'];

                continue;
            }

            $path = tempnam(sys_get_temp_dir(), 'wayfindr-inbound-');

            if ($path === false) {
                $skipped[] = $attachment['name'];

                continue;
            }

            try {
                file_put_contents($path, $decoded);

                $file = new UploadedFile($path, $attachment['name'], $attachment['content_type'], null, true);
                $record = $this->attachments->store($conversation, $file, $visitor);

                // Bound through the binder rather than by setting the column:
                // it is the path that already checks the attachment belongs to
                // this conversation and this sender.
                $this->binder->bind($conversation, $stored, [$record->id], $visitor);
            } catch (ValidationException $exception) {
                $skipped[] = $attachment['name'];
            } finally {
                @unlink($path);
            }
        }

        return $skipped;
    }

    /**
     * @param  list<string>  $skipped
     */
    private function skippedNotice(array $skipped): string
    {
        return '[Wayfindr could not accept '
            .Str::plural('this file', count($skipped)).': '
            .implode(', ', $skipped)
            .'. The sender was not told.]';
    }
}
