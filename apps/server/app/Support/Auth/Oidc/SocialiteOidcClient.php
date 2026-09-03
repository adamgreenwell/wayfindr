<?php

declare(strict_types=1);

namespace App\Support\Auth\Oidc;

use App\Models\OidcConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use SocialiteProviders\Manager\Config;
use SocialiteProviders\OpenIDConnect\Provider;

final readonly class SocialiteOidcClient implements OidcClient
{
    public function __construct(private OidcHttpClientFactory $httpClients) {}

    public function redirect(Request $request, OidcConnection $connection): RedirectResponse
    {
        $response = $this->provider($request, $connection)->redirect();
        $this->httpClients->assertAllowed($response->getTargetUrl());

        return $response;
    }

    public function user(Request $request, OidcConnection $connection): OidcUser
    {
        $providerUser = $this->provider($request, $connection)->user();
        $raw = $providerUser->getRaw();
        $subject = $providerUser->getId();
        $email = $providerUser->getEmail();

        if (! is_string($subject) || $subject === '' || strlen($subject) > 255) {
            throw new InvalidArgumentException('OIDC identity has no usable subject.');
        }

        return new OidcUser(
            subject: $subject,
            email: is_string($email) ? $email : null,
            emailVerified: ($raw['email_verified'] ?? null) === true,
        );
    }

    private function provider(Request $request, OidcConnection $connection): Provider
    {
        $callback = route('oidc.callback', ['connectionPublicId' => $connection->public_id]);
        $provider = new Provider(
            $request,
            $connection->client_id,
            $connection->client_secret,
            $callback,
        );
        $provider->setConfig(new Config(
            $connection->client_id,
            $connection->client_secret,
            $callback,
            [
                'base_url' => $connection->issuer_url,
                'issuer' => $connection->issuer_url,
                'require_email' => true,
                'verify_jwt' => true,
                'use_nonce' => true,
                'cache_ttl' => 300,
                'http_connect_timeout' => 5,
                'http_timeout' => 10,
            ],
        ));
        $provider->setHttpClient($this->httpClients->make());

        return $provider;
    }
}
