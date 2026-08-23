<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which sites a report is allowed to count, and which one it was asked to.
 *
 * The rule this exists to enforce is that a user-supplied site id can only ever
 * narrow the report (ADR 0015). The allowlist is resolved from the agent's own
 * visibility first, and any requested site is checked against it before it
 * reaches a query -- so a hand-edited query string selects nothing rather than
 * something it should not see.
 *
 * Archived sites are included, matching the account audit page rather than the
 * conversation queue. Archiving takes a site out of service without destroying
 * anything, so a conversation closed last month on a site archived last week is
 * still work that happened. Excluding it would mean tidying up a site silently
 * rewrote the previous quarter's numbers. Purging is the operation that removes
 * history, and it is meant to.
 */
final class ReportingScope
{
    /**
     * @param  Collection<int, Site>  $sites
     * @param  list<int>  $siteIds
     */
    private function __construct(
        public readonly Account $account,
        public readonly Collection $sites,
        public readonly array $siteIds,
        public readonly ?int $requestedSiteId,
    ) {}

    public static function for(Account $account, User $agent, mixed $requestedSite = null): self
    {
        $sites = $account->sites()
            ->visibleToAgentIncludingArchived($agent)
            ->orderBy('name')
            ->orderBy('domain')
            ->get();

        /** @var list<int> $siteIds */
        $siteIds = $sites->pluck('id')->map(fn (int|string $id): int => (int) $id)->values()->all();

        return new self($account, $sites, $siteIds, self::validatedSiteId($requestedSite, $siteIds));
    }

    /**
     * The sites a report should actually count.
     *
     * Either the single requested site or the whole allowlist -- never anything
     * outside it.
     *
     * @return list<int>
     */
    public function countableSiteIds(): array
    {
        return $this->requestedSiteId === null ? $this->siteIds : [$this->requestedSiteId];
    }

    /**
     * True when the agent can see no sites at all.
     *
     * Worth asking explicitly: an empty allowlist must produce an empty report,
     * and a `whereIn` against an empty list does that, but only if nothing else
     * widens the query first.
     */
    public function isEmpty(): bool
    {
        return $this->countableSiteIds() === [];
    }

    /**
     * @param  list<int>  $siteIds
     */
    private static function validatedSiteId(mixed $requestedSite, array $siteIds): ?int
    {
        $requested = is_string($requestedSite) && ctype_digit($requestedSite)
            ? (int) $requestedSite
            : (is_int($requestedSite) ? $requestedSite : null);

        return $requested !== null && in_array($requested, $siteIds, true) ? $requested : null;
    }
}
