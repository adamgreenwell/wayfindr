<?php

use App\Support\Ai\Network\AiDnsResolver;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiNetworkPolicy;
use App\Support\Ai\Network\AiProviderRoutes;
use App\Support\Ai\Network\BoundedAiDnsResolver;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use Symfony\Component\Process\Process;

function aiNetworkPolicyConnection(string $endpoint = 'https://models.example.test/v1', int $accountId = 7, string $source = 'account'): ResolvedAgentCopilotConnection
{
    return new ResolvedAgentCopilotConnection($accountId, $source, str_repeat('a', 64), 'openai-compatible', 'support-model', $endpoint, '', 'synthetic-key');
}

function aiNetworkPolicyGrant(string $origin = 'http://models.internal:11434', array $ips = ['10.0.0.7'], int $accountId = 7, string $source = 'account'): array
{
    return ['account_id' => $accountId, 'source' => $source, 'origin' => $origin, 'ips' => $ips];
}

function aiNetworkPolicyReason(Closure $operation): ?string
{
    try {
        $operation();

        return null;
    } catch (AiNetworkException $exception) {
        return $exception->reason;
    }
}

function aiNetworkPolicyDeadline(): float
{
    return hrtime(true) / 1_000_000_000 + 2;
}

test('AI public destinations retain their wire hostname and pin every approved DNS address', function (): void {
    $policy = new AiNetworkPolicy(new BoundedAiDnsResolver(fn (): array => ['2001:4860:4860::8888', '8.8.8.8']), ['mode' => 'public-only'], 'revision-key');
    $inspected = $policy->inspect(aiNetworkPolicyConnection('https://Models.Example.Test:443/v1'), 'https://models.example.test/v1/chat/completions', aiNetworkPolicyDeadline());

    expect($inspected['origin'])->toBe('https://models.example.test')
        ->and($inspected['url'])->toBe('https://models.example.test/v1/chat/completions')
        ->and($inspected['host'])->toBe('models.example.test')
        ->and($inspected['port'])->toBe(443)
        ->and($inspected['ips'])->toBe(['2001:4860:4860::8888', '8.8.8.8'])
        ->and($inspected['curl'][CURLOPT_RESOLVE])->toBe(['models.example.test:443:[2001:4860:4860::8888],8.8.8.8'], 'The inspected addresses must be pinned, with the hostname retained for TLS.')
        ->and($inspected['curl'][CURLOPT_NOPROXY])->toBe('*')
        ->and($inspected['curl'][CURLOPT_PROTOCOLS])->toBe(CURLPROTO_HTTPS);
});

test('AI policy checks DNS again on each request and rejects rebinding without a second lookup', function (): void {
    $dns = new class implements AiDnsResolver
    {
        public int $calls = 0;

        public function resolve(string $host, float $deadline): array
        {
            return ++$this->calls === 1 ? ['8.8.8.8'] : ['10.0.0.7'];
        }
    };
    $policy = new AiNetworkPolicy($dns, [], 'revision-key');
    $first = $policy->inspect(aiNetworkPolicyConnection(), 'https://models.example.test/v1/models', aiNetworkPolicyDeadline());
    $reason = aiNetworkPolicyReason(fn () => $policy->inspect(aiNetworkPolicyConnection(), 'https://models.example.test/v1/models', aiNetworkPolicyDeadline()));

    expect($first['curl'][CURLOPT_RESOLVE])->toBe(['models.example.test:443:8.8.8.8'])
        ->and($reason)->toBe('destination_denied', 'A later private answer must not reuse an earlier public approval.')
        ->and($dns->calls)->toBe(2, 'Inspection must use exactly one authoritative answer set per request.');
});

test('AI public only denies mixed reserved mapped and private DNS answers', function (array $answers): void {
    $policy = new AiNetworkPolicy(new BoundedAiDnsResolver(fn (): array => $answers), ['mode' => 'public-only'], 'revision-key');
    expect(aiNetworkPolicyReason(fn () => $policy->inspect(aiNetworkPolicyConnection(), 'https://models.example.test/v1/models', aiNetworkPolicyDeadline())))
        ->toBe('destination_denied', 'Every A/AAAA answer must be public; one safe address cannot authorize the rest.');
})->with([
    'mixed public/private' => [['8.8.8.8', '10.0.0.7']],
    'loopback' => [['127.0.0.1']],
    'private' => [['192.168.1.1']],
    'metadata' => [['169.254.169.254']],
    'AWS IPv6 metadata' => [['fd00:ec2::254']],
    'GCP IPv6 metadata' => [['fd20:ce::254']],
    'carrier NAT metadata' => [['100.100.100.200']],
    'mapped loopback' => [['::ffff:127.0.0.1']],
    'mapped public' => [['::ffff:8.8.8.8']],
    'ULA' => [['fd00::7']],
    'link local' => [['fe80::1']],
    'reserved IPv6' => [['4000::1']],
]);

