<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ExternalIssueProviderConnection;
use App\Support\ExternalIssues\InboundIssueStateSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JiraWebhookController extends Controller
{
    public function __construct(private readonly InboundIssueStateSync $sync) {}

    /**
     * Receive Jira issue webhooks and reflect the external issue's state onto
     * the linked Wayfindr ticket. Jira plain webhooks carry no standard
     * signature, so Wayfindr defines a convention: the webhook must send the
     * connection's secret in the X-Wayfindr-Webhook-Token header, compared
     * constant-time. Jira has no "closed" action — an issue is done when its
     * status moves into the "done" status category — so the state is read
     * from fields.status.statusCategory.key. Reflected, never enforced.
     */
    public function __invoke(Request $request, ExternalIssueProviderConnection $connection): JsonResponse
    {
        abort_unless($connection->provider === 'jira' && $connection->is_enabled, 404);

        $secret = data_get($connection->credentials, 'webhook_secret');

        if (! is_string($secret) || trim($secret) === '') {
            return response()->json(['message' => 'Webhook not configured.'], 403);
        }

        $token = (string) $request->header('X-Wayfindr-Webhook-Token', '');

        if (! hash_equals(trim($secret), $token)) {
            return response()->json(['message' => 'Invalid token.'], 401);
        }

        $event = (string) $request->input('webhookEvent');

        if (! str_starts_with($event, 'jira:issue_')) {
            return response()->json(['message' => 'Ignored.'], 202);
        }

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
}
