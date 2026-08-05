<?php

declare(strict_types=1);

namespace App\Support\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Evidence that a worker is consuming a given queue (ADR 0013).
 *
 * Laravel's queue drivers keep no consumer registry — there is nothing to ask
 * "is anyone working this queue". Queue depth cannot stand in for it either: an
 * empty queue looks identical whether a worker is draining it or no worker
 * exists and nothing has been enqueued. So the worker says so itself, and this
 * records what it said.
 *
 * The answers are deliberately three-valued, because the difference between
 * "no worker" and "no way to tell" is the whole point of a machine check:
 *
 *   true  — a worker was observed within the window
 *   false — the channel works, and nothing was observed
 *   null  — this install cannot carry the signal at all
 *
 * Null arises when the cache store cannot be seen by another process. The web
 * process asks the question; the worker is a different process, usually a
 * different container. An `array` store answers only within one request and a
 * `null` store answers nothing, so a heartbeat written through either is
 * invisible to the reader — and reporting "no worker" on that basis would
 * blame the operator for a limitation of their cache configuration.
 *
 * Every other store (redis, memcached, database, file) is treated as shared.
 * That is right for the installs that exist: compose mounts one
 * `wayfindr-storage` volume across web and every worker, and a host-managed
 * install runs them on one filesystem. A multi-server install with an unshared
 * `file` cache would read as "no worker" — but such an install has already
 * broken cached config, locks, and rate limiters, which the readiness cache
 * check reports separately and far more loudly.
 */
class QueueConsumerHeartbeat
{
    /**
     * Stores that cannot carry a signal from one process to another.
     *
     * `null` covers a store whose driver is absent from config entirely, which
     * is unresolvable rather than non-persistent — either way it cannot be read
     * back by anyone else.
     */
    private const UNSHAREABLE_DRIVERS = ['array', 'null', null];

    private const KEY_PREFIX = 'wayfindr:queue-consumer:';

    /**
     * How long a recorded sighting stays readable.
     *
     * This is the storage lifetime, not the freshness rule — callers decide how
     * recent is recent enough by passing a window to `seenWithin()`. It only has
     * to outlive the longest window any caller uses, so the retention is
     * deliberately generous.
     */
    private const RETENTION_SECONDS = 86400;

    /**
     * The last moment each queue was written, per process.
     *
     * `Looping` fires on every poll of the worker loop, which for an idle worker
     * is once per `--sleep` and for a busy one is between every job. Writing on
     * each would be a pointless amount of cache traffic to answer a question
     * nobody asks more than once a request.
     *
     * @var array<string, float>
     */
    private array $lastWriteAt = [];

    public function __construct(private readonly int $throttleSeconds = 15) {}

    /**
     * Record that a worker is consuming this queue, now.
     *
     * `$queue` may be a comma-separated list, because that is what
     * `queue:work --queue=a,b` passes through to the worker loop. Each is
     * recorded: a worker draining `a,backups` is genuinely consuming `backups`,
     * and recording the raw string would file it under a queue of that name
     * which nothing will ever ask about.
     *
     * Never throws. This runs inside the worker loop, and a cache blip must not
     * take down a worker to protect a diagnostic.
     */
    public function record(string $connection, ?string $queue): void
    {
        try {
            foreach ($this->queueNames($connection, $queue) as $name) {
                $this->recordOne($connection, $name);
            }
        } catch (Throwable) {
            // Recording is best-effort; the worker's job is to work.
        }
    }

    /**
     * Was a worker observed on this queue within the last `$seconds`?
     *
     * Returns null when the signal cannot travel between processes here, which
     * is not the same as no worker and must not be reported as one.
     */
    public function seenWithin(string $connection, ?string $queue, int $seconds): ?bool
    {
        $seenAt = $this->lastSeenAt($connection, $queue);

        if ($seenAt === null) {
            return $this->canObserve() ? false : null;
        }

        return $seenAt->getTimestamp() >= CarbonImmutable::now()->getTimestamp() - $seconds;
    }

    /**
     * When a worker was last observed on this queue, or null if never (or if
     * this install cannot carry the signal — call `canObserve()` to tell those
     * apart).
     */
    public function lastSeenAt(string $connection, ?string $queue): ?CarbonImmutable
    {
        if (! $this->canObserve()) {
            return null;
        }

        try {
            $names = $this->queueNames($connection, $queue);
            $latest = null;

            foreach ($names as $name) {
                /** @var mixed $stamp */
                $stamp = Cache::get($this->key($connection, $name));

                if (! is_int($stamp) && ! (is_string($stamp) && ctype_digit($stamp))) {
                    continue;
                }

                $seen = CarbonImmutable::createFromTimestampUTC((int) $stamp);

                if ($latest === null || $seen->greaterThan($latest)) {
                    $latest = $seen;
                }
            }

            return $latest;
        } catch (Throwable) {
            // An unreachable cache has not demonstrated the absence of a worker.
            return null;
        }
    }

    /**
     * Whether a sighting written by another process could be read back here.
     */
    public function canObserve(): bool
    {
        try {
            $default = (string) config('cache.default', 'file');

            foreach ($this->storeMembers($default) as $name) {
                /** @var mixed $driver */
                $driver = config("cache.stores.{$name}.driver");
                $driver = is_string($driver) ? $driver : null;

                // One shareable member is enough: a failover chain writes
                // through to whichever store is up, and a chain containing a
                // real backing store can carry the signal.
                if (! in_array($driver, self::UNSHAREABLE_DRIVERS, true)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    private function recordOne(string $connection, string $queue): void
    {
        $key = $this->key($connection, $queue);
        $now = microtime(true);

        if (isset($this->lastWriteAt[$key]) && $now - $this->lastWriteAt[$key] < $this->throttleSeconds) {
            return;
        }

        if (! $this->canObserve()) {
            // Nothing could read it back. Skip the write rather than spend it.
            return;
        }

        Cache::put($key, CarbonImmutable::now()->getTimestamp(), self::RETENTION_SECONDS);

        // Only after a successful write, so a throwing cache retries next loop
        // instead of being throttled out for the next throttle window.
        $this->lastWriteAt[$key] = $now;
    }

    /**
     * @return list<string>
     */
    private function queueNames(string $connection, ?string $queue): array
    {
        $raw = $queue ?? '';

        if (trim($raw) === '') {
            /** @var mixed $configured */
            $configured = config("queue.connections.{$connection}.queue", 'default');
            $raw = is_string($configured) ? $configured : 'default';
        }

        $names = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $name): bool => $name !== '',
        ));

        return $names === [] ? ['default'] : $names;
    }

    /**
     * @return list<string>
     */
    private function storeMembers(string $default): array
    {
        /** @var mixed $driver */
        $driver = config("cache.stores.{$default}.driver");

        if ($driver !== 'failover') {
            return [$default];
        }

        /** @var mixed $members */
        $members = config("cache.stores.{$default}.stores", []);

        return array_values(array_filter((array) $members, 'is_string'));
    }

    private function key(string $connection, string $queue): string
    {
        return self::KEY_PREFIX.$connection.':'.$queue;
    }
}