test('AI wire origin and base path are checked before any DNS lookup', function (string $wireUrl): void {
    $called = false;
    $dns = new BoundedAiDnsResolver(function () use (&$called): array {
        $called = true;

        return ['8.8.8.8'];
    });
    $policy = new AiNetworkPolicy($dns, [], 'revision-key');
    expect(aiNetworkPolicyReason(fn () => $policy->inspect(aiNetworkPolicyConnection(), $wireUrl, aiNetworkPolicyDeadline())))
        ->toBe('endpoint_mismatch', 'A configured base URL cannot authorize another origin or path prefix.')
        ->and($called)->toBeFalse();
})->with([
    'other host' => 'https://other.example.test/v1/models',
    'other port' => 'https://models.example.test:8443/v1/models',
    'downgrade' => 'http://models.example.test/v1/models',
    'prefix confusion' => 'https://models.example.test/v10/models',
    'outside base' => 'https://models.example.test/models',
]);

test('AI endpoints reject ambiguous URL components and traversal', function (string $url): void {
    $policy = new AiNetworkPolicy(new BoundedAiDnsResolver(fn (): array => ['8.8.8.8']), [], 'revision-key');
    expect(aiNetworkPolicyReason(fn () => $policy->inspect(aiNetworkPolicyConnection($url), $url, aiNetworkPolicyDeadline())))
        ->toBe('endpoint_invalid', 'URL spellings must identify the same unambiguous destination and safe path at validation and dispatch.');
})->with([
    'credentials' => 'https://user:secret@models.example.test/v1',
    'query' => 'https://models.example.test/v1?api_key=secret',
    'fragment' => 'https://models.example.test/v1#secret',
    'trailing dot' => 'https://models.example.test./v1',
    'short IPv4' => 'http://127.1/v1',
    'decimal IPv4' => 'http://2130706433/v1',
    'hex IPv4' => 'http://0x7f000001/v1',
    'octal IPv4' => 'http://0177.0.0.1/v1',
    'encoded host' => 'http://%31%32%37.0.0.1/v1',
    'zone identifier' => 'http://[fe80::1%25eth0]/v1',
    'backslash' => 'https://models.example.test/v1\\..\\models',
    'dot traversal' => 'https://models.example.test/v1/../models',
    'encoded dot traversal' => 'https://models.example.test/v1/%2e%2e/models',
    'encoded slash traversal' => 'https://models.example.test/v1/..%2fmodels',
    'double encoded traversal' => 'https://models.example.test/v1/%252e%252e/models',
    'matrix traversal' => 'https://models.example.test/v1/..;x/admin',
    'encoded matrix traversal' => 'https://models.example.test/v1/..%3bx/admin',
    'matrix prefix' => 'https://models.example.test/v1;parameter/models',
    'repeated slash' => 'https://models.example.test/v1//models',
]);

test('AI path binding permits literal Gemini method colons', function (): void {
    $policy = new AiNetworkPolicy(new BoundedAiDnsResolver(fn (): array => ['8.8.8.8']), [], 'revision-key');
    $result = $policy->inspect(aiNetworkPolicyConnection(), 'https://models.example.test/v1/models/support-model:generateContent', aiNetworkPolicyDeadline());
    expect($result['url'])->toBe('https://models.example.test/v1/models/support-model:generateContent');
});

test('AI public only ignores otherwise valid private grants', function (): void {
    $policy = new AiNetworkPolicy(new BoundedAiDnsResolver(fn (): array => ['10.0.0.7']), [
        'mode' => 'public-only', 'private_grants' => [aiNetworkPolicyGrant()],
    ], 'revision-key');
    expect(aiNetworkPolicyReason(fn () => $policy->inspect(aiNetworkPolicyConnection('http://models.internal:11434'), 'http://models.internal:11434/api/chat', aiNetworkPolicyDeadline())))
        ->toBe('destination_denied', 'A private grant must have no authority while the installation policy is public-only.');
});

