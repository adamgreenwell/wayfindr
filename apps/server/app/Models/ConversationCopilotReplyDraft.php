<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCopilotSourceFreshness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conversation_id',
    'requested_by_id',
    'generation',
    'connection_revision',
    'policy_revision',
    'source_context_fingerprint',
    'status',
    'draft',
    'source_last_message_id',
    'source_message_count',
    'provider',
    'model',
    'prompt_tokens',
    'completion_tokens',
    'failure_code',
    'requested_at',
    'started_at',
    'completed_at',
])]
final class ConversationCopilotReplyDraft extends Model
{
    use HasCopilotSourceFreshness;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    private const PENDING_FRESH_MINUTES = 5;

    protected function casts(): array
    {
        return [
            'source_last_message_id' => 'integer',
            'source_message_count' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'preparation_source_snapshot' => 'array',
            'previous_output' => 'array',
            'retry_not_before' => 'immutable_datetime',
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function hasFreshPendingRequest(): bool
    {
        $freshnessClock = match ($this->status) {
            self::STATUS_PENDING => $this->requested_at,
            self::STATUS_RUNNING => $this->started_at,
            default => null,
        };

        return $freshnessClock?->isAfter(now()->subMinutes(self::PENDING_FRESH_MINUTES)) === true;
    }

    public function displayStatus(): string
    {
        if (in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true)) {
            return $this->hasFreshPendingRequest()
                ? self::STATUS_PENDING
                : self::STATUS_FAILED;
        }

        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_READY, self::STATUS_FAILED], true)
            ? $this->status
            : self::STATUS_FAILED;
    }
}
