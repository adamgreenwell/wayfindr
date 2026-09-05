<?php

namespace App\Jobs;

use App\Exceptions\AgentAlertDeliveryPendingException;
use App\Models\SlaAlertDelivery;
use App\Notifications\SlaDeadlineAlert;
use App\Support\AgentAlertDeliveryCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        if ($channel === 'mail') {
            $job->delay(5);
        }

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

    public function handle(AgentAlertDeliveryCoordinator $agentAlertDeliveries): void
    {
        $channel = SlaAlertDelivery::query()->whereKey($this->deliveryId)->value('channel');

        if ($channel === null) {
            return;
        }

        if ($channel === 'mail') {
            $this->handleMail($agentAlertDeliveries);

            return;
        }

        $this->handleDatabase();
    }

    private function handleDatabase(): void
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
                // A database alert and its accepted receipt share this
                // transaction. Retrying therefore reuses one durable ID
                // without exposing a post-insert checkpoint seam.
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

    private function handleMail(AgentAlertDeliveryCoordinator $agentAlertDeliveries): void
    {
        $prepared = DB::transaction(function () use ($agentAlertDeliveries): ?array {
            $delivery = SlaAlertDelivery::query()
                ->whereKey($this->deliveryId)
                ->lockForUpdate()
                ->first();

            if (
                $delivery === null
                || $delivery->accepted_at !== null
                || $delivery->deduplicated_at !== null
                || $delivery->cancelled_at !== null
                || $delivery->started_at !== null
                || ($delivery->claimed_at !== null
                    && $delivery->claimed_at->isAfter(now()->subMinutes(SlaAlertDelivery::CLAIM_LEASE_MINUTES)))
            ) {
                return null;
            }

            $delivery->loadMissing(['clock.site', 'clock.subject', 'user']);
            $clock = $delivery->clock;
            $agent = $delivery->user;

            if ($clock === null || $clock->subject === null || $agent === null) {
                $delivery->forceFill(['cancelled_at' => now()])->save();

                return null;
            }

            $notification = new SlaDeadlineAlert($clock, $delivery->stage, $delivery->channel);
            $notification->id = $delivery->public_id;

            if (! $notification->shouldSend($agent, $delivery->channel)) {
                $delivery->forceFill(['cancelled_at' => now()])->save();

                return null;
            }

            $agentAlertDecision = $agentAlertDeliveries->claimNotificationMail($agent, $notification);

            if ($agentAlertDecision['status'] === 'covered') {
                $delivery->forceFill(['deduplicated_at' => now()])->save();

                return ['deduplicated' => true];
            }

            if ($agentAlertDecision['status'] === 'pending') {
                throw new AgentAlertDeliveryPendingException;
            }

            if ($agentAlertDecision['status'] === 'claimed') {
                $notification->useAgentAlertMailClaim($agentAlertDecision['claim']);
            }

            $delivery->forceFill([
                // This is only a recoverable worker lease. The MessageSending
                // listener places started_at at Laravel's transport boundary,
                // so a crash before sendNow never strands an unsent message.
                'claimed_at' => now(),
                'attempts' => $delivery->attempts + 1,
                'last_attempted_at' => now(),
                'failed_at' => null,
                'cancelled_at' => null,
            ])->save();

            return [
                'agent' => $agent,
                'delivery' => $delivery,
                'notification' => $notification,
                'agent_alert_claim' => $notification->agentAlertMailClaim(),
            ];
        });

        if ($prepared === null || ($prepared['deduplicated'] ?? false)) {
            return;
        }

        /** @var SlaAlertDelivery $delivery */
        $delivery = $prepared['delivery'];

        try {
            Notification::sendNow($prepared['agent'], $prepared['notification'], ['mail']);

            DB::transaction(function () use ($agentAlertDeliveries, $delivery, $prepared): void {
                $current = SlaAlertDelivery::query()
                    ->whereKey($delivery->id)
                    ->lockForUpdate()
                    ->first();

                if ($current === null || $current->accepted_at !== null || $current->cancelled_at !== null) {
                    return;
                }

                if ($current->started_at === null) {
                    // Notification::shouldSend vetoed the channel between the
                    // preparation check and MailChannel. No transport event
                    // ran, so this delivery is known not to have been sent.
                    $current->forceFill([
                        'claimed_at' => null,
                        'failed_at' => null,
                        'cancelled_at' => now(),
                    ])->save();

                    if (is_array($prepared['agent_alert_claim'])) {
                        $agentAlertDeliveries->releaseUnstartedMailClaim($prepared['agent_alert_claim']);
                    }

                    return;
                }

                $current->forceFill([
                    'accepted_at' => now(),
                    'failed_at' => null,
                ])->save();
            });
        } catch (Throwable $exception) {
            $outcome = DB::transaction(function () use ($agentAlertDeliveries, $delivery, $prepared): string {
                $current = SlaAlertDelivery::query()
                    ->whereKey($delivery->id)
                    ->lockForUpdate()
                    ->first();

                if ($current === null || $current->accepted_at !== null) {
                    return 'complete';
                }

                if ($current->started_at === null) {
                    if ($current->cancelled_at !== null) {
                        return 'cancelled';
                    }

                    if (is_array($prepared['agent_alert_claim'])) {
                        $agentAlertDeliveries->releaseUnstartedMailClaim($prepared['agent_alert_claim']);
                    }

                    // Rendering, policy vetoes, and transport-boundary setup
                    // failures happen before SMTP. Release the lease so those
                    // known-unsent attempts remain safe to retry.
                    $current->forceFill([
                        'claimed_at' => null,
                        'failed_at' => now(),
                    ])->save();

                    return 'retryable';
                }

                $current->forceFill(['failed_at' => now()])->save();

                return 'uncertain';
            });

            if ($outcome === 'cancelled' || $outcome === 'complete') {
                return;
            }

            if ($outcome === 'uncertain') {
                Log::critical('SLA mail transport outcome is uncertain; automatic retries stopped.', [
                    'sla_alert_delivery_id' => $delivery->public_id,
                    'exception' => $exception::class,
                ]);
            }

            throw $exception;
        }
    }
}
