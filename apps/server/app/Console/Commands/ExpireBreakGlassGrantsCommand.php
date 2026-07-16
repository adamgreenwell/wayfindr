<?php

namespace App\Console\Commands;

use App\Support\BreakGlass\BreakGlassGrants;
use Illuminate\Console\Command;

class ExpireBreakGlassGrantsCommand extends Command
{
    protected $signature = 'wayfindr:expire-break-glass-grants';

    protected $description = 'Stamp overdue active break-glass grants as expired and record the audit event (access already cuts off live at expires_at).';

    public function handle(BreakGlassGrants $grants): int
    {
        $expired = $grants->expireOverdue();

        $this->info(sprintf(
            'Expired %d break-glass grant%s.',
            $expired,
            $expired === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
