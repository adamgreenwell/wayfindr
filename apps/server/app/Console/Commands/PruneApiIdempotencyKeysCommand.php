<?php

namespace App\Console\Commands;

use App\Models\ApiIdempotencyKey;
use Illuminate\Console\Command;

class PruneApiIdempotencyKeysCommand extends Command
{
    protected $signature = 'wayfindr:prune-api-idempotency-keys';

    protected $description = 'Delete expired public API idempotency receipts.';

    public function handle(): int
    {
        $deleted = ApiIdempotencyKey::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $this->info(sprintf('Pruned %d expired API idempotency %s.', $deleted, $deleted === 1 ? 'receipt' : 'receipts'));

        return self::SUCCESS;
    }
}
