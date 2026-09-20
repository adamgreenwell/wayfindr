<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Conversations\LegacyOwnerSessionSweep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Finish the owning-session sweep after the previous release's requests drain.
 *
 * Activation stops NEW requests reaching the old release; it does not cancel the
 * ones already executing. One of those can commit a conversation with no owning
 * session AFTER the immediate post-activation pass has finished, and a null
 * grants access to nobody -- so the visitor who just opened it is told it does
 * not exist.
 *
 * The daily scheduled sweep is too late on its own to be the answer: the widget
 * keeps one support code, and on a refusal it used to forget it, so a repair
 * arriving hours later had nothing left to repair FOR. The widget no longer
 * discards the code, and this pass shortens the wait from a day to a couple of
 * minutes, which is the difference between a visitor reloading into their
 * conversation and reloading into an intake form.
 */
final class ClaimLegacyConversationSessionsAfterDrain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 60];
    }

    public function handle(): void
    {
        $claimed = LegacyOwnerSessionSweep::run();

        if ($claimed > 0) {
            Log::info('Claimed conversations with no owning session after old requests drained.', [
                'claimed' => $claimed,
            ]);
        }
    }
}
