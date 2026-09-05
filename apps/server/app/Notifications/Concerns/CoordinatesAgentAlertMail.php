<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Support\AgentAlertDeliveryCoordinator;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;

/** Carries a durable alert-delivery claim from channel selection to SMTP. */
trait CoordinatesAgentAlertMail
{
    /** @var array{notification_id: string, alert_version: string, state_key: string, claim_token: string}|null */
    private ?array $agentAlertMailClaim = null;

    /** Let realtime and Web Push settle before the email fallback is eligible. */
    public function withDelay(object $notifiable, string $channel): ?int
    {
        return $channel === 'mail' ? 5 : null;
    }

    /** @param array{notification_id: string, alert_version: string, state_key: string, claim_token: string} $claim */
    public function useAgentAlertMailClaim(array $claim): void
    {
        $this->agentAlertMailClaim = $claim;
    }

    /** @return array{notification_id: string, alert_version: string, state_key: string, claim_token: string}|null */
    public function agentAlertMailClaim(): ?array
    {
        return $this->agentAlertMailClaim;
    }

    protected function coordinateAgentAlertMail(MailMessage $message): MailMessage
    {
        $claim = $this->agentAlertMailClaim;

        if ($claim === null) {
            return $message;
        }

        return $message->withSymfonyMessage(function (Email $email) use ($claim): void {
            $headers = $email->getHeaders();
            $headers->addTextHeader(AgentAlertDeliveryCoordinator::ID_HEADER, $claim['notification_id']);
            $headers->addTextHeader(AgentAlertDeliveryCoordinator::VERSION_HEADER, $claim['alert_version']);
            $headers->addTextHeader(AgentAlertDeliveryCoordinator::STATE_HEADER, $claim['state_key']);
            $headers->addTextHeader(AgentAlertDeliveryCoordinator::CLAIM_HEADER, $claim['claim_token']);
        });
    }
}
