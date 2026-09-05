<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Webhooks\OutboundWebhookDestination;
use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Minishlink\WebPush\WebPush;
use Psr\Http\Message\RequestInterface;

/** Build the Web Push sender with a pinned, public-only outbound transport. */
final readonly class AgentWebPushFactory
{
    public function __construct(
        private OutboundWebhookDestination $destination,
        private ?Closure $baseHandler = null,
    ) {}

    public function make(): WebPush
    {
        $pendingRequest = Http::timeout(30)
            ->connectTimeout(10)
            ->withoutRedirecting()
            ->withOptions(['proxy' => ''])
            ->withMiddleware(function (callable $handler): callable {
                return function (RequestInterface $request, array $options) use ($handler) {
                    try {
                        $inspected = $this->destination->inspect((string) $request->getUri());
                    } catch (InvalidArgumentException) {
                        // This is a deterministic local policy rejection, not
                        // a transient connection failure. Return a permanent
                        // report so the queue does not retry an SSRF-blocked
                        // endpoint on every backoff interval.
                        return Create::promiseFor(new Response(400));
                    }

                    $options['allow_redirects'] = false;
                    $options['proxy'] = '';
                    $options['curl'] = array_replace(
                        $options['curl'] ?? [],
                        $inspected['curl'],
                        [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS],
                    );

                    return $handler($request, $options);
                };
            });

        if ($this->baseHandler instanceof Closure) {
            $pendingRequest->setHandler($this->baseHandler);
        }

        $publicKey = trim((string) config('webpush.vapid.public_key'));
        $privateKey = trim((string) config('webpush.vapid.private_key'));
        $auth = [];

        if ($publicKey !== '' && $privateKey !== '') {
            $auth['VAPID'] = [
                'subject' => trim((string) config('webpush.vapid.subject')) ?: url('/'),
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ];
        }

        return (new WebPush(
            $auth,
            [],
            $pendingRequest->buildClient(),
            new HttpFactory,
            new HttpFactory,
        ))
            ->setReuseVAPIDHeaders(true)
            ->setAutomaticPadding(config('webpush.automatic_padding', true));
    }
}
