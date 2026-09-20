<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Conversations\LegacyOwnerSessionSweep;
use Illuminate\Console\Command;

/**
 * Re-run the owning-session sweep.
 *
 * The migration does the bulk of it, but on the supported zero-downtime path it
 * runs BEFORE the new release is activated -- so the old writers are still
 * accepting widget traffic while it works, and a conversation opened after its
 * batch was passed keeps a null owning session. Null grants nothing, so that
 * visitor could not reach the conversation they had just started.
 *
 * The zero-downtime deploy script calls this after activation, when the only
 * code serving is the code that records a session. `standard-deploy.sh` does not
 * and does not need to: it takes the site down before migrating, so no
 * conversation can be opened during the window at all. The scheduler runs this
 * daily for the install shapes that run neither script, and for the last old
 * request that finishes after the post-activation pass.
 *
 * Idempotent, so running it again costs a scan and changes nothing.
 */
class ClaimLegacyConversationSessionsCommand extends Command
{
    protected $signature = 'wayfindr:claim-legacy-conversation-sessions';

    protected $description = 'Mark conversations with no owning session as predating session ownership';

    public function handle(): int
    {
        $claimed = LegacyOwnerSessionSweep::run();

        // Nothing to do is the expected result on every run after the first, and
        // saying so plainly stops it reading like a failure in a deploy log.
        $this->info($claimed === 0
            ? 'No conversation needed an owning session claimed.'
            : sprintf('Claimed %d conversation(s) as predating session ownership.', $claimed));

        return self::SUCCESS;
    }
}
