<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Accounts\DeactivatedAgentCredentialSweep;
use Illuminate\Console\Command;

/**
 * Withdraw API tokens and webhook endpoints left behind by deactivated agents.
 *
 * Both deploy scripts run this after the new code is the only code serving, and
 * the scheduler runs it daily for the install shapes that run neither script.
 *
 * It reports what it did rather than working silently. After the backlog is
 * cleared this finds nothing forever, because deactivation withdraws
 * synchronously -- so a later run that withdraws something is a signal, and a
 * sweep that quietly disabled live integrations every night would be the wrong
 * thing to have built.
 */
class WithdrawDeactivatedAgentCredentialsCommand extends Command
{
    protected $signature = 'wayfindr:withdraw-deactivated-agent-credentials';

    protected $description = 'Revoke API tokens and disable webhook endpoints created by agents who have since been deactivated';

    public function handle(DeactivatedAgentCredentialSweep $sweep): int
    {
        ['agents' => $agents, 'tokens' => $tokens, 'endpoints' => $endpoints] = $sweep->sweep();

        if ($tokens === 0 && $endpoints === 0) {
            $this->info('No API tokens or webhook endpoints are held by deactivated agents.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Revoked %d API token%s and disabled %d webhook endpoint%s created by %d deactivated agent%s.',
            $tokens,
            $tokens === 1 ? '' : 's',
            $endpoints,
            $endpoints === 1 ? '' : 's',
            $agents,
            $agents === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
