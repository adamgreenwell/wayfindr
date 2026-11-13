<?php

declare(strict_types=1);

namespace App\Support\Ai\Usage;

use App\Support\Ai\Network\AiNetworkException;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Throwable;

final readonly class DatabaseAiUsageClock implements AiUsageClock
{
    public function __construct(private DatabaseManager $database) {}

    public function now(): CarbonImmutable
    {
        try {
            $connection = $this->database->connection();
            $sql = match ($connection->getDriverName()) {
                // now()/CURRENT_TIMESTAMP freezes at transaction start on PG.
                'pgsql' => 'SELECT clock_timestamp() AS usage_now',
                'sqlite' => "SELECT strftime('%Y-%m-%dT%H:%M:%fZ', 'now') AS usage_now",
                default => throw new AiNetworkException('usage_clock_unavailable'),
            };
            // Use the writer that owns the admission locks, never a replica.
            $value = $connection->selectOne($sql, [], false)?->usage_now;
            if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}(?::?[0-9]{2})?)\z/', $value) !== 1) {
                throw new AiNetworkException('usage_clock_unavailable');
            }

            $parsed = CarbonImmutable::parse($value, 'UTC');
            if (substr($value, 0, 10) !== $parsed->format('Y-m-d')) {
                throw new AiNetworkException('usage_clock_unavailable');
            }

            return $parsed->utc();
        } catch (Throwable) {
            // No PHP wall-clock fallback or database exception/object chain.
            throw new AiNetworkException('usage_clock_unavailable');
        }
    }
}
