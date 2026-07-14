<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\ConversationMessageAttachment;
use App\Support\Attachments\AttachmentResponder;
use App\Support\VisitorConversationResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Visitor-side attachment download (ADR 0007).
 *
 * The visitor proves access to the conversation exactly as they do for
 * messages — signed token matched to site + anonymous id, conversation matched
 * to that visitor — and the attachment is then resolved *within* that
 * conversation. A visitor can only ever fetch an attachment from their own
 * conversation; an id from any other session or visitor resolves to nothing and
 * returns 404.
 */
class ConversationAttachmentController extends Controller
{
    public function show(
        Request $request,
        string $supportCode,
        int $attachment,
        VisitorConversationResolver $conversations,
        AttachmentResponder $responder,
    ): StreamedResponse {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
        ]);

        $conversation = $conversations->resolve(
            $request,
            $supportCode,
            $validated['site_public_key'],
            $validated['anonymous_id'],
        );

        $record = ConversationMessageAttachment::query()
            ->forConversation($conversation)
            ->whereKey($attachment)
            ->first();

        abort_unless($record && $record->isDownloadable(), 404);

        return $responder->stream($record);
    }
}
