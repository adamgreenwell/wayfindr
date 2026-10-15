<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use Closure;
use SensitiveParameter;
use Symfony\Component\Process\Process;
use Throwable;

/** libc DNS can block; a disposable child gives it an enforceable deadline. */
final readonly class BoundedAiDnsResolver implements AiDnsResolver
{
    private const MAXIMUM_SECONDS = 5.0;

    private const MAXIMUM_OUTPUT_BYTES = 16_384;

    private const MAXIMUM_ADDRESSES = 64;

    /** @var Closure(string, float): (Process|array) */
    private Closure $runner;

    /**
     * The injected runner permits deterministic DNS and subprocess tests. The
     * production runner creates an argv-only process, never a shell command.
     *
     * @param  (Closure(string, float): (Process|array))|null  $runner
     */
    public function __construct(?Closure $runner = null)
    {
        $this->runner = $runner ?? static function (string $host): Process {
            $script = <<<'PHP'
            $answers = @dns_get_record($argv[1], DNS_A | DNS_AAAA);
            if (!is_array($answers)) { exit(1); }
            $ips = [];
            foreach ($answers as $answer) {
                $ip = $answer['ip'] ?? $answer['ipv6'] ?? null;
                if (is_string($ip)) { $ips[] = $ip; }
                if (count($ips) > 64) { exit(1); }
            }
            echo json_encode(array_values(array_unique($ips)), JSON_THROW_ON_ERROR);
            PHP;

            return new Process([PHP_BINARY, '-n', '-r', $script, $host]);
        };
    }

    public function resolve(#[SensitiveParameter] string $host, float $deadline): array
    {
        $now = self::time();
        if (! is_finite($deadline) || $deadline <= $now) {
            throw new AiNetworkException('dns_deadline');
        }
        $deadline = min($deadline, $now + self::MAXIMUM_SECONDS);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [inet_ntop(inet_pton($host))];
        }
        if ($host === '' || strlen($host) > 253 || preg_match('/\A[a-zA-Z0-9.-]+\z/', $host) !== 1) {
            throw new AiNetworkException('dns_unavailable');
        }

        try {
            $result = ($this->runner)($host, $deadline);
            $answers = $result instanceof Process ? $this->execute($result, $deadline) : $result;
            $this->beforeDeadline($deadline);

            if (! is_array($answers) || ! array_is_list($answers) || count($answers) > self::MAXIMUM_ADDRESSES) {
                throw new AiNetworkException('dns_invalid_answer');
            }
            if ($answers === []) {
                throw new AiNetworkException('dns_unavailable');
            }

            $ips = [];
            foreach ($answers as $ip) {
                if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    throw new AiNetworkException('dns_invalid_answer');
                }
                $ips[] = inet_ntop(inet_pton($ip));
            }

            return array_values(array_unique($ips));
        } catch (AiNetworkException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AiNetworkException('dns_unavailable');
        }
    }

    /** @return mixed Decoded child result, validated by resolve(). */
    private function execute(#[SensitiveParameter] Process $process, float $deadline): mixed
    {
        $this->beforeDeadline($deadline);
        $process->setTimeout(null);
        $process->start();

        try {
            while ($process->isRunning()) {
                $this->beforeDeadline($deadline);
                $this->boundedOutput($process);
                usleep((int) min(5_000, max(1, ($deadline - self::time()) * 1_000_000)));
            }
            $this->beforeDeadline($deadline);
            $this->boundedOutput($process);
            if (! $process->isSuccessful()) {
                throw new AiNetworkException('dns_unavailable');
            }

            return json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    private function boundedOutput(#[SensitiveParameter] Process $process): void
    {
        if (strlen($process->getOutput()) + strlen($process->getErrorOutput()) > self::MAXIMUM_OUTPUT_BYTES) {
            throw new AiNetworkException('dns_unavailable');
        }
    }

    private function beforeDeadline(float $deadline): void
    {
        if (self::time() >= $deadline) {
            throw new AiNetworkException('dns_deadline');
        }
    }

    private static function time(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
