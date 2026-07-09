<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ExternalIssueProviderConnection;
use App\Support\ExternalIssues\InboundIssueStateSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitLabWebhookController extends Controller
{
    public function __construct(private readonly InboundIssueStateSync $sync) {}

    /**
     * Receive GitLab issue webhooks and reflect the external issue's state
     * onto the linked Wayfindr ticket. GitLab does not HMAC-sign; it echoes
     * the configured secret in the X-Gitlab-Token header, so authenticity is
     * a constant-time comparison against the per-connection webhook secret.
     * State is reflected, never enforced.
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

        if ($request->input('object_kind') !== 'issue') {
            return response()->json(['message' => 'Ignored.'], 202);
        }

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
}
