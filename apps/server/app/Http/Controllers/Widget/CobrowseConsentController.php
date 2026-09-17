<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\CobrowseSession;
use App\Support\CobrowseAuditTrail;
use App\Support\VisitorConversationResolver;
use App\Support\Visitors\VisitorConversationWriteAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CobrowseConsentController extends Controller
{
    public function store(
        Request $request,
        string $supportCode,
        VisitorConversationResolver $conversations,
        VisitorConversationWriteAuthorization $conversationWrites,
        CobrowseAuditTrail $cobrowseAudit,
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

        [$conversation, $cobrowseSession, $previousStatus] = DB::transaction(function () use ($conversation, $conversationWrites, $validated, $cobrowseAudit): array {
            $conversation = $conversationWrites->lock($conversation, $validated['anonymous_id']);
            $cobrowseSession = $conversationWrites->lockCobrowseSession($conversation, grantedOnly: false);

            // Read under the same lock the write takes, so the recorded
            // `previous_status` is the state this answer actually changed and
            // not one a racing request has already moved on from.
            $previousStatus = (string) $cobrowseSession->status;

            if ($validated['granted']) {
                $cobrowseSession = $cobrowseSession->updateAtomically(function (CobrowseSession $session) use ($previousStatus): void {
                    $session->forceFill([
                        'status' => 'granted',
                        // Only on the transition. Consent happened once, and a
                        // duplicate or racing post is the same answer arriving
                        // twice -- moving the stamp would drift it away from
                        // the single audit row that records when it was given,
                        // leaving two disagreeing answers to the same question.
                        'consented_at' => $previousStatus === 'granted' ? $session->consented_at : now(),
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

            // A GRANT is audited inside the transaction: screen sharing must
            // not begin on a record that failed to write, so a failed insert
            // takes the grant with it.
            //
            // A REFUSAL is not, and putting it here was the mistake -- see
            // below. Both directions fail closed toward NOT sharing; only the
            // grant achieves that by rolling back.
            if ($validated['granted'] && $previousStatus !== $cobrowseSession->status) {
                $cobrowseAudit->consentAnswered(
                    $cobrowseSession,
                    $conversation->visitor,
                    $previousStatus,
                    true,
                );
            }

            return [$conversation, $cobrowseSession, $previousStatus];
        });

        // Declines and revocations are audited AFTER the commit, on purpose.
        // Rolling a refusal back because its audit row failed would leave the
        // session `granted` and the visitor's screen still being shared -- the
        // widget surfaces the error without stopping the mutation stream, so
        // the visitor would believe they had stopped it while it continued.
        //
        // An unrecorded stop is bad. A stop that did not happen is worse, and
        // the whole point of failing closed is to prefer the first.
        if (! $validated['granted'] && $previousStatus !== $cobrowseSession->status) {
            try {
                $cobrowseAudit->consentAnswered(
                    $cobrowseSession,
                    $conversation->visitor,
                    $previousStatus,
                    false,
                );
            } catch (Throwable $exception) {
                report($exception);
            }
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
