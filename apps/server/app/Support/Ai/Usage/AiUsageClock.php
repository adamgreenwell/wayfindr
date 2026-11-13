<?php

declare(strict_types=1);

namespace App\Support\Ai\Usage;

use Carbon\CarbonImmutable;

interface AiUsageClock
{
    /** Database UTC wall time, sampled by the caller after its usage locks. */
    public function now(): CarbonImmutable;
}
