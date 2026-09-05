<?php

declare(strict_types=1);

namespace App\Support;

use Generator;
use Illuminate\Database\Eloquent\Collection;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessageInterface;
use RuntimeException;

/** Process every delivery report, then surface transient failures to the queue. */
final class AgentWebPushChannel extends WebPushChannel
{
    /**
     * @param  Collection<array-key, PushSubscription>  $subscriptions
     */
    protected function handleReports(Generator $reports, Collection $subscriptions, WebPushMessageInterface $message): void
    {
        $retryableFailure = false;

        foreach ($reports as $report) {
            $subscription = $this->findSubscription($subscriptions, $report);

            if (! $subscription instanceof PushSubscription) {
                continue;
            }

            $this->reportHandler->handleReport($report, $subscription, $message);
            $retryableFailure = $retryableFailure || $this->isRetryable($report);
        }

        if ($retryableFailure) {
            // The queued listener owns retries and backoff. Do this only after
            // every report has been handled so one failed browser does not
            // prevent successful/expired sibling subscriptions from settling.
            throw new RuntimeException('Web Push delivery received a retryable failure.');
        }
    }

    private function isRetryable(MessageSentReport $report): bool
    {
        if ($report->isSuccess() || $report->isSubscriptionExpired()) {
            return false;
        }

        $status = $report->getResponse()?->getStatusCode();

        return $status === null
            || in_array($status, [408, 425, 429], true)
            || $status >= 500;
    }
}
