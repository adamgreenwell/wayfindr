<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\CobrowseSession;
use App\Support\VisitorConversationResolver;
use App\Support\Visitors\VisitorConversationWriteAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CobrowseConsentController extends Controller
{
    public function store(
        Request $request,
        string $supportCode,
        VisitorConversationResolver $conversations,
        VisitorConversationWriteAuthorization $conversationWrites,
    ): JsonResponse {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'granted' => ['required', 'boolean'],
        ]);

        $conversation = $conversations->resolve(
            $request,
            $supportCode,
            $validated['site_public_key'],
            $validated['anonymous_id'],
        );

        [$conversation, $cobrowseSession] = DB::transaction(function () use ($conversation, $conversationWrites, $validated): array {
            $conversation = $conversationWrites->lock($conversation, $validated['anonymous_id']);
            $cobrowseSession = $conversationWrites->lockCobrowseSession($conversation, grantedOnly: false);

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

            return [$conversation, $cobrowseSession];
        });

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
