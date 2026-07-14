<?php

namespace App\Models;

use Database\Factories\ConversationMessageAttachmentFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file attached to a conversation message (ADR 0007).
 *
 * Uploads are two-step: the row is created first (conversation-scoped, no
 * message yet) and a later message send binds it. The row carries denormalized
 * `conversation_id`/`account_id`/`site_id` so an authorized-access check can
 * scope the lookup by owner as well as by conversation — a leaked or guessed id
 * still fails the scope. The binary lives on a private disk under an opaque
 * `storage_key` and is only ever reached by streaming through an authorized
 * endpoint.
 */
#[Fillable([
    'conversation_message_id',
    'conversation_id',
    'account_id',
    'site_id',
    'uploaded_by_type',
    'uploaded_by_id',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function uploadedBy(): MorphTo
    {
        return $this->morphTo();
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
    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * An attachment is bound once a message send has claimed it. A bound
     * attachment is part of the transcript and visible to both parties of the
     * conversation; an unbound one is a not-yet-sent upload.
     */
    public function isBound(): bool
    {
        return $this->conversation_message_id !== null;
    }

    public function wasUploadedBy(Model $principal): bool
    {
        return $this->uploaded_by_type === $principal->getMorphClass()
            && (string) $this->uploaded_by_id === (string) $principal->getKey();
    }

    /**
     * The download rule, applied after the caller has already proven access to
     * the conversation: the attachment must be ready, and it must either be
     * bound to a message (part of the transcript, so visible to both parties) or
     * still be the caller's own not-yet-sent upload. This stops one party from
     * fetching the other's attachment before it is actually sent.
     */
    public function isDownloadableBy(Model $principal): bool
    {
        return $this->isReady()
            && ($this->isBound() || $this->wasUploadedBy($principal));
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    /**
     * The safe, client-facing shape of an attachment. Never exposes the storage
     * disk, key, checksum, or uploader — only what a transcript needs to render
     * a row and build a download link.
     *
     * @return array{id: int, filename: string, mime_type: string, size_bytes: int, is_image: bool, status: string}
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'is_image' => $this->isImage(),
            'status' => $this->status,
        ];
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
            ->where('conversation_id', $conversation->id)
            ->where('account_id', $conversation->site?->account_id)
            ->where('site_id', $conversation->site_id);
    }
}
