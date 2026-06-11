<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AlertDigestCandidateCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AlertDigestPreviewCommand extends Command
{
    protected $signature = 'wayfindr:alert-digest-preview
        {--email= : Limit the preview to one agent email address}';

    protected $description = 'Preview metadata-only alert digest candidates without sending mail.';

    public function handle(AlertDigestCandidateCollector $collector): int
    {
        $email = trim((string) $this->option('email'));

        if ($email !== '' && ! User::query()->where('email', $email)->exists()) {
            $this->error("No agent found for {$email}.");

            return self::FAILURE;
        }

        $agents = $this->eligibleAgents($email);

        $this->info('Alert digest preview');

        $agentsScanned = 0;
        $candidateCount = 0;

        foreach ($agents as $agent) {
            $agentsScanned++;

            $candidates = $collector->forAgent($agent);
            $candidateCount += $candidates->count();

            if ($candidates->isEmpty()) {
                continue;
            }

            $this->newLine();
            $this->line("Agent: {$agent->name} <{$agent->email}>");
            $this->line('Candidates: '.$candidates->count());

            foreach ($candidates as $candidate) {
                $this->line(sprintf(
                    '- %s | %s | %s | %s | %s | %s | %s',
                    $candidate['kind'],
                    $candidate['reference'],
                    $candidate['site_name'],
                    $candidate['subject'],
                    $candidate['status'] ?? 'n/a',
                    $candidate['priority'] ?? 'n/a',
                    $candidate['url'],
                ));
            }
        }

        if ($candidateCount === 0) {
            $this->line('No digest candidates found.');
        }

        $this->line("Digest preview complete. Agents scanned: {$agentsScanned}. Candidates: {$candidateCount}.");

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
