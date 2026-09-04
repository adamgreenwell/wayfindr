<?php

namespace App\Support\Mail;

use App\Events\ConversationMessageCreated;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\Attachments\AttachmentBinder;
use App\Support\Attachments\AttachmentRejected;
use App\Support\Attachments\AttachmentUploadService;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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
        private readonly SiteManagerCoverage $siteManagerCoverage,
    ) {}

    public function route(InboundMessage $message): ?ConversationMessage
    {
        $site = $this->site($message);

        if ($site === null) {
            return null;
        }

        // A delivery already accepted is answered with what it produced the
        // first time. Providers retry on a timeout or a lost response, and a
        // retry that inserts again duplicates a reply -- or, for a first email
        // with no thread to join, opens a second conversation about the same
        // question.
        $seen = $this->alreadyAccepted($site, $message);

        if ($seen !== null) {
            return $seen;
        }

        $stored = DB::transaction(function () use ($site, $message): ?ConversationMessage {
            $this->siteManagerCoverage->lockAccount((int) $site->account_id);
            $site = Site::query()
                ->servable()
                ->whereKey($site->id)
                ->sharedLock()
                ->first();

            // Archive may have won after the inbound-address lookup. Refuse
            // from the locked state, and take account-site before any existing
            // conversation is reopened by its synchronous SLA observer.
            if (! $site) {
                return null;
            }

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

        if (! $stored) {
            return null;
        }

        // After the commit, exactly as the widget path does it. The listeners
        // notify eligible agents and reopen pending tickets, and dispatching
        // inside the transaction would have them read rows nothing else can see
        // yet -- or act on a message a rollback then removed.
        //
        // Failures here are logged and swallowed, and that is the difference
        // between a late notification and a duplicated conversation. These
        // listeners are synchronous -- `ConversationMessageCreated` is
        // `ShouldBroadcastNow` -- so an unreachable Reverb or a refused SMTP
        // connection throws PAST the commit. Letting it escape answers the
        // provider with a 5xx for a message that is already stored, and the
        // provider then redelivers it. Mail carrying no `Message-Id` has
        // nothing for `alreadyAccepted()` to match on, so the redelivery is
        // not recognised and opens a SECOND conversation about the same
        // question.
        //
        // Once the row is committed the delivery has been accepted, and the
        // provider is owed that answer. What happens to the live update
        // afterwards is this install's problem, not something to solve by
        // asking for the message again.
        try {
            event(new ConversationMessageCreated($stored));
        } catch (Throwable $exception) {
            Log::error('Inbound mail stored, but announcing it failed.', [
                'conversation_message_id' => $stored->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        return $stored;
    }

    /**
     * The message this delivery already produced, if it has been seen.
     */
    private function alreadyAccepted(Site $site, InboundMessage $message): ?ConversationMessage
    {
        if ($message->messageId === null) {
            return null;
        }

        return ConversationMessage::query()
            ->where('email_message_id', $message->messageId)
            ->whereHas('conversation', fn ($query) => $query->where('site_id', $site->id))
            ->first();
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
    /**
     * How many refused filenames the transcript notice spells out before it
     * starts counting instead.
     */
    private const NAMED_SKIPS = 5;

    /**
     * How much of a refused filename the notice repeats.
     *
     * Long enough to recognise the file that did not arrive, short enough that
     * the notice cannot become a message.
     */
    private const NAME_LENGTH = 60;

    private function attach(Conversation $conversation, ConversationMessage $stored, InboundMessage $message, Visitor $visitor): array
    {
        $skipped = [];
        $bound = 0;

        // The per-message cap, enforced here because the binder cannot enforce
        // it here. `AttachmentBinder::bind()` checks the size of the array it
        // is handed, which is the right check for the composer -- one call,
        // every file. This loop binds one file per call, so the binder sees an
        // array of one every time and the limit never fires however many files
        // arrive. The cap is not decoration: this branch's own operator
        // documentation sizes `post_max_size` from it.
        $maxPerMessage = (int) config('wayfindr.attachments.max_per_message');

        foreach ($message->attachments as $attachment) {
            // Refused BEFORE the bytes are decoded or stored, so an overflow
            // file costs no disk and leaves no orphaned row. Reported rather
            // than dropped, which is the contract this method already keeps.
            if ($bound >= $maxPerMessage) {
                $skipped[] = $attachment['name'];

                continue;
            }

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

                // Counted only once the bind returns, so a file the binder
                // refused for its own reasons costs no budget.
                $bound++;
            } catch (AttachmentRejected $exception) {
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
        $total = count($skipped);

        // Every one of these names is a string the SENDER chose, and this
        // notice is written into a message body attributed to the visitor --
        // so an agent reads it as the customer's own words. Capping the count
        // alone was not enough: a filename may be 998 characters, so five of
        // them is still five kilobytes of attacker-written prose in someone
        // else's transcript. Both dimensions are bounded, and each name is cut
        // to something that still identifies the file.
        $named = [];

        foreach (array_slice($skipped, 0, self::NAMED_SKIPS) as $name) {
            $named[] = Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $name) ?? ''), self::NAME_LENGTH);
        }

        $remainder = $total - count($named);

        return '[Wayfindr could not accept '
            .Str::plural('this file', $total).': '
            .implode(', ', $named)
            .($remainder > 0 ? sprintf(' and %d more', $remainder) : '')
            .'. The sender was not told.]';
    }
}
