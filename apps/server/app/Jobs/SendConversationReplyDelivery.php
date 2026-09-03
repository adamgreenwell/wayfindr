<?php

namespace App\Jobs;

use App\Mail\ConversationReplyMessage;
use App\Models\ConversationReplyDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one durable conversation-reply outbox row.
 *
 * The job itself sends the mailable instead of enqueueing a second job. That
 * keeps Redis acceptance from becoming another unrecorded handoff between the
 * durable row and the actual work.
 */
class SendConversationReplyDelivery implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 7200;

    public function __construct(private readonly int $deliveryId) {}

    /**
     * Hand the durable row to the queue without stranding its uniqueness lock
     * when Redis rejects the push. The database row remains the source of
     * truth; releasing this cache lock lets the scheduler retry immediately.
     */
    public static function dispatchPending(int $deliveryId): void
    {
        $job = new self($deliveryId);

        try {
            dispatch($job);
        } catch (Throwable $exception) {
            try {
                (new UniqueLock(app(CacheRepository::class)))->release($job);
            } catch (Throwable) {
                // A dead cache may prevent both acquiring and releasing the
                // lock. Its finite TTL remains the last-resort recovery path.
            }

            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        try {
            DB::transaction(function (): void {
                $delivery = ConversationReplyDelivery::query()
                    ->whereKey($this->deliveryId)
                    ->lockForUpdate()
                    ->first();

                if ($delivery === null || $delivery->accepted_at !== null) {
                    return;
                }

                $delivery->loadMissing(['message.attachments', 'message.conversation', 'message.conversation.site']);
                $message = $delivery->message;
                $site = $message?->conversation?->site;

                if ($message === null || $site === null) {
                    return;
                }

                $delivery->forceFill([
                    'attempts' => $delivery->attempts + 1,
                    'last_attempted_at' => now(),
                ])->save();

                Mail::to($delivery->recipient)->send(new ConversationReplyMessage(
                    $message,
                    $site,
                    $delivery->message_id,
                    $delivery->in_reply_to,
                ));

                $delivery->forceFill([
                    // This is transport acceptance, not proof that a mailbox
                    // received exactly one copy. Generic SMTP has no atomic
                    // commit with this database transaction.
                    'accepted_at' => now(),
                    'failed_at' => null,
                ])->save();
            });
        } catch (Throwable $exception) {
            // The transaction rolled its attempt stamp back with the failed
            // send. Record that attempt separately, without retaining provider
            // errors or message content in the outbox.
            ConversationReplyDelivery::query()
                ->whereKey($this->deliveryId)
                ->increment('attempts', 1, ['last_attempted_at' => now()]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        ConversationReplyDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereNull('accepted_at')
            ->update(['failed_at' => now()]);
    }
}
