<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Support\VisitorSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CobrowseConsentController extends Controller
{
    public function store(Request $request, string $supportCode, VisitorSessionToken $visitorSessionToken): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'granted' => ['required', 'boolean'],
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
            ->where('conversation_id', $conversation->id)
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->whereNull('ended_at')
            ->latest('id')
            ->first();

        abort_unless($cobrowseSession, 404, 'Cobrowse session not active.');

        if ($validated['granted']) {
            $cobrowseSession = $cobrowseSession->updateAtomically(function (CobrowseSession $session): void {
                $session->forceFill([
                    'status' => 'granted',
                    'consented_at' => now(),
                    'ended_at' => null,
                ]);
            });
        } else {
            $cobrowseSession = $cobrowseSession->updateAtomically(function (CobrowseSession $session): void {
                $metadata = $session->metadata ?? [];
                $metadata['ended_by_name'] = 'Visitor';
                $metadata['ended_by_type'] = 'visitor';

                $session->forceFill([
                    'status' => 'revoked',
                    'metadata' => $metadata,
                    'ended_at' => now(),
                ]);
            });
        }

        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                ],
                'cobrowse' => [
                    'status' => $cobrowseSession->status,
                    'consent' => $cobrowseSession->status === 'granted' ? 'granted' : 'revoked',
                    'consented_at' => $cobrowseSession->consented_at?->toJSON(),
                    'ended_at' => $cobrowseSession->ended_at?->toJSON(),
                ],
            ],
        ]);
    }
}
