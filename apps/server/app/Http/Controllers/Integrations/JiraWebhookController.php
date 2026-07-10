<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ExternalIssueProviderConnection;
use App\Support\ExternalIssues\InboundCommentSync;
use App\Support\ExternalIssues\InboundIssueStateSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JiraWebhookController extends Controller
{
    public function __construct(
        private readonly InboundIssueStateSync $sync,
        private readonly InboundCommentSync $commentSync,
    ) {}

    /**
     * Receive Jira issue and comment webhooks for a linked ticket. Jira Cloud
     * webhooks configured with a secret sign the delivery with an HMAC-SHA256
     * in the X-Hub-Signature header (sha256=…) over the raw body — the same
     * scheme as GitHub, one header name off — so authenticity is a
     * constant-time HMAC comparison against the per-connection webhook secret.
     * Issue state is reflected (never enforced); comments are mirrored as
     * internal notes, skipping comments Wayfindr itself relayed.
     */
    public function __invoke(Request $request, ExternalIssueProviderConnection $connection): JsonResponse
    {
        abort_unless($connection->provider === 'jira' && $connection->is_enabled, 404);

        $secret = data_get($connection->credentials, 'webhook_secret');

        if (! is_string($secret) || trim($secret) === '') {
            return response()->json(['message' => 'Webhook not configured.'], 403);
        }

        if (! $this->signatureIsValid($request, trim($secret))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = (string) $request->input('webhookEvent');

        if ($event === 'comment_created') {
            return $this->handleComment($request, $connection);
        }

        if (str_starts_with($event, 'jira:issue_')) {
            return $this->handleIssueState($request, $connection);
        }

        return response()->json(['message' => 'Ignored.'], 202);
    }

    private function handleIssueState(Request $request, ExternalIssueProviderConnection $connection): JsonResponse
    {
        $issue = $request->input('issue');
        $categoryKey = data_get($issue, 'fields.status.statusCategory.key');

        // Jira status categories: "new" and "indeterminate" are open work,
        // "done" is completed. Anything else is left unreflected.
        $state = match ($categoryKey) {
            'done' => 'closed',
            'new', 'indeterminate' => 'open',
            default => null,
        };

        if ($state === null) {
            return response()->json(['message' => 'Ignored.'], 202);
        }

        $externalId = data_get($issue, 'id');

        if (! is_scalar($externalId)) {
            return response()->json(['message' => 'Ignored.'], 202);
        }

        $link = $this->sync->linkFor($connection, (string) $externalId);

        if (! $link) {
            return response()->json(['message' => 'No linked ticket.'], 202);
        }

        $this->sync->reflect($link, $state, 'jira_webhook');

        return response()->json(['message' => 'Synced.'], 200);
    }

    private function handleComment(Request $request, ExternalIssueProviderConnection $connection): JsonResponse
    {
        $comment = $request->input('comment');
        $externalId = data_get($request->input('issue'), 'id');
        $commentId = data_get($comment, 'id');
        $body = $this->commentText(data_get($comment, 'body'));

        if (! is_scalar($externalId) || ! is_scalar($commentId) || $body === '') {
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
            (string) data_get($comment, 'author.displayName', ''),
            'jira_webhook',
        );

        return response()->json(['message' => $recorded ? 'Comment recorded.' : 'Ignored.'], $recorded ? 200 : 202);
    }

    /**
     * Jira comment bodies arrive as a plain string (Server/DC and most Cloud
     * deliveries) or, defensively, as an Atlassian Document Format object;
     * flatten ADF to its text so we store a readable note either way.
     */
    private function commentText(mixed $body): string
    {
        if (is_string($body)) {
            return trim($body);
        }

        if (is_array($body)) {
            return trim($this->flattenAdf($body));
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function flattenAdf(array $node): string
    {
        if (($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null)) {
            return $node['text'];
        }

        $parts = [];

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $parts[] = $this->flattenAdf($child);
            }
        }

        $joined = implode('', $parts);

        // Each paragraph becomes its own line.
        return ($node['type'] ?? null) === 'paragraph' ? $joined."\n" : $joined;
    }

    private function signatureIsValid(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('X-Hub-Signature', '');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
