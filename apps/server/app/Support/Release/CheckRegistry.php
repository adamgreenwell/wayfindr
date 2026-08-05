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

                $heartbeat = app(QueueConsumerHeartbeat::class);

                return $heartbeat->seenWithin(
                    'backups',
                    is_string($queue) ? $queue : null,
                    // The window lives on the heartbeat so the backups page
                    // applies the same freshness rule. Two copies would let the
                    // page call a worker healthy while the check called the
                    // requirement unmet.
                    $heartbeat->windowSecondsFor('backups'),
                );
            } catch (Throwable) {
                return null;
            }
        });
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
