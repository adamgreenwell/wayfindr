<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who is on this site right now (ADR 0019, #747).
 *
 * The question the visitor directory cannot answer. That list is ordered by
 * `last_seen_at`, which is every channel at once -- somebody who emailed an
 * hour ago outranks somebody reading a pricing page this second -- and it
 * describes people rather than a moment.
 *
 * This reads `last_web_seen_at` only. An email is contact and belongs in the
 * directory; it is not somebody being on the website, and an agent who acts on
 * it goes looking for a browser that is not there.
 */
final class LiveVisitorBoard
{
    /**
     * Nobody quieter than this is "here".
     *
     * The same fifteen minutes that already means `recent` everywhere else,
     * rather than a third cutoff invented for this surface. A board with its
     * own idea of present would disagree with the queue filter and the visitor
     * profile about the same person at the same moment.
     */
    public const PRESENT_MINUTES = VisitorPresence::RECENT_MINUTES;

    /**
     * How many rows the board renders at once.
     *
     * A page nobody can read is not a board. The COUNT is deliberately not
     * bounded by this -- see countFor().
     */
    public const DISPLAY_LIMIT = 200;

    /**
     * How many visitors are on the site, without the display cap.
     *
     * `for()` stops at 200 rows because a board nobody can read is not a
     * board; the COUNT has no such excuse, and reporting "200" to a site with
     * four hundred people on it is the one number an agent would have trusted.
     */
    public static function countFor(Site $site): int
    {
        return self::present($site)->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function for(Site $site): Collection
    {
        return self::present($site)
            ->withCount('conversations')
            ->orderByDesc('last_web_seen_at')
            ->orderByDesc('id')
            // Capped so one page stays readable and one query stays bounded.
            // The count beside it is not capped, so a busier site reads as
            // "247 here" above the 200 most recent rather than as 200.
            ->limit(self::DISPLAY_LIMIT)
            ->get()
            ->map(static fn (Visitor $visitor): array => self::row($visitor))
            ->values();
    }

    /**
     * Everyone this site counts as present, before ordering or capping.
     */
    private static function present(Site $site): Builder
    {
        return Visitor::query()
            ->where('site_id', $site->id)
            ->whereNotNull('last_web_seen_at')
            ->where('last_web_seen_at', '>=', now()->subMinutes(self::PRESENT_MINUTES))
            // A tester visitor is an agent looking at their own site, and the
            // directory already refuses them for the reason that matters more
            // here: on a board, an agent watches themselves browse.
            ->where(function ($query): void {
                $query->whereNull('anonymous_id')
                    ->orWhere('anonymous_id', 'not like', 'tester-site-%');
            });
    }

    /**
     * One visitor, as the board describes them.
     *
     * Keys rather than sentences, and the same rule the conversation presence
     * payload already follows: this is broadcast to every agent watching and
     * they do not all read the same language, so state travels and words are
     * chosen at the surface.
     *
     * @return array<string, mixed>
     */
    public static function row(Visitor $visitor): array
    {
        return [
            'id' => $visitor->id,
            'name' => $visitor->name,
            'email' => $visitor->email,
            'state' => VisitorPresence::stateFor($visitor->last_web_seen_at),
            'page_url' => is_array($visitor->metadata) ? ($visitor->metadata['last_page_url'] ?? null) : null,
            'last_web_seen_at' => $visitor->last_web_seen_at?->toJSON(),
            'visit_started_at' => $visitor->current_visit_started_at?->toJSON(),
            // Whether this is somebody the desk has never heard from. It is the
            // difference between "a stranger is reading your pricing page" and
            // "a customer you know is back", and an agent chooses differently
            // on each.
            'made_contact' => ! $visitor->presence_only,
            // Counted rather than defaulted. The board's own query loads this
            // with withCount(); a broadcast carries one visitor resolved
            // somewhere else, and letting it fall back to zero meant the first
            // realtime update silently rewrote "3 conversations" as "0".
            'conversations_count' => (int) ($visitor->conversations_count ?? $visitor->conversations()->count()),
            'profile_url' => $visitor->presence_only ? null : route('dashboard.visitors.show', $visitor->id),
        ];
    }
}
