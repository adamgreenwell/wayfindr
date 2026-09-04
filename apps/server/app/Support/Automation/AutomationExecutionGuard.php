<?php

namespace App\Support\Automation;

use App\Models\Conversation;
use App\Models\Ticket;
use Closure;

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

    /**
     * Run an internal model mutation without treating its observer callback as
     * a new automation event. Preserve an existing guard owned by the caller.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function suppress(Ticket|Conversation $subject, Closure $callback): mixed
    {
        $key = $this->key($subject);
        $alreadyActive = isset($this->activeSubjects[$key]);
        $this->activeSubjects[$key] = true;

        try {
            return $callback();
        } finally {
            if (! $alreadyActive) {
                unset($this->activeSubjects[$key]);
            }
        }
    }

    private function key(Ticket|Conversation $subject): string
    {
        return $subject->getMorphClass().':'.$subject->getKey();
    }
}
