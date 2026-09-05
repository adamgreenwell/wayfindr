<?php

namespace App\Console\Commands;

use App\Jobs\SendUnattendedConversationAlert;
use App\Models\User;
use App\Support\UnattendedConversationAlertCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendUnattendedConversationAlertsCommand extends Command
{
    protected $signature = 'wayfindr:send-unattended-conversation-alerts
        {--email= : Limit delivery to one agent email address}';

    protected $description = 'Email unattended-cadence agents when a visitor message has waited unseen past the threshold.';

    public function handle(UnattendedConversationAlertCollector $collector): int
    {
        $email = trim((string) $this->option('email'));

        if ($email !== '' && ! User::query()->where('email', $email)->exists()) {
            $this->error("No agent found for {$email}.");

            return self::FAILURE;
        }

        $agentsScanned = 0;
        $emailsQueued = 0;
        $failed = 0;

        foreach ($this->eligibleAgents($email) as $agent) {
            $agentsScanned++;

            $candidates = $collector->forAgent($agent);

            if ($candidates->isEmpty()) {
                continue;
            }

            try {
                SendUnattendedConversationAlert::dispatchPending($agent->id);

                $emailsQueued++;

                $this->line("Queued unattended alert for {$agent->name} <{$agent->email}> with {$candidates->count()} waiting conversation(s).");
            } catch (Throwable $exception) {
                $failed++;

                Log::warning('Unattended conversation alert delivery failed.', [
                    'agent_id' => $agent->id,
                    'agent_email' => $agent->email,
                    'exception' => $exception,
                ]);

                $this->error("Failed unattended alert for {$agent->name} <{$agent->email}>.");
            }
        }

        $summary = "Unattended alert delivery complete. Agents scanned: {$agentsScanned}. Emails queued: {$emailsQueued}.";

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
        return User::query()
            ->when($email !== '', fn ($query) => $query->where('email', $email))
            ->orderBy('email')
            ->get()
            ->filter(fn (User $agent): bool => ! $agent->isDeactivated()
                && $agent->wantsUnattendedAlertEmail())
            ->values();
    }
}
