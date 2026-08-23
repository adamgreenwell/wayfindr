<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationRating;
use App\Support\Conversations\ConversationLifecycleLog;
use App\Support\VisitorConversationResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * **One answer per close.** A visitor changing their mind replaces what they
 * said; a visitor -- or a script holding their token -- posting the same score
 * two hundred times does not move the aggregate two hundred times. Bounding
 * this matters more than it looks: CSAT response rates are low, so the
 * denominator is small, and a small denominator is cheap to swamp.
 *
 * A genuine REOPEN starts a new episode and earns a new row. The second answer
 * is then a second data point rather than a correction: the same conversation
 * going well and later badly is exactly the signal worth keeping.
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
        $episodeStart = $this->currentEpisodeStart($conversation);

        $existing = $conversation->ratings()
            ->when($episodeStart !== null, fn (Builder $query) => $query->where('rated_at', '>=', $episodeStart))
            ->latest('rated_at')
            ->first();

        $attributes = [
            'site_id' => $conversation->site_id,
            'score' => $validated['score'],
            'comment' => $comment === '' ? null : $comment,
            'rated_at' => now(),
        ];

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();

            return response()->json(['data' => ['rating' => ['score' => $existing->score]]]);
        }

        $rating = $conversation->ratings()->create($attributes);

        return response()->json([
            'data' => ['rating' => ['score' => $rating->score]],
        ], 201);
    }

    /**
     * When the stretch of work being rated was closed.
     *
     * Null when nothing closed it, which is the widget's own race: an agent can
     * reopen between the prompt appearing and the visitor answering it. The
     * answer is kept rather than refused -- losing real feedback to a timing
     * accident is worse than attributing it to the wrong episode.
     */
    private function currentEpisodeStart(Conversation $conversation): ?CarbonImmutable
    {
        $closedAt = AuditEvent::query()
            ->where('subject_type', $conversation->getMorphClass())
            ->where('subject_id', $conversation->id)
            ->where('action', ConversationLifecycleLog::CLOSED)
            ->max('occurred_at');

        $closedAt ??= $conversation->closed_at;

        return $closedAt === null ? null : CarbonImmutable::parse($closedAt);
    }
}
