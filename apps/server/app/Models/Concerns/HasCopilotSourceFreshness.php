<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Ai\Execution\AiSupportUsage;
use Throwable;

/** Shared visible freshness for the four private support artifacts. */
trait HasCopilotSourceFreshness
{
    public function isStaleComparedTo(?int $latestMessageId): bool
    {
        if ($this->status !== self::STATUS_READY) {
            return false;
        }
        if ($latestMessageId !== null && $this->source_last_message_id !== $latestMessageId) {
            return true;
        }
        $fingerprint = $this->source_context_fingerprint;
        if (! is_string($fingerprint) || $fingerprint === '') {
            // Pre-policy artifacts retain their existing readable ID-based state.
            return false;
        }
        try {
            return ! hash_equals($fingerprint, AiSupportUsage::sourceFingerprint($this));
        } catch (Throwable) {
            // An edited/deleted or unreadable source can never look fresh.
            return true;
        }
    }
}
