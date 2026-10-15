<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Webhooks\OutboundWebhookDestination;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Operator-owned route grants. Neither environment labels nor tenants grant egress. */
final readonly class AiNetworkPolicy
{
    private string $mode;

    /** @var list<array{account_id: int, source: string, origin: string, ips: list<string>}> */
    private array $grants;

    private string $policyRevision;

    public function __construct(AiDnsResolver $dns, #[SensitiveParameter] array $configuration, #[SensitiveParameter] string $revisionKey)
    {
        $this->dns = $dns;
        $mode = $configuration['mode'] ?? 'public-only';
        if (! in_array($mode, ['public-only', 'operator-private'], true) || $revisionKey === '') {
            throw new AiNetworkException('network_policy_invalid');
        }
        $this->mode = $mode;
        $this->grants = $this->normalizeGrants($configuration['private_grants'] ?? []);
        $routes = AiProviderRoutes::DEFAULTS;
        ksort($routes, SORT_STRING);
        $this->policyRevision = hash_hmac('sha256', json_encode([
            'version' => 'ai-network-v1', 'mode' => $this->mode, 'private_grants' => $this->grants,
            'provider_routes' => $routes,
        ], JSON_THROW_ON_ERROR), $revisionKey);
    }

    private AiDnsResolver $dns;

    public function revision(): string
    {
        return $this->policyRevision;
    }

    /** @return array{url: string, host: string, port: int, ips: list<string>, curl: array<int, mixed>, origin: string} */
    public function inspect(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] string $wireUrl, float $deadline): array
    {
        $this->beforeDeadline($deadline);
        $base = $this->url(AiProviderRoutes::baseUrl($connection));
        $wire = $this->url($wireUrl);
        $basePath = rtrim($base['path'], '/');

        if ($wire['origin'] !== $base['origin']
            || ($basePath !== '' && $wire['path'] !== $basePath && ! str_starts_with($wire['path'], $basePath.'/'))) {
            throw new AiNetworkException('endpoint_mismatch');
        }

        // An IP literal is already the actual wire address. Never let a resolver
        // classify a different IP while cURL still connects to this literal.
        try {
            $answers = filter_var($wire['host'], FILTER_VALIDATE_IP) !== false
                ? [$wire['host']] : $this->dns->resolve($wire['host'], $deadline);
        } catch (AiNetworkException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AiNetworkException('dns_unavailable');
        }
        $this->beforeDeadline($deadline);
        $ips = $this->addresses($answers, 'dns_invalid_answer');
        if ($ips === []) {
            throw new AiNetworkException('dns_unavailable');
        }

        $granted = [];
        if ($this->mode === 'operator-private') {
            foreach ($this->grants as $grant) {
                if ($grant['account_id'] === $connection->accountId && $grant['source'] === $connection->source
                    && $grant['origin'] === $wire['origin']) {
                    $granted = $grant['ips'];
                    break;
                }
            }
        }

        if ($granted !== []) {
            foreach ($ips as $ip) {
                if (! in_array($ip, $granted, true) || ! $this->privateIp($ip)) {
                    throw new AiNetworkException('destination_denied');
                }
            }
            $curl = $this->pin($wire['host'], $wire['port'], $ips);
        } else {
            try {
                $public = (new OutboundWebhookDestination(fn (): array => $ips))->inspect($wire['url']);
                $curl = $public['curl'];
            } catch (InvalidArgumentException) {
                throw new AiNetworkException('destination_denied');
            }
        }

        $curl[CURLOPT_PROTOCOLS] = $wire['scheme'] === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP;
        $this->beforeDeadline($deadline);

        return [
            'url' => $wire['url'], 'host' => $wire['host'], 'port' => $wire['port'],
            'ips' => $ips, 'curl' => $curl, 'origin' => $wire['origin'],
        ];
    }

    /** @return array{url: string, origin: string, host: string, port: int, path: string, scheme: string} */
    private function url(#[SensitiveParameter] string $url): array
    {
        $parts = parse_url($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false || ! is_array($parts)
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []) {
            throw new AiNetworkException('endpoint_invalid');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $rawHost = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $rawHost === '' || str_ends_with($rawHost, '.')) {
            throw new AiNetworkException('endpoint_invalid');
        }
        $host = str_starts_with($rawHost, '[') && str_ends_with($rawHost, ']') ? substr($rawHost, 1, -1) : $rawHost;
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $host = inet_ntop(inet_pton($host));
        } elseif (strlen($host) > 253
            || preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/', $host) !== 1
            || preg_match('/\A(?:[0-9]+|0x[0-9a-f]+)(?:\.(?:[0-9]+|0x[0-9a-f]+))*\z/', $host) === 1) {
            throw new AiNetworkException('endpoint_invalid');
        }
        if ($host === 'metadata' || str_starts_with($host, 'metadata.')
            || $host === 'instance-data' || str_starts_with($host, 'instance-data.')) {
            throw new AiNetworkException('destination_denied');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = (string) ($parts['path'] ?? '/');
        $decodedPath = rawurldecode($path);
        if ($port < 1 || $port > 65535 || ! str_starts_with($path, '/')
            || preg_match('/%(?:2f|5c|25)/i', $path) === 1
            || preg_match('/[\x00-\x20\x7f\\\\]/', $decodedPath) === 1
            || str_contains($decodedPath, ';') || str_contains($decodedPath, '//')
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $decodedPath) === 1) {
            throw new AiNetworkException('endpoint_invalid');
        }
        $wireHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $origin = $scheme.'://'.$wireHost.($port === ($scheme === 'https' ? 443 : 80) ? '' : ':'.$port);

        return ['url' => $origin.$path, 'origin' => $origin, 'host' => $host, 'port' => $port, 'path' => $path, 'scheme' => $scheme];
    }

    /** @return list<array{account_id: int, source: string, origin: string, ips: list<string>}> */
    private function normalizeGrants(#[SensitiveParameter] mixed $configuration): array
    {
        if (is_string($configuration) && ! str_starts_with(ltrim($configuration), '[')) {
            throw new AiNetworkException('network_policy_invalid');
        }
        try {
            $grants = is_string($configuration) ? json_decode($configuration, true, 8, JSON_THROW_ON_ERROR) : $configuration;
        } catch (Throwable) {
            throw new AiNetworkException('network_policy_invalid');
        }
        if (! is_array($grants) || ! array_is_list($grants) || count($grants) > 256) {
            throw new AiNetworkException('network_policy_invalid');
        }
        $normalized = [];
        foreach ($grants as $grant) {
            if (! is_array($grant) || count($grant) !== 4
                || ! isset($grant['account_id'], $grant['source'], $grant['origin'], $grant['ips'])
                || ! is_int($grant['account_id']) || $grant['account_id'] <= 0
                || ! in_array($grant['source'], ['account', 'installation'], true) || ! is_string($grant['origin'])) {
                throw new AiNetworkException('network_policy_invalid');
            }
            try {
                $route = $this->url($grant['origin']);
            } catch (AiNetworkException) {
                throw new AiNetworkException('network_policy_invalid');
            }
            if ($route['path'] !== '/') {
                throw new AiNetworkException('network_policy_invalid');
            }
            $ips = $this->addresses($grant['ips'], 'network_policy_invalid');
            if ($ips === []) {
                throw new AiNetworkException('network_policy_invalid');
            }
            foreach ($ips as $ip) {
                if (! $this->privateIp($ip)) {
                    throw new AiNetworkException('network_policy_invalid');
                }
            }
            $identity = $grant['account_id'].'|'.$grant['source'].'|'.$route['origin'];
            $ips = array_values(array_unique([...($normalized[$identity]['ips'] ?? []), ...$ips]));
            sort($ips, SORT_STRING);
            $normalized[$identity] = ['account_id' => $grant['account_id'], 'source' => $grant['source'], 'origin' => $route['origin'], 'ips' => $ips];
        }
        ksort($normalized, SORT_STRING);

        return array_values($normalized);
    }

    /** @return list<string> */
    private function addresses(#[SensitiveParameter] mixed $answers, string $reason): array
    {
        if (! is_array($answers) || ! array_is_list($answers) || count($answers) > 64) {
            throw new AiNetworkException($reason);
        }
        $ips = [];
        foreach ($answers as $ip) {
            if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new AiNetworkException($reason);
            }
            $ips[] = inet_ntop(inet_pton($ip));
        }
        $ips = array_values(array_unique($ips));
        sort($ips, SORT_STRING);

        return $ips;
    }

    private function privateIp(string $ip): bool
    {
        // AWS and GCP metadata have ULA endpoints; ULA alone is no model grant.
        if (in_array($ip, ['fd00:ec2::254', 'fd20:ce::254'], true)) {
            return false;
        }
        foreach ([['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['127.0.0.0', 8], ['::1', 128], ['fc00::', 7]] as [$network, $prefix]) {
            if ($this->cidrContains($ip, $network, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function cidrContains(string $ip, string $network, int $prefix): bool
    {
        $packed = inet_pton($ip);
        $range = inet_pton($network);
        if ($packed === false || $range === false || strlen($packed) !== strlen($range)) {
            return false;
        }
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;

        return substr($packed, 0, $bytes) === substr($range, 0, $bytes)
            && ($bits === 0 || (ord($packed[$bytes]) & (0xFF << (8 - $bits))) === (ord($range[$bytes]) & (0xFF << (8 - $bits))));
    }

    /** @param list<string> $ips */
    private function pin(string $host, int $port, array $ips): array
    {
        $curl = [CURLOPT_NOPROXY => '*'];
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $curl[CURLOPT_RESOLVE] = [$host.':'.$port.':'.implode(',', array_map(
                static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip, $ips,
            ))];
        }

        return $curl;
    }

    private function beforeDeadline(float $deadline): void
    {
        if (! is_finite($deadline) || hrtime(true) / 1_000_000_000 >= $deadline) {
            throw new AiNetworkException('dns_deadline');
        }
    }

    public function __debugInfo(): array
    {
        return ['mode' => $this->mode, 'revision' => $this->policyRevision];
    }

    public function __serialize(): array
    {
        throw new LogicException('AI network policy cannot be serialized.');
    }
}
