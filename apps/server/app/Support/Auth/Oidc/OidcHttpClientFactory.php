<?php

declare(strict_types=1);

namespace App\Support\Auth\Oidc;

use App\Support\Webhooks\OutboundWebhookDestination;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;

/**
 * Builds an OIDC transport that re-resolves and pins every outbound host.
 *
 * Discovery documents control the token, JWKS, and user-info destinations, so
 * checking only the configured issuer would still leave an SSRF path. This
 * middleware applies the existing public-HTTPS policy to every Guzzle request.
 */
final readonly class OidcHttpClientFactory
{
    public function __construct(
        private OutboundWebhookDestination $destination,
        private ?Closure $baseHandler = null,
    ) {}

    public function make(?callable $handler = null): Client
    {
        $stack = HandlerStack::create($handler ?? $this->baseHandler);
        $stack->push(function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $inspected = $this->destination->inspect((string) $request->getUri());

                $options['allow_redirects'] = false;
                $options['proxy'] = '';
                $options['curl'] = array_replace(
                    $options['curl'] ?? [],
                    $inspected['curl'],
                    [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS],
                );

                return $handler($request, $options);
            };
        }, 'wayfindr_oidc_public_https');

        return new Client([
            'handler' => $stack,
            'allow_redirects' => false,
            'proxy' => '',
            'connect_timeout' => 5,
            'timeout' => 10,
        ]);
    }

    public function assertAllowed(string $url): void
    {
        $this->destination->inspect($url);
    }
}
