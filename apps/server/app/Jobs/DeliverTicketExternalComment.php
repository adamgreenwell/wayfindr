<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Ticket;
use App\Models\TicketExternalCommentDelivery;
use App\Models\TicketExternalLink;
use App\Models\User;
use App\Support\ExternalIssues\ExternalIssueCommentFailed;
use App\Support\ExternalIssues\GitHubIssueCommenter;
use App\Support\ExternalIssues\GitLabIssueCommenter;
use App\Support\ExternalIssues\InboundCommentSync;
use App\Support\ExternalIssues\IssueCommenter;
use App\Support\ExternalIssues\JiraIssueCommenter;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Deliver one at-most-once external-comment outbox row. */
class DeliverTicketExternalComment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_POSTED = 'posted';

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 7200;

    public function __construct(private readonly int $deliveryId) {}

    public static function dispatchPending(int $deliveryId): void
    {
        $job = new self($deliveryId);

        try {
            dispatch($job);
        } catch (Throwable $exception) {
            try {
                (new UniqueLock(app(CacheRepository::class)))->release($job);
            } catch (Throwable) {
                // The database row remains recoverable when the queue or its
                // uniqueness cache is unavailable. The finite lock TTL is the
                // last fallback if both queueing and release fail.
            }

            throw $exception;
        }
    }

    public static function processNow(int $deliveryId): string
    {
        try {
            return app()->call([new self($deliveryId), 'handle']);
        } catch (Throwable $exception) {
            // The intent committed before this synchronous fast path. Return a
            // truthful queued result instead of turning a recoverable handoff
            // into an HTTP error that invites the agent to submit it again.
            Log::warning('External issue comment remains queued for recovery.', [
                'delivery_id' => $deliveryId,
                'exception' => $exception::class,
            ]);

            return self::OUTCOME_PENDING;
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(InboundCommentSync $commentSync): string
    {
        $delivery = DB::transaction(function (): ?TicketExternalCommentDelivery {
            $delivery = TicketExternalCommentDelivery::query()
                ->whereKey($this->deliveryId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null || $delivery->delivered_at !== null || $delivery->failed_at !== null) {
                return $delivery;
            }

            // A committed start marker is deliberately not retried. Provider
            // comment APIs offer no idempotency key, so a worker that vanished
            // after acceptance leaves an uncertain row for reconciliation
            // instead of risking a duplicate customer-visible comment.
            if ($delivery->started_at !== null) {
                return null;
            }

            $link = TicketExternalLink::query()
                ->whereKey($delivery->ticket_external_link_id)
                ->where('ticket_id', $delivery->ticket_id)
                ->lockForUpdate()
                ->first();
            $connection = ExternalIssueProviderConnection::query()
                ->whereKey($delivery->provider_connection_id)
                ->where('account_id', $delivery->account_id)
                ->lockForUpdate()
                ->first();

            if (
                $link === null
                || $connection === null
                || ! $connection->is_enabled
                || ! $connection->hasCapability('add_comment')
                || $this->commenterFor($link->provider) === null
            ) {
                $delivery->forceFill([
                    'failed_at' => now(),
                    'last_error' => 'target_unavailable',
                ])->save();

                return $delivery;
            }

            $delivery->forceFill([
                'attempts' => $delivery->attempts + 1,
                'started_at' => now(),
                'last_error' => null,
            ])->save();
            $delivery->setRelation('externalLink', $link);
            $delivery->setRelation('providerConnection', $connection);

            return $delivery;
        });

        if ($delivery === null) {
            return self::OUTCOME_PENDING;
        }

        if ($delivery->delivered_at !== null) {
            return self::OUTCOME_POSTED;
        }

        if ($delivery->failed_at !== null) {
            return self::OUTCOME_FAILED;
        }

        $link = $delivery->externalLink;
        $connection = $delivery->providerConnection;
        $commenter = $this->commenterFor($link->provider);

        if ($commenter === null) {
            return self::OUTCOME_FAILED;
        }

        try {
            $result = $commenter->comment($connection, $link, $delivery->body);
        } catch (ExternalIssueCommentFailed $exception) {
            $this->recordFailure($delivery, $exception);

            return self::OUTCOME_FAILED;
        } catch (Throwable $exception) {
            $this->recordUnexpectedFailure($delivery, $exception);

            return self::OUTCOME_FAILED;
        }

        // The provider has accepted an irreversible comment. From here on,
        // never throw back into a queue retry or HTTP retry: if local completion
        // fails, the committed started_at marker prevents a duplicate send.
        try {
            DB::transaction(function () use ($commentSync, $delivery, $result): void {
                $fresh = TicketExternalCommentDelivery::query()
                    ->whereKey($delivery->id)
                    ->lockForUpdate()
                    ->first();

                if ($fresh === null || $fresh->delivered_at !== null) {
                    return;
                }

                $link = TicketExternalLink::query()
                    ->whereKey($fresh->ticket_external_link_id)
                    ->lockForUpdate()
                    ->first();

                if ($link === null) {
                    return;
                }

                $commentSync->remember($link, $result['id'] ?? null);
                $this->recordActivity($fresh, $link, 'ticket.external_comment_posted', [
                    'url' => $result['url'] ?? $link->url,
                ]);
                $fresh->forceFill([
                    'delivered_at' => now(),
                    'failed_at' => null,
                    'remote_comment_id' => $result['id'] ?? null,
                    'remote_url' => $result['url'] ?? $link->url,
                    'last_error' => null,
                ])->save();
            });
        } catch (Throwable $exception) {
            Log::critical('External issue comment was accepted but local completion failed.', [
                'delivery_id' => $delivery->public_id,
                'exception' => $exception::class,
            ]);
        }

        return self::OUTCOME_POSTED;
    }

    private function recordFailure(TicketExternalCommentDelivery $delivery, ExternalIssueCommentFailed $exception): void
    {
        try {
            DB::transaction(function () use ($delivery, $exception): void {
                $fresh = TicketExternalCommentDelivery::query()
                    ->whereKey($delivery->id)
                    ->lockForUpdate()
                    ->first();

                if ($fresh === null || $fresh->delivered_at !== null || $fresh->failed_at !== null) {
                    return;
                }

                $link = TicketExternalLink::query()->whereKey($fresh->ticket_external_link_id)->first();

                if ($link !== null) {
                    $this->recordActivity($fresh, $link, 'ticket.external_comment_failed', [
                        'status' => $exception->status(),
                        'message' => Str::limit($exception->getMessage(), 300),
                    ]);
                }

                $fresh->forceFill([
                    'failed_at' => now(),
                    'last_error' => 'provider_rejected',
                ])->save();
            });
        } catch (Throwable $recordingFailure) {
            Log::error('External issue comment failure could not be recorded.', [
                'delivery_id' => $delivery->public_id,
                'exception' => $recordingFailure::class,
            ]);
        }
    }

    private function recordUnexpectedFailure(TicketExternalCommentDelivery $delivery, Throwable $exception): void
    {
        try {
            TicketExternalCommentDelivery::query()
                ->whereKey($delivery->id)
                ->whereNull('delivered_at')
                ->whereNull('failed_at')
                ->update([
                    'failed_at' => now(),
                    'last_error' => 'transport_uncertain',
                ]);
        } catch (Throwable) {
            // The committed start marker still prevents an automatic resend.
        }

        Log::error('External issue comment delivery failed unexpectedly.', [
            'delivery_id' => $delivery->public_id,
            'exception' => $exception::class,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function recordActivity(TicketExternalCommentDelivery $delivery, TicketExternalLink $link, string $action, array $metadata): void
    {
        AuditEvent::query()->create([
            'account_id' => $delivery->account_id,
            'site_id' => $delivery->site_id,
            'actor_type' => User::class,
            'actor_id' => $delivery->actor_id,
            'subject_type' => Ticket::class,
            'subject_id' => $delivery->ticket_id,
            'action' => $action,
            'metadata' => [
                'external_link_id' => $link->id,
                'provider' => $link->provider,
                'external_key' => $link->external_key,
                ...$metadata,
            ],
            'occurred_at' => now(),
        ]);
    }

    private function commenterFor(string $provider): ?IssueCommenter
    {
        return match ($provider) {
            'github' => app(GitHubIssueCommenter::class),
            'gitlab' => app(GitLabIssueCommenter::class),
            'jira' => app(JiraIssueCommenter::class),
            default => null,
        };
    }
}
