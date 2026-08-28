<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Console\Commands\PrunePresenceVisitorsCommand;
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
            // An archived site reports nothing, whatever its settings still
            // say. The write path already refuses these -- but the ANSWER is
            // what the widget acts on, and a request that declines to record a
            // visitor while replying "keep reporting" leaves the tab sending
            // heartbeats and page addresses to a site taken out of service.
            //
            // It cannot correct itself later either: once the site is
            // archived the next heartbeat is a 404 from the resolver, and a
            // 404 carries no configuration, so this reply is the last
            // instruction that tab will ever get.
            ! $site->isArchived() && ($config['enabled'] ?? false) === true,
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
     * How many days a presence-only visitor is kept, as this install applies it.
     *
     * Clamped the same way the pruner clamps it, because the disclosure has to
     * name the number that will actually be used -- an operator shortening the
     * window to seven days had every visitor told thirty.
     */
    public static function retentionDays(): int
    {
        $configured = (int) config('wayfindr.presence.retention_days', PrunePresenceVisitorsCommand::MAXIMUM_DAYS);

        return max(1, min($configured, PrunePresenceVisitorsCommand::MAXIMUM_DAYS));
    }

    /**
     * @return array{reports: bool, every: int, page_urls: bool, retention_days: int}
     */
    public function toPayload(): array
    {
        return [
            'retention_days' => self::retentionDays(),
            'reports' => $this->enabled,
            'every' => self::HEARTBEAT_SECONDS,
            // The site's own policy, NOT ANDed with `reports`.
            //
            // Folding `enabled` in here looked tidy and was wrong in a way that
            // reached every install: a default site has presence off and page
            // addresses on, so this reported `page_urls: false`, and the widget
            // copies that into the setting it applies to bootstrap and
            // conversation start. Every site that has never touched presence
            // would have stopped storing page addresses entirely.
            //
            // `reports` already says whether to report. This says what a report
            // may contain, and it is a separate question.
            'page_urls' => $this->pageUrls,
        ];
    }
}
