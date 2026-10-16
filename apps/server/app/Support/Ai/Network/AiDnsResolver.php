<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use SensitiveParameter;

interface AiDnsResolver
{
    /**
     * @param  float  $deadline  Absolute monotonic time in hrtime seconds.
     * @return list<string> A/AAAA addresses, never hostnames.
     */
    public function resolve(#[SensitiveParameter] string $host, float $deadline): array;
}
