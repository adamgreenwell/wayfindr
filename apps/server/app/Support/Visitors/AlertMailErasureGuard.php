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
 * A mail that passes this check has been handed to the mail server, which
 * erasure cannot reach (§6).
 */
final class AlertMailErasureGuard
{
    public const HEADER = 'X-Wayfindr-Alert-About';

    public static function stamp(MailMessage $message, Ticket|Conversation $subject): MailMessage
    {
        $about = $subject instanceof Ticket
            ? 'ticket:'.$subject->getKey().':'.(self::stripped($subject) ? 'stripped' : 'intact')
            : 'conversation:'.$subject->getKey();

        return $message->withSymfonyMessage(
            fn (Email $email) => $email->getHeaders()->addTextHeader(self::HEADER, $about),
        );
    }

    public static function allows(Email $email): bool
    {
        $header = $email->getHeaders()->get(self::HEADER);

        if ($header === null) {
            return true;
        }

        [$type, $id, $built] = array_pad(explode(':', trim($header->getBodyAsString()), 3), 3, '');

        return DB::transaction(fn (): bool => match ($type) {
            'conversation' => Conversation::query()->whereKey((int) $id)->sharedLock()->exists(),
            'ticket' => ($ticket = Ticket::query()->whereKey((int) $id)->sharedLock()->first(['id', 'metadata'])) instanceof Ticket
                && ($built === 'stripped' || ! self::stripped($ticket)),
            default => true,
        });
    }

    private static function stripped(Ticket $ticket): bool
    {
        return data_get($ticket->metadata, 'requester_erased') === true;
    }
}
