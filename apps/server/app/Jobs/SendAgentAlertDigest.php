<?php

namespace App\Jobs;

use App\Mail\AlertDigestMessage;
use App\Models\User;
use App\Support\AlertDigestCandidateCollector;
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

/** Rebuild and send one agent digest at the worker delivery boundary. */
class SendAgentAlertDigest implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 7200;

    public function __construct(
        private readonly int $agentId,
        private readonly int $initialCandidateCount,
    ) {}

    public static function dispatchPending(int $agentId, int $candidateCount): void
    {
        $job = new self($agentId, $candidateCount);

        try {
            dispatch($job);
        } catch (Throwable $exception) {
            try {
                (new UniqueLock(app(CacheRepository::class)))->release($job);
            } catch (Throwable) {
                // The next hourly sweep can recover once the finite lock ends.
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

    public function handle(AlertDigestCandidateCollector $collector): void
    {
        $agent = User::query()->whereKey($this->agentId)->first();

        if (! $agent instanceof User
            || $agent->isDeactivated()
            || ! $agent->alertEmailEnabled()
            || $agent->alertCadence() !== User::ALERT_CADENCE_DIGEST
            || $agent->alertInterruptionsPaused()) {
            return;
        }

        $candidates = $collector->forAgent($agent);

        if ($candidates->isEmpty()) {
            return;
        }

        // Re-read immediately before transport handoff. Profile updates and a
        // quiet-window boundary can happen while the job waits or collects.
        $agent->refresh();

        if ($agent->isDeactivated()
            || ! $agent->alertEmailEnabled()
            || $agent->alertCadence() !== User::ALERT_CADENCE_DIGEST
            || $agent->alertInterruptionsPaused()) {
            return;
        }

        $claim = (string) Str::uuid();
        $candidates = $collector->claimForDelivery($agent, $candidates, $claim);

        if ($candidates->isEmpty()) {
            return;
        }

        $agent->refresh();

        if ($agent->isDeactivated()
            || ! $agent->alertEmailEnabled()
            || $agent->alertCadence() !== User::ALERT_CADENCE_DIGEST
            || $agent->alertInterruptionsPaused()) {
            $collector->releaseDeliveryClaim($candidates, $claim);

            return;
        }

        $deliveredAt = now();

        try {
            Mail::to($agent->email)->send(new AlertDigestMessage(
                agentName: $agent->name,
                candidates: $candidates->all(),
                generatedAt: $deliveredAt,
                deliveryClaim: $claim,
            ));
        } catch (Throwable $exception) {
            try {
                $collector->releaseDeliveryClaim($candidates, $claim);
            } catch (Throwable $releaseException) {
                Log::critical('Digest delivery is uncertain after its claim could not be released.', [
                    'agent_id' => $agent->id,
                    'candidate_count' => $candidates->count(),
                    'exception' => $releaseException,
                ]);

                return;
            }

            throw $exception;
        }

        try {
            $collector->acceptDeliveryClaim($agent, $candidates, $claim, $deliveredAt);
        } catch (Throwable $exception) {
            // SMTP already accepted the message. The durable claim is left in
            // place so neither this job nor a later sweep can send it again.
            Log::critical('Digest delivery was accepted but could not be finalized.', [
                'agent_id' => $agent->id,
                'candidate_count' => $candidates->count(),
                'exception' => $exception,
            ]);

            return;
        }

        try {
            $agent->recordAlertDigestDelivery([
                'status' => User::ALERT_DIGEST_DELIVERY_QUEUED,
                'candidate_count' => $candidates->count(),
                'message' => User::digestQueuedMessage($candidates->count()),
                'last_attempted_at' => $deliveredAt->toISOString(),
            ]);
        } catch (Throwable $exception) {
            // Candidate stamps already prevent a second email; readiness state
            // can be repaired by the next successful digest run.
            Log::warning('Digest delivery was accepted but readiness state was not recorded.', [
                'agent_id' => $agent->id,
                'candidate_count' => $candidates->count(),
                'exception' => $exception,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $agent = User::query()->whereKey($this->agentId)->first();

        if (! $agent instanceof User) {
            return;
        }

        $agent->recordAlertDigestDelivery([
            'status' => User::ALERT_DIGEST_DELIVERY_FAILED,
            'candidate_count' => $this->initialCandidateCount,
            'message' => 'Digest email could not be delivered.',
            'last_attempted_at' => now()->toISOString(),
        ]);
    }
}
