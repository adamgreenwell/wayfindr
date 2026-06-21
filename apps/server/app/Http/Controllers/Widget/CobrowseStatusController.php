<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Support\CobrowseConsentState;
use App\Support\CobrowseResyncRequestPolicy;
use App\Support\VisitorSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CobrowseStatusController extends Controller
{
    public function __construct(
        private readonly CobrowseResyncRequestPolicy $resyncRequestPolicy,
        private readonly CobrowseConsentState $cobrowseConsentState,
    ) {}

    public function __invoke(Request $request, string $supportCode, VisitorSessionToken $visitorSessionToken): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
        ]);

        $site = Site::query()
            ->where('public_key', $validated['site_public_key'])
            ->first();

        abort_unless($site, 404, 'Site not found.');

        $visitor = $visitorSessionToken->visitorFromRequest($request, $site, $validated['anonymous_id']);

        $conversation = Conversation::query()
            ->where('support_code', $supportCode)
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->first();

        abort_unless($conversation, 404, 'Conversation not found.');

        $cobrowseSession = CobrowseSession::query()
            ->with('requestedBy')
            ->where('conversation_id', $conversation->id)
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->latest('id')
            ->first();

        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                ],
                'cobrowse' => $this->cobrowsePayload($cobrowseSession, $conversation),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function cobrowsePayload(?CobrowseSession $cobrowseSession, Conversation $conversation): array
    {
        if (! $cobrowseSession) {
            return [
                'status' => 'unavailable',
                'consent' => 'unavailable',
                'requested_by' => null,
                'requested_at' => null,
                'consented_at' => null,
                'ended_at' => null,
                'visitor_notice' => null,
                'resync' => $this->resyncPayload(null),
            ];
        }

        $status = $cobrowseSession->status;

        return [
            'status' => $status,
            'consent' => match ($status) {
                'requested', 'granted', 'revoked', 'ended' => $status,
                default => 'unavailable',
            },
            'requested_by' => $cobrowseSession->requestedBy ? [
                'name' => $cobrowseSession->requestedBy->name,
            ] : null,
            'requested_at' => $cobrowseSession->created_at?->toJSON(),
            'consented_at' => $cobrowseSession->consented_at?->toJSON(),
            'ended_at' => $cobrowseSession->ended_at?->toJSON(),
            'visitor_notice' => $this->visitorNotice($cobrowseSession, $conversation),
            'resync' => $this->resyncPayload($cobrowseSession),
        ];
    }

    /**
     * @return array{state: string, message: string}|null
     */
    private function visitorNotice(CobrowseSession $cobrowseSession, Conversation $conversation): ?array
    {
        if ($cobrowseSession->status !== 'granted' || $cobrowseSession->ended_at) {
            return null;
        }

        $conversation->setRelation('latestCobrowseSession', $cobrowseSession);

        $transport = $this->cobrowseConsentState->queueTransportForConversation($conversation);
        $state = (string) ($transport['state'] ?? 'unavailable');
        $message = match ($state) {
            'degraded' => 'Cobrowse is catching up with recent page changes. Sensitive fields stay masked.',
            'reconnecting' => 'Cobrowse is reconnecting and may briefly lag. Sensitive fields stay masked.',
            'stale' => 'Cobrowse is waiting for fresh page updates. Sensitive fields stay masked.',
            'unavailable' => 'Cobrowse is getting ready. Sensitive fields stay masked.',
            default => null,
        };

        if (! $message) {
            return null;
        }

        return [
            'state' => $state,
            'message' => $message,
        ];
    }

    /**
     * @return array{requested: bool, request_id: string|null, requested_at: string|null, requested_by: array{name: string}|null}
     */
    private function resyncPayload(?CobrowseSession $cobrowseSession): array
    {
        $request = $cobrowseSession?->metadata['resync_request'] ?? null;

        if (
            ! $cobrowseSession
            || $cobrowseSession->status !== 'granted'
            || $cobrowseSession->ended_at
            || ! is_array($request)
            || ! filled($request['id'] ?? null)
            || filled($request['fulfilled_at'] ?? null)
            || $this->resyncRequestPolicy->isAttemptExhausted($request)
            || $this->resyncRequestPolicy->isExpired($request)
        ) {
            return [
                'requested' => false,
                'request_id' => null,
                'requested_at' => null,
                'requested_by' => null,
            ];
        }

        return [
            'requested' => true,
            'request_id' => (string) $request['id'],
            'requested_at' => filled($request['requested_at'] ?? null) ? (string) $request['requested_at'] : null,
            'requested_by' => [
                'name' => filled($request['requested_by_name'] ?? null) ? (string) $request['requested_by_name'] : 'Support',
            ],
        ];
    }
}
