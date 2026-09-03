<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Jobs\DeliverOutboundWebhook;
use App\Models\AuditEvent;
use App\Models\OutboundWebhookDelivery;
use App\Models\OutboundWebhookEndpoint;
use App\Models\User;
use App\Rules\PublicWebhookUrl;
use App\Support\DatabaseKey;
use App\Support\Sites\SiteManagerCoverage;
use App\Support\Webhooks\OutboundWebhookDestination;
use App\Support\Webhooks\OutboundWebhookPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/** Admin lifecycle controls for account outbound-webhook endpoints (ADR 0020). */
class AgentAccountOutboundWebhookController extends Controller
{
    public function __construct(private readonly SiteManagerCoverage $siteManagerCoverage) {}

    public function store(Request $request, OutboundWebhookDestination $destination): RedirectResponse
    {
        $agent = $request->user();
        $account = $agent->account()->firstOrFail();

        abort_unless($agent->hasAccountPermission(AccountPermission::ManageIntegrations), 403);

        $validated = $request->validate([
            'webhook.name' => ['required', 'string', 'max:120'],
            'webhook.url' => ['required', 'string', 'max:2048', new PublicWebhookUrl($destination)],
            'webhook.events' => ['required', 'array', 'min:1'],
            'webhook.events.*' => ['string', Rule::in(OutboundWebhookEndpoint::EVENTS)],
            'webhook.site_ids' => ['nullable', 'array'],
            'webhook.site_ids.*' => ['integer'],
        ])['webhook'];

        $generated = OutboundWebhookEndpoint::generateSecret();
        $askedForSpecificSites = $request->has('webhook.site_ids');

        $endpoint = DB::transaction(function () use ($account, $agent, $validated, $generated, $askedForSpecificSites): OutboundWebhookEndpoint {
            $this->siteManagerCoverage->lockAccount((int) $account->id);
            $lockedAgent = User::query()
                ->whereKey($agent->id)
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->first();

            abort_unless($lockedAgent?->hasAccountPermission(AccountPermission::ManageIntegrations), 403);
            abort_unless(
                collect($validated['events'])->every(
                    fn (string $event): bool => OutboundWebhookPermissions::allows($lockedAgent, $event),
                ),
                403,
            );

            $visibleSiteIds = $account->sites()
                ->visibleToAgentIncludingArchived($lockedAgent)
                ->pluck('id');
            $siteIds = $askedForSpecificSites
                ? $visibleSiteIds->intersect($validated['site_ids'] ?? [])->values()
                : $visibleSiteIds->values();

            $endpoint = $account->outboundWebhookEndpoints()->create([
                'created_by_id' => $lockedAgent->id,
                'name' => $validated['name'],
                'url' => trim($validated['url']),
                'secret' => $generated['plain'],
                'secret_last_four' => $generated['last_four'],
                'events' => array_values(array_unique($validated['events'])),
                // Like API tokens, every endpoint is pinned to its issuer's
                // current ceiling and never widens as sites are added later.
                'restricts_sites' => true,
            ]);

            $endpoint->sites()->sync($siteIds);
            $this->audit($lockedAgent, $endpoint, 'outbound_webhook.created', [
                'name' => $endpoint->name,
                'events' => $endpoint->events,
                'restricted_site_ids' => $siteIds->all(),
            ]);

            return $endpoint;
        });

        return redirect()
            ->route('dashboard.account.api-tokens.index')
            // The signer must retain this reversibly, but the UI still shows
            // it once. Encrypt before database-backed session flash, matching
            // the token boundary on this page.
            ->with('issued_webhook_secret', Crypt::encryptString($generated['plain']))
            ->with('status', $askedForSpecificSites
                ? 'outbound_webhooks.flash.created'
                : 'outbound_webhooks.flash.created_limited');
    }

