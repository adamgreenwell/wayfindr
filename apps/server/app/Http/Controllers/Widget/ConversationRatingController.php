<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\ConversationRating;
use App\Support\VisitorConversationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A visitor saying how it went.
 *
 * Authorised the same way every other visitor write is -- through the resolver,
 * which checks the support code against this site and this visitor's token. A
 * rating endpoint that took only a support code would let anybody score
 * somebody else's conversation, and a support code appears in emails.
 */
class ConversationRatingController extends Controller
{
    public function __invoke(Request $request, string $supportCode, VisitorConversationResolver $conversations): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'score' => ['required', 'string', Rule::in(ConversationRating::SCORES)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $conversation = $conversations->resolve(
            $request,
            $supportCode,
            $validated['site_public_key'],
            $validated['anonymous_id'],
        );

        $comment = trim((string) ($validated['comment'] ?? ''));

        $rating = $conversation->ratings()->create([
            'site_id' => $conversation->site_id,
            'score' => $validated['score'],
            'comment' => $comment === '' ? null : $comment,
            'rated_at' => now(),
        ]);

        return response()->json([
            'data' => ['rating' => ['score' => $rating->score]],
        ], 201);
    }
}
