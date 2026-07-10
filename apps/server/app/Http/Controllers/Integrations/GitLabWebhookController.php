<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ExternalIssueProviderConnection;
use App\Support\ExternalIssues\InboundCommentSync;
use App\Support\ExternalIssues\InboundIssueStateSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitLabWebhookController extends Controller
{
    public function __construct(
        private readonly InboundIssueStateSync $sync,
        private readonly InboundCommentSync $commentSync,
    ) {}

    /**
     * Receive GitLab issue and note webhooks for a linked ticket. GitLab does
     * not HMAC-sign; it echoes the configured secret in the X-Gitlab-Token
     * header, so authenticity is a constant-time comparison against the
     * per-connection webhook secret. Issue state is reflected (never enforced);
     * issue notes (comments) are mirrored as internal notes, skipping comments
     * Wayfindr itself relayed.
     */
    public function __invoke(Request $request, ExternalIssueProviderConnection $connection): JsonResponse
    {
        abort_unless($connection->provider === 'gitlab' && $connection->is_enabled, 404);

        $secret = data_get($connection->credentials, 'webhook_secret');

        if (! is_string($secret) || trim($secret) === '') {
            return response()->json(['message' => 'Webhook not configured.'], 403);
        }

        $token = (string) $request->header('X-Gitlab-Token', '');

        if (! hash_equals(trim($secret), $token)) {
            return response()->json(['message' => 'Invalid token.'], 401);
        }

        return match ($request->input('object_kind')) {
            'issue' => $this->handleIssueState($request, $connection),
            'note' => $this->handleNote($request, $connection),
            default => response()->json(['message' => 'Ignored.'], 202),
        };
    }

    private function handleIssueState(Request $request, ExternalIssueProviderConnection $connection): JsonResponse
    {
        $attributes = $request->input('object_attributes');
        $state = match (data_get($attributes, 'action')) {
            'close' => 'closed',
            'reopen', 'open' => 'open',
            default => null,
        };

        if ($state === null) {
            return response()->json(['message' => 'Ignored.'], 202);
        }

        $externalId = data_get($attributes, 'id');

        if (! is_scalar($externalId)) {
            return response()->json(['message' => 'Ignored.'], 202);
        }

        $link = $this->sync->linkFor($connection, (string) $externalId);

        if (! $link) {
            return response()->json(['message' => 'No linked ticket.'], 202);
        }

        $this->sync->reflect($link, $state, 'gitlab_webhook');

        return response()->json(['message' => 'Synced.'], 200);
    }

    private function handleNote(Request $request, ExternalIssueProviderConnection $connection): JsonResponse
    {
        $attributes = $request->input('object_attributes');

        // Note hooks also fire for merge requests, commits, and snippets; only
        // issue notes become ticket notes.
        if (data_get($attributes, 'noteable_type') !== 'Issue') {
            return response()->json(['message' => 'Ignored.'], 202);
        }

        // Note hooks also fire on edits (action=update/destroy). Only new
        // comments become notes — otherwise editing a comment created before
        // the link existed (its id not in the ledger) would mirror as new.
        // A missing action means an older GitLab that only emits creations.
        $action = data_get($attributes, 'action');

        if ($action !== null && $action !== 'create') {
            return response()->json(['message' => 'Ignored.'], 202);
        }

        $externalId = data_get($request->input('issue'), 'id');
        $commentId = data_get($attributes, 'id');
        $body = data_get($attributes, 'note');

        if (! is_scalar($externalId) || ! is_scalar($commentId) || ! is_string($body)) {
            return response()->json(['message' => 'Ignored.'], 202);
        }

        $link = $this->sync->linkFor($connection, (string) $externalId);

        if (! $link) {
            return response()->json(['message' => 'No linked ticket.'], 202);
        }

        $recorded = $this->commentSync->record(
            $link,
            (string) $commentId,
            $body,
            (string) data_get($request->input('user'), 'username', ''),
            'gitlab_webhook',
        );

        return response()->json(['message' => $recorded ? 'Comment recorded.' : 'Ignored.'], $recorded ? 200 : 202);
    }
}
