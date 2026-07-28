<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operator-triggered backup run (ADR 0011 slice 3). Written by RunBackupJob
 * as it progresses (running -> succeeded/failed) so the operator sees the
 * outcome of the queued "run a backup now" action.
 */
#[Fillable([
    'status',
    'message',
    'archive_path',
    'size_bytes',
    'offsite_disk',
    'offsite_key',
    'pruned_local',
    'pruned_remote',
    'triggered_by_id',
    'started_at',
    'finished_at',
])]
class BackupRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'pruned_local' => 'integer',
            'pruned_remote' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_id');
    }
}
