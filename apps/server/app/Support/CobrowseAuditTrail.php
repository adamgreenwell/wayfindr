<?php

namespace App\Support;

use App\Models\CobrowseSession;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CobrowseAuditTrail
{
    /**
     * One preview-view audit event per agent + session within this window is
     * enough to answer "who watched, when" without flooding audit_events from
     * the live auto-refresh loop.
     */
    private const PREVIEW_VIEW_THROTTLE_MINUTES = 15;

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $previousRequest
     */
    public function resyncRequested(CobrowseSession $session, User $actor, array $request, ?array $previousRequest): void
    {
        $this->record($session, $actor, 'cobrowse.resync_requested', [
            'support_code' => $this->supportCode($session),
            'request_id' => $this->stringOrNull($request['id'] ?? null),
            'requested_at' => $this->stringOrNull($request['requested_at'] ?? null),
            'previous_request_id' => $this->stringOrNull($previousRequest['id'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $transition
     */
    public function resyncFulfilled(CobrowseSession $session, Visitor $actor, array $transition): void
    {
        $this->record($session, $actor, 'cobrowse.resync_fulfilled', [
            'support_code' => $this->supportCode($session),
            'request_id' => $this->stringOrNull($transition['request_id'] ?? null),
            'fulfilled_at' => $this->stringOrNull($transition['fulfilled_at'] ?? null),
            'snapshot_reported_at' => $this->stringOrNull($transition['snapshot_reported_at'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $transition
     */
    public function resyncIgnored(CobrowseSession $session, Visitor $actor, array $transition): void
    {
        $this->record($session, $actor, 'cobrowse.resync_ignored', [
            'support_code' => $this->supportCode($session),
            'active_request_id' => $this->stringOrNull($transition['active_request_id'] ?? null),
            'response_request_id' => $this->stringOrNull($transition['response_request_id'] ?? null),
            'reason' => $this->stringOrNull($transition['reason'] ?? null),
            'ignored_at' => $this->stringOrNull($transition['ignored_at'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $transition
     */
    public function resyncExhausted(CobrowseSession $session, Visitor $actor, array $transition): void
    {
        $this->record($session, $actor, 'cobrowse.resync_exhausted', [
            'support_code' => $this->supportCode($session),
            'request_id' => $this->stringOrNull($transition['request_id'] ?? null),
            'attempts_exhausted_at' => $this->stringOrNull($transition['attempts_exhausted_at'] ?? null),
            'dropped_batches' => $this->intOrNull($transition['dropped_batches'] ?? null),
            'reconnects' => $this->intOrNull($transition['reconnects'] ?? null),
        ]);
    }

    /**
     * Record a snapshot keyframe's provenance: when it was captured, how much
     * was masked, and — crucially — which masking ruleset was actually in
     * force at capture time, so masking is provable later even after the
     * site's rules change. Metadata is provenance only, never snapshot content.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array{selectors: array<int, string>, terms: array<int, string>}|null  $reportedRuleset
     * @param  array{selectors: array<int, string>, terms: array<int, string>}  $siteRuleset
     */
    public function snapshotReceived(CobrowseSession $session, Visitor $actor, array $snapshot, ?array $reportedRuleset, array $siteRuleset): void
    {
        $this->record($session, $actor, 'cobrowse.snapshot_received', [
            'support_code' => $this->supportCode($session),
            'reported_at' => $this->stringOrNull($snapshot['reported_at'] ?? null),
            'page_url' => $this->stringOrNull($snapshot['page_url'] ?? null),
            'node_count' => $this->intOrNull($snapshot['node_count'] ?? null),
            'masked_count' => $this->intOrNull($snapshot['masked_count'] ?? null),
            'html_length' => $this->intOrNull($snapshot['html_length'] ?? null),
            'mutation_sequence' => $this->intOrNull($snapshot['mutation_sequence'] ?? null),
            'resync_request_id' => $this->stringOrNull($snapshot['resync_request_id'] ?? null),
            'masking_ruleset' => $this->maskingRulesetProvenance($reportedRuleset, $siteRuleset),
        ]);
    }

    /**
     * The widget masks with the ruleset it cached at bootstrap, which can
     * differ from the site's current settings if an admin edits them
     * mid-session — so provenance records what the widget reports it applied
     * (source: widget_reported), falling back to the receipt-time site
     * settings only for widgets that predate ruleset reporting. The hash pins
     * the full untruncated ruleset; matches_site_settings makes a mid-session
     * rules change visible in the trail. Rulesets are public widget
     * configuration (never secrets); the stock widget's built-in defaults are
     * code-versioned, proxied here by the release version.
     *
     * @param  array{selectors: array<int, string>, terms: array<int, string>}|null  $reportedRuleset
     * @param  array{selectors: array<int, string>, terms: array<int, string>}  $siteRuleset
     * @return array<string, mixed>
     */
    private function maskingRulesetProvenance(?array $reportedRuleset, array $siteRuleset): array
    {
        $applied = $reportedRuleset ?? $siteRuleset;
        $appliedHash = $this->rulesetHash($applied);
        $selectors = $this->boundedRulesetList($applied['selectors']);
        $terms = $this->boundedRulesetList($applied['terms']);

        return [
            'source' => $reportedRuleset !== null ? 'widget_reported' : 'site_settings_at_receipt',
            'hash' => $appliedHash,
            'mask_selectors' => $selectors['items'],
            'mask_selector_count' => count($applied['selectors']),
            'sensitive_terms' => $terms['items'],
            'sensitive_term_count' => count($applied['terms']),
            'truncated' => $selectors['truncated'] || $terms['truncated'],
            'matches_site_settings' => $appliedHash === $this->rulesetHash($siteRuleset),
            'release' => $this->stringOrNull(config('wayfindr.release.version')),
        ];
    }

    /**
     * @param  array{selectors: array<int, string>, terms: array<int, string>}  $ruleset
     */
    private function rulesetHash(array $ruleset): string
    {
        return hash('sha256', (string) json_encode([$ruleset['selectors'], $ruleset['terms']]));
    }

    /**
     * @param  array<int, string>  $items
     * @return array{items: array<int, string>, truncated: bool}
     */
    private function boundedRulesetList(array $items): array
    {
        $clean = array_values(array_filter($items, 'is_string'));
        $kept = array_slice($clean, 0, 50);
        $truncated = count($clean) > 50;

        foreach ($kept as $item) {
            if (mb_strlen($item) > 120) {
                $truncated = true;

                break;
            }
        }

        return [
            'items' => array_map(static fn (string $item): string => mb_substr($item, 0, 120), $kept),
            'truncated' => $truncated,
        ];
    }

    /**
     * Record that an agent actually viewed a rendered replay preview, so "who
     * watched the visitor's screen, and when" is auditable. Throttled per
     * agent + session so the live auto-refresh loop cannot flood the audit log;
     * one event per window still answers the audit question. Metadata is
     * provenance only — never preview content.
     *
     * @param  array<string, mixed>  $preview
     */
    public function previewViewed(CobrowseSession $session, User $agent, string $trigger, array $preview): void
    {
        if ($this->recentlyRecordedPreviewView($session, $agent)) {
            return;
        }

        $this->record($session, $agent, 'cobrowse.preview_viewed', [
            'support_code' => $this->supportCode($session),
            'trigger' => $trigger,
            'snapshot_reported_at' => $this->stringOrNull(data_get($session->metadata, 'snapshot.reported_at')),
            'applied_mutations' => $this->stringOrNull($preview['applied_mutations'] ?? null),
            'skipped_mutations' => $this->stringOrNull($preview['skipped_mutations'] ?? null),
            'drift_state' => $this->stringOrNull(data_get($preview, 'drift.state')),
        ]);
    }

    private function recentlyRecordedPreviewView(CobrowseSession $session, User $agent): bool
    {
        $recorded = $session->auditEvents()
            ->where('action', 'cobrowse.preview_viewed')
            ->where('actor_type', $agent::class)
            ->where('actor_id', $agent->getKey())
            ->where('occurred_at', '>=', now()->subMinutes(self::PREVIEW_VIEW_THROTTLE_MINUTES))
            ->exists();

        if ($recorded) {
            return true;
        }

        // Concurrent requests (multiple tabs, a reload racing the live refresh
        // loop) can all pass the exists() check before any row commits. Claim
        // the throttle window atomically — Cache::add is add-if-absent on every
        // store — so only one racer records. The database check above remains
        // the durable source of truth if the cache entry is evicted early.
        $claimKey = sprintf(
            'cobrowse:preview-viewed:%s:%s',
            $session->getKey(),
            $agent->getKey(),
        );

        return ! Cache::add($claimKey, true, now()->addMinutes(self::PREVIEW_VIEW_THROTTLE_MINUTES));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(CobrowseSession $session, ?Model $actor, string $action, array $metadata): void
    {
        $session->loadMissing(['conversation', 'site']);

        $session->auditEvents()->create([
            'account_id' => $session->site?->account_id,
            'site_id' => $session->site_id,
            'actor_type' => $actor ? $actor::class : null,
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function supportCode(CobrowseSession $session): ?string
    {
        $session->loadMissing('conversation');

        return $session->conversation?->support_code;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
