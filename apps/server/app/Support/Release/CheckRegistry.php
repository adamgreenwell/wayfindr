<?php

declare(strict_types=1);

namespace App\Support\Release;

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
            // dedicated `backups` connection, or a queued backup never starts.
            try {
                $connection = config('queue.connections.backups');

                if (! is_array($connection)) {
                    return null;
                }

                // Runtime evidence for this arrives with the worker heartbeat in
                // slice 3; until then the configuration is all that can be seen,
                // so it reports "cannot evaluate" rather than a false pass.
                return null;
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
