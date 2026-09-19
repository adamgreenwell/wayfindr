<?php

namespace App\Support;

use App\Models\Site;
use App\Models\Visitor;
use App\Support\Visitors\VisitorIdentityResolver;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VisitorSessionToken
{
    public function __construct(private readonly VisitorIdentityResolver $identities) {}

    public function issue(
        Site $site,
        Visitor $visitor,
        ?string $anonymousId = null,
        ?DateTimeInterface $sessionStartedAt = null,
    ): string {
        $anonymousId ??= (string) $visitor->anonymous_id;

        if ($anonymousId !== (string) $visitor->anonymous_id
            && ! $this->isCurrentAnonymousId($site, $visitor, $anonymousId)) {
            $alias = $this->identities->aliasForAnonymousId((int) $site->id, $anonymousId);
            $allowedVisitorIds = [
                (int) ($alias?->visitor_id ?? 0),
                ...array_map('intval', is_array($alias?->previous_visitor_ids) ? $alias->previous_visitor_ids : []),
            ];

            if (! $alias || ! in_array((int) $visitor->id, $allowedVisitorIds, true)) {
                throw new \LogicException('Visitor session alias does not belong to this visitor.');
            }
        }

        return $this->encode($site, $visitor, $anonymousId, now(), $sessionStartedAt);
    }

    /**
     * When the session this request belongs to began, if it is continuing one.
     *
     * Bootstrap re-mints on every panel open, so without this an ordinary
     * reopen would restart the clock an absolute session cap is meant to
     * measure -- and a visitor could hold a session open indefinitely by
     * closing and reopening the widget.
     *
     * Deliberately TOLERANT, unlike `refresh()`. Bootstrap is reachable with
     * no token at all and must stay that way; a caller presenting nothing, or
     * a token for another site or visitor, is simply starting a new session
     * rather than being refused. The only thing a presented token buys is
     * continuity of a clock that is not in the caller's favour.
     */
    public function continuingSessionStartedAt(
        Request $request,
        Site $site,
        Visitor $visitor,
        string $anonymousId,
    ): ?CarbonImmutable {
        $token = $this->tokenFromRequest($request);

        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            $payload = $this->decode($token);
        } catch (HttpException) {
            return null;
        }

        if ((int) ($payload['site_id'] ?? 0) !== $site->id) {
            return null;
        }

        if (! hash_equals((string) ($payload['anonymous_id'] ?? ''), $anonymousId)) {
            return null;
        }

        // The anonymous id is not enough to prove the token belongs to THIS
        // visitor's session. Deleting a visitor frees their browser identity,
        // and the same string can later name a different person -- whose
        // genuinely new session would then inherit a start from a row that no
        // longer exists, and expire early once an absolute cap measures it.
        //
        // Lineage rather than equality, because a deliberate merge moves a
        // browser identity between rows on purpose and a token issued before
        // it still describes the same session.
        if (! $this->tokenBelongsToVisitor($payload, $site, $visitor, $anonymousId)) {
            return null;
        }

        return $this->sessionStartedAt($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function tokenBelongsToVisitor(array $payload, Site $site, Visitor $visitor, string $anonymousId): bool
    {
        $tokenVisitorId = (int) ($payload['visitor_id'] ?? 0);

        if ($tokenVisitorId === (int) $visitor->id) {
            return true;
        }

        $alias = $this->identities->aliasForAnonymousId((int) $site->id, $anonymousId);

        if (! $alias) {
            return false;
        }

        $lineage = [
            (int) ($alias->visitor_id ?? 0),
            ...array_map('intval', is_array($alias->previous_visitor_ids) ? $alias->previous_visitor_ids : []),
        ];

        return in_array($tokenVisitorId, $lineage, true)
            && in_array((int) $visitor->id, $lineage, true);
    }

    /**
     * Exchange a currently-valid token for a fresh one.
     *
     * The gap this fills: `issue()` is reachable only from widget bootstrap,
     * which asks for nothing but a site's public key and an anonymous id. So
     * there has never been a way to obtain a token that proves MORE than
     * bootstrap does, and therefore no way to shorten a token's life without
     * stranding every session that outlives it.
     *
     * This proves possession of a current token -- `visitorFromRequest()` is
     * the same check every conversation endpoint makes -- and hands back one
     * minted now. That is strictly more than bootstrap asks, which is what
     * makes it a safe thing to require before a TTL exists.
     *
     * `session_started_at` rides along unchanged so that a later absolute cap
     * has something to measure. Without it, rotation alone would let a token
     * live forever by refreshing just before each expiry.
     *
     * NOTE: this rotates, it does not revoke. Tokens are stateless encrypted
     * payloads with no server-side record, so the previous token stays valid
     * until something expires it. Rotation becomes a security property when
     * the TTL lands, not before.
     */
    public function refresh(Request $request, Site $site, string $anonymousId): string
    {
        $visitor = $this->visitorFromRequest($request, $site, $anonymousId);

        $sessionStartedAt = $this->sessionStartedAt($this->decode((string) $this->tokenFromRequest($request)));

        return $this->encode($site, $visitor, $anonymousId, now(), $sessionStartedAt);
    }

    /**
     * A stable name for the SESSION a token belongs to, across rotations.
     *
     * Exists to be a quota key, and the property that makes it usable as one is
     * narrow: refreshing carries `session_started_at` forward unchanged, so a
     * widget rotating its token keeps the same identity, while a caller who
     * bootstraps gets a fresh start and therefore a different one.
     *
     * That distinction is what the visitor id alone cannot make. Bootstrap
     * mints a working token for anyone presenting a site's public key and an
     * anonymous id -- both values Wayfindr publishes or displays -- so a budget
     * keyed on the visitor is spendable by a stranger who bootstraps once and
     * then refreshes legitimately. The session start is inside the encrypted
     * payload, so it cannot be named by someone who has only read the id.
     *
     * Hashed because it is a cache key, not a claim: nothing should be able to
     * read a visitor id or a session time back out of a rate limiter's store.
     */
    public function sessionIdentity(string $token): string
    {
        $payload = $this->decode($token);

        return hash('sha256', implode('|', [
            (string) ($payload['site_id'] ?? ''),
            (string) ($payload['visitor_id'] ?? ''),
            $this->sessionStartedAt($payload)->toJSON(),
        ]));
    }

    /**
     * When this SESSION began, as opposed to when this token was minted.
     *
     * Tokens issued before the field existed carry only `issued_at`; treating
     * that as the session start is the truthful reading -- it is the earliest
     * moment we can evidence.
     */
    public function sessionStartedAt(array $payload): CarbonImmutable
    {
        foreach (['session_started_at', 'issued_at'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && $value !== '') {
                try {
                    return CarbonImmutable::parse($value);
                } catch (InvalidFormatException) {
                    // Fall through: an unparseable stamp is not evidence of a
                    // start, and guessing one would be worse than saying now.
                }
            }
        }

        return CarbonImmutable::now();
    }

    /**
     * When this token stops being usable, or null if nothing expires.
     *
     * Advertised to the widget so it can refresh ahead of the moment rather
     * than discovering it as a failure -- and now also ENFORCED, by
     * `abortIfExpired()`, against the same `issued_at` this computes from. The
     * two must keep agreeing: the widget refreshes at the half-life of what
     * this returns, so a server refusing earlier than it advertises would
     * strand sessions that did exactly what they were told.
     */
    public function expiresAt(string $token): ?CarbonImmutable
    {
        $minutes = (int) config('wayfindr.visitor_session_ttl_minutes', 0);

        if ($minutes <= 0) {
            return null;
        }

        return $this->issuedAt($token)?->addMinutes($minutes);
    }

    /**
     * How much longer this token has, in seconds, or null if nothing expires.
     *
     * RELATIVE on purpose. An absolute instant is only meaningful against a
     * clock, and the clock that would read it is the visitor's browser -- which
     * can be wrong by any amount. A browser running ten minutes slow subtracts
     * its own `now` from a server-authored deadline and concludes it has
     * fifteen minutes left on a five-minute token, then schedules its refresh
     * for after the credential is already dead.
     *
     * A duration is skew-free: the widget adds it to its own clock, so both
     * ends of the arithmetic are the same clock and the error cancels.
     */
    public function expiresInSeconds(string $token): ?int
    {
        $expiresAt = $this->expiresAt($token);

        if ($expiresAt === null) {
            return null;
        }

        return max(0, (int) round(CarbonImmutable::now()->diffInSeconds($expiresAt, false)));
    }

    /**
     * When this token was minted. Written since the beginning and, until the
     * refresh path existed, never read by anything.
     */
    public function issuedAt(string $token): ?CarbonImmutable
    {
        $value = $this->decode($token)['issued_at'] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function encode(
        Site $site,
        Visitor $visitor,
        string $anonymousId,
        DateTimeInterface $issuedAt,
        ?DateTimeInterface $sessionStartedAt = null,
    ): string {
        return Crypt::encryptString(json_encode([
            'site_id' => $site->id,
            'visitor_id' => $visitor->id,
            'anonymous_id' => $anonymousId,
            'issued_at' => CarbonImmutable::instance($issuedAt)->toJSON(),
            'session_started_at' => CarbonImmutable::instance($sessionStartedAt ?? $issuedAt)->toJSON(),
        ], JSON_THROW_ON_ERROR));
    }

    private function isCurrentAnonymousId(Site $site, Visitor $visitor, string $anonymousId): bool
    {
        // MySQL and MariaDB use the configured column collation for identity
        // lookup. Let that same collation decide whether a differently-cased
        // request still names this current row before treating it as an alias.
        return Visitor::query()
            ->whereKey($visitor->id)
            ->where('site_id', $site->id)
            ->where('anonymous_id', $anonymousId)
            ->exists();
    }

    public function visitorFromRequest(Request $request, Site $site, string $anonymousId): Visitor
    {
        $token = $this->tokenFromRequest($request);

        abort_if(! $token, 401, 'Visitor token is required.');

        $payload = $this->decode($token);

        $this->abortIfExpired($payload);

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
     * Refuse a token past its lifetime, once an install has configured one.
     *
     * Measured from `issued_at`, which is exactly what `expiresAt()` already
     * advertises to the widget as `token_expires_in` -- so the server refuses
     * at the moment it told the widget to expect, and the widget has been
     * refreshing at the half-life to stay ahead of it.
     *
     * Deliberately NOT measured from `session_started_at`. That would be an
     * absolute cap, which is a good idea and a different change, because
     * `continuingSessionStartedAt()` carries a presented token's session start
     * forward with no age check of its own. A capped session would refuse, the
     * widget would bootstrap to recover, and the replacement token would be
     * born already expired -- forever, surviving a page reload, because the
     * widget presents the same stored token to bootstrap again. It would also
     * silence its own recovery: `sessionIdentity()` hashes the session start,
     * so every re-mint lands in one rate-limit bucket and the 429 that follows
     * reads to the widget as "server unavailable" rather than "token
     * rejected", at which point it stops trying. A cap needs
     * `continuingSessionStartedAt()` bounded first.
     *
     * 401 rather than 403, matching what this file already does: 403 here means
     * the token names a different site or visitor, and the widget treats 403 as
     * terminal. An expired token is the one refusal with a defined recovery.
     *
     * @param  array<string, mixed>  $payload
     */
    private function abortIfExpired(array $payload): void
    {
        $minutes = (int) config('wayfindr.visitor_session_ttl_minutes', 0);

        if ($minutes <= 0) {
            return;
        }

        $issuedAt = $this->issuedAtFromPayload($payload);

        // A token minted before this field existed carries no issue time. Treat
        // it as current rather than as infinitely old: expiring every one of
        // them the moment an operator sets the value would log out every open
        // session at once, which is the stranding this ordering exists to
        // avoid. They age out as soon as the widget next rotates.
        if ($issuedAt === null) {
            return;
        }

        abort_if(
            $issuedAt->addMinutes($minutes)->isPast(),
            401,
            'Visitor session has expired.',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function issuedAtFromPayload(array $payload): ?CarbonImmutable
    {
        $value = $payload['issued_at'] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * @return array{site_id?: int, visitor_id?: int, anonymous_id?: string, issued_at?: string, session_started_at?: string}
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
        // `input()` already reads the query string on a GET and the body on a
        // POST, so the explicit `query()` read that used to follow was dead.
        // Removing it changes no behaviour -- a query-supplied token is still
        // accepted, which the widgets already embedded in customers' pages
        // depend on. Refusing that transport is a later, separate step.
        return $request->bearerToken()
            ?: $request->input('visitor_token');
    }
}
