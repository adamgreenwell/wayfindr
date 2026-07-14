<?php

namespace App\Support\Attachments;

use App\Models\ConversationMessageAttachment;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an authorized attachment back to the caller with the hardened
 * response headers ADR 0007 requires. Authorization and scoping happen before
 * this is ever reached — by the time an attachment gets here, the caller has
 * already proven access to its conversation.
 */
class AttachmentResponder
{
    public function stream(ConversationMessageAttachment $attachment): StreamedResponse
    {
        $disk = $attachment->disk();

        // The row can outlive its binary (a failed/swept object). Treat a
        // missing file as absent rather than 500-ing.
        abort_unless($disk->exists($attachment->storage_key), 404);

        // download() forces `Content-Disposition: attachment` so the browser can
        // never render the file inline as active content. We additionally pin
        // the SERVER-detected content type (never the client's), send nosniff so
        // the browser cannot second-guess it, and forbid shared caching of a
        // private file.
        return $disk->download(
            $attachment->storage_key,
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }
}
