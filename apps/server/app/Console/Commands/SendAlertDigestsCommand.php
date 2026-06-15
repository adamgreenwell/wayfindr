<?php

namespace App\Console\Commands;

use App\Mail\AlertDigestMessage;
use App\Models\User;
use App\Support\AlertDigestCandidateCollector;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAlertDigestsCommand extends Command
{
    protected $signature = 'wayfindr:send-alert-digests
        {--email= : Limit delivery to one agent email address}';

    protected $description = 'Queue metadata-only alert digest email for digest-enabled agents.';

    public function handle(AlertDigestCandidateCollector $collector): int
    {
        $email = trim((string) $this->option('email'));

        if ($email !== '' && ! User::query()->where('email', $email)->exists()) {
            $this->error("No agent found for {$email}.");

            return self::FAILURE;
        }

        $this->info('Alert digest delivery');

        $agentsScanned = 0;
        $emailsQueued = 0;
        $candidateCount = 0;
        $failed = 0;

        foreach ($this->eligibleAgents($email) as $agent) {
            $agentsScanned++;
            $attemptedAt = now();

            $candidates = $collector->forAgent($agent);

            if ($candidates->isEmpty()) {
                $agent->recordAlertDigestDelivery([
                    'status' => User::ALERT_DIGEST_DELIVERY_NO_ALERTS,
                    'candidate_count' => 0,
                    'message' => 'No digest-ready alerts found.',
                    'last_attempted_at' => $attemptedAt->toISOString(),
                ]);

                continue;
            }

            $candidateCount += $candidates->count();

            try {
                Mail::to($agent->email)->queue(new AlertDigestMessage(
                    agentName: $agent->name,
                    candidates: $candidates->all(),
                    generatedAt: $attemptedAt,
                ));

                $emailsQueued++;

                $this->markCandidatesQueued($candidates, $attemptedAt);

                $agent->recordAlertDigestDelivery([
                    'status' => User::ALERT_DIGEST_DELIVERY_QUEUED,
                    'candidate_count' => $candidates->count(),
                    'message' => User::digestQueuedMessage($candidates->count()),
                    'last_attempted_at' => $attemptedAt->toISOString(),
                ]);

                $this->line("Queued digest for {$agent->name} <{$agent->email}> with {$candidates->count()} candidates.");
            } catch (Throwable $exception) {
                $failed++;

                Log::warning('Alert digest delivery failed.', [
                    'agent_id' => $agent->id,
                    'agent_email' => $agent->email,
                    'exception' => $exception,
                ]);

                $agent->recordAlertDigestDelivery([
                    'status' => User::ALERT_DIGEST_DELIVERY_FAILED,
                    'candidate_count' => $candidates->count(),
                    'message' => 'Digest email could not be queued.',
                    'last_attempted_at' => $attemptedAt->toISOString(),
                ]);

                $this->error("Failed digest for {$agent->name} <{$agent->email}>.");
            }
        }

        if ($emailsQueued === 0) {
            $this->line('No alert digest emails queued.');
        }

        $summary = "Alert digest delivery complete. Agents scanned: {$agentsScanned}. Emails queued: {$emailsQueued}. Candidates: {$candidateCount}.";

        if ($failed > 0) {
            $summary .= " Failed: {$failed}.";
        }

        $this->line($summary);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, User>
     */
    private function eligibleAgents(string $email): Collection
    {
        $query = User::query()
            ->when($email !== '', fn ($query) => $query->where('email', $email))
            ->orderBy('email');

        return $query
            ->get()
            ->filter(fn (User $agent): bool => ! $agent->isDeactivated()
                && $agent->alertEmailEnabled()
                && $agent->alertMode() !== User::ALERT_MODE_QUIET
                && $agent->alertCadence() === User::ALERT_CADENCE_DIGEST)
            ->values();
    }

    /**
     * @param  Collection<int, array{notification_id: string}>  $candidates
     */
    private function markCandidatesQueued(Collection $candidates, CarbonInterface $queuedAt): void
    {
        DatabaseNotification::query()
            ->whereIn('id', $candidates->pluck('notification_id')->all())
            ->get()
            ->each(function (DatabaseNotification $notification) use ($queuedAt): void {
                $notification->forceFill([
                    'data' => [
                        ...$notification->data,
                        AlertDigestCandidateCollector::DIGEST_QUEUED_AT_KEY => $queuedAt->toISOString(),
                    ],
                ])->save();
            });
    }
}
