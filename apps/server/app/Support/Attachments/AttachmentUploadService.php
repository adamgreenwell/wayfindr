<?php

namespace App\Support\Attachments;

use App\Models\Conversation;
use App\Models\ConversationMessageAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates and stores an uploaded file as a pending (not-yet-sent) attachment
 * (ADR 0007). Every limit here is enforced server-side, independent of the
 * client, and the MIME allowlist is matched against the SERVER-detected type —
 * the client's filename and Content-Type are display hints only.
 *
 * The row lands unbound (no message): a later message send binds it. The binary
 * goes to the private `attachments` disk under an opaque key.
 */
class AttachmentUploadService
{
    public function store(Conversation $conversation, UploadedFile $file, Model $uploader): ConversationMessageAttachment
    {
        $conversation->loadMissing('site');
        $site = $conversation->site;

        abort_unless($site, 404);

        $sizeBytes = (int) $file->getSize();

        if ($sizeBytes <= 0) {
            throw ValidationException::withMessages(['file' => 'The file could not be read.']);
        }

        $maxFileBytes = (int) config('wayfindr.attachments.max_file_bytes');

        if ($sizeBytes > $maxFileBytes) {
            throw ValidationException::withMessages([
                'file' => 'The file is larger than the '.$this->humanBytes($maxFileBytes).' limit.',
            ]);
        }

        // Sniff the MIME from the file's bytes (finfo), never the client header,
        // and allowlist by that detected type.
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $allowed = (array) config('wayfindr.attachments.allowed_mime_types', []);

        if (! in_array($mimeType, $allowed, true)) {
            throw ValidationException::withMessages(['file' => 'This file type is not allowed.']);
        }

        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;
        $maxConversationBytes = (int) config('wayfindr.attachments.max_conversation_bytes');
        $filename = $this->sanitizeFilename($file->getClientOriginalName());

        // Opaque, non-guessable key: no client-derived segment, no extension, no
        // relation to the conversation id.
        $storageKey = Str::lower((string) Str::ulid()).'/'.Str::lower((string) Str::ulid());

        return DB::transaction(function () use (
            $conversation, $site, $file, $uploader, $sizeBytes, $mimeType, $checksum, $filename, $storageKey, $maxConversationBytes
        ): ConversationMessageAttachment {
            // Serialize concurrent uploads to this conversation so the cap check
            // and the insert are atomic — without the lock, two uploads could
            // both read the old total and both push it over the limit.
            Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->first();

            $existingBytes = (int) ConversationMessageAttachment::query()
                ->where('conversation_id', $conversation->id)
                ->sum('size_bytes');

            if ($existingBytes + $sizeBytes > $maxConversationBytes) {
                throw ValidationException::withMessages([
                    'file' => 'This conversation has reached its attachment storage limit.',
                ]);
            }

            // The disk is configured with throw => false, so a failed write
            // returns false rather than throwing — surface it instead of
            // recording a row that points at a missing file.
            abort_if(
                Storage::disk('attachments')->putFileAs(dirname($storageKey), $file, basename($storageKey)) === false,
                500,
                'The attachment could not be stored.',
            );

            $attachment = ConversationMessageAttachment::query()->create([
                'conversation_message_id' => null,
                'conversation_id' => $conversation->id,
                'account_id' => $site->account_id,
                'site_id' => $site->id,
                'uploaded_by_type' => $uploader->getMorphClass(),
                'uploaded_by_id' => $uploader->getKey(),
                'storage_disk' => 'attachments',
                'storage_key' => $storageKey,
                'original_filename' => $filename,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'checksum' => $checksum,
                'status' => ConversationMessageAttachment::STATUS_READY,
            ]);

            $conversation->auditEvents()->create([
                'account_id' => $site->account_id,
                'site_id' => $site->id,
                'actor_type' => $uploader->getMorphClass(),
                'actor_id' => $uploader->getKey(),
                'action' => 'attachment.uploaded',
                'metadata' => [
                    'attachment_id' => $attachment->id,
                    'mime_type' => $mimeType,
                    'size_bytes' => $sizeBytes,
                    'filename' => $attachment->original_filename,
                ],
                'occurred_at' => now(),
            ]);

            return $attachment;
        });
    }

    private function sanitizeFilename(?string $name): string
    {
        // Keep only the basename (strip any path the client tried to smuggle),
        // drop control characters, and cap the length for display.
        $name = basename((string) $name);
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'attachment' : Str::limit($name, 180, '');
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024)).' MB';
        }

        return round($bytes / 1024).' KB';
    }
}
