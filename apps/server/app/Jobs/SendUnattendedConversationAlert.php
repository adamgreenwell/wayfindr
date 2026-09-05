<?php

namespace App\Jobs;

use App\Mail\UnattendedConversationAlertMessage;
use App\Models\User;
use App\Support\UnattendedConversationAlertCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/** Rebuild and send one unattended alert at the worker delivery boundary. */
class SendUnattendedConversationAlert implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 7200;

    public function __construct(private readonly int $agentId) {}

    public static function dispatchPending(int $agentId): void
    {
        $job = new self($agentId);

        try {
            dispatch($job);
        } catch (Throwable $exception) {
            try {
                (new UniqueLock(app(CacheRepository::class)))->release($job);
            } catch (Throwable) {
                // The next five-minute sweep can recover after the lock ends.
            }

            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->agentId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(UnattendedConversationAlertCollector $collector): void
    {
        $agent = User::query()->whereKey($this->agentId)->first();

        if (! $agent instanceof User || ! $agent->wantsUnattendedAlertEmail()) {
            return;
        }

        $candidates = $collector->forAgent($agent);

        if ($candidates->isEmpty()) {
            return;
        }

        // Re-read immediately before transport handoff so a preference change
        // or newly active quiet window suppresses this queued copy.
        $agent->refresh();

        if ($agent->isDeactivated() || ! $agent->wantsUnattendedAlertEmail()) {
            return;
        }

        $claim = (string) Str::uuid();
        $candidates = $collector->claimForDelivery($agent, $candidates, $claim);

        if ($candidates->isEmpty()) {
            return;
        }

        $agent->refresh();

        if ($agent->isDeactivated() || ! $agent->wantsUnattendedAlertEmail()) {
            $collector->releaseDeliveryClaim($candidates, $claim);

            return;
        }

        $deliveredAt = now();

        try {
            Mail::to($agent->email)->send(new UnattendedConversationAlertMessage(
                agentName: $agent->name,
                candidates: $candidates->all(),
                generatedAt: $deliveredAt,
                deliveryClaim: $claim,
            ));
        } catch (Throwable $exception) {
            try {
                $collector->releaseDeliveryClaim($candidates, $claim);
            } catch (Throwable $releaseException) {
                Log::critical('Unattended delivery is uncertain after its claim could not be released.', [
                    'agent_id' => $agent->id,
                    'candidate_count' => $candidates->count(),
                    'exception' => $releaseException,
                ]);

                return;
            }

            throw $exception;
        }

        try {
            $collector->acceptDeliveryClaim($candidates, $claim, $deliveredAt);
        } catch (Throwable $exception) {
            // SMTP already accepted the message. The claim remains durable so
            // a worker retry or later scheduler sweep cannot duplicate it.
            Log::critical('Unattended delivery was accepted but could not be finalized.', [
                'agent_id' => $agent->id,
                'candidate_count' => $candidates->count(),
                'exception' => $exception,
            ]);
        }
    }
}