test('AI operator private grants require exact account source origin and every answer', function (array $changes, array $answers): void {
    $grant = [...aiNetworkPolicyGrant(), ...$changes];
    $policy = new AiNetworkPolicy(new BoundedAiDnsResolver(fn (): array => $answers), [
        'mode' => 'operator-private', 'private_grants' => [$grant],
    ], 'revision-key');
    expect(aiNetworkPolicyReason(fn () => $policy->inspect(aiNetworkPolicyConnection('http://models.internal:11434'), 'http://models.internal:11434/api/chat', aiNetworkPolicyDeadline())))
        ->toBe('destination_denied', 'A private route grant cannot authorize another account, source, origin, or address.');
})->with([
    'foreign account' => [['account_id' => 8], ['10.0.0.7']],
    'other source' => [['source' => 'installation'], ['10.0.0.7']],
    'other origin' => [['origin' => 'http://other.internal:11434'], ['10.0.0.7']],
    'other port' => [['origin' => 'http://models.internal:11435'], ['10.0.0.7']],
    'unapproved answer' => [[], ['10.0.0.8']],
    'mixed approved/private' => [[], ['10.0.0.7', '10.0.0.8']],
    'mixed approved/public' => [[], ['10.0.0.7', '8.8.8.8']],
    'public rebinding' => [[], ['8.8.8.8']],
    'AWS metadata rebinding' => [[], ['fd00:ec2:0:0:0:0:0:254']],
    'GCP metadata rebinding' => [[], ['fd20:ce:0:0:0:0:0:254']],
]);

