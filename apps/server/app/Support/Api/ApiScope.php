<?php

namespace App\Support\Api;

use App\Http\Middleware\AuthenticateApiToken;
use App\Models\ApiToken;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use LogicException;

/**
 * What one token is allowed to see, expressed as ids to filter by.
 *
 * The same shape `ReportingScope` uses, and for the same reason: isolation that
 * is applied as a `whereIn` cannot be forgotten by the next endpoint somebody
 * writes, whereas isolation that is checked after loading can be, and the
 * failure is silent when it is.
 *
 * A token restricted to no sites is restricted to its ACCOUNT's sites -- not to
 * nothing. Read the other way round it would be a footgun: the natural way to
 * create a token is without picking sites, and that must not silently produce a
 * credential that returns empty lists forever.
 */
final class ApiScope
{
    private function __construct(
        public readonly ApiToken $token,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $token = $request->attributes->get(AuthenticateApiToken::ATTRIBUTE);

        if (! $token instanceof ApiToken) {
            // Unreachable behind the middleware, and thrown rather than
            // defaulted to an empty scope: an endpoint that lost its
            // authentication should stop, not quietly serve nothing.
            throw new LogicException('ApiScope requires a request authenticated by an API token.');
        }

        return new self($token);
    }

    /**
     * The reachable sites as a SUBQUERY, for filtering with.
     *
     * Not an array of ids. Loading them and passing them to `whereIn` costs one
     * bind parameter per site, so an agency account with enough sites exceeds
     * the driver's parameter limit and every endpoint fails outright -- the
     * same unbounded-`whereIn` shape that had to be fixed in the ticket
     * reporting walk. A subquery is one parameter regardless, and saves the
     * round trip that loaded the ids.
     *
     * @return Builder<Site>
     */
    public function siteIdsQuery(): Builder
    {
        // Archived sites INCLUDED, matching `ReportingScope`. Archiving takes
        // a site out of service; it does not delete what happened on it, and a
        // read surface that dropped an archived site would make a year of
        // transcripts vanish from an integration the day somebody tidied up.
        // Purging is the operation that removes data, and it removes these rows
        // too.
        //
        // The write surface will need the opposite rule -- an archived site
        // stops accepting new work, exactly as the inbound mail router already
        // refuses it.
        $query = Site::query()
            ->select('sites.id')
            ->where('sites.account_id', $this->token->account_id);

        // Intersected in SQL, never trusted. A restriction row could name a
        // site that has since moved account or been purged, and the account is
        // the boundary that must hold regardless of what the pivot says.
        if ($this->isRestricted()) {
            $query->whereIn('sites.id', $this->token->sites()->select('sites.id'));
        }

        return $query;
    }

    /**
     * Whether one site is inside this token's reach.
     *
     * An `exists` rather than a lookup in a loaded list, for the same reason
     * the filter is a subquery.
     */
    public function includesSite(int $siteId): bool
    {
        return $this->siteIdsQuery()->whereKey($siteId)->exists();
    }

    /**
     * The reachable site ids as a list.
     *
     * Only for `/me`, where the list IS the answer somebody asked for. Every
     * other caller wants `siteIdsQuery()`.
     *
     * @return list<int>
     */
    public function siteIds(): array
    {
        return $this->siteIdsQuery()
            ->pluck('sites.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->values()
            ->all();
    }

    public function accountId(): int
    {
        return (int) $this->token->account_id;
    }

    /**
     * Whether this token was restricted to specific sites at all.
     *
     * Read from the token's own flag, never inferred from whether pivot rows
     * exist. A restricted token whose only site is purged has NO reach -- the
     * cascade removes the pivot row, and treating that as "unrestricted" would
     * hand it the whole account.
     *
     * A token with no restriction reaches its ACCOUNT's sites, not nothing:
     * the natural way to create one is without picking sites, and that must
     * not silently produce a credential returning empty lists forever.
     */
    private function isRestricted(): bool
    {
        return (bool) $this->token->restricts_sites;
    }
}
