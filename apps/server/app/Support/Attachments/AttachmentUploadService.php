<?php

namespace App\Support\Attachments;

use App\Models\Conversation;
use App\Models\ConversationMessageAttachment;
use App\Models\OperatorSetting;
use App\Models\Site;
use App\Support\Attachments\Scanning\AttachmentScanner;
use App\Support\Settings\OperatorSettings;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
    public function __construct(private readonly AttachmentScanner $scanner) {}

    /**
     * @param  (Closure(Model, Conversation): array{0: Model, 1: Conversation})|null  $authorizeWrite
     */
    public function store(Conversation $conversation, UploadedFile $file, Model $uploader, ?Closure $authorizeWrite = null): ConversationMessageAttachment
    {
        $conversation->loadMissing('site');
        $site = $conversation->site;

        abort_unless($site, 404);

        $sizeBytes = (int) $file->getSize();

        if ($sizeBytes <= 0) {
            throw AttachmentRejected::file('composer.rejected.unreadable');
        }

        $maxFileBytes = (int) config('wayfindr.attachments.max_file_bytes');

        if ($sizeBytes > $maxFileBytes) {
            throw AttachmentRejected::file('composer.rejected.too_large', [
                'limit' => $this->humanBytes($maxFileBytes),
            ]);
        }

        // Sniff the MIME from the file's bytes (finfo), never the client header,
        // and allowlist by that detected type.
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $allowed = (array) config('wayfindr.attachments.allowed_mime_types', []);

        if (! in_array($mimeType, $allowed, true)) {
            throw AttachmentRejected::file('composer.rejected.type');
        }

        $filename = $this->sanitizeFilename($file->getClientOriginalName());

        // Pre-flight the destination disk before scanning: a misconfigured
        // storage target must reject the upload loudly, not waste a scan. The
        // authoritative disk is re-resolved under a shared lock inside the
        // transaction (below) so a concurrent location change cannot strand the
        // object.
        $diskName = AttachmentStorage::diskName();

        // Scan the bytes before they are stored, so an infected file never
        // reaches the disk.
        $this->scan($file, $conversation, $site, $uploader, $filename, $mimeType);

        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;
        $maxConversationBytes = (int) config('wayfindr.attachments.max_conversation_bytes');

        // Opaque, non-guessable key: no client-derived segment, no extension, no
        // relation to the conversation id.
        $storageKey = Str::lower((string) Str::ulid()).'/'.Str::lower((string) Str::ulid());

        return DB::transaction(function () use (
            $conversation, $site, $file, $uploader, $sizeBytes, $mimeType, $checksum, $filename, $storageKey, $maxConversationBytes, $diskName, $authorizeWrite
        ): ConversationMessageAttachment {
            // Scanning deliberately finishes before this callback. Rejected
            // scans write security audit events and throw; wrapping them in the
            // storage transaction would roll those records back. Successful
            // scans reauthorize immediately before any binary or row persists.
            if ($authorizeWrite !== null) {
                [$uploader, $conversation] = $authorizeWrite($uploader, $conversation);
                $conversation->loadMissing('site');
                $site = $conversation->site;

                abort_unless($site, 404);
            }

            // Serialize concurrent uploads to this conversation so the cap check
            // and the insert are atomic — without the lock, two uploads could
            // both read the old total and both push it over the limit.
            Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->first();

            // Take a SHARED lock on the storage-disk setting and resolve the disk
            // under it. An operator changing the S3 location takes the EXCLUSIVE
            // lock (OperatorStorageSettingsController), so it cannot run between
            // this upload resolving its disk and committing its row — which would
            // otherwise strand the object in the old bucket. Shared locks let
            // uploads still run concurrently with each other.
            //
            // Ensure the lock-target row exists with insertOrIgnore — a WRITE with
            // no preceding SELECT. A plain SELECT (e.g. firstOrCreate) here would,
            // under MySQL/MariaDB REPEATABLE READ, freeze this transaction's read
            // snapshot BEFORE the lock, so the later refresh would still read the
            // pre-change values. With no consistent read before the lock, the
            // refresh below is the first one and sees the committed-latest state.
            OperatorSetting::query()->insertOrIgnore([
                'key' => 'storage.disk',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            OperatorSetting::query()->where('key', 'storage.disk')->sharedLock()->first();
            // Re-apply the committed storage settings from the DB under the lock:
            // this request's config was bootstrapped at boot and the shared lock
            // alone does not refresh it, so without this the disk could still
            // resolve a stale bucket after a concurrent location change.
            app(OperatorSettings::class)->refreshFromDatabase();
            $diskName = AttachmentStorage::diskName();

            $existingBytes = (int) ConversationMessageAttachment::query()
                ->where('conversation_id', $conversation->id)
                ->sum('size_bytes');

            if ($existingBytes + $sizeBytes > $maxConversationBytes) {
                throw AttachmentRejected::file('composer.rejected.conversation_full');
            }

            // The disk is configured with throw => false, so a failed write
            // returns false rather than throwing — surface it instead of
            // recording a row that points at a missing file.
            abort_if(
                Storage::disk($diskName)->putFileAs(dirname($storageKey), $file, basename($storageKey)) === false,
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
                'storage_disk' => $diskName,
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

    /**
     * Run the configured malware scanner (if any) synchronously. Clean files
     * pass through; an infected file is rejected and audited; an unreachable
     * scanner is rejected under the default fail-closed policy (logged and
     * audited so the operator sees it) or, if fail-open, accepted with a warning.
     */
    private function scan(UploadedFile $file, Conversation $conversation, Site $site, Model $uploader, string $filename, string $mimeType): void
    {
        if (! $this->scanner->isConfigured()) {
            // No scanner: accept with defense-in-depth (the standing allowlist,
            // private storage, forced-download, and nosniff protections).
            return;
        }

        $result = $this->scanner->scan($file->getRealPath());

        if ($result->isClean()) {
            return;
        }

        if ($result->isInfected()) {
            $this->recordScanAudit($conversation, $site, $uploader, 'attachment.quarantined', [
                'filename' => $filename,
                'mime_type' => $mimeType,
                'threat' => $result->threat,
            ]);

            throw AttachmentRejected::file('composer.rejected.infected');
        }

        // Unavailable: the scanner could not verify the file.
        Log::error('Attachment malware scan could not be completed.', [
            'conversation_id' => $conversation->id,
            'error' => $result->error,
        ]);

        if ((bool) config('wayfindr.attachments.scanner.fail_closed', true)) {
            $this->recordScanAudit($conversation, $site, $uploader, 'attachment.scan_unavailable', [
                'filename' => $filename,
                'error' => $result->error,
            ]);

            throw AttachmentRejected::file('composer.rejected.unscannable');
        }

        // Fail-open: accept the unscanned file, but leave a clear warning.
        Log::warning('Attachment accepted without a malware scan (scanner configured fail-open).', [
            'conversation_id' => $conversation->id,
            'filename' => $filename,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordScanAudit(Conversation $conversation, Site $site, Model $uploader, string $action, array $metadata): void
    {
        $conversation->auditEvents()->create([
            'account_id' => $site->account_id,
            'site_id' => $site->id,
            'actor_type' => $uploader->getMorphClass(),
            'actor_id' => $uploader->getKey(),
            'action' => $action,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
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
