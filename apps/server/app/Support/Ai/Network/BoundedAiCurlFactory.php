<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use Closure;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Handler\HeaderProcessor;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;
use Throwable;

/** One transfer, with bounded headers before Guzzle retains or parses them. */
final class BoundedAiCurlFactory implements CurlFactoryInterface
{
    private const HEADER_BYTES = 32_768;

    private const HEADER_LINES = 128;

    private CurlFactory $inner;

    private bool $created = false;

    public function __construct(private readonly Closure $checkDeadline)
    {
        $this->inner = new CurlFactory(0);
    }

    public function create(#[SensitiveParameter] RequestInterface $request, #[SensitiveParameter] array $options): EasyHandle
    {
        // CurlFactory::finish can retry even without retry middleware. Never
        // create another handle for this already admitted transfer.
        if ($this->created) {
            throw new AiNetworkException('transport_failed');
        }
        $this->created = true;

        try {
            $easy = $this->inner->create($request, $options);
            // Guzzle installs its callback after merging raw cURL options, so
            // replace it only after create() has finished configuring the handle.
            if (! curl_setopt($easy->handle, CURLOPT_HEADERFUNCTION, $this->headerFunction($easy))) {
                $this->inner->release($easy);
                throw new AiNetworkException('transport_failed');
            }

            return $easy;
        } catch (Throwable) {
            throw new AiNetworkException('transport_failed');
        }
    }

    public function release(#[SensitiveParameter] EasyHandle $easy): void
    {
        $this->inner->release($easy);
    }

    private function headerFunction(#[SensitiveParameter] EasyHandle $easy): Closure
    {
        $bytes = 0;
        $lines = 0;
        $startingResponse = false;
        $collectingTrailers = false;

        return function (#[SensitiveParameter] $handle, #[SensitiveParameter] string $line) use ($easy, &$bytes, &$lines, &$startingResponse, &$collectingTrailers): int {
            try {
                ($this->checkDeadline)();
                $bytes += strlen($line);
                $lines++;
                if ($bytes > self::HEADER_BYTES || $lines > self::HEADER_LINES) {
                    throw new AiNetworkException('response_headers_too_large');
                }

                $value = trim($line, " \n\r\t\0\x0B");
                if ($line === "\r\n" || $line === "\n" || $line === "\r" || $line === '') {
                    if (! $collectingTrailers) {
                        $startingResponse = true;
                        $easy->createResponse();
                        if (isset($easy->options['on_headers'])) {
                            ($easy->options['on_headers'])($easy->response);
                        }
                    }
                } elseif ($startingResponse || $collectingTrailers) {
                    if ($easy->response !== null && ! HeaderProcessor::isStatusLineCandidate($line)) {
                        // Trailer bytes still count, but are never retained.
                        $collectingTrailers = true;
                    } else {
                        $collectingTrailers = false;
                        $easy->headers = [$value];
                    }
                    $startingResponse = false;
                } else {
                    $easy->headers[] = $value;
                }

                return strlen($line);
            } catch (Throwable $exception) {
                // Keep only the stable reason, not provider header values or
                // the parser/handler exception and its request object chain.
                $easy->onHeadersException = new AiNetworkException(
                    $exception instanceof AiNetworkException ? $exception->reason : 'transport_failed',
                );

                return 0;
            }
        };
    }
}
