<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\Visitor;
use App\Models\VisitorIdentityAlias;

/** Resolve both a contact's current browser ID and IDs retained by a merge. */
final class VisitorIdentityResolver
{
    public function forAnonymousId(int $siteId, string $anonymousId): ?Visitor
    {
        $visitor = Visitor::query()
            ->where('site_id', $siteId)
            ->where('anonymous_id', $anonymousId)
            ->first();

        if ($visitor instanceof Visitor) {
            return $visitor;
        }

        return $this->aliasForAnonymousId($siteId, $anonymousId)
            ?->visitor;
    }

    public function aliasForAnonymousId(int $siteId, string $anonymousId): ?VisitorIdentityAlias
    {
        return VisitorIdentityAlias::query()
            ->where('site_id', $siteId)
            ->where('anonymous_id', $anonymousId)
            ->whereHas('visitor', fn ($query) => $query->where('site_id', $siteId))
            ->with('visitor')
            ->first();
    }
}
