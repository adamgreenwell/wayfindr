<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ExternalIssueProviderConnection;
use App\Support\ExternalIssues\InboundCommentSync;
use App\Support\ExternalIssues\InboundIssueStateSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    public function __construct(
        private readonly InboundIssueStateSync $sync,
        private readonly InboundCommentSync $commentSync,
    ) {}

    /**
     * Receive GitHub issue and issue-comment webhooks for a linked ticket. This
     * is a public, unauthenticated endpoint, so authenticity rests entirely on
     * the per-connection webhook secret: the delivery must carry a valid
     * X-Hub-Signature-256 HMAC over the raw body. Issue state is reflected (not
     * enforced) — it never auto-closes the Wayfindr ticket; issue comments are
     * mirrored as internal notes, skipping comments Wayfindr itself relayed.
     */
    public function __invoke(Request $request, ExternalIssueProviderConnection $connection): JsonResponse
    {
        abort_unless($connection->provider === 'github' && $connection->is_enabled, 404);

        $secret = data_get($connection->credentials, 'webhook_secret');

        if (! is_string($secret) || trim($secret) === '') {
            // No secret configured means the connection cannot verify
            // authenticity; refuse rather than trust an unsigned delivery.
            return response()->json(['message' => 'Webhook not configured.'], 403);
        }

        if (! $this->signatureIsValid($request, trim($secret))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = (string) $request->header('X-GitHub-Event', '');
        $response = match ($event) {
            'issues' => $this->handleIssueState($request, $connection),
            'issue_comment' => $this->handleIssueComment($request, $connection),
            default => response()->json(['message' => 'Ignored.'], 202),
        };

        $connection->recordInboundWebhookDelivery($event, $response->getStatusCode());

        return $response;
    }

    private function handleIssueState(Request $request, ExternalIssueProviderConnection $connection): JsonResponse
    {
        $state = match ((string) $request->input('action')) {
            'closed' => 'closed',
            'reopened', 'opened' => 'open',
            default => null,
        };

        if ($state === null) {
            return response()->json(['message' => 'Ignored.'], 202);
        }

        $externalId = data_get($request->input('issue'), 'id');

        if (! is_scalar($externalId)) {
            return response()->json(['message' => 'Ignored.'], 202);
        }

        $link = $this->sync->linkFor($connection, (string) $externalId);

        if (! $link) {
            // A valid delivery for an issue Wayfindr does not track: accept
            // it so GitHub does not retry, but change nothing.
            return response()->json(['message' => 'No linked ticket.'], 202);
        }

        $this->sync->reflect($link, $state, 'github_webhook');

        return response()->json(['message' => 'Synced.'], 200);
    }

    private function handleIssueComment(Request $request, ExternalIssueProviderConnection $connection): JsonResponse
    {
        if ((string) $request->input('action') !== 'created') {
            // Only new comments become notes; edits/deletes are ignored.
            return response()->json(['message' => 'Ignored.'], 202);
        }

        $externalId = data_get($request->input('issue'), 'id');
        $comment = $request->input('comment');
        $commentId = data_get($comment, 'id');
        $body = data_get($comment, 'body');

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
            (string) data_get($comment, 'user.login', ''),
            'github_webhook',
        );

        // A skipped comment (our own relayed note, or a retry) is still a valid
        // delivery — acknowledge it so GitHub does not retry.
        return response()->json(['message' => $recorded ? 'Comment recorded.' : 'Ignored.'], $recorded ? 200 : 202);
    }

    private function signatureIsValid(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
