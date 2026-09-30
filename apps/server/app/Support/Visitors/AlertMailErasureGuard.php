<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\Conversation;
use App\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Email;

/**
 * An agent alert mail is built on a worker that may have read its ticket or
 * conversation before an erasure, and reach SMTP after it with the old
 * subject or the visitor's message in hand (ADR 0026 §1). Erasing deletes the
 * alert's database copy, but mail whose copy is missing is still sent: that is
 * the rolling-deploy seam ClaimAgentAlertMailDelivery keeps open.
 *
 * So each alert mail names what it was built from, and the check every alert
 * mail makes just before SMTP reads that again under a shared lock: a mail
 * about a deleted conversation, or built from a ticket since stripped, goes no
 * further. A stripped ticket still alerts, with what the stripped ticket says.
 *
 * That lock ends before the transport runs, so a mail that passes is recorded
 * as in flight under it, and erasing the contact refuses while such a send is
 * fresh. The record goes when the mail is sent; a send that never reports back
 * stops holding erasure after the in-flight window.
 */
final class AlertMailErasureGuard
{
    public const HEADER = 'X-Wayfindr-Alert-About';

    public const SEND_HEADER = 'X-Wayfindr-Alert-Send';

    public static function stamp(MailMessage $message, Ticket|Conversation $subject): MailMessage
    {
        $about = $subject instanceof Ticket
            ? 'ticket:'.$subject->getKey().':'.(self::stripped($subject) ? 'stripped' : 'intact')
            : 'conversation:'.$subject->getKey();

        return $message->withSymfonyMessage(
            fn (Email $email) => $email->getHeaders()->addTextHeader(self::HEADER, $about),
        );
    }

    /**
     * Just before SMTP. False when the mail must not be sent; otherwise the
     * send is in flight until finish() is called with the same message.
     */
    public static function begin(Email $email): bool
    {
        $header = $email->getHeaders()->get(self::HEADER);

        if ($header === null) {
            return true;
        }

        [$type, $id, $built] = array_pad(explode(':', trim($header->getBodyAsString()), 3), 3, '');
        $subject = match ($type) {
            'conversation' => new Conversation,
            'ticket' => new Ticket,
            default => null,
        };

        if ($subject === null) {
            return true;
        }

        return DB::transaction(function () use ($email, $subject, $id, $built): bool {
            $current = $subject->newQuery()->whereKey((int) $id)->sharedLock()->first();

            if ($current === null || ($current instanceof Ticket && $built !== 'stripped' && self::stripped($current))) {
                return false;
            }

            $send = DB::table('alert_mail_sends')->insertGetId([
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => (int) $id,
                'started_at' => now(),
            ]);
            $email->getHeaders()->addTextHeader(self::SEND_HEADER, (string) $send);

            return true;
        });
    }

    /** After SMTP, or where the send stopped short of it. */
    public static function finish(Email $email): void
    {
        $header = $email->getHeaders()->get(self::SEND_HEADER);

        if ($header === null) {
            return;
        }

        DB::table('alert_mail_sends')
            ->where('id', (int) trim($header->getBodyAsString()))
            ->delete();
        // Sends that never reported back, so the table stays small.
        DB::table('alert_mail_sends')
            ->where('started_at', '<', now()->subSeconds(VisitorEraser::IN_FLIGHT_WINDOW_SECONDS))
            ->delete();
    }

    private static function stripped(Ticket $ticket): bool
    {
        return data_get($ticket->metadata, 'requester_erased') === true;
    }
}
