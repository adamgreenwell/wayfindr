<?php

namespace App\Listeners;

use App\Models\SlaAlertDelivery;
use App\Notifications\SlaDeadlineAlert;
use App\Support\AgentAlertDeliveryCoordinator;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Str;
use LogicException;

/** Place durable alert ambiguity boundaries immediately before mail transport. */
class MarkSlaMailTransportStarted
{
    public function __construct(private readonly AgentAlertDeliveryCoordinator $agentAlertDeliveries) {}

    public function handle(MessageSending $event): void
    {
        $agentAlertClaim = $this->agentAlertClaim($event);
        $header = $event->message->getHeaders()->get(SlaDeadlineAlert::DELIVERY_HEADER);
        $deliveryId = $header === null ? null : trim($header->getBodyAsString());

        if ($agentAlertClaim !== null) {
            // The common and SLA-specific boundaries move together. A live
            // browser receipt can still cancel the fallback before SMTP, and
            // a stale SLA claim rolls the common boundary back with it.
            $this->agentAlertDeliveries->markMailTransportStarted($agentAlertClaim, $deliveryId);

            return;
        }

        if ($deliveryId === null) {
            return;
        }

        $started = SlaAlertDelivery::query()
            ->where('public_id', $deliveryId)
            ->where('channel', 'mail')
            ->whereNotNull('claimed_at')
            ->whereNull('started_at')
            ->whereNull('accepted_at')
            ->whereNull('cancelled_at')
            ->update(['started_at' => now()]);

        if ($started !== 1) {
            throw new LogicException('The SLA mail delivery is no longer eligible for transport.');
        }
    }

    /** @return array{notification_id: string, alert_version: string, state_key: string, claim_token: string}|null */
    private function agentAlertClaim(MessageSending $event): ?array
    {
        $headers = $event->message->getHeaders();
        $values = [
            'notification_id' => $headers->get(AgentAlertDeliveryCoordinator::ID_HEADER),
            'alert_version' => $headers->get(AgentAlertDeliveryCoordinator::VERSION_HEADER),
            'state_key' => $headers->get(AgentAlertDeliveryCoordinator::STATE_HEADER),
            'claim_token' => $headers->get(AgentAlertDeliveryCoordinator::CLAIM_HEADER),
        ];

        if (collect($values)->every(fn ($header): bool => $header === null)) {
            return null;
        }

        $claim = collect($values)
            ->map(fn ($header): string => trim((string) $header?->getBodyAsString()))
            ->all();

        if (! Str::isUuid($claim['notification_id'])
            || ! Str::isUuid($claim['alert_version'])
            || ! Str::isUuid($claim['claim_token'])
            || preg_match('/^[a-f0-9]{64}$|^event$/', $claim['state_key']) !== 1) {
            throw new LogicException('The agent alert mail delivery headers are invalid.');
        }

        return $claim;
    }
}
