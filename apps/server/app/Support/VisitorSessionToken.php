<?php

namespace App\Support;

use App\Models\Site;
use App\Models\Visitor;
use App\Support\Visitors\VisitorIdentityResolver;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use JsonException;

class VisitorSessionToken
{
    public function __construct(private readonly VisitorIdentityResolver $identities) {}

    public function issue(Site $site, Visitor $visitor, ?string $anonymousId = null): string
    {
        $anonymousId ??= (string) $visitor->anonymous_id;

        if ($anonymousId !== (string) $visitor->anonymous_id) {
            $alias = $this->identities->aliasForAnonymousId((int) $site->id, $anonymousId);
            $allowedVisitorIds = [
                (int) ($alias?->visitor_id ?? 0),
                ...array_map('intval', is_array($alias?->previous_visitor_ids) ? $alias->previous_visitor_ids : []),
            ];

            if (! $alias || ! in_array((int) $visitor->id, $allowedVisitorIds, true)) {
                throw new \LogicException('Visitor session alias does not belong to this visitor.');
            }
        }

        return Crypt::encryptString(json_encode([
            'site_id' => $site->id,
            'visitor_id' => $visitor->id,
            'anonymous_id' => $anonymousId,
            'issued_at' => now()->toJSON(),
        ], JSON_THROW_ON_ERROR));
    }

    public function visitorFromRequest(Request $request, Site $site, string $anonymousId): Visitor
    {
        $token = $this->tokenFromRequest($request);

        abort_if(! $token, 401, 'Visitor token is required.');

        $payload = $this->decode($token);

        abort_if((int) ($payload['site_id'] ?? 0) !== $site->id, 403, 'Visitor token does not match this site.');
        abort_if(! hash_equals((string) ($payload['anonymous_id'] ?? ''), $anonymousId), 403, 'Visitor token does not match this visitor.');

        $tokenVisitorId = (int) ($payload['visitor_id'] ?? 0);
        $visitor = Visitor::query()
            ->whereKey($tokenVisitorId)
            ->where('site_id', $site->id)
            ->where('anonymous_id', $anonymousId)
            ->first();

        if ($visitor instanceof Visitor) {
            return $visitor;
        }

        $alias = $this->identities->aliasForAnonymousId((int) $site->id, $anonymousId);
        $allowedVisitorIds = [
            (int) ($alias?->visitor_id ?? 0),
            ...array_map('intval', is_array($alias?->previous_visitor_ids) ? $alias->previous_visitor_ids : []),
        ];

        abort_unless($alias && in_array($tokenVisitorId, $allowedVisitorIds, true), 401, 'Visitor token is invalid.');

        $visitor = $alias->visitor;
        abort_unless($visitor instanceof Visitor, 401, 'Visitor token is invalid.');

        // The encrypted visitor id used to be the row itself. A deliberate
        // agent merge deletes that row, so aliases carry only the ids that
        // previously owned this browser identity. Keeping the lineage explicit
        // lets an old tab follow the merge without making its token valid for
        // an unrelated row that later reuses the anonymous id.

        return $visitor;
    }

    /**
     * @return array{site_id?: int, visitor_id?: int, anonymous_id?: string, issued_at?: string}
     */
    private function decode(string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            abort(401, 'Visitor token is invalid.');
        }

        abort_unless(is_array($payload), 401, 'Visitor token is invalid.');

        return $payload;
    }

    private function tokenFromRequest(Request $request): ?string
    {
        return $request->bearerToken()
            ?: $request->input('visitor_token')
            ?: $request->query('visitor_token');
    }
}
