<?php

use App\Support\Updates\ManagedRealtimeProbe;
use GuzzleHttp\Psr7\Message;
use Pusher\Pusher;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Socket\Connection;

use function React\Promise\reject;
use function React\Promise\resolve;

/** Real socket pairs and RFC6455 frames; only DNS/TLS and publication are faked. */
class ManagedRealtimeProbeFixture extends ManagedRealtimeProbe
{
    public string $mode = 'success';

    public bool $boundedCurl = true;

    public array $connections = [];

    public array $published = [];

    public array $subscriptions = [];

    public array $sockets = [];

    private ?Connection $peer = null;

    private ?LoopInterface $loop = null;

    protected function budget(): float
    {
        return 0.15;
    }

    protected function boundedCurlAvailable(): bool
    {
        return $this->boundedCurl && parent::boundedCurlAvailable();
    }

    protected function connect(string $address, array $options, LoopInterface $loop): PromiseInterface
    {
        $this->connections[] = [$address, $options];
        $this->loop = $loop;
        if ($this->mode === 'connection_failure') {
            return reject(new RuntimeException('private-host-and-credential-not-public'));
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $client = new Connection($pair[0], $loop);
        $this->peer = new Connection($pair[1], $loop);
        $this->sockets = [$client, $this->peer];
        $headers = '';
        $upgraded = false;
        $buffer = new MessageBuffer(new CloseFrameChecker, function ($message): void {
            $value = json_decode($message->getPayload(), true, flags: JSON_THROW_ON_ERROR);
            $this->subscriptions[] = $value;
            $channel = $value['data']['channel'];
            $expected = 'public-test-key:'.hash_hmac('sha256', '123.456:'.$channel, 'private-test-secret');
            if ($value['event'] !== 'pusher:subscribe' || ! hash_equals($expected, $value['data']['auth']) || $this->mode === 'invalid_auth') {
                $this->send(['event' => 'pusher:error', 'data' => ['code' => 4009]]);

                return;
            }
            $this->send(['event' => 'pusher_internal:subscription_succeeded', 'channel' => $channel, 'data' => '{}']);
        });
        $this->peer->on('data', function (string $bytes) use (&$headers, &$upgraded, $buffer): void {
            if (! $upgraded) {
                $headers .= $bytes;
                $end = strpos($headers, "\r\n\r\n");
                if ($end === false) {
                    return;
                }
                $request = Message::parseRequest(substr($headers, 0, $end + 4));
                $accept = base64_encode(sha1($request->getHeaderLine('Sec-WebSocket-Key').'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                $status = $this->mode === 'http_only' ? '200 OK' : '101 Switching Protocols';
                $accept = $this->mode === 'invalid_accept' ? 'wrong' : $accept;
                $extra = $this->mode === 'oversized_header' ? 'X-Padding: '.str_repeat('a', 8192)."\r\n" : '';
                $this->peer->write("HTTP/1.1 $status\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n$extra\r\n");
                $upgraded = true;
                if ($this->mode === 'closed') {
                    $this->peer->close();

                    return;
                }
                if ($this->mode === 'malformed_json') {
                    $this->peer->write((new Frame('{broken'))->getContents());
                } elseif ($this->mode === 'oversized_frame') {
                    $this->peer->write((new Frame(str_repeat('a', 8193)))->getContents());
                } elseif ($this->mode === 'binary_frame') {
                    $this->peer->write((new Frame('{}', true, Frame::OP_BINARY))->getContents());
                } else {
                    $this->send(['event' => 'pusher:connection_established', 'data' => json_encode(['socket_id' => '123.456', 'activity_timeout' => 30])]);
                }

                return;
            }
            $buffer->onData($bytes);
        });

        return resolve($client);
    }

    protected function publish(Pusher $publisher, string $channel, string $event, array $data, float $deadline): void
    {
        $this->published[] = [$channel, $event, $data, $deadline];
        if ($this->mode === 'no_delivery') {
            return;
        }
        if ($this->mode === 'publish_failure') {
            throw new RuntimeException('private-http-signature-not-public');
        }
        if ($this->mode === 'wrong_channel') {
            $channel .= '-other';
        }
        if ($this->mode === 'wrong_nonce') {
            $data['nonce'] = str_repeat('0', 64);
        }
        $this->send(['event' => $event, 'channel' => $channel, 'data' => json_encode($data)]);
    }

    private function send(array $value): void
    {
        $this->loop->futureTick(fn () => $this->peer?->write((new Frame(json_encode($value, JSON_THROW_ON_ERROR)))->getContents()));
    }

    public function closeFixture(): void
    {
        foreach ($this->sockets as $socket) {
            $socket->close();
        }
    }
}

beforeEach(function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('app.url', 'https://support.example.test');
    config()->set('broadcasting.connections.reverb', [
        'key' => 'public-test-key', 'secret' => 'private-test-secret', 'app_id' => '12345',
        'options' => ['host' => 'internal-reverb', 'port' => 8080, 'scheme' => 'http', 'client_host' => 'browser.example.test', 'client_port' => 443, 'client_scheme' => 'https'],
    ]);
    $this->realtimeProbe = new ManagedRealtimeProbeFixture;
});

afterEach(fn () => $this->realtimeProbe->closeFixture());

test('realtime proof authenticates an ephemeral channel and requires delivery over the browser endpoint', function (): void {
    $this->realtimeProbe->verify();

    [$address, $options] = $this->realtimeProbe->connections[0];
    [$channel, $event, $payload] = $this->realtimeProbe->published[0];
    expect($address)->toBe('tls://browser.example.test:443')
        ->and($options['tls'])->toBe(['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => 'browser.example.test'])
        ->and($options['timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(0.15)
        ->and($channel)->toMatch('/^private-wayfindr-update-[a-f0-9]{48}$/D')
        ->and($event)->toBe('wayfindr.update.probe')
        ->and(array_keys($payload))->toBe(['nonce'])
        ->and($payload['nonce'])->toMatch('/^[a-f0-9]{64}$/D')
        ->and($this->realtimeProbe->subscriptions)->toHaveCount(1)
        ->and($this->realtimeProbe->sockets[0]->isReadable())->toBeFalse();
});

test('realtime probe uses the same client fallback as browsers and creates unique private channels', function (): void {
    config()->set('broadcasting.connections.reverb.options.client_host', null);
    config()->set('broadcasting.connections.reverb.options.client_port', null);
    config()->set('broadcasting.connections.reverb.options.client_scheme', null);
    $this->realtimeProbe->verify();
    $first = $this->realtimeProbe->published[0][0];
    $this->realtimeProbe->closeFixture();
    $this->realtimeProbe->verify();

    expect($this->realtimeProbe->connections[0][0])->toBe('tcp://internal-reverb:8080')
        ->and($this->realtimeProbe->published[1][0])->not->toBe($first);
});

test('publication uses the internal application endpoint and the remaining absolute request budget', function (): void {
    $configuration = new ReflectionMethod(ManagedRealtimeProbe::class, 'configuration');
    [, $publisher] = $configuration->invoke($this->realtimeProbe, hrtime(true) / 1e9 + 0.02);
    $settings = $publisher->getSettings();
    $client = (new ReflectionProperty(Pusher::class, 'client'))->getValue($publisher);

    // Pusher supplies a per-request timeout of its own. A Guzzle default alone
    // would silently leave publication using the SDK's fresh 30-second budget.
    expect($settings['timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(0.02)
        ->and($settings['host'])->toBe('internal-reverb')->and($settings['port'])->toBe(8080)
        ->and($settings['scheme'])->toBe('http')
        ->and($client->getConfig('allow_redirects'))->toBeFalse()
        ->and($client->getConfig('verify'))->toBeTrue()
        ->and($client->getConfig('proxy'))->toBe('');
    expect(fn () => $client->getConfig('progress')(65537, 1))->toThrow(RuntimeException::class, 'managed_apply_realtime_unverified');
});

test('a runtime without bounded cURL DNS refuses before opening the public connection', function (): void {
    $this->realtimeProbe->boundedCurl = false;
    expect(fn () => $this->realtimeProbe->verify())->toThrow(RuntimeException::class, 'managed_apply_realtime_unverified');
    expect($this->realtimeProbe->connections)->toBeEmpty()->and($this->realtimeProbe->published)->toBeEmpty();
});

test('healthy HTTP or a listener cannot replace authenticated realtime delivery', function (string $mode): void {
    $this->realtimeProbe->mode = $mode;
    $started = microtime(true);
    expect(fn () => $this->realtimeProbe->verify())->toThrow(RuntimeException::class, 'managed_apply_realtime_unverified');
    expect(microtime(true) - $started)->toBeLessThan(1.0);
    if ($this->realtimeProbe->sockets !== []) {
        expect($this->realtimeProbe->sockets[0]->isReadable())->toBeFalse();
    }
})->with(['http_only', 'invalid_accept', 'oversized_header', 'closed', 'malformed_json', 'oversized_frame', 'binary_frame', 'invalid_auth', 'wrong_channel', 'wrong_nonce', 'no_delivery', 'publish_failure', 'connection_failure']);

test('disabled or malformed realtime configuration refuses before opening a connection', function (string $key, mixed $value): void {
    config()->set($key, $value);
    expect(fn () => $this->realtimeProbe->verify())->toThrow(RuntimeException::class, 'managed_apply_realtime_unverified');
    expect($this->realtimeProbe->connections)->toBeEmpty()->and($this->realtimeProbe->published)->toBeEmpty();
})->with([
    ['broadcasting.default', 'null'],
    ['broadcasting.connections.reverb.secret', ''],
    ['broadcasting.connections.reverb.options.client_host', 'user@host.test'],
    ['broadcasting.connections.reverb.options.client_host', 'host.test/path'],
    ['broadcasting.connections.reverb.options.client_port', '0'],
    ['broadcasting.connections.reverb.options.client_port', '65536'],
    ['broadcasting.connections.reverb.options.client_scheme', 'ftp'],
    ['broadcasting.connections.reverb.options.host', "host.test\r\nInjected"],
    ['app.url', 'https://user@host.test'],
]);
