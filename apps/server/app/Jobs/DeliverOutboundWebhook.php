<?php

namespace App\Jobs;

use App\Models\OutboundWebhookDelivery;
use App\Models\Site;
use App\Support\Webhooks\OutboundWebhookDestination;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Deliver one immutable outbound-webhook outbox row. */
class DeliverOutboundWebhook implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public int $uniqueFor = 7200;

    private const MAX_RESPONSE_BODY_BYTES = 4096;

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
                // The finite uniqueness TTL is the last recovery path when the
                // same dead cache refuses both queueing and lock release.
            }

            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(OutboundWebhookDestination $destination): void
    {
        // This first read is only a pointer to the lifecycle rows we must lock.
        // It is deliberately not authority to send: an endpoint disable or site
        // purge may commit immediately after it, and everything below re-reads
        // the decision while holding the same rows those actions mutate.
        $pointer = OutboundWebhookDelivery::query()
            ->select(['id', 'outbound_webhook_endpoint_id', 'site_id'])
            ->find($this->deliveryId);

        if ($pointer === null) {
            return;
        }

        try {
            $shouldRetry = DB::transaction(function () use ($pointer, $destination): bool {
                // Holding the site and this specific delivery through the POST
                // gives purge/disable a precise contract: they either win
                // before this recheck and no request starts, or wait for this
                // already-started request to finish before they return. Do NOT
                // lock the endpoint here: publishers use that row to allocate
                // sequence numbers, and foreground support writes must never
                // queue behind subscriber network I/O.
                $site = Site::query()
                    ->whereKey($pointer->site_id)
                    // Normal support creation also takes a shared site lock so
                    // it can coordinate with purge without serializing readers.
                    // Match that mode: only the destructive DELETE must wait.
                    ->sharedLock()
                    ->first();

                if ($site === null) {
                    return false;
                }

                $delivery = OutboundWebhookDelivery::query()
                    ->whereKey($pointer->id)
                    ->lockForUpdate()
                    ->first();

                if (
                    $delivery === null
                    || (int) $delivery->site_id !== (int) $site->id
                    || $delivery->delivered_at !== null
                    || $delivery->failed_at !== null
                    || $delivery->cancelled_at !== null
                ) {
                    return false;
                }

                // A plain fresh read is intentional. Disable locks the endpoint
                // only long enough to stop publishers, then cancels this row;
                // if it is in progress, that cancellation waits on the delivery
                // lock above. Therefore an uncommitted disable means this POST
                // is already the winner, while a committed disable is visible
                // here and prevents it. No endpoint lock is needed across I/O.
                $endpoint = $delivery->endpoint()->first();

                if (
                    $endpoint === null
                    || (int) $endpoint->id !== (int) $pointer->outbound_webhook_endpoint_id
                    || ! $endpoint->isEnabled()
                ) {
                    $delivery->forceFill(['cancelled_at' => now()])->save();

                    return false;
                }

                $delivery->forceFill([
                    'attempts' => $delivery->attempts + 1,
                    'last_attempted_at' => now(),
                    'response_status' => null,
                    'response_body' => null,
                    'last_error' => null,
                ])->save();

                try {
                    // Resolve for every attempt, then pin the verified answer
                    // for the request. This is both configuration drift
                    // handling and the DNS rebinding boundary.
                    $inspected = $destination->inspect($endpoint->url);
                    $body = json_encode(
                        $delivery->payload,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    );
                    $signature = 'sha256='.hash_hmac('sha256', $body, $endpoint->secret);
                    $responseBodyBuffer = Utils::streamFor('');
                    $responseSink = FnStream::decorate($responseBodyBuffer, [
                        // cURL requires the callback to report the whole chunk
                        // as consumed. Retain only the diagnostic prefix and
                        // discard the rest during transfer so a subscriber
                        // cannot fill worker memory or disk.
                        'write' => static function (string $chunk) use ($responseBodyBuffer): int {
                            $remaining = self::MAX_RESPONSE_BODY_BYTES - ($responseBodyBuffer->getSize() ?? 0);

                            if ($remaining > 0) {
                                $responseBodyBuffer->write(substr($chunk, 0, $remaining));
                            }

                            return strlen($chunk);
                        },
                    ]);

                    $response = Http::withOptions([
                        'allow_redirects' => false,
                        // A host-level proxy must not get a fresh opportunity
                        // to resolve this name after the public-address check.
                        'proxy' => '',
                        'curl' => $inspected['curl'],
                        'sink' => $responseSink,
                    ])
                        ->connectTimeout(5)
                        ->timeout(15)
                        ->withHeaders([
                            'User-Agent' => 'Wayfindr-Webhooks/1.0',
                            'X-Wayfindr-Event' => $delivery->event,
                            'X-Wayfindr-Delivery' => $delivery->public_id,
                            'X-Wayfindr-Signature' => $signature,
                        ])
                        ->withBody($body, 'application/json')
                        ->post($inspected['url']);

                    $responseBodyBuffer->rewind();
                    $responseBody = self::boundedResponseBody($responseBodyBuffer->getContents());

                    if (! $response->successful()) {
                        $delivery->forceFill([
                            'response_status' => $response->status(),
                            'response_body' => $responseBody,
                            'last_error' => 'http_status',
                        ])->save();

                        return true;
                    }

                    $delivery->forceFill([
                        // This is HTTP acceptance, not exactly-once
                        // processing. If the worker exits after POST but before
                        // this save, the stable delivery id and sequence let
                        // the subscriber de-duplicate.
                        'response_status' => $response->status(),
                        'response_body' => $responseBody,
                        'last_error' => null,
                        'delivered_at' => now(),
                        'failed_at' => null,
                    ])->save();

                    return false;
                } catch (Throwable) {
                    $delivery->forceFill(['last_error' => 'connection'])->save();

                    return true;
                }
            });
        } catch (Throwable) {
            // Database/crypto failures also retry, but their details do not
            // belong in queue output or a subscriber-visible record.
            throw new RuntimeException('Outbound webhook delivery failed.');
        }

        if ($shouldRetry) {

            // No subscriber response body, destination or transport exception
            // is copied into the queue error. The operator has the bounded log
            // above; workers only need a retry signal.
            throw new RuntimeException('Outbound webhook delivery failed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        OutboundWebhookDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereNull('delivered_at')
            ->whereNull('cancelled_at')
            ->update(['failed_at' => now()]);
    }

    private static function boundedResponseBody(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        // Subscriber bodies are diagnostic only. Invalid bytes are replaced
        // and the stored/displayed sample is capped so a peer cannot grow the
        // delivery log without bound.
        return mb_strcut(mb_scrub($body, 'UTF-8'), 0, self::MAX_RESPONSE_BODY_BYTES, 'UTF-8');
    }
}
