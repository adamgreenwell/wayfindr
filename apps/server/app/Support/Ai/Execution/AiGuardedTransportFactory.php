<?php

declare(strict_types=1);

namespace App\Support\Ai\Execution;

use App\Support\Ai\Network\AiHttpTransport;
use App\Support\Ai\Network\AiNetworkPolicy;
use App\Support\Ai\Usage\AiUsageLedger;
use Closure;

/** Handler/clock are trusted test seams; no account option can supply them. */
final readonly class AiGuardedTransportFactory
{
    public function __construct(private AiNetworkPolicy $policy, private AiUsageLedger $ledger, private AiRequestAuthority $authority,
        private ?Closure $terminalHandler = null, private ?Closure $monotonicClock = null) {}

    public function make(AiExecutionContext $context): AiHttpTransport
    {
        return new AiHttpTransport($this->policy, new AiScopedOutboundAdmission($this->ledger, $this->authority, $context),
            $this->terminalHandler, $this->monotonicClock);
    }
}
