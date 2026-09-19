<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessageAttachment;
use App\Models\Visitor;
use App\Support\Attachments\AttachmentDownloadLink;
use App\Support\Attachments\AttachmentRejected;
use App\Support\Attachments\AttachmentResponder;
use App\Support\Attachments\AttachmentUploadService;
use App\Support\Sites\WidgetLanguage;
use App\Support\VisitorConversationResolver;
use App\Support\Visitors\VisitorConversationWriteAuthorization;
use App\Support\WidgetSiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Visitor-side attachment upload + download (ADR 0007).
 *
 * The visitor proves access to the conversation exactly as they do for
 * messages — signed token matched to site + anonymous id, conversation matched
 * to that visitor — and the attachment is then resolved *within* that
 * conversation. A visitor can only ever fetch (or upload to) their own
 * conversation; an id from any other session or visitor resolves to nothing and
 * returns 404.
 */
class ConversationAttachmentController extends Controller
{
    public function store(
        Request $request,
        string $supportCode,
        VisitorConversationResolver $conversations,
        AttachmentUploadService $uploads,
        VisitorConversationWriteAuthorization $conversationWrites,
    ): JsonResponse {
        // Identity FIRST, so the site is known before anything can fail in
        // words. Validating the file up here rejected an oversized upload with
        // a framework message in the INSTALL's language, before the site --
        // and with it the visitor's language -- had been resolved at all.
        $identity = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
        ]);

        $conversation = $conversations->resolve(
            $request,
            $supportCode,
            $identity['site_public_key'],
            $identity['anonymous_id'],
        );

        // From here every failure is words a VISITOR reads, so the request
        // speaks the site's language for the rest of its life -- framework
        // validation included, which no catch block can reach.
        //
        // Safe to move: `DashboardLanguage` reads the install default from its
        // own config key precisely because `App::setLocale()` moves
        // `app.locale`.
        App::setLocale(WidgetLanguage::forVisitor($request->input('locale'), $conversation->site));

        $maxKilobytes = (int) ceil(((int) config('wayfindr.attachments.max_file_bytes')) / 1024);

        $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKilobytes],
        ]);

        // The uploader is the conversation's own visitor — the same principal
        // the resolver just authenticated.
        try {
            $attachment = $uploads->store(
                $conversation,
                $request->file('file'),
                $conversation->visitor,
                function (Visitor $visitor, Conversation $conversation) use ($conversationWrites, $identity): array {
                    $conversation = $conversationWrites->lock($conversation, $identity['anonymous_id']);

                    return [$conversation->visitor, $conversation];
                },
            );
        } catch (AttachmentRejected $rejected) {
            // Keyed, not just worded: this reader's language is the widget's,
            // and on an unpinned site the server does not know it.
            return $rejected->toWidgetResponse();
        }

        return response()->json([
            'data' => ['attachment' => $attachment->toPayload()],
        ], 201);
    }

    /**
     * Stream an attachment from a signed, short-lived link.
     *
     * The signature proves this URL was minted by this install and not altered.
     * It does NOT prove who is holding it -- a new-tab navigation and an
     * `<img>` fetch carry no header and no first-party cookie, so the caller
     * cannot be identified at all. Everything below is therefore still
     * enforced: the signature decides that the link is real, and the same
     * ownership checks `show()` makes decide that the file may be served.
     */
    public function download(
        Request $request,
        string $supportCode,
        int $attachment,
        AttachmentResponder $responder,
    ): StreamedResponse {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'v' => ['required', 'string', 'max:255'],
        ]);

        $site = WidgetSiteResolver::resolveOrFail($validated['site_public_key']);

        $record = DB::transaction(function () use ($site, $supportCode, $attachment, $validated): ConversationMessageAttachment {
            $conversation = Conversation::query()
                ->where('site_id', $site->id)
                ->where('support_code', $supportCode)
                ->lockForUpdate()
                ->first();

            abort_unless($conversation !== null, 404);

            $visitor = $conversation->visitor;

            abort_unless($visitor !== null, 404);

            // Under the same lock a merge takes, so a link minted for a visitor
            // who has since been merged away stops working rather than
            // resolving to whoever holds the conversation now.
            abort_unless(
                hash_equals(
                    AttachmentDownloadLink::visitorBinding($site, $visitor),
                    (string) $validated['v'],
                ),
                404,
            );

            $record = ConversationMessageAttachment::query()
                ->forConversation($conversation)
                ->whereKey($attachment)
                ->first();

            abort_unless($record && $record->isDownloadableBy($visitor), 404);

            return $record;
        });

        return $responder->stream($record);
    }

    public function show(
        Request $request,
        string $supportCode,
        int $attachment,
        VisitorConversationResolver $conversations,
        AttachmentResponder $responder,
        VisitorConversationWriteAuthorization $conversationWrites,
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

        $record = DB::transaction(function () use ($conversation, $conversationWrites, $validated, $attachment): ConversationMessageAttachment {
            $conversation = $conversationWrites->lock($conversation, $validated['anonymous_id']);
            $record = ConversationMessageAttachment::query()
                ->forConversation($conversation)
                ->whereKey($attachment)
                ->first();

            // Re-authorize under the merge lock before checking ownership: a
            // pending upload moves to the canonical visitor during a merge.
            abort_unless($record && $record->isDownloadableBy($conversation->visitor), 404);

            return $record;
        });

        return $responder->stream($record);
    }

    /**
     * Delete a not-yet-sent upload, e.g. when the visitor removes its chip
     * before sending. Only an unbound attachment this visitor uploaded can be
     * deleted; a bound attachment is part of the transcript. Freeing it here
     * reclaims the per-conversation quota immediately rather than waiting for
     * the retention sweep.
     */
    public function destroy(
        Request $request,
        string $supportCode,
        int $attachment,
        VisitorConversationResolver $conversations,
        VisitorConversationWriteAuthorization $conversationWrites,
    ): Response {
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

        // Lock the row and re-assert unbound under the lock so a concurrent send
        // that binds this attachment cannot race the delete — the binder locks
        // the same row, so the delete either wins (still unbound) or 404s (a send
        // bound it first) rather than deleting a just-sent attachment.
        DB::transaction(function () use ($conversation, $conversationWrites, $validated, $attachment): void {
            $conversation = $conversationWrites->lock($conversation, $validated['anonymous_id']);
            $visitor = $conversation->visitor;
            abort_unless($visitor instanceof Visitor, 404);

            $record = ConversationMessageAttachment::query()
                ->forConversation($conversation)
                ->whereKey($attachment)
                ->whereNull('conversation_message_id')
                ->where('uploaded_by_type', $visitor->getMorphClass())
                ->where('uploaded_by_id', $visitor->getKey())
                ->lockForUpdate()
                ->first();

            abort_unless($record, 404);

            // The deleting hook removes the binary with the row.
            $record->delete();
        });

        return response()->noContent();
    }
}
