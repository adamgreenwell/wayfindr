<?php

namespace App\Models;

use Database\Factories\ProactiveMessageDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'site_id',
    'proactive_message_rule_id',
    'visitor_id',
    'conversation_id',
    'rule_public_id',
    'visitor_key',
    'claim_key',
    'message',
    'claimed_at',
    'expires_at',
    'shown_at',
    'engaged_at',
    'dismissed_at',
])]
class ProactiveMessageDelivery extends Model
{
    /** @use HasFactory<ProactiveMessageDeliveryFactory> */
    use HasFactory;

    public const CLAIM_MINUTES = 5;

    public const RETENTION_DAYS = 90;

    protected static function booted(): void
    {
        static::creating(function (self $delivery): void {
            $delivery->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'claimed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'shown_at' => 'immutable_datetime',
            'engaged_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ProactiveMessageRule::class, 'proactive_message_rule_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
