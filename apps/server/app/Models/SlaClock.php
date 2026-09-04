<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'account_id',
    'site_id',
    'subject_type',
    'subject_id',
    'metric',
    'priority',
    'target_seconds',
    'warning_seconds',
    'elapsed_seconds',
    'started_at',
    'last_counted_at',
    'warned_at',
    'breached_at',
    'warning_alerted_user_ids',
    'warning_mail_alerted_user_ids',
    'warning_alerted_at',
    'breach_alerted_user_ids',
    'breach_mail_alerted_user_ids',
    'breach_alerted_at',
    'satisfied_at',
    'cancelled_at',
])]
class SlaClock extends Model
{
    public const METRIC_FIRST_RESPONSE = 'first_response';

    public const METRIC_RESOLUTION = 'resolution';

    public const WARNING_PERCENT = 80;

    protected function casts(): array
    {
        return [
            'target_seconds' => 'integer',
            'warning_seconds' => 'integer',
            'elapsed_seconds' => 'integer',
            'started_at' => 'datetime',
            'last_counted_at' => 'datetime',
            'warned_at' => 'datetime',
            'breached_at' => 'datetime',
            'warning_alerted_user_ids' => 'array',
            'warning_mail_alerted_user_ids' => 'array',
            'warning_alerted_at' => 'datetime',
            'breach_alerted_user_ids' => 'array',
            'breach_mail_alerted_user_ids' => 'array',
            'breach_alerted_at' => 'datetime',
            'satisfied_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isActive(): bool
    {
        return $this->satisfied_at === null && $this->cancelled_at === null;
    }

    public static function warningSeconds(int $targetSeconds): int
    {
        return max(1, (int) floor($targetSeconds * (self::WARNING_PERCENT / 100)));
    }

    /** @return list<int> */
    public function alertedUserIds(string $stage, string $channel = 'database'): array
    {
        $ids = $this->getAttribute(match ([$stage, $channel]) {
            ['warning', 'database'] => 'warning_alerted_user_ids',
            ['warning', 'mail'] => 'warning_mail_alerted_user_ids',
            ['breach', 'database'] => 'breach_alerted_user_ids',
            ['breach', 'mail'] => 'breach_mail_alerted_user_ids',
            default => throw new \InvalidArgumentException('Unknown SLA alert handoff.'),
        });

        return collect(is_array($ids) ? $ids : [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function alertWasHandedOff(string $stage, string $channel, int $userId): bool
    {
        return in_array($userId, $this->alertedUserIds($stage, $channel), true);
    }

    public function alertStageIsCurrent(string $stage): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return match ($stage) {
            'warning' => $this->warned_at !== null
                && $this->breached_at === null
                && $this->elapsed_seconds >= $this->warning_seconds,
            'breach' => $this->breached_at !== null
                && $this->elapsed_seconds >= $this->target_seconds,
            default => false,
        };
    }
}
