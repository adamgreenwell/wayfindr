<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationRating;
use App\Models\User;
use App\Support\Sites\RatingTemplate;
use App\Support\Sites\SiteRatingPrompt;
use App\Support\VisitorConversationResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A visitor saying how it went.
 *
 * Authorised the same way every other visitor write is -- through the resolver,
 * which checks the support code against this site and this visitor's token. A
 * rating endpoint that took only a support code would let anybody score
 * somebody else's conversation, and a support code appears in emails.
 *
 * **One answer per close**, enforced by a unique index rather than by looking
 * first. A visitor changing their mind replaces what they said; a visitor -- or
 * a script holding their token -- posting the same score two hundred times does
 * not move the aggregate two hundred times. Bounding this matters more than it
 * looks: CSAT response rates are low, so the denominator is small, and a small
 * denominator is cheap to swamp.
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
            // Which close the visitor was looking at when they answered.
            'episode' => ['required', 'string', 'max:64'],
            // The template the visitor was SHOWN (#1156). Absent from a widget
            // that predates templates, which only ever asked the single
            // overall question.
            'template' => ['nullable', 'string', Rule::in(RatingTemplate::keys())],
            'template_version' => ['nullable', 'integer', 'min:1', 'max:65535'],
            // The handle of the agent the question named, and the answer to it.
            // A null score with a handle is "skipped", which is absent data.
            'agent' => ['nullable', 'string', 'max:64'],
            // The name the question showed, verified against the handle.
            'agent_name' => ['nullable', 'string', 'max:255'],
            'agent_score' => ['nullable', 'string', Rule::in(RatingTemplate::AGENT_SCORES)],
        ]);

        $conversation = $conversations->resolve(
            $request,
            $supportCode,
            $validated['site_public_key'],
            $validated['anonymous_id'],
        );

        // A rating nobody asked for is not feedback. The widget shows the
        // prompt only where an operator switched it on and only once a
        // conversation has closed, but this endpoint is reachable without the
        // widget -- and an operator who deliberately turned collection off must
        // not find scores in their reports regardless.
        if (! SiteRatingPrompt::for($conversation->site)->enabled) {
            throw ValidationException::withMessages([
                'score' => 'This site is not asking how conversations went.',
            ]);
        }

        $episode = $conversation->currentCloseEpisode();

        if ($episode === null) {
            throw ValidationException::withMessages([
                'score' => 'This conversation is still open, so there is nothing to rate yet.',
            ]);
        }

        // The answer is bound to the close it was SHOWN for. Without this the
        // server silently picks the latest close, so a conversation reopened
        // and closed again between the widget's last refresh and this request
        // has the old score and comment attributed to the new close -- and that
        // new prompt marked answered, so nobody is ever asked about it.
        if (! hash_equals((string) $conversation->currentCloseEpisodeToken(), $validated['episode'])) {
            throw ValidationException::withMessages([
                'episode' => 'This conversation has changed since the question was asked.',
            ]);
        }

        // Stored against the template the visitor was shown, not the one the
        // site is set to now: a settings change while the prompt was open must
        // not reinterpret the answer.
        $template = RatingTemplate::find(
            $validated['template'] ?? RatingTemplate::OVERALL,
            (int) ($validated['template_version'] ?? 1),
        );

        if ($template === null) {
            throw ValidationException::withMessages([
                'template' => 'That is not a question this site has asked.',
            ]);
        }

        [$agentId, $agentName, $agentScore, $ratedAgentId] = $this->agentAnswer($conversation, $episode, $template, $validated);

        $comment = trim((string) ($validated['comment'] ?? ''));
        $existed = $conversation->ratings()->where('episode_event_id', $episode->id)->exists();

        // Upserted against the unique index on (conversation_id,
        // episode_event_id). Reading first and then creating loses the race:
        // two concurrent requests both see no row, both insert, and the bound
        // that keeps a small denominator from being swamped quietly stops
        // holding.
        //
        // Keyed on the close EVENT, not its timestamp: two closes inside one
        // second are two episodes, and only the row id distinguishes them.
        ConversationRating::query()->upsert(
            [[
                'conversation_id' => $conversation->id,
                'site_id' => $conversation->site_id,
                'episode_event_id' => $episode->id,
                'episode_closed_at' => CarbonImmutable::parse($episode->occurred_at)->toDateTimeString(),
                'score' => $validated['score'],
                'comment' => $comment === '' ? null : $comment,
                'template_key' => $template->key,
                'template_version' => $template->version,
                'agent_user_id' => $agentId,
                'agent_name' => $agentName,
                'rated_agent_id' => $ratedAgentId,
                'agent_score' => $agentScore,
                'rated_at' => now()->toDateTimeString(),
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]],
            ['conversation_id', 'episode_event_id'],
            [
                'score', 'comment', 'template_key', 'template_version',
                'agent_user_id', 'agent_name', 'rated_agent_id', 'agent_score', 'rated_at', 'updated_at',
            ],
        );

        return response()->json([
            'data' => ['rating' => ['score' => $validated['score']]],
        ], $existed ? 200 : 201);
    }

    /**
     * The agent snapshot for this answer: [user id, name, score].
     *
     * Only where the template asks about the agent AND the widget names the
     * agent it showed. The server recomputes who qualifies for this close and
     * refuses a handle naming anyone else, so a score attaches to the person
     * the visitor saw -- never to whoever is assigned, or clicked Close.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: int|null, 1: string|null, 2: string|null, 3: int|null} [live account id, shown name, score, stable agent id]
     */
    private function agentAnswer(Conversation $conversation, AuditEvent $episode, RatingTemplate $template, array $validated): array
    {
        $handle = $validated['agent'] ?? null;
        $score = $validated['agent_score'] ?? null;

        if ($handle === null) {
            if ($score !== null) {
                throw ValidationException::withMessages([
                    'agent_score' => 'This question did not name anyone to rate.',
                ]);
            }

            return [null, null, null, null];
        }

        $agentId = $template->asksAboutAgent() ? $conversation->ratingEpisodeAgentId($episode) : null;
        $shownName = $validated['agent_name'] ?? null;

        if ($agentId === null
            || $shownName === null
            || ! hash_equals(Conversation::ratingAgentToken($episode, $agentId, $shownName), $handle)) {
            throw ValidationException::withMessages([
                'agent' => 'That is not the person who helped with this conversation.',
            ]);
        }

        // The name the visitor saw, not whatever the account is called now: a
        // rename between the prompt and the answer must not attach the score
        // to a name the visitor never read. Verified by the handle, so it is
        // the name the server issued. An account deleted since keeps the
        // answer too -- the id clears, the name stays, as for a later deletion.
        $agentStillExists = User::query()->whereKey($agentId)->exists();

        return [$agentStillExists ? $agentId : null, $shownName, $score, $agentId];
    }
}
