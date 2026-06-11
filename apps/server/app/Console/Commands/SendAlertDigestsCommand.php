<?php

namespace App\Console\Commands;

use App\Mail\AlertDigestMessage;
use App\Models\User;
use App\Support\AlertDigestCandidateCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

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

        foreach ($this->eligibleAgents($email) as $agent) {
            $agentsScanned++;

            $candidates = $collector->forAgent($agent);

            if ($candidates->isEmpty()) {
                continue;
            }

            $candidateCount += $candidates->count();
            $emailsQueued++;

            Mail::to($agent->email)->queue(new AlertDigestMessage(
                agentName: $agent->name,
                candidates: $candidates->all(),
                generatedAt: now(),
            ));

            $this->line("Queued digest for {$agent->name} <{$agent->email}> with {$candidates->count()} candidates.");
        }

        if ($emailsQueued === 0) {
            $this->line('No alert digest emails queued.');
        }

        $this->line("Alert digest delivery complete. Agents scanned: {$agentsScanned}. Emails queued: {$emailsQueued}. Candidates: {$candidateCount}.");

        return self::SUCCESS;
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
}
