<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\Site;
use App\Models\Visitor;
use App\Support\Sites\SitePresenceReporting;
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
     * The rows and the total that describes them, together.
     *
     * They used to be two calls, and a visitor committing between them landed
     * in the count and not in the table. Worse than a heading that is out by
     * one: during a resync the socket event for that visitor is buffered, so
     * the browser applies this count and THEN replays them as an arrival,
     * adding them a second time on a board that shows everybody.
     *
     * Below the cap the rows are the population, so the count is exact and the
     * second query is not needed -- which removes the window rather than
     * narrowing it. At the cap it is unavoidable: the rows are a window onto a
     * larger set and only the server can say how large. There the two can
     * disagree by one for an instant, and the browser does not adjust a capped
     * total from what it can see, so the next resync settles it.
     *
     * @return array{visitors: Collection<int, array<string, mixed>>, total: int}
     */
    public static function snapshotFor(Site $site, bool $showConversationCounts = true): array
    {
        $visitors = self::for($site, $showConversationCounts);

        return [
            'visitors' => $visitors,
            'total' => $visitors->count() < self::DISPLAY_LIMIT
                ? $visitors->count()
                : self::countFor($site),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function for(Site $site, bool $showConversationCounts = true): Collection
    {
        // Read as of NOW, not as of route binding. The site handed in is the
        // model the request resolved on its way in, and another operator
        // revoking page addresses between that and this query leaves it
        // describing a policy that has already been replaced -- while the
        // sweep is still walking the rows, so the addresses are still there to
        // be rendered.
        //
        // The broadcast closed the same window by re-reading; this is the
        // other half of it, on the page. A site that has gone withholds.
        $current = Site::query()->whereKey($site->getKey())->first();

        $showPageUrls = $current !== null && SitePresenceReporting::for($current)->pageUrls;

        return self::present($site)
            ->when($showConversationCounts, fn (Builder $query) => $query->withCount('conversations'))
            ->orderByDesc('last_web_seen_at')
            ->orderByDesc('id')
            // Capped so one page stays readable and one query stays bounded.
            // The count beside it is not capped, so a busier site reads as
            // "247 here" above the 200 most recent rather than as 200.
            ->limit(self::DISPLAY_LIMIT)
            ->get()
            ->map(static fn (Visitor $visitor): array => self::row($visitor, $showPageUrls, $showConversationCounts))
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
    /**
     * @param  bool  $showPageUrls  whether the site's policy still allows an
     *                              address to be SHOWN, which is a different
     *                              question from whether one is stored
     * @param  bool  $showConversationCount  whether the reader may see the
     *                                       support history total
     */
    public static function row(
        Visitor $visitor,
        bool $showPageUrls = true,
        bool $showConversationCount = true,
    ): array {
        return [
            'id' => $visitor->id,
            'name' => $visitor->name,
            'email' => $visitor->email,
            'state' => VisitorPresence::stateFor($visitor->last_web_seen_at),
            // Answered from the policy in force, not from what happens to be
            // stored. Revoking addresses commits the setting and THEN sweeps
            // the rows -- the safe order, since the sweep would otherwise hold
            // a row lock per visitor across the whole table while an operator
            // waited on a form post. Between the commit and the sweep reaching
            // a row, that row still holds an address, and a sweep that fails or
            // is interrupted leaves it there indefinitely.
            //
            // So the cleanup is what removes the data and this is what stops
            // showing it, and the second does not wait on the first.
            'page_url' => $showPageUrls && is_array($visitor->metadata)
                ? ($visitor->metadata['last_page_url'] ?? null)
                : null,
            'last_web_seen_at' => $visitor->last_web_seen_at?->toJSON(),
            'visit_started_at' => $visitor->current_visit_started_at?->toJSON(),
            // Whether this is somebody the desk has never heard from. It is the
            // difference between "a stranger is reading your pricing page" and
            // "a customer you know is back", and an agent chooses differently
            // on each.
            'made_contact' => ! $visitor->presence_only,
            // Counted rather than defaulted. The board's own query loads this
            // with withCount(); a private snapshot may fall back to a count,
            // while the shared broadcast deliberately omits the field.
            ...($showConversationCount ? [
                'conversations_count' => (int) ($visitor->conversations_count ?? $visitor->conversations()->count()),
            ] : []),
            'profile_url' => $visitor->presence_only ? null : route('dashboard.visitors.show', $visitor->id),
        ];
    }
}
