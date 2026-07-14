<?php

namespace App\Models;

use Database\Factories\ConversationMessageAttachmentFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file attached to a conversation message (ADR 0007).
 *
 * The row carries denormalized `account_id`/`site_id` so an authorized-access
 * check can scope the lookup by owner as well as by conversation — a leaked or
 * guessed id still fails the scope. The binary lives on a private disk under an
 * opaque `storage_key` and is only ever reached by streaming through an
 * authorized endpoint.
 */
#[Fillable([
    'conversation_message_id',
    'account_id',
    'site_id',
    'storage_disk',
    'storage_key',
    'original_filename',
    'mime_type',
    'size_bytes',
    'checksum',
    'status',
    'scan_status',
    'scanned_at',
])]
class ConversationMessageAttachment extends Model
{
    /** @use HasFactory<ConversationMessageAttachmentFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_QUARANTINED = 'quarantined';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'scanned_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->storage_disk);
    }

    /**
     * Only a fully-stored, non-quarantined attachment may be served. Anything
     * else (still uploading, failed, or held by the scanner) is treated as
     * absent by the download path so its existence and state stay opaque.
     */
    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Scope an attachment lookup to a single conversation AND its owning
     * account/site. This is the query half of the access boundary: the caller
     * still enforces the visitor/agent authorization, but even an authorized
     * caller can only ever resolve an attachment that belongs to the exact
     * conversation they proved access to.
     *
     * @param  Builder<ConversationMessageAttachment>  $query
     * @return Builder<ConversationMessageAttachment>
     */
    public function scopeForConversation(Builder $query, Conversation $conversation): Builder
    {
        return $query
            ->where('account_id', $conversation->site?->account_id)
            ->where('site_id', $conversation->site_id)
            ->whereHas('message', fn (Builder $message): Builder => $message
                ->where('conversation_id', $conversation->id));
    }
}
