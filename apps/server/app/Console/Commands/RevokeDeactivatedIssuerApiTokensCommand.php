<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Accounts\DeactivatedIssuerApiTokenSweep;
use Illuminate\Console\Command;

/**
 * Revoke API tokens whose issuing agent is deactivated.
 *
 * Both deploy scripts run this after the new code is the only code serving, and
 * the scheduler runs it daily for the install shapes that run neither script.
 *
 * It reports what it did rather than working silently. After the backlog is
 * cleared this finds nothing forever, because deactivation revokes
 * synchronously -- so a later run that revokes something is a signal, and a
 * sweep that quietly disabled live credentials every night would be the wrong
 * thing to have built.
 */
class RevokeDeactivatedIssuerApiTokensCommand extends Command
{
    protected $signature = 'wayfindr:revoke-deactivated-issuer-api-tokens';

    protected $description = 'Revoke API tokens issued by agents who have since been deactivated';

    public function handle(DeactivatedIssuerApiTokenSweep $sweep): int
    {
        ['issuers' => $issuers, 'tokens' => $tokens] = $sweep->sweep();

        if ($tokens === 0) {
            $this->info('No API tokens are held by deactivated issuers.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Revoked %d API token%s issued by %d deactivated agent%s.',
            $tokens,
            $tokens === 1 ? '' : 's',
            $issuers,
            $issuers === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
