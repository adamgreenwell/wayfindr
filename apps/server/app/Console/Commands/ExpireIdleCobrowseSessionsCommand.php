<?php

namespace App\Console\Commands;

use App\Models\CobrowseSession;
use Illuminate\Console\Command;

class ExpireIdleCobrowseSessionsCommand extends Command
{
    protected $signature = 'wayfindr:expire-idle-cobrowse-sessions {--dry-run : Report what would be expired without changing anything}';

    protected $description = 'End granted cobrowse sessions that have gone idle past the idle window, so abandoned sessions stop reading as active and their content becomes eligible for the retention pruner.';

    public function handle(): int
    {
        $minutes = max(1, (int) config('wayfindr.cobrowse.session_idle_expiry_minutes', 15));
        $cutoff = now()->subMinutes($minutes);
        $dryRun = (bool) $this->option('dry-run');
        $expired = 0;

        // A session's updated_at advances on every telemetry write (page state,
        // snapshot, mutation, telemetry all save the row), so it is a reliable
        // last-activity clock. ended_at is otherwise only set when an agent stops
        // or a visitor revokes — a visitor who closes the tab leaves the session
        // granted forever, which keeps it "active" in readiness and, worse, out
        // of the ended-only retention pruner. Expire the idle ones here.
        CobrowseSession::query()
            ->where('status', 'granted')
            ->whereNull('ended_at')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use ($dryRun, $cutoff, $minutes, &$expired): void {
                foreach ($sessions as $session) {
                    if ($dryRun) {
                        $expired++;

                        continue;
                    }

                    $didExpire = false;

                    $session->updateAtomically(function (CobrowseSession $locked) use ($cutoff, $minutes, &$didExpire): void {
                        // Re-check under lock: a late telemetry write or an explicit
                        // end could have landed between the query and the lock.
                        if ($locked->status !== 'granted' || $locked->ended_at !== null) {
                            return;
                        }

                        if ($locked->updated_at === null || $locked->updated_at->greaterThan($cutoff)) {
                            return;
                        }

                        // ended_at is the last activity, so the retention pruner's
                        // 72h clock counts from when the visitor actually left, not
                        // from when this sweep noticed.
                        $lastActivity = $locked->updated_at;
                        $metadata = $locked->metadata ?? [];
                        $metadata['ended_by_type'] = 'system';
                        $metadata['ended_reason'] = 'idle_timeout';
                        $metadata['idle_expiry_minutes'] = $minutes;

                        $locked->forceFill([
                            'status' => 'ended',
                            'metadata' => $metadata,
                            'ended_at' => $lastActivity,
                        ]);

                        $didExpire = true;
                    });

                    if ($didExpire) {
                        $expired++;
                    }
                }
            });

        $this->info(sprintf(
            '%s %d idle cobrowse session%s past the %d-minute idle window.',
            $dryRun ? 'Would expire' : 'Expired',
            $expired,
            $expired === 1 ? '' : 's',
            $minutes,
        ));

        return self::SUCCESS;
    }
}
