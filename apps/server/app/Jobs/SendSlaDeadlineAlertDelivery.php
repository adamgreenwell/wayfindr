<?php

namespace App\Jobs;

use App\Models\SlaAlertDelivery;
use App\Notifications\SlaDeadlineAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Deliver one immutable SLA-alert outbox row. */
class SendSlaDeadlineAlertDelivery implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 7200;

    public function __construct(private readonly int $deliveryId) {}

    /**
     * Database alerts retain their synchronous behavior. Mail uses the normal
     * worker, while both channels share the durable delivery identity.
     */
    public static function dispatchPending(int $deliveryId, string $channel): void
    {
        $job = (new self($deliveryId))->onConnection($channel === 'database'
            ? 'sync'
            : (string) config('queue.default'));

        try {
            dispatch($job);
        } catch (Throwable $exception) {
            try {
                (new UniqueLock(app(CacheRepository::class)))->release($job);
            } catch (Throwable) {
                // The row remains recoverable on the next SLA evaluation. The
                // finite uniqueness TTL is the final fallback for a dead cache.
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
        return [30, 120];
    }

    public function handle(): void
    {
        try {
            DB::transaction(function (): void {
                $delivery = SlaAlertDelivery::query()
                    ->whereKey($this->deliveryId)
                    ->lockForUpdate()
                    ->first();

                if ($delivery === null || $delivery->accepted_at !== null || $delivery->cancelled_at !== null) {
                    return;
                }

                $delivery->loadMissing(['clock.site', 'clock.subject', 'user']);
                $clock = $delivery->clock;
                $agent = $delivery->user;

                if ($clock === null || $clock->subject === null || $agent === null) {
                    $delivery->forceFill(['cancelled_at' => now()])->save();

                    return;
                }

                $notification = new SlaDeadlineAlert($clock, $delivery->stage, $delivery->channel);
                // Retrying the durable row reuses the database notification ID
                // and the mail Message-ID instead of inventing another alert.
                $notification->id = $delivery->public_id;

                if (! $notification->shouldSend($agent, $delivery->channel)) {
                    $delivery->forceFill(['cancelled_at' => now()])->save();

                    return;
                }

                $delivery->forceFill([
                    'attempts' => $delivery->attempts + 1,
                    'last_attempted_at' => now(),
                    'failed_at' => null,
                    'cancelled_at' => null,
                ])->save();

                // This is deliberately sendNow: the outbox job is already the
                // queue boundary, so nesting another queued notification would
                // recreate the uncheckpointed handoff this row removes.
                Notification::sendNow($agent, $notification, [$delivery->channel]);

                $delivery->forceFill([
                    'accepted_at' => now(),
                    'failed_at' => null,
                ])->save();
            });
        } catch (Throwable $exception) {
            SlaAlertDelivery::query()
                ->whereKey($this->deliveryId)
                ->whereNull('accepted_at')
                ->whereNull('cancelled_at')
                ->increment('attempts', 1, [
                    'last_attempted_at' => now(),
                    'failed_at' => now(),
                ]);

            throw $exception;
        }
    }
}
