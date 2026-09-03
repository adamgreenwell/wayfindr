<?php

namespace App\Support\Webhooks;

use Closure;
use InvalidArgumentException;

/**
 * Resolves an outbound webhook destination without giving it an SSRF escape.
 *
 * Validation alone is not enough: DNS can change between saving the endpoint
 * and delivering it. Delivery resolves again, refuses the whole hostname if
 * any answer is non-public, then pins cURL to a verified answer so a second
 * lookup cannot rebind the request to an internal address.
 */
final class OutboundWebhookDestination
{
    /**
     * Special-use ranges PHP's NO_RES_RANGE does not consistently reject.
     * The broad blocks are deliberate: a webhook has no reason to reach a
     * documentation, benchmark, transition, carrier-NAT or multicast range.
     *
     * @var list<array{string, int}>
     */
    private const NON_PUBLIC_CIDRS = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['192.88.99.0', 24],
        ['192.168.0.0', 16],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
        ['::', 128],
        ['::1', 128],
        ['::ffff:0:0', 96],
        ['64:ff9b::', 96],
        ['64:ff9b:1::', 48],
        ['100::', 64],
        ['2001::', 23],
        ['2001:db8::', 32],
        ['2002::', 16],
        ['3fff::', 20],
        ['5f00::', 16],
        ['fc00::', 7],
        ['fec0::', 10],
        ['fe80::', 10],
        ['ff00::', 8],
    ];

    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /** @param (Closure(string): list<string>)|null $resolver */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return [$host];
            }

            $answers = dns_get_record($host, DNS_A | DNS_AAAA);

            if ($answers === false) {
                return [];
            }

            return array_values(array_unique(array_filter(array_map(
                static fn (array $answer): ?string => $answer['ip'] ?? $answer['ipv6'] ?? null,
                $answers,
            ))));
        };
    }

    /**
     * @return array{url: string, host: string, port: int, ips: list<string>, curl: array<int, mixed>}
     */
    public function inspect(string $url): array
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (
            $url === ''
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('The webhook destination must be a public HTTPS URL.');
        }

        $rawHost = (string) $parts['host'];

        // A trailing dot is DNS-equivalent but not guaranteed to match the
        // hostname passed to CURLOPT_RESOLVE. Reject it rather than validate
        // one spelling and let cURL resolve another spelling itself.
        if (str_ends_with($rawHost, '.')) {
            throw new InvalidArgumentException('The webhook destination host must not have a trailing dot.');
        }

        $host = strtolower($rawHost);

        // Keep the wire host unambiguous. Unicode hostnames can be supplied in
        // their ASCII/punycode form; control characters and percent escapes do
        // not get a chance to mean something different to parse_url and cURL.
        if (
            $host === ''
            || (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^[a-z0-9.-]+$/', $host) !== 1)
        ) {
            throw new InvalidArgumentException('The webhook destination must have an unambiguous public host.');
        }

        $port = (int) ($parts['port'] ?? 443);
        $ips = ($this->resolver)($host);

        if ($ips === []) {
            throw new InvalidArgumentException('The webhook destination did not resolve.');
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new InvalidArgumentException('The webhook destination did not resolve only to public addresses.');
            }
        }

        sort($ips, SORT_STRING);
        $curl = [CURLOPT_NOPROXY => '*'];

        // IP literals already name their destination. Hostnames are pinned to
        // every verified answer while retaining their hostname for TLS SNI
        // and certificate verification. libcurl can then fall back across a
        // healthy A/AAAA set without performing another, rebindable lookup.
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $resolvedIps = array_map(
                static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip,
                $ips,
            );
            $curl[CURLOPT_RESOLVE] = [$host.':'.$port.':'.implode(',', $resolvedIps)];
        }

        return compact('url', 'host', 'port', 'ips', 'curl');
    }

    public function isAllowed(string $url): bool
    {
        try {
            $this->inspect($url);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function isPublicIp(string $ip): bool
    {
        // PHP's NO_RES_RANGE accepts large currently-reserved IPv6 blocks,
        // including 4000::/3 and the historical site-local range. IANA's
        // presently allocated global-unicast space is 2000::/3; fail closed
        // outside it so a locally routed reserved prefix cannot become an SSRF
        // tunnel. A future IANA expansion should be an explicit code change.
        if (
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && ! self::cidrContains($ip, '2000::', 3)
        ) {
            return false;
        }

        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        foreach (self::NON_PUBLIC_CIDRS as [$network, $prefix]) {
            if (self::cidrContains($ip, $network, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private static function cidrContains(string $ip, string $network, int $prefix): bool
    {
        $packedIp = inet_pton($ip);
        $packedNetwork = inet_pton($network);

        if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if (substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }
}
