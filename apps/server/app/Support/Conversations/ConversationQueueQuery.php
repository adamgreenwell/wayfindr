<?php

declare(strict_types=1);

namespace App\Support\Conversations;

use App\Models\Conversation;
use App\Models\User;
use App\Support\LiteralLike;
use App\Support\Visitors\VisitorPresence;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of "which conversations are in this queue, in what order".
 *
 * It was written twice before this: inline in the queue's index() and again in
 * the lane-count helper, in the same controller. A third copy was about to be
 * written for the breadcrumb switcher, which needs the SAME list to say what
 * the next conversation is -- and a switcher that disagrees with the queue it
 * came from is worse than no switcher.
 *
 * That duplication has a track record here: ADR 0013 records "a second
 * implementation of the same decision, in another language, running a version
 * behind" as a recurring root cause of the upgrade guard's review findings.
 */
final class ConversationQueueQuery
{
    /** @var list<string> */
    public const LANES = [
        'new_activity',
        'needs_reply',
        'assigned_to_me',
        'unassigned',
        'cobrowse_attention',
        'closed',
    ];

    /**
     * Every conversation the agent may see, before lane or presence narrowing.
     */
    public static function visibleTo(User $agent, string $status = 'open', ?int $siteId = null, string $search = ''): Builder
    {
        return Conversation::query()
            ->where('status', $status)
            ->whereHas('site', fn (Builder $query) => $query->visibleToAgent($agent))
            ->when($siteId, fn (Builder $query) => $query->where('site_id', $siteId))
            ->when($search !== '', fn (Builder $query) => self::applySearch($query, $search));
    }

    public static function applySearch(Builder $query, string $search): Builder
    {
        $pattern = self::searchPattern($search);

        return $query->where(function (Builder $query) use ($pattern): void {
            self::whereLiteralLike($query, 'subject', $pattern);
            self::whereLiteralLike($query, 'support_code', $pattern, 'or');
            $query->orWhereHas('visitor', function (Builder $query) use ($pattern): void {
                self::whereLiteralLike($query, 'anonymous_id', $pattern);
                self::whereLiteralLike($query, 'external_id', $pattern, 'or');
                self::whereLiteralLike($query, 'name', $pattern, 'or');
                self::whereLiteralLike($query, 'email', $pattern, 'or');
            });
        });
    }

    public static function applyLane(Builder $query, string $lane, User $agent): Builder
    {
        return $query
            ->when($lane === 'new_activity', fn (Builder $query) => $query->withNewActivityFor($agent))
            ->when($lane === 'needs_reply', function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->whereDoesntHave('messages')
                        ->orWhereHas('latestMessage', fn (Builder $query) => $query->where('sender_type', '!=', User::class));
                });
            })
            ->when($lane === 'assigned_to_me', fn (Builder $query) => $query->where('assigned_agent_id', $agent->id))
            ->when($lane === 'unassigned', fn (Builder $query) => $query->whereNull('assigned_agent_id'))
            ->when($lane === 'cobrowse_attention', fn (Builder $query) => $query->withActiveCobrowseSession());
    }

    public static function applyPresence(Builder $query, string $presence): Builder
    {
        // The cutoffs live in VisitorPresence. They were written here and in
        // Visitor::presenceState() independently, which is exactly the second
        // implementation this class exists to prevent.
        return $query->whereHas('visitor', function (Builder $query) use ($presence): void {
            VisitorPresence::constrain($query, $presence, 'last_web_seen_at');
        });
    }

    /**
     * The queue's order. The switcher must walk conversations in exactly this
     * sequence or "next" means something different depending on where you ask.
     */
    public static function ordered(Builder $query): Builder
    {
        return $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at');
    }

    public static function searchPattern(string $search): string
    {
        return LiteralLike::pattern($search);
    }

    /**
     * Kept as a thin forward so the call sites below read as they always have.
     * The rule itself moved to App\Support\LiteralLike when a second caller
     * (knowledge articles) needed it.
     */
    private static function whereLiteralLike(Builder $query, string $column, string $pattern, string $boolean = 'and'): void
    {
        LiteralLike::where($query, $column, $pattern, $boolean);
    }
}
