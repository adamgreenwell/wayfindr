<?php

use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\BoundedAiDnsResolver;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

function boundedAiDnsReason(Closure $operation): ?string
{
    try {
        $operation();

        return null;
    } catch (AiNetworkException $exception) {
        return $exception->reason;
    }
}

test('bounded AI DNS normalizes injected A AAAA answers without live DNS', function (): void {
    $resolver = new BoundedAiDnsResolver(function (string $host, float $deadline): array {
        expect($host)->toBe('models.example.test')
            ->and($deadline)->toBeLessThanOrEqual(hrtime(true) / 1_000_000_000 + 5.0);

        return ['8.8.8.8', '2001:4860:4860:0:0:0:0:8888', '8.8.8.8'];
    });
    expect($resolver->resolve('models.example.test', hrtime(true) / 1_000_000_000 + 20))
        ->toBe(['8.8.8.8', '2001:4860:4860::8888']);
});

test('bounded AI DNS never starts a lookup after the deadline', function (): void {
    $called = false;
    $resolver = new BoundedAiDnsResolver(function () use (&$called): array {
        $called = true;

        return ['8.8.8.8'];
    });
    expect(boundedAiDnsReason(fn () => $resolver->resolve('models.example.test', hrtime(true) / 1_000_000_000 - 1)))
        ->toBe('dns_deadline')->and($called)->toBeFalse();
});

test('bounded AI DNS rejects an injected runner that returns after its deadline', function (): void {
    $resolver = new BoundedAiDnsResolver(function (): array {
        usleep(20_000);

        return ['8.8.8.8'];
    });
    expect(boundedAiDnsReason(fn () => $resolver->resolve('models.example.test', hrtime(true) / 1_000_000_000 + 0.005)))
        ->toBe('dns_deadline', 'Late DNS results must not escape the absolute request deadline.');
});

test('bounded AI DNS stops a blocked subprocess at the absolute deadline', function (): void {
    // This child only sleeps. It deliberately performs no DNS or network call.
    $process = new Process([PHP_BINARY, '-n', '-r', 'usleep(2000000); echo "[\"8.8.8.8\"]";']);
    $resolver = new BoundedAiDnsResolver(fn (): Process => $process);
    $started = hrtime(true) / 1_000_000_000;
    expect(boundedAiDnsReason(fn () => $resolver->resolve('models.example.test', $started + 0.08)))
        ->toBe('dns_deadline', 'The DNS child must be interrupted rather than allowed to outlive the request deadline.')
        ->and($process->isRunning())->toBeFalse('A timed-out DNS child must not remain running.')
        ->and(hrtime(true) / 1_000_000_000 - $started)->toBeLessThan(0.8);
});

test('bounded AI DNS accepts bounded child JSON and excludes raw child failures', function (): void {
    $success = new BoundedAiDnsResolver(fn (): Process => new Process([PHP_BINARY, '-n', '-r', 'echo "[\"8.8.8.8\"]";']));
    expect($success->resolve('models.example.test', hrtime(true) / 1_000_000_000 + 2))->toBe(['8.8.8.8']);
    $failed = new BoundedAiDnsResolver(fn (): Process => new Process([PHP_BINARY, '-n', '-r', 'fwrite(STDERR, "sensitive-dns-marker"); exit(1);']));
    try {
        $failed->resolve('models.example.test', hrtime(true) / 1_000_000_000 + 2);
        $exception = null;
    } catch (AiNetworkException $error) {
        $exception = $error;
    }
    expect($exception)->not->toBeNull()
        ->and($exception->reason)->toBe('dns_unavailable')
        ->and($exception->getMessage())->not->toContain('sensitive-dns-marker')
        ->and($exception->getPrevious())->toBeNull();
});

test('bounded AI DNS rejects excessive child output', function (): void {
    $process = new Process([PHP_BINARY, '-n', '-r', 'echo str_repeat(" ", 20000)."[\"8.8.8.8\"]";']);
    $resolver = new BoundedAiDnsResolver(fn (): Process => $process);
    expect(boundedAiDnsReason(fn () => $resolver->resolve('models.example.test', hrtime(true) / 1_000_000_000 + 2)))
        ->toBe('dns_unavailable')->and($process->isRunning())->toBeFalse();
});

test('bounded AI DNS errors redact subprocess arguments from traces', function (): void {
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        // The extra argv value is a topology marker; this child only exits.
        $process = new Process([PHP_BINARY, '-n', '-r', 'exit(1);', 'private-dns-topology-marker']);
        $resolver = new BoundedAiDnsResolver(fn (): Process => $process);
        try {
            $resolver->resolve('private-dns-topology-marker.internal', hrtime(true) / 1_000_000_000 + 2);
            $exception = null;
        } catch (AiNetworkException $error) {
            $exception = $error;
        }
        expect($exception)->not->toBeNull();
        ob_start();
        var_dump($exception->getTrace());
        $trace = ob_get_clean();
        expect($trace)->not->toContain('private-dns-topology-marker', 'exit(1)');
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
});

