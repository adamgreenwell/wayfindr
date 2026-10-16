<?php

declare(strict_types=1);
use App\Support\Ai\Usage\AiUsageClock;
use Carbon\CarbonImmutable;

/** Explicit test double for legacy fixtures that freeze Laravel's clock. */
final class CopilotTestUsageClock implements AiUsageClock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(now());
    }
}
