<?php

declare(strict_types=1);

namespace App\Support\Auth\Oidc;

use App\Models\OidcConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        $name = $providerUser->getName();

        if (! is_string($subject) || $subject === '' || strlen($subject) > 255) {
            throw new InvalidArgumentException('OIDC identity has no usable subject.');
        }

        return new OidcUser(
            subject: $subject,
            email: is_string($email) ? $email : null,
            emailVerified: ($raw['email_verified'] ?? null) === true,
            name: is_string($name) && trim($name) !== '' ? Str::limit(trim($name), 255, '') : null,
            roleClaimValues: $this->roleClaimValues(
                is_array($raw) ? $raw : [],
                $connection->role_claim,
            ),
        );
    }

    /**
     * Read only the configured authorization claim into request memory. Raw
     * provider claims and tokens are never persisted or logged.
     *
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    private function roleClaimValues(array $claims, ?string $claimName): array
    {
        $claimName = is_string($claimName) ? trim($claimName) : '';

        if ($claimName === '' || ! array_key_exists($claimName, $claims)) {
            return [];
        }

        $value = $claims[$claimName];
        $values = is_array($value) ? $value : [$value];

        return collect($values)
            ->filter(fn (mixed $item): bool => is_string($item))
            ->map(fn (string $item): string => trim($item))
            ->filter(fn (string $item): bool => $item !== '' && Str::length($item) <= 255)
            ->uniqueStrict()
            ->take(100)
            ->values()
            ->all();
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
