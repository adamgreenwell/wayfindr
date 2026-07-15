<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ConversationMessageAttachment;
use App\Models\User;
use App\Support\Attachments\AttachmentResponder;
use App\Support\Attachments\AttachmentUploadService;
use Illuminate\Http\JsonResponse;
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
    public function store(
        Request $request,
        string $supportCode,
        AttachmentUploadService $uploads,
    ): JsonResponse {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);

        $conversation = Conversation::query()
            ->where('support_code', $supportCode)
            ->firstOrFail();

        // Attaching a file is part of replying, so it takes the `reply` ability
        // (which is the same site-support scope as `view`).
        abort_unless(Gate::forUser($agent)->allows('reply', $conversation), 404);

        $conversation->loadMissing('site');

        $maxKilobytes = (int) ceil(((int) config('wayfindr.attachments.max_file_bytes')) / 1024);

        $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKilobytes],
        ]);

        $attachment = $uploads->store($conversation, $request->file('file'), $agent);

        return response()->json([
            'data' => ['attachment' => $attachment->toPayload()],
        ], 201);
    }

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

        abort_unless($record && $record->isDownloadableBy($agent), 404);

        // Build the streamed response first: stream() aborts 404 if the stored
        // object is gone, so we only audit an access that can actually be served.
        $response = $responder->stream($record);

        $this->recordAgentAccess($conversation, $record, $agent);

        return $response;
    }

    /**
     * Record an accountability trail of an agent retrieving a visitor's file,
     * deduped per agent+attachment so an inline preview that re-loads on every
     * page view (downloads are no-store) does not flood the audit log — the
     * first access by an agent is what matters.
     */
    private function recordAgentAccess(Conversation $conversation, ConversationMessageAttachment $attachment, User $agent): void
    {
        $alreadyRecorded = $conversation->auditEvents()
            ->where('action', 'attachment.downloaded')
            ->where('actor_type', $agent->getMorphClass())
            ->where('actor_id', $agent->getKey())
            ->where('metadata->attachment_id', $attachment->id)
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        $conversation->auditEvents()->create([
            'account_id' => $conversation->site?->account_id,
            'site_id' => $conversation->site_id,
            'actor_type' => $agent->getMorphClass(),
            'actor_id' => $agent->getKey(),
            'action' => 'attachment.downloaded',
            'metadata' => [
                'attachment_id' => $attachment->id,
                'filename' => $attachment->original_filename,
            ],
            'occurred_at' => now(),
        ]);
    }
}
