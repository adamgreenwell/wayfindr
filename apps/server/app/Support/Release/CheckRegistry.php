<?php

declare(strict_types=1);

namespace App\Support\Release;

use App\Support\Queue\QueueConsumerHeartbeat;
use Throwable;

/**
 * The machine checks a declaration may name (ADR 0013).
 *
 * A check is what makes a requirement *verified* rather than merely attested, so
 * the registry only ever answers with evidence:
 *
 *   true  — the condition holds
 *   false — it does not
 *   null  — this build cannot evaluate it
 *
 * Null is not a pass. An unknown check name lands here, which matters because a
 * declaration can outlive the build reading it: an older image evaluating a
 * newer release's history will meet names it has never heard of, and treating
 * those as satisfied would let the requirement through unexamined.
 */
final class CheckRegistry
{
    /** @var array<string, callable(): ?bool> */
    private array $checks = [];

    public function __construct()
    {
        $this->register('backups-queue-consumer', function (): ?bool {
            // Declared by the backups GUI release: a worker must be consuming the
            // dedicated `backups` connection, or a queued backup never starts —
            // the run sits at "Running" forever and nothing says why.
            try {
                /** @var mixed $connection */
                $connection = config('queue.connections.backups');

                if (! is_array($connection)) {
                    // No such connection. The declaration is asking about
                    // something this install does not have, which is a question
                    // this check cannot answer rather than a failure.
                    return null;
                }

                /** @var mixed $queue */
                $queue = $connection['queue'] ?? null;

                return app(QueueConsumerHeartbeat::class)->seenWithin(
                    'backups',
                    is_string($queue) ? $queue : null,
                    self::backupsWindowSeconds($connection),
                );
            } catch (Throwable) {
                return null;
            }
        });
    }

    /**
     * How long a backups worker may go unseen and still count as present.
     *
     * A worker announces itself as it loops and as each job starts, but not
     * during a job — and the backups worker runs with `--timeout=3600`, so one
     * legitimate backup can occupy it for an hour in silence. Asking "seen in
     * the last minute" would therefore report "no worker" most reliably while a
     * backup was actually running.
     *
     * `retry_after` is already this install's answer to "the longest a job may
     * hold the worker before the queue gives up on it", so it is the bound that
     * is true by construction rather than a number chosen to feel safe. The
     * consequence — a worker stopped an hour ago still reads as present — is
     * the right way round for this question. The check asks whether the operator
     * set the worker up, not whether it is alive this instant; the backups page
     * shows the actual last-seen time for that.
     *
     * @param  array<mixed>  $connection
     */
    private static function backupsWindowSeconds(array $connection): int
    {
        /** @var mixed $retryAfter */
        $retryAfter = $connection['retry_after'] ?? null;

        $window = is_numeric($retryAfter) ? (int) $retryAfter : 0;

        // A connection with no usable retry_after still needs a bound. An hour
        // matches the default backup job timeout.
        return $window > 0 ? $window : 3600;
    }

    /**
     * @param  callable(): ?bool  $check
     */
    public function register(string $name, callable $check): void
    {
        $this->checks[$name] = $check;
    }

    public function evaluate(string $name): ?bool
    {
        if (! isset($this->checks[$name])) {
            return null;
        }

        try {
            return ($this->checks[$name])();
        } catch (Throwable) {
            // A check that blows up has not demonstrated anything.
            return null;
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->checks);
    }
}
