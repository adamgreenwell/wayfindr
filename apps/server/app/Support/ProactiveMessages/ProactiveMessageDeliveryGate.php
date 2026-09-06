<?php

declare(strict_types=1);

namespace App\Support\ProactiveMessages;

use App\Models\ProactiveMessageDelivery;
use App\Models\ProactiveMessageRule;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\Sites\SiteAvailability;
use App\Support\Sites\SiteManagerCoverage;
use App\Support\Sites\SitePresenceReporting;
use App\Support\Sites\SiteSupportAvailability;
use App\Support\Visitors\VisitorIdentityResolver;
use App\Support\Visitors\VisitorPresence;
use Illuminate\Support\Facades\DB;

/**
 * The server-side half of proactive targeting.
 *
 * The browser knows whether a page/referrer/visit-count condition matched. It
 * never sends those observed values here. This gate owns every fact the server
 * can authoritatively re-check, and serializes claims through the visitor row
 * so two tabs cannot both win the same frequency window.
 */
final readonly class ProactiveMessageDeliveryGate
{
    public function __construct(
        private SiteManagerCoverage $siteManagerCoverage,
        private SiteSupportAvailability $supportAvailability,
        private VisitorIdentityResolver $visitorIdentities,
    ) {}

    public function claim(
        Site $site,
        string $rulePublicId,
        string $anonymousId,
        string $claimKey,
    ): ?ProactiveMessageDelivery {
        return DB::transaction(function () use ($anonymousId, $claimKey, $rulePublicId, $site): ?ProactiveMessageDelivery {
            $this->siteManagerCoverage->shareAccount((int) $site->account_id);
            $site = Site::query()
                ->servable()
                ->whereKey($site->id)
                ->sharedLock()
                ->first();

            if (! $site instanceof Site
                || ! SitePresenceReporting::for($site)->enabled
                || ! SiteAvailability::for($site)->open) {
                return null;
            }

            $rule = $site->proactiveMessageRules()
                ->enabled()
                ->where('public_id', $rulePublicId)
                ->sharedLock()
                ->first();

            if (! $rule instanceof ProactiveMessageRule) {
                return null;
            }

            $visitor = $this->visitorIdentities->forAnonymousId((int) $site->id, $anonymousId);
            $visitor = $visitor instanceof Visitor
                ? Visitor::query()->whereKey($visitor->id)->where('site_id', $site->id)->lockForUpdate()->first()
                : null;

            if (! $visitor instanceof Visitor
                || VisitorPresence::stateFor($visitor->last_web_seen_at) !== VisitorPresence::ACTIVE) {
                return null;
            }

            if ($rule->requires_available_agent
                && ! $this->supportAvailability->hasOnlineConversationAgent($site)) {
                return null;
            }

            $existing = ProactiveMessageDelivery::query()
                ->where('site_id', $site->id)
                ->where('claim_key', $claimKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ProactiveMessageDelivery) {
                return (int) $existing->visitor_id === (int) $visitor->id
                    && (string) $existing->rule_public_id === (string) $rule->public_id
                    && ($existing->shown_at !== null || $existing->expires_at->isFuture())
                        ? $existing
                        : null;
            }

            $now = now();
            $deliveries = ProactiveMessageDelivery::query()
                ->where('site_id', $site->id)
                ->where('visitor_id', $visitor->id);

            $frequencyBlocked = (clone $deliveries)
                ->whereNotNull('shown_at')
                ->where('shown_at', '>=', $now->copy()->subMinutes($rule->frequency_cap_minutes))
                ->exists();
            $dismissalBlocked = (clone $deliveries)
                ->whereNotNull('dismissed_at')
                ->where('dismissed_at', '>=', $now->copy()->subMinutes($rule->dismissal_snooze_minutes))
                ->exists();
            $claimPending = (clone $deliveries)
                ->whereNull('shown_at')
                ->where('expires_at', '>', $now)
                ->exists();

            if ($frequencyBlocked || $dismissalBlocked || $claimPending) {
                return null;
            }

            return ProactiveMessageDelivery::query()->create([
                'site_id' => $site->id,
                'proactive_message_rule_id' => $rule->id,
                'visitor_id' => $visitor->id,
                'rule_public_id' => $rule->public_id,
                'claim_key' => $claimKey,
                // Snapshot the exact invitation this claim authorized. A rule
                // edit must not make the later conversation transcript say
                // something different from what this visitor actually saw.
                'message' => $rule->message,
                'claimed_at' => $now,
                'expires_at' => $now->copy()->addMinutes(ProactiveMessageDelivery::CLAIM_MINUTES),
            ]);
        });
    }

    public function recordOutcome(
        Site $site,
        string $deliveryPublicId,
        string $anonymousId,
        string $outcome,
    ): ?ProactiveMessageDelivery {
        return DB::transaction(function () use ($anonymousId, $deliveryPublicId, $outcome, $site): ?ProactiveMessageDelivery {
            $this->siteManagerCoverage->shareAccount((int) $site->account_id);
            $site = Site::query()->servable()->whereKey($site->id)->sharedLock()->first();

            if (! $site instanceof Site) {
                return null;
            }

            $visitor = $this->visitorIdentities->forAnonymousId((int) $site->id, $anonymousId);
            $visitor = $visitor instanceof Visitor
                ? Visitor::query()->whereKey($visitor->id)->where('site_id', $site->id)->lockForUpdate()->first()
                : null;

            if (! $visitor instanceof Visitor) {
                return null;
            }

            $delivery = ProactiveMessageDelivery::query()
                ->where('site_id', $site->id)
                ->where('visitor_id', $visitor->id)
                ->where('public_id', $deliveryPublicId)
                ->lockForUpdate()
                ->first();

            if (! $delivery instanceof ProactiveMessageDelivery) {
                return null;
            }

            $now = now();

            if ($outcome === 'shown') {
                if ($delivery->shown_at === null && $delivery->expires_at->lessThan($now)) {
                    return null;
                }

                $delivery->shown_at ??= $now;
            } elseif ($outcome === 'engaged') {
                // A retry after the first response was lost is still success,
                // even if the short display claim expired in the meantime.
                // The recorded engagement is the durable fact; expiry only
                // prevents a NEW late engagement.
                if ($delivery->engaged_at !== null) {
                    return $delivery;
                }

                if ($delivery->shown_at === null
                    || $delivery->dismissed_at !== null
                    || $delivery->expires_at->lessThan($now)) {
                    return null;
                }

                $delivery->engaged_at ??= $now;
            } elseif ($outcome === 'dismissed') {
                if ($delivery->dismissed_at !== null) {
                    return $delivery;
                }

                if ($delivery->shown_at === null
                    || $delivery->engaged_at !== null
                    || $delivery->expires_at->lessThan($now)) {
                    return null;
                }

                $delivery->dismissed_at ??= $now;
            } else {
                return null;
            }

            $delivery->save();

            return $delivery;
        });
    }
}
