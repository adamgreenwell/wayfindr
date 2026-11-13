<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Usage\AiUsageLedger;
use Illuminate\Console\Command;

final class ExpireAiUsageReservationsCommand extends Command
{
    protected $signature = 'wayfindr:expire-ai-usage-reservations {--limit=1000 : Maximum receipts to process (1–1000)}';

    protected $description = 'Release expired unsent AI holds and expired concurrency leases without refunding unknown dispatched usage.';

    public function handle(AiUsageLedger $ledger): int
    {
        $limit = $this->option('limit');
        if (! is_string($limit) || preg_match('/\A[1-9][0-9]{0,3}\z/', $limit) !== 1 || (int) $limit > 1000) {
            $this->error('Choose a whole-number limit from 1 to 1000.');

            return self::INVALID;
        }

        try {
            $expired = $ledger->expire((int) $limit);
        } catch (AiNetworkException) {
            $this->error('AI usage reservations could not be expired.');

            return self::FAILURE;
        }
        $this->info(sprintf('Expired %d AI usage reservations.', $expired));

        return self::SUCCESS;
    }
}
