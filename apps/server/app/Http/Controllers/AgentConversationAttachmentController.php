<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ConversationMessageAttachment;
use App\Support\Attachments\AttachmentResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Agent-side attachment download (ADR 0007).
 *
 * Authorization is the existing conversation `view` policy — the agent must
 * support the site that owns the conversation (Site::supportsAgent, honoring
 * explicit support-agent assignments) and not be deactivated. An agent outside
 * that site's support scope, or in another account, never gets past the gate
 * (404), and the attachment is then resolved *within* the authorized
 * conversation, so a leaked id from another conversation still resolves to
 * nothing.
 */
class AgentConversationAttachmentController extends Controller
{
    public function show(
        Request $request,
        string $supportCode,
        int $attachment,
        AttachmentResponder $responder,
    ): StreamedResponse {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);

        $conversation = Conversation::query()
            ->where('support_code', $supportCode)
            ->firstOrFail();

        // 404 (not 403) on an unsupported/foreign conversation so its existence
        // stays opaque — the same posture as every other agent conversation
        // action.
        abort_unless(Gate::forUser($agent)->allows('view', $conversation), 404);

        $conversation->loadMissing('site');

        $record = ConversationMessageAttachment::query()
            ->forConversation($conversation)
            ->whereKey($attachment)
            ->first();

        abort_unless($record && $record->isDownloadable(), 404);

        return $responder->stream($record);
    }
}
