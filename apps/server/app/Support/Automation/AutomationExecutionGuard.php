<?php

namespace App\Support\Automation;

use App\Models\Conversation;
use App\Models\Ticket;

final class AutomationExecutionGuard
{
    /** @var array<string, true> */
    private array $activeSubjects = [];

    public function enter(Ticket|Conversation $subject): bool
    {
        $key = $this->key($subject);

        if (isset($this->activeSubjects[$key])) {
            return false;
        }

        $this->activeSubjects[$key] = true;

        return true;
    }

    public function leave(Ticket|Conversation $subject): void
    {
        unset($this->activeSubjects[$this->key($subject)]);
    }

    private function key(Ticket|Conversation $subject): string
    {
        return $subject->getMorphClass().':'.$subject->getKey();
    }
}
