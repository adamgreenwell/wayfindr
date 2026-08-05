<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Queue\QueueConsumerHeartbeat;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Lets a worker announce that it is consuming a queue (ADR 0013).
 *
 * `backups-queue-consumer` is the check that turns "run a second queue worker"
 * from a changelog paragraph into a requirement the platform can verify. It
 * needs evidence that a worker exists, and only the worker can supply it.
 *
 * Two events, because neither covers the worker's whole life:
 *
 * - `Looping` fires on every pass of the worker loop, so an idle worker is seen
 *   once per `--sleep`. It does NOT fire while a job runs.
 * - `JobProcessing` fires as a job starts, which is the one moment inside a long
 *   job that the worker can speak. The backups worker runs with
 *   `--timeout=3600`, so a single backup can occupy it for an hour with no
 *   `Looping` at all — without this a worker would appear to vanish precisely
 *   while it was doing the work it exists for.
 *
 * The remaining gap (the middle of a long job) is covered on the reading side
 * instead, by asking about a window wide enough to span one legal job rather
 * than by writing more often here.
 */
class QueueConsumerHeartbeatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QueueConsumerHeartbeat::class);
    }

    public function boot(): void
    {
        // Console only: web requests never consume a queue, and a heartbeat
        // written by the process that also reads it would prove nothing.
        if (! $this->app->runningInConsole()) {
            return;
        }

        Event::listen(Looping::class, function (Looping $event): void {
            $this->record(
                is_string($event->connectionName) ? $event->connectionName : null,
                is_string($event->queue) ? $event->queue : null,
            );
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $queue = null;

            try {
                $queue = $event->job->getQueue();
            } catch (Throwable) {
                // A job implementation that cannot name its queue still proves a
                // worker is alive on the connection; fall back to the connection's
                // configured queue rather than dropping the sighting.
            }

            $this->record(
                is_string($event->connectionName) ? $event->connectionName : null,
                is_string($queue) ? $queue : null,
            );
        });
    }

    private function record(?string $connection, ?string $queue): void
    {
        if ($connection === null || $connection === '') {
            return;
        }

        try {
            $this->app->make(QueueConsumerHeartbeat::class)->record($connection, $queue);
        } catch (Throwable) {
            // Resolution can fail during early boot or a torn-down container.
            // A missing sighting reads as "cannot tell", which is safe; a throw
            // here would kill the worker, which is not.
        }
    }
}
