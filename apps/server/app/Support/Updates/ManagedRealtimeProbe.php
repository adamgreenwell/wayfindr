<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Support\WidgetRealtimeConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Uri;
use Pusher\Pusher;
use Ratchet\RFC6455\Handshake\ClientNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use RuntimeException;
use Throwable;

/**
 * Prove the configured browser transport carries one authenticated private event.
 *
 * This creates no application records, sessions, or queued jobs. Signing inside
 * the CLI avoids opening PHP intake while held; user-session authorization at
 * /broadcasting/auth remains a separate acceptance check after the update.
 */
class ManagedRealtimeProbe
{
    private const FAILURE = 'managed_apply_realtime_unverified';

    private const HEADER_LIMIT = 8192;

    private const MESSAGE_LIMIT = 8192;

    private const TOTAL_LIMIT = 65536;

    public function verify(): void
    {
        $connection = null;
        $pending = null;
        $loop = Factory::create();
        $deadline = hrtime(true) / 1e9 + $this->budget();
        $passed = false;
        $failed = false;
        $finish = function (bool $success) use (&$passed, &$failed, $loop): void {
            if (! $passed && ! $failed) {
                $passed = $success;
                $failed = ! $success;
            }
            $loop->stop();
        };
        $timer = $loop->addTimer($this->remaining($deadline), fn () => $finish(false));

        try {
            [$public, $publisher] = $this->configuration($deadline);
            $scheme = $public['scheme'] === 'https' ? 'wss' : 'ws';
            $authority = $public['host'].':'.$public['port'];
            $uri = new Uri($scheme.'://'.$authority.'/app/'.rawurlencode($public['app_key']).'?protocol=7&client=wayfindr-updater&version=1');
            $negotiator = new ClientNegotiator(new HttpFactory);
            $request = $negotiator->generateRequest($uri)
                ->withHeader('Sec-WebSocket-Key', base64_encode(random_bytes(16)))
                ->withHeader('Origin', $this->origin());
            $channel = 'private-wayfindr-update-'.bin2hex(random_bytes(24));
            $event = 'wayfindr.update.probe';
            $nonce = bin2hex(random_bytes(32));
            $options = [
                'timeout' => $this->remaining($deadline),
                'tls' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => trim($public['host'], '[]')],
            ];
            $pending = $this->connect(($scheme === 'wss' ? 'tls' : 'tcp').'://'.$authority, $options, $loop);
            $pending->then(function (ConnectionInterface $stream) use (&$connection, $request, $negotiator, $channel, $event, $nonce, $publisher, $deadline, $finish): void {
                $connection = $stream;
                $headers = '';
                $upgraded = false;
                $stage = 'connection';
                $total = 0;
                $messages = 0;
                $buffer = null;
                $buffer = new MessageBuffer(new CloseFrameChecker, function (MessageInterface $message) use (&$stage, &$messages, &$buffer, $channel, $event, $nonce, $publisher, $deadline, $finish): void {
                    try {
                        $this->remaining($deadline);
                        if (++$messages > 16 || $message->isBinary()) {
                            throw new RuntimeException;
                        }
                        $value = json_decode($message->getPayload(), true, 16, JSON_THROW_ON_ERROR);
                        if (! is_array($value) || ! is_string($value['event'] ?? null)) {
                            throw new RuntimeException;
                        }
                        if ($value['event'] === 'pusher:ping') {
                            $buffer->sendMessage('{"event":"pusher:pong","data":{}}');

                            return;
                        }
                        if ($stage === 'connection' && $value['event'] === 'pusher:connection_established') {
                            $data = $value['data'] ?? null;
                            $data = is_string($data) ? json_decode($data, true, 8, JSON_THROW_ON_ERROR) : $data;
                            if (! is_array($data) || ! is_string($data['socket_id'] ?? null)
                                || preg_match('/^[0-9]{1,20}\.[0-9]{1,20}$/D', $data['socket_id']) !== 1) {
                                throw new RuntimeException;
                            }
                            $authorization = json_decode($publisher->authorizeChannel($channel, $data['socket_id']), true, 8, JSON_THROW_ON_ERROR);
                            $stage = 'subscription';
                            $buffer->sendMessage(json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => $authorization['auth']]], JSON_THROW_ON_ERROR));

                            return;
                        }
                        if ($stage === 'subscription' && $value['event'] === 'pusher_internal:subscription_succeeded' && ($value['channel'] ?? null) === $channel) {
                            $stage = 'delivery';
                            $this->publish($publisher, $channel, $event, ['nonce' => $nonce], $deadline);

                            return;
                        }
                        $data = $value['data'] ?? null;
                        $data = is_string($data) ? json_decode($data, true, 8, JSON_THROW_ON_ERROR) : $data;
                        if ($stage !== 'delivery' || $value['event'] !== $event || ($value['channel'] ?? null) !== $channel
                            || ! is_array($data) || array_keys($data) !== ['nonce'] || ! is_string($data['nonce']) || ! hash_equals($nonce, $data['nonce'])) {
                            throw new RuntimeException;
                        }
                        $stage = 'complete';
                        $finish(true);
                    } catch (Throwable) {
                        $finish(false);
                    }
                }, function ($frame) use (&$buffer, $finish): void {
                    if ($frame->getOpcode() === Frame::OP_PING) {
                        $buffer->sendFrame(new Frame($frame->getPayload(), true, Frame::OP_PONG));
                    } elseif ($frame->getOpcode() !== Frame::OP_PONG) {
                        $finish(false);
                    }
                }, false, null, self::MESSAGE_LIMIT, self::MESSAGE_LIMIT, fn (string $bytes) => $stream->write($bytes));
                $stream->on('data', function (string $bytes) use (&$headers, &$upgraded, &$total, $request, $negotiator, $buffer, $deadline, $finish): void {
                    try {
                        $this->remaining($deadline);
                        $total += strlen($bytes);
                        if ($total > self::TOTAL_LIMIT) {
                            throw new RuntimeException;
                        }
                        if (! $upgraded) {
                            $headers .= $bytes;
                            $end = strpos($headers, "\r\n\r\n");
                            if (($end === false && strlen($headers) > self::HEADER_LIMIT) || ($end !== false && $end + 4 > self::HEADER_LIMIT)) {
                                throw new RuntimeException;
                            }
                            if ($end === false) {
                                return;
                            }
                            $response = Message::parseResponse(substr($headers, 0, $end + 4));
                            if (! $negotiator->validateResponse($request, $response) || $response->getStatusCode() !== 101
                                || count($response->getHeader('Sec-WebSocket-Accept')) !== 1 || $response->hasHeader('Sec-WebSocket-Extensions')) {
                                throw new RuntimeException;
                            }
                            $bytes = substr($headers, $end + 4);
                            $headers = '';
                            $upgraded = true;
                        }
                        if ($bytes !== '') {
                            $buffer->onData($bytes);
                        }
                    } catch (Throwable) {
                        $finish(false);
                    }
                });
                $stream->on('error', fn () => $finish(false));
                $stream->on('close', fn () => $finish(false));
                $stream->write(Message::toString($request));
            }, fn () => $finish(false));
            if (! $passed && ! $failed) {
                $loop->run();
            }
            if (! $passed || $failed || hrtime(true) / 1e9 >= $deadline) {
                throw new RuntimeException;
            }
        } catch (Throwable) {
            throw new RuntimeException(self::FAILURE);
        } finally {
            $loop->cancelTimer($timer);
            $pending?->cancel();
            $connection?->close();
            $loop->stop();
        }
    }

    protected function budget(): float
    {
        return 20.0;
    }

    protected function connect(string $address, array $options, LoopInterface $loop): PromiseInterface
    {
        return (new Connector($options, $loop))->connect($address);
    }

    protected function publish(Pusher $publisher, string $channel, string $event, array $data, float $deadline): void
    {
        // Pusher passes its own timeout on each request, ahead of the Guzzle
        // client's default. Rebuild at publication time with the remaining
        // absolute budget so a slow handshake cannot buy a fresh HTTP timeout.
        [, $publisher] = $this->configuration($deadline);
        $publisher->trigger($channel, $event, $data);
        $this->remaining($deadline);
    }

    protected function boundedCurlAvailable(): bool
    {
        // The stream fallback can block in getaddrinfo beyond its timeout. Use
        // cURL with an asynchronous resolver or refuse before network activity.
        return function_exists('curl_version') && defined('CURL_VERSION_ASYNCHDNS')
            && (curl_version()['features'] & CURL_VERSION_ASYNCHDNS) !== 0;
    }

    private function remaining(float $deadline): float
    {
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new RuntimeException(self::FAILURE);
        }

        return $remaining;
    }

    private function configuration(float $deadline): array
    {
        $public = WidgetRealtimeConfig::public();
        $config = config('broadcasting.connections.reverb');
        if ($public === null || ! is_array($config) || ! is_array($config['options'] ?? null) || ! $this->boundedCurlAvailable()) {
            throw new RuntimeException;
        }
        foreach (['key', 'secret', 'app_id'] as $key) {
            if (! is_string($config[$key] ?? null) || preg_match('/^[A-Za-z0-9_.-]{1,256}$/D', $config[$key]) !== 1) {
                throw new RuntimeException;
            }
        }
        $options = $config['options'];
        foreach ([[$public['host'], $public['port'], $public['scheme']], [$options['host'] ?? null, $options['port'] ?? null, $options['scheme'] ?? null]] as [$host, $port, $scheme]) {
            if (! is_string($host) || strlen($host) > 253 || ! $this->host($host)
                || (! is_string($port) && ! is_int($port)) || preg_match('/^[1-9][0-9]{0,4}$/D', (string) $port) !== 1 || (int) $port > 65535
                || ! in_array($scheme, ['http', 'https'], true)) {
                throw new RuntimeException;
            }
        }
        $timeout = $this->remaining($deadline);
        $publisher = new Pusher($config['key'], $config['secret'], $config['app_id'], [
            'host' => $options['host'], 'port' => (int) $options['port'], 'scheme' => $options['scheme'], 'useTLS' => $options['scheme'] === 'https',
            'timeout' => $timeout,
        ], new Client([
            'handler' => HandlerStack::create(new CurlHandler),
            'timeout' => $timeout, 'connect_timeout' => min(5.0, $timeout), 'allow_redirects' => false, 'verify' => true, 'proxy' => '',
            'progress' => function ($downloadTotal, $downloaded) use ($deadline): void {
                $this->remaining($deadline);
                if ($downloadTotal > self::TOTAL_LIMIT || $downloaded > self::TOTAL_LIMIT) {
                    throw new RuntimeException(self::FAILURE);
                }
            },
        ]));

        return [$public, $publisher];
    }

    private function host(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D', $host) === 1;
    }

    private function origin(): string
    {
        $url = config('app.url');
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || ! $this->host($parts['host'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException;
        }

        return rtrim($url, '/');
    }
}
