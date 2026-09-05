<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Retry mail after the active Web Push handoff reaches a durable outcome. */
final class AgentAlertDeliveryPendingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Web Push delivery is still in progress for this alert version.');
    }
}
