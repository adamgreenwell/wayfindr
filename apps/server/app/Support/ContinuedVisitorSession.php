<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The session a presented token proves the holder already had.
 *
 * Both halves travel together on purpose. The session START exists so an
 * absolute cap has something to measure; the session ID exists so a conversation
 * can be bound to the session that opened it. They are carried forward on exactly
 * the same condition -- a token that decrypts, names this site and this visitor's
 * merge lineage, and matches the anonymous id -- and separating them into two
 * calls is how that one condition would become two that drift.
 *
 * A drift here is silent and expensive: carry the start and lose the id, and
 * every session recovering from an expired token is orphaned from its own
 * conversation.
 */
final readonly class ContinuedVisitorSession
{
    /**
     * The continued session's id, or null when the token named none.
     *
     * Null rather than '', because the one thing every caller does with this is
     * ask "is there an id to carry forward" -- and `??` answers that correctly
     * for null and wrongly for an empty string. A token minted before sessions
     * were identified decodes to no id at all, so that empty case is the
     * upgrade path, not an edge: read as an id it mints a successor naming no
     * session either, and the holder is then locked out of every conversation,
     * including ones they have not opened yet.
     */
    public ?string $sessionId;

    public function __construct(
        public CarbonImmutable $startedAt,
        ?string $sessionId,
    ) {
        $this->sessionId = ($sessionId === null || $sessionId === '') ? null : $sessionId;
    }
}
