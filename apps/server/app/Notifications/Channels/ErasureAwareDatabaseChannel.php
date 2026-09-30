<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\Conversation;
use App\Models\Ticket;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Stores an agent alert only while what it names still stands (ADR 0026 §1).
 *
 * A request can commit its change and then call `notify()` with the model it
 * already holds; the database channel runs inline, so without this an erasure
 * that finishes in between is followed by a fresh alert quoting the person.
 * The insert shares a lock with erasure on the conversation or ticket the
 * alert names, so it lands either before erasure (which then deletes it with
 * the rest) or after it, when it can see what erasure did.
 */
final class ErasureAwareDatabaseChannel extends DatabaseChannel
{
    public function send($notifiable, Notification $notification)
    {
        return DB::transaction(function () use ($notifiable, $notification) {
            $payload = $this->buildPayload($notifiable, $notification);
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $conversationId = $this->id($data['conversation_id'] ?? null);
            $ticketId = $this->id($data['ticket_id'] ?? null);

            if ($conversationId !== null
                && ! Conversation::query()->whereKey($conversationId)->sharedLock()->exists()) {
                return null;
            }

            if ($ticketId !== null) {
                $ticket = Ticket::query()->whereKey($ticketId)->sharedLock()->first(['id', 'subject', 'metadata']);

                if ($ticket === null) {
                    return null;
                }

                // A stripped ticket is still a work item and may still alert,
                // but only under the subject it carries now.
                if (data_get($ticket->metadata, 'requester_erased') === true && array_key_exists('subject', $data)) {
                    $payload['data'] = ['subject' => (string) $ticket->subject] + $data;
                }
            }

            return $notifiable->routeNotificationFor('database', $notification)->create($payload);
        });
    }

    private function id(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