test('bounded AI DNS rejects malformed answer sets', function (array $answers, string $reason): void {
    $resolver = new BoundedAiDnsResolver(fn (): array => $answers);
    expect(boundedAiDnsReason(fn () => $resolver->resolve('models.example.test', hrtime(true) / 1_000_000_000 + 2)))->toBe($reason);
})->with([
    'empty answers' => [[], 'dns_unavailable'],
    'hostname as answer' => [['internal.example.test'], 'dns_invalid_answer'],
    'malformed IP' => [['127.1'], 'dns_invalid_answer'],
    'nonstring answer' => [[17], 'dns_invalid_answer'],
    'too many answers' => [array_fill(0, 65, '8.8.8.8'), 'dns_invalid_answer'],
    'associative answers' => [['ip' => '8.8.8.8'], 'dns_invalid_answer'],
]);

test('bounded AI DNS handles IP literals without spawning a lookup', function (): void {
    $called = false;
    $resolver = new BoundedAiDnsResolver(function () use (&$called): array {
        $called = true;

        return [];
    });
    expect($resolver->resolve('127.0.0.1', hrtime(true) / 1_000_000_000 + 2))->toBe(['127.0.0.1'])
        ->and($resolver->resolve('2001:4860:4860:0:0:0:0:8888', hrtime(true) / 1_000_000_000 + 2))->toBe(['2001:4860:4860::8888'])
        ->and($called)->toBeFalse();
});

test('bounded AI DNS resolves exact localhost to loopback without DNS or hosts file dependence', function (): void {
    $calls = [];
    $resolver = new BoundedAiDnsResolver(function (string $host) use (&$calls): array {
        $calls[] = $host;

        return [];
    });

    foreach (['localhost', 'LOCALHOST'] as $host) {
        try {
            $result = $resolver->resolve($host, hrtime(true) / 1_000_000_000 + 2);
        } catch (AiNetworkException $exception) {
            $result = $exception->reason;
        }
        expect($result)->toBe(['127.0.0.1', '::1'], 'Exact localhost must work without a DNS record or hosts-file lookup.');
    }
    expect($calls)->toBe([], 'Exact localhost must not invoke the DNS subprocess.');

    foreach (['localhost.example.test', 'localhost.localdomain', 'sub.localhost'] as $alias) {
        expect(boundedAiDnsReason(fn () => $resolver->resolve($alias, hrtime(true) / 1_000_000_000 + 2)))
            ->toBe('dns_unavailable', 'The exact localhost exception must not authorize aliases.');
    }
    expect($calls)->toBe(['localhost.example.test', 'localhost.localdomain', 'sub.localhost']);
});

test('bounded AI DNS requires a current deadline even for exact localhost', function (): void {
    $resolver = new BoundedAiDnsResolver(fn (): array => []);
    expect(boundedAiDnsReason(fn () => $resolver->resolve('localhost', hrtime(true) / 1_000_000_000 - 1)))
        ->toBe('dns_deadline', 'Loopback handling must not bypass the absolute request deadline.');
});

test('bounded AI DNS selects the discovered CLI instead of the current web SAPI executable', function (): void {
    $finder = new class extends PhpExecutableFinder
    {
        public array $arguments = [];

        public function find(bool $includeArgs = true): string|false
        {
            $this->arguments[] = $includeArgs;

            return '/trusted/php-cli';
        }
    };
    $selected = null;
    $resolver = new BoundedAiDnsResolver(function (string $host, float $deadline, ?string $phpCli = null) use (&$selected): array {
        $selected = $phpCli;

        return ['8.8.8.8'];
    }, $finder);
    expect($resolver->resolve('models.example.test', hrtime(true) / 1_000_000_000 + 2))->toBe(['8.8.8.8'])
        ->and($selected)->toBe('/trusted/php-cli', 'DNS must use the discovered CLI, not a current FPM or FrankenPHP executable.')
        ->and($finder->arguments)->toBe([false], 'The executable must be a single argv entry without shell arguments.');
});

test('bounded AI DNS fails closed when a PHP CLI cannot be found', function (): void {
    $finder = new class extends PhpExecutableFinder
    {
        public function find(bool $includeArgs = true): string|false
        {
            return false;
        }
    };
    $called = false;
    $resolver = new BoundedAiDnsResolver(function () use (&$called): array {
        $called = true;

        return ['8.8.8.8'];
    }, $finder);
    expect(boundedAiDnsReason(fn () => $resolver->resolve('models.example.test', hrtime(true) / 1_000_000_000 + 2)))
        ->toBe('dns_unavailable', 'Missing PHP CLI must not fall back to the current web SAPI binary.')
        ->and($called)->toBeFalse();
});

test('bounded AI DNS production subprocess argv uses the selected CLI', function (): void {
    $resolver = new BoundedAiDnsResolver;
    $factory = (new ReflectionProperty(BoundedAiDnsResolver::class, 'runner'))->getValue($resolver);
    // Build the actual production Process without starting its DNS script.
    $process = $factory('models.example.test', hrtime(true) / 1_000_000_000 + 2, '/trusted/php-cli');
    expect($process)->toBeInstanceOf(Process::class)
        ->and(str_contains($process->getCommandLine(), '/trusted/php-cli'))->toBeTrue('The production DNS argv must use the selected PHP CLI.')
        ->and(str_contains($process->getCommandLine(), PHP_BINARY))->toBeFalse('A web SAPI executable must never replace the selected CLI in production argv.');
});
