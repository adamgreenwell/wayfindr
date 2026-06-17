<?php

namespace App\Http\Controllers\Widget;

use App\Events\ConversationTypingUpdated;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Site;
use App\Support\VisitorSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationTypingController extends Controller
{
    public function __invoke(Request $request, string $supportCode, VisitorSessionToken $visitorSessionToken): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'is_typing' => ['required', 'boolean'],
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

        $visitor->update(['last_seen_at' => now()]);

        $metadata = $conversation->metadata ?? [];

        if ((bool) $validated['is_typing']) {
            $metadata['visitor_typing_at'] = now()->toJSON();
        } else {
            unset($metadata['visitor_typing_at']);
        }

        $conversation->forceFill(['metadata' => $metadata])->save();
        $conversation->refresh();

        event(new ConversationTypingUpdated($conversation));

        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                    'status' => $conversation->status,
                ],
                'typing' => $conversation->visitorTypingPayload(),
            ],
        ]);
    }
}