    public function destroy(Request $request, string $webhookEndpoint): RedirectResponse
    {
        $agent = $request->user();
        $account = $agent->account()->firstOrFail();

        abort_unless($agent->hasAccountPermission(AccountPermission::ManageIntegrations), 403);

        $endpoint = DatabaseKey::isValid($webhookEndpoint)
            ? OutboundWebhookEndpoint::query()->whereKey($webhookEndpoint)->first()
            : null;

        abort_if($endpoint === null || (int) $endpoint->account_id !== (int) $account->id, 404);

        $alreadyDisabled = DB::transaction(function () use ($agent, $endpoint): bool {
            $locked = OutboundWebhookEndpoint::query()->whereKey($endpoint->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isEnabled()) {
                return true;
            }

            $locked->deliveries()
                ->whereNull('delivered_at')
                ->whereNull('failed_at')
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => now()]);
            $locked->forceFill(['disabled_at' => now()])->save();

            $this->audit($agent, $locked, 'outbound_webhook.disabled', [
                'name' => $locked->name,
            ]);

            return false;
        });

        return redirect()
            ->route('dashboard.account.api-tokens.index')
            ->with('status', $alreadyDisabled
                ? 'outbound_webhooks.flash.already_disabled'
                : 'outbound_webhooks.flash.disabled');
    }

    public function retry(Request $request, string $webhookDelivery): RedirectResponse
    {
        $agent = $request->user();
        $account = $agent->account()->firstOrFail();

        abort_unless($agent->hasAccountPermission(AccountPermission::ManageIntegrations), 403);

        $delivery = DatabaseKey::isValid($webhookDelivery)
            ? OutboundWebhookDelivery::query()->with('endpoint')->whereKey($webhookDelivery)->first()
            : null;

        abort_if(
            $delivery === null
            || $delivery->endpoint === null
            || (int) $delivery->endpoint->account_id !== (int) $account->id,
            404,
        );

        // Retrying is an outbound action for that site. An account admin whose
        // current site ceiling excludes it can see neither the log row nor
        // trigger another delivery by guessing its numeric database key.
        abort_unless(
            $delivery->site_id !== null
            && $account->sites()
                ->visibleToAgentIncludingArchived($agent)
                ->whereKey($delivery->site_id)
                ->exists()
            && OutboundWebhookPermissions::allows($agent, $delivery->event),
            404,
        );

        $retryId = DB::transaction(function () use ($account, $agent, $delivery): ?int {
            $this->siteManagerCoverage->lockAccount((int) $account->id);
            $lockedAgent = User::query()
                ->whereKey($agent->id)
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->first();

            abort_unless($lockedAgent?->hasAccountPermission(AccountPermission::ManageIntegrations), 404);

            $locked = OutboundWebhookDelivery::query()->with('endpoint')->whereKey($delivery->id)->lockForUpdate()->first();

            if (
                $locked === null
                || $locked->site_id === null
                || $locked->endpoint === null
                || (int) $locked->endpoint->account_id !== (int) $account->id
            ) {
                return null;
            }

            abort_unless(
                OutboundWebhookPermissions::allows($lockedAgent, $locked->event)
                && $account->sites()
                    ->visibleToAgentIncludingArchived($lockedAgent)
                    ->whereKey($locked->site_id)
                    ->exists(),
                404,
            );

            if (
                $locked->failed_at === null
                || $locked->delivered_at !== null
                || $locked->cancelled_at !== null
                || ! $locked->endpoint->isEnabled()
            ) {
                return null;
            }

            $locked->forceFill(['failed_at' => null])->save();

            $this->audit($lockedAgent, $locked->endpoint, 'outbound_webhook.delivery_retried', [
                'delivery_id' => $locked->public_id,
                'event' => $locked->event,
                'sequence' => $locked->sequence,
            ]);

            return (int) $locked->id;
        });

        if ($retryId === null) {
            return redirect()
                ->route('dashboard.account.api-tokens.index')
                ->with('status', 'outbound_webhooks.flash.not_retryable');
        }

        try {
            DeliverOutboundWebhook::dispatchPending($retryId);
        } catch (Throwable $exception) {
            Log::error('Outbound webhook manual retry stored, but its queue handoff failed.', [
                'outbound_webhook_delivery_id' => $retryId,
                'exception' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('dashboard.account.api-tokens.index')
            ->with('status', 'outbound_webhooks.flash.retrying');
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $actor, OutboundWebhookEndpoint $endpoint, string $action, array $metadata): void
    {
        AuditEvent::query()->create([
            'account_id' => $actor->account_id,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->id,
            'subject_type' => $endpoint->getMorphClass(),
            'subject_id' => $endpoint->id,
            'action' => $action,
            // Never the destination or signing secret. Audit exports identify
            // the endpoint by its operator-chosen name and lifecycle only.
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
