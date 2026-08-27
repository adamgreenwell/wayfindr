<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;

/**
 * Whether this site watches visitors who have not made contact.
 *
 * Off unless an operator turns it on (ADR 0019 §1). A default install keeps
 * exactly the privacy posture it has today, and upgrading changes nothing until
 * somebody decides otherwise -- which is the whole reason this is a switch
 * rather than a behaviour.
 *
 * There is deliberately NO per-site query-parameter allowlist, though
 * `VisitorPageUrl` can take one. Sanitising also happens in a model `saving`
 * hook, which runs without knowing which site a row belongs to and can only
 * apply the strict rule -- so an allowlist here would be stripped again on the
 * next save by any writer, and an operator would watch their configured
 * parameter vanish for no visible reason.
 *
 * A guarantee that no stored page address ever carries a query string is worth
 * more than the configurability, and it is the version that can be asserted in
 * one line. Wiring the allowlist up means giving the hook a site first.
 */
final class SitePresenceReporting
{
    /**
     * How often the widget reports, in seconds (ADR 0019 §3a).
     *
     * Comfortably inside `VisitorPresence::ACTIVE_MINUTES`, with room for one
     * lost report: at 45 seconds a continuously present visitor gets two
     * chances to land inside the two-minute cutoff. A cadence at or near the
     * cutoff makes them flicker between active and quiet for most of every
     * interval.
     */
    public const HEARTBEAT_SECONDS = 45;

    private function __construct(
        public readonly bool $enabled,
        public readonly bool $pageUrls,
    ) {}

    public static function for(Site $site): self
    {
        $config = is_array($site->settings['presence'] ?? null) ? $site->settings['presence'] : [];

        return new self(
            ($config['enabled'] ?? false) === true,
            // On unless switched off, because "which page" is most of the value
            // and a site with no secrets in its paths should not have to opt in
            // to the ordinary case.
            //
            // The switch exists because redaction is a heuristic and cannot be
            // made into a proof: there is no shape that separates a short
            // lowercase token from a short lowercase word. A site that puts
            // secrets in path segments has a real answer here rather than a
            // rule that is right most of the time.
            ($config['page_urls'] ?? true) === true,
        );
    }

    /**
     * @return array{reports: bool, every: int, page_urls: bool}
     */
    public function toPayload(): array
    {
        return [
            'reports' => $this->enabled,
            'every' => self::HEARTBEAT_SECONDS,
            // Told to the widget rather than only enforced here, so a page
            // address the operator has said not to keep is never put on the
            // wire in the first place. Enforced on arrival too: the endpoint is
            // public and the payload is a request, not a promise.
            'page_urls' => $this->enabled && $this->pageUrls,
        ];
    }
}