test('AI private grants permit only exact RFC1918 loopback or ULA addresses', function (string $ip): void {
    $policy = new AiNetworkPolicy(new BoundedAiDnsResolver(fn (): array => [$ip]), [
        'mode' => 'operator-private', 'private_grants' => [aiNetworkPolicyGrant(ips: [$ip])],
    ], 'revision-key');
    $result = $policy->inspect(aiNetworkPolicyConnection('http://models.internal:11434'), 'http://models.internal:11434/api/chat', aiNetworkPolicyDeadline());

    expect($result['ips'])->toBe([$ip])
        ->and($result['curl'][CURLOPT_PROTOCOLS])->toBe(CURLPROTO_HTTP)
        ->and($result['curl'][CURLOPT_RESOLVE])->toBe(['models.internal:11434:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)]);
})->with(['10.0.0.7', '172.16.0.7', '192.168.0.7', '127.0.0.1', '::1', 'fd12::7']);

test('AI default localhost Ollama requires and pins an exact loopback route grant', function (): void {
    $called = false;
    $dns = new BoundedAiDnsResolver(function () use (&$called): array {
        $called = true;

        return [];
    });
    $connection = new ResolvedAgentCopilotConnection(7, 'account', str_repeat('a', 64), 'ollama', 'support-model', '', '', '');
    $wireUrl = 'http://localhost:11434/api/chat';
    $grant = aiNetworkPolicyGrant('http://localhost:11434', ['127.0.0.1', '::1']);

    foreach ([
        ['mode' => 'public-only', 'private_grants' => [$grant]],
        ['mode' => 'operator-private', 'private_grants' => []],
        ['mode' => 'operator-private', 'private_grants' => [[...$grant, 'account_id' => 8]]],
        ['mode' => 'operator-private', 'private_grants' => [[...$grant, 'source' => 'installation']]],
        ['mode' => 'operator-private', 'private_grants' => [[...$grant, 'origin' => 'http://localhost:11435']]],
        ['mode' => 'operator-private', 'private_grants' => [[...$grant, 'ips' => ['127.0.0.1']]]],
    ] as $configuration) {
        $policy = new AiNetworkPolicy($dns, $configuration, 'revision-key');
        expect(aiNetworkPolicyReason(fn () => $policy->inspect($connection, $wireUrl, aiNetworkPolicyDeadline())))
            ->toBe('destination_denied', 'Resolving localhost must not widen private-grant authority or skip either loopback address.');
    }

    $policy = new AiNetworkPolicy($dns, ['mode' => 'operator-private', 'private_grants' => [$grant]], 'revision-key');
    $result = null;
    $reason = aiNetworkPolicyReason(function () use ($policy, $connection, $wireUrl, &$result): void {
        $result = $policy->inspect($connection, $wireUrl, aiNetworkPolicyDeadline());
    });
    expect($reason)->toBeNull('The documented exact localhost grant must permit the default Ollama route without DNS.')
        ->and($result['ips'])->toBe(['127.0.0.1', '::1'])
        ->and($result['curl'][CURLOPT_RESOLVE])->toBe(['localhost:11434:127.0.0.1,[::1]'])
        ->and($called)->toBeFalse();
});

test('AI private grants cannot authorize metadata link local mapped or reserved ranges', function (string $ip): void {
    expect(aiNetworkPolicyReason(fn () => new AiNetworkPolicy(new BoundedAiDnsResolver(fn (): array => [$ip]), [
        'mode' => 'operator-private', 'private_grants' => [aiNetworkPolicyGrant(ips: [$ip])],
    ], 'revision-key')))->toBe('network_policy_invalid', 'Even an explicit operator grant cannot authorize metadata, link-local, or ambiguous address space.');
})->with(['169.254.169.254', '100.100.100.200', 'fd00:ec2::254', 'fd00:ec2:0:0:0:0:0:254', 'fd20:ce::254', 'fd20:ce:0:0:0:0:0:254', 'fe80::1', '::ffff:127.0.0.1', '::ffff:10.0.0.7', '0.0.0.0', '192.0.2.1', '4000::1', '8.8.8.8']);

test('AI literal URLs use their actual address without consulting DNS', function (): void {
    $called = false;
    $dns = new BoundedAiDnsResolver(function () use (&$called): array {
        $called = true;

        return ['8.8.8.8'];
    });
    $policy = new AiNetworkPolicy($dns, [], 'revision-key');
    expect(aiNetworkPolicyReason(fn () => $policy->inspect(aiNetworkPolicyConnection('https://127.0.0.1/v1'), 'https://127.0.0.1/v1/models', aiNetworkPolicyDeadline())))
        ->toBe('destination_denied', 'A resolver must never substitute a public classification for a private IP-literal wire URL.')
        ->and($called)->toBeFalse();

    $public = $policy->inspect(aiNetworkPolicyConnection('https://[2001:4860:4860::8888]/v1'), 'https://[2001:4860:4860::8888]/v1/models', aiNetworkPolicyDeadline());
    expect($public['origin'])->toBe('https://[2001:4860:4860::8888]')
        ->and($public['curl'])->not->toHaveKey(CURLOPT_RESOLVE)
        ->and($called)->toBeFalse();
});

test('AI policy rejects expired and DNS overrun deadlines', function (): void {
    $dns = new class implements AiDnsResolver
    {
        public bool $called = false;

        public function resolve(string $host, float $deadline): array
        {
            $this->called = true;
            usleep(20_000);

            return ['8.8.8.8'];
        }
    };
    $policy = new AiNetworkPolicy($dns, [], 'revision-key');
    expect(aiNetworkPolicyReason(fn () => $policy->inspect(aiNetworkPolicyConnection(), 'https://models.example.test/v1/models', hrtime(true) / 1_000_000_000 - 1)))
        ->toBe('dns_deadline')->and($dns->called)->toBeFalse();
    expect(aiNetworkPolicyReason(fn () => $policy->inspect(aiNetworkPolicyConnection(), 'https://models.example.test/v1/models', hrtime(true) / 1_000_000_000 + 0.005)))
        ->toBe('dns_deadline', 'An answer arriving after the absolute deadline must never authorize transmission.');
});

test('AI policy revisions canonicalize equivalent grants and redact private topology', function (): void {
    $dns = new BoundedAiDnsResolver(fn (): array => ['10.0.0.7']);
    $grants = [aiNetworkPolicyGrant(ips: ['fd12::7', '10.0.0.7']), aiNetworkPolicyGrant('https://other.internal', ['127.0.0.1'])];
    $first = new AiNetworkPolicy($dns, ['mode' => 'operator-private', 'private_grants' => $grants], 'revision-key');
    $equivalent = new AiNetworkPolicy($dns, ['private_grants' => json_encode([
        aiNetworkPolicyGrant('https://other.internal:443/', ['127.0.0.1']),
        aiNetworkPolicyGrant(ips: ['10.0.0.7', 'fd12:0:0:0:0:0:0:7', '10.0.0.7']),
    ]), 'mode' => 'operator-private'], 'revision-key');
    $different = new AiNetworkPolicy($dns, ['mode' => 'operator-private', 'private_grants' => [aiNetworkPolicyGrant(ips: ['10.0.0.8'])]], 'revision-key');
    expect($first->revision())->toBe($equivalent->revision())
        ->and($first->revision())->not->toBe($different->revision())
        ->and($first->revision())->not->toBe((new AiNetworkPolicy($dns, ['mode' => 'operator-private', 'private_grants' => $grants], 'different-key'))->revision());
    ob_start();
    var_dump($first);
    $debug = ob_get_clean();
    expect($debug)->not->toContain('models.internal', 'other.internal', '10.0.0.7', 'fd12::7', 'revision-key')
        ->and(fn () => serialize($first))->toThrow(LogicException::class);
});

test('AI malformed grants fail closed', function (mixed $grants): void {
    expect(aiNetworkPolicyReason(fn () => new AiNetworkPolicy(new BoundedAiDnsResolver(fn (): array => ['8.8.8.8']), [
        'mode' => 'operator-private', 'private_grants' => $grants,
    ], 'revision-key')))->toBe('network_policy_invalid');
})->with([
    'malformed JSON' => ['{not json'],
    'JSON object instead of list' => ['{}'],
    'object rather than list' => [['grant' => aiNetworkPolicyGrant()]],
    'extra field' => [[aiNetworkPolicyGrant() + ['unexpected' => true]]],
    'string account' => [[[...aiNetworkPolicyGrant(), 'account_id' => '7']]],
    'zero account' => [[[...aiNetworkPolicyGrant(), 'account_id' => 0]]],
    'unknown source' => [[[...aiNetworkPolicyGrant(), 'source' => 'tenant-selected']]],
    'wildcard origin' => [[aiNetworkPolicyGrant('http://*.internal:11434')]],
    'path in origin' => [[aiNetworkPolicyGrant('http://models.internal:11434/v1')]],
    'empty addresses' => [[aiNetworkPolicyGrant(ips: [])]],
    'CIDR instead of exact IP' => [[aiNetworkPolicyGrant(ips: ['10.0.0.0/8'])]],
]);

test('AI policy revisions bind provider default routes without depending on route order', function (): void {
    // Separate PHP processes let this immutable route fixture vary without
    // changing repository files or invoking any provider or DNS operation.
    $revision = function (array $routes): string {
        $script = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'
            .'class AiPolicyRouteFixture { public const DEFAULTS = '.var_export($routes, true).'; }'
            .'class_alias(AiPolicyRouteFixture::class, "App\\\\Support\\\\Ai\\\\Network\\\\AiProviderRoutes");'
            .'$dns = new class implements App\\Support\\Ai\\Network\\AiDnsResolver { public function resolve(string $host, float $deadline): array { return []; } };'
            .'echo (new App\\Support\\Ai\\Network\\AiNetworkPolicy($dns, [], "revision-key"))->revision();';
        $process = new Process([PHP_BINARY, '-r', $script]);
        $process->setTimeout(2);
        $process->run();
        expect($process->isSuccessful())->toBeTrue('The isolated route fixture must run before its revision comparison.');

        return trim($process->getOutput());
    };
    $routes = AiProviderRoutes::DEFAULTS;
    $original = $revision($routes);
    expect($revision(array_reverse($routes, true)))->toBe($original);
    $routes['openai'] = 'https://another-provider.example.test/v1';
    expect($revision($routes))->not->toBe($original, 'Changing a default provider route must invalidate previous policy receipts.');
});

test('AI policy errors redact topology from argument traces', function (): void {
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        try {
            new AiNetworkPolicy(new BoundedAiDnsResolver(fn (): array => []), [
                'mode' => 'operator-private', 'private_grants' => [aiNetworkPolicyGrant('http://private-topology-marker.internal', ['169.254.169.254'])],
            ], 'private-revision-marker');
            $exception = null;
        } catch (AiNetworkException $error) {
            $exception = $error;
        }
        expect($exception)->not->toBeNull();
        ob_start();
        var_dump($exception->getTrace());
        $trace = ob_get_clean();
        expect($trace)->not->toContain('private-topology-marker', 'private-revision-marker', '169.254.169.254');
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
});
