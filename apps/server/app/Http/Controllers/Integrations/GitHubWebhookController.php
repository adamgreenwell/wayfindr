<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ExternalIssueProviderConnection;
use App\Support\ExternalIssues\InboundIssueStateSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    public function __construct(private readonly InboundIssueStateSync $sync) {}

    /**
     * Receive GitHub issue webhooks and reflect the external issue's state
     * onto the linked Wayfindr ticket. This is a public, unauthenticated
     * endpoint, so authenticity rests entirely on the per-connection webhook
     * secret: the delivery must carry a valid X-Hub-Signature-256 HMAC over
     * the raw body. The state is reflected (not enforced) — closing the GitHub
     * issue records the change on the link and the ticket timeline; it never
     * auto-closes the Wayfindr ticket, which stays the agent's decision.
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

        if ($request->header('X-GitHub-Event') !== 'issues') {
            // Acknowledge non-issue events (ping, etc.) without acting.
            return response()->json(['message' => 'Ignored.'], 202);
        }

        $action = (string) $request->input('action');
        $state = match ($action) {
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
