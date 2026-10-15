<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use App\Support\Ai\ResolvedAgentCopilotConnection;
use Closure;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;
use Throwable;

/** One admitted, origin-bound request; no caller-controlled transport options. */
final readonly class AiHttpTransport
{
    private const TOTAL_SECONDS = 20.0;

    private const REQUEST_BYTES = 65_536;

    private const RESPONSE_BYTES = 262_144;

    /** The handler and clock are trusted test seams, never account input. */
    public function __construct(
        private AiNetworkPolicy $policy,
        private AiOutboundAdmission $admission,
        private ?Closure $terminalHandler = null,
        private ?Closure $monotonicClock = null,
    ) {}

    /** @param array<string, string|list<string>> $headers */
    public function send(
        #[SensitiveParameter] ResolvedAgentCopilotConnection $connection,
        string $purpose,
        string $attemptId,
        string $method,
        #[SensitiveParameter] string $url,
        #[SensitiveParameter] array $headers = [],
        #[SensitiveParameter] string $body = '',
    ): ResponseInterface {
        $deadline = $this->now() + self::TOTAL_SECONDS;

        try {
            // Budget/authority admission must happen before DNS or any I/O.
            $this->admission->assertAllowed($connection, $purpose, $attemptId);
            $this->remaining($deadline);

            $method = strtoupper($method);
            if (! in_array($method, ['GET', 'POST'], true)
                || strlen($body) > self::REQUEST_BYTES
                || ($method === 'GET' && $body !== '')) {
                throw new AiNetworkException('invalid_request');
            }

            if ($this->terminalHandler === null && ! function_exists('curl_exec')) {
                throw new AiNetworkException('transport_unavailable');
            }

            $destination = $this->policy->inspect($connection, $url, $deadline);
            $this->remaining($deadline);
            $request = new Request($method, $destination['url'], $headers, $body);
            $request = $this->bindRequest($request, $destination['origin']);
            $sink = $this->boundedSink($deadline);
            $remaining = $this->remaining($deadline);
            $scheme = $request->getUri()->getScheme();
            $options = [
                'allow_redirects' => false,
                'proxy' => '',
                'verify' => true,
                'http_errors' => false,
                'decode_content' => false,
                'stream' => false,
                'connect_timeout' => min(5.0, $remaining),
                'timeout' => $remaining,
                'sink' => $sink,
                'on_headers' => function (ResponseInterface $response) use ($deadline): void {
                    $this->checkResponse($response, $deadline);
                },
                'curl' => array_replace($destination['curl'], [
                    CURLOPT_PROTOCOLS => $scheme === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
                    CURLOPT_REDIR_PROTOCOLS => 0,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_NOPROXY => '*',
                    CURLOPT_PROXY => '',
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_FRESH_CONNECT => true,
                    CURLOPT_FORBID_REUSE => true,
                ]),
            ];

            // A new cURL-only terminal handler owns its connection pool. There
            // is no stream fallback, redirect/retry middleware or shared client.
            $handler = $this->terminalHandler ?? new CurlHandler;
            $response = $handler($request, $options)->wait();
            $this->checkResponse($response, $deadline);

            // Test handlers may ignore sink/on_headers. Consume their response
            // under the same cap, and return only a fully bounded local body.
            $bounded = Utils::streamFor('');
            $input = $response->getBody();
            if ($input->isSeekable()) {
                $input->rewind();
            }
            $bytes = 0;
            while (! $input->eof()) {
                $this->remaining($deadline);
                $chunk = $input->read(min(8_192, self::RESPONSE_BYTES - $bytes + 1));
                $bytes += strlen($chunk);
                if ($bytes > self::RESPONSE_BYTES) {
                    throw new AiNetworkException('response_too_large');
                }
                if ($chunk === '' && ! $input->eof()) {
                    throw new AiNetworkException('transport_failed');
                }
                $bounded->write($chunk);
            }
            $this->remaining($deadline);
            $bounded->rewind();

            return new Response(
                $response->getStatusCode(), $response->getHeaders(), $bounded,
                $response->getProtocolVersion(),
            );
        } catch (Throwable $exception) {
            // Guzzle wraps header/sink failures with the request and previous
            // exception. Retain only our stable reason, never that object chain.
            $reason = 'transport_failed';
            for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
                if ($current instanceof AiNetworkException) {
                    $reason = $current->reason;
                    break;
                }
            }

            throw new AiNetworkException($reason);
        }
    }

    private function bindRequest(#[SensitiveParameter] Request $request, string $origin): Request
    {
        $uri = $request->getUri();
        $authority = $uri->getAuthority();
        if ($uri->getScheme().'://'.$authority !== $origin) {
            throw new AiNetworkException('origin_mismatch');
        }

        $hosts = $request->getHeader('Host');
        $allowedHosts = [strtolower($authority)];
        if ($uri->getPort() === null) {
            $allowedHosts[] = strtolower($authority).($uri->getScheme() === 'https' ? ':443' : ':80');
        }
        if (count($hosts) !== 1
            || ! in_array(strtolower(trim($hosts[0])), $allowedHosts, true)) {
            throw new AiNetworkException('origin_mismatch');
        }

        foreach (['Transfer-Encoding', 'Trailer', 'Upgrade', 'Proxy-Authorization', 'Proxy-Connection', 'Connection'] as $header) {
            if ($request->hasHeader($header)) {
                throw new AiNetworkException('invalid_request');
            }
        }
        if ($request->hasHeader('Content-Length')
            && $request->getHeaderLine('Content-Length') !== (string) $request->getBody()->getSize()) {
            throw new AiNetworkException('invalid_request');
        }
        if ($request->hasHeader('Content-Encoding') && strtolower($request->getHeaderLine('Content-Encoding')) !== 'identity') {
            throw new AiNetworkException('invalid_request');
        }

        return $request->withHeader('Host', $authority)->withHeader('Accept-Encoding', 'identity');
    }

    private function checkResponse(#[SensitiveParameter] ResponseInterface $response, float $deadline): void
    {
        $this->remaining($deadline);
        if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
            throw new AiNetworkException('redirect_denied');
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new AiNetworkException('http_error');
        }
        $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
        if ($encoding !== '' && $encoding !== 'identity') {
            throw new AiNetworkException('response_encoding');
        }
        if ($response->hasHeader('Content-Length')) {
            $length = $response->getHeaderLine('Content-Length');
            if (preg_match('/\A[0-9]+\z/', $length) !== 1 || (float) $length > self::RESPONSE_BYTES) {
                throw new AiNetworkException('response_too_large');
            }
        }
    }

    private function boundedSink(float $deadline): StreamInterface
    {
        return new class(Utils::streamFor(''), self::RESPONSE_BYTES, fn (): float => $this->remaining($deadline)) implements StreamInterface
        {
            use StreamDecoratorTrait;

            private int $written = 0;

            public function __construct(private StreamInterface $stream, private int $maximum, private Closure $remaining) {}

            public function write(#[SensitiveParameter] $string): int
            {
                ($this->remaining)();
                $this->written += strlen($string);
                if ($this->written > $this->maximum) {
                    throw new AiNetworkException('response_too_large');
                }

                return $this->stream->write($string);
            }
        };
    }

    private function remaining(float $deadline): float
    {
        $remaining = $deadline - $this->now();
        if ($remaining <= 0) {
            throw new AiNetworkException('deadline_exceeded');
        }

        return $remaining;
    }

    private function now(): float
    {
        return $this->monotonicClock === null ? hrtime(true) / 1e9 : ($this->monotonicClock)();
    }
}
