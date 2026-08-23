<?php

namespace App\Support\Api;

use App\Http\Middleware\AuthenticateApiToken;
use App\Models\ApiToken;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    /**
     * @param  list<int>  $siteIds
     */
    private function __construct(
        public readonly ApiToken $token,
        private readonly array $siteIds,
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

        return new self($token, self::resolveSiteIds($token));
    }

    /**
     * The sites this token may read, already intersected with its account.
     *
     * @return list<int>
     */
    public function siteIds(): array
    {
        return $this->siteIds;
    }

    public function accountId(): int
    {
        return (int) $this->token->account_id;
    }

    /**
     * True when the token can reach nothing -- an account with no sites, or a
     * restriction naming only sites that have since been purged.
     */
    public function isEmpty(): bool
    {
        return $this->siteIds === [];
    }

    /**
     * @return list<int>
     */
    private static function resolveSiteIds(ApiToken $token): array
    {
        /** @var Collection<int, int> $accountSiteIds */
        $accountSiteIds = Site::query()
            ->where('account_id', $token->account_id)
            ->pluck('id');

        $restrictedTo = $token->sites()->pluck('sites.id');

        if ($restrictedTo->isEmpty()) {
            return $accountSiteIds->map(fn (int|string $id): int => (int) $id)->values()->all();
        }

        // Intersected, never trusted. A restriction row could name a site that
        // has since moved account or been purged, and the account is the
        // boundary that must hold regardless.
        return $accountSiteIds
            ->intersect($restrictedTo)
            ->map(fn (int|string $id): int => (int) $id)
            ->values()
            ->all();
    }
}
