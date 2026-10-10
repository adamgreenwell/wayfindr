<?php

use App\Support\Attachments\Scanning\ClamAvScanner;

final class ClamdReadFailureStream
{
    public mixed $context;

    private int $reads = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        return $this->reads++ === 0 ? 'readable prefix' : false;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }
}

/** An isolated real socket peer, with no access to customer files. */
function withClamdProtocolPeer(array $options, Closure $exercise): mixed
{
    $directory = sys_get_temp_dir().'/wayfindr-clamd-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $socket = $directory.'/daemon.sock';
    $script = $directory.'/peer.php';
    $trace = $directory.'/trace.json';
    file_put_contents($script, <<<'PEER'
    <?php
    $options = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    $server = stream_socket_server('unix://'.$argv[1]);
    $client = stream_socket_accept($server, 5);
    if (!$client) { exit(2); }
    stream_set_timeout($client, 5);
    function exact($client, $length) {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = fread($client, $length - strlen($bytes));
            if ($part === false || $part === '') { return null; }
            $bytes .= $part;
        }
        return $bytes;
    }
    $command = ''; $body = ''; $finished = false;
    if (!($options['early'] ?? false)) {
        do {
            $byte = exact($client, 1);
            if ($byte === null) { break; }
            $command .= $byte;
        } while ($byte !== "\0");
        if ($command === "zINSTREAM\0") {
            while (true) {
                $header = exact($client, 4);
                if ($header === null) { break; }
                $length = unpack('N', $header)[1];
                if ($length === 0) { $finished = true; break; }
                $chunk = exact($client, $length);
                if ($chunk === null) { break; }
                $body .= $chunk;
            }
        }
    }
    file_put_contents($argv[3], json_encode([
        'command' => $command, 'finished' => $finished,
        'bytes' => strlen($body), 'sha256' => hash('sha256', $body),
    ], JSON_THROW_ON_ERROR));
    usleep($options['before_reply_us'] ?? 0);
    foreach ($options['parts'] ?? [] as $part) {
        @fwrite($client, base64_decode($part));
        usleep($options['between_parts_us'] ?? 0);
    }
    usleep($options['hold_open_us'] ?? 0);
    fclose($client); fclose($server);
    PEER);
    $process = proc_open([PHP_BINARY, $script, $socket, base64_encode(json_encode($options)), $trace], [
        0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/stdout', 'w'], 2 => ['file', $directory.'/stderr', 'w'],
    ], $pipes);
    expect($process)->not->toBeFalse();
    try {
        $until = hrtime(true) + 3_000_000_000;
        while (! file_exists($socket) && hrtime(true) < $until && proc_get_status($process)['running']) {
            usleep(10_000);
        }
        expect(file_exists($socket))->toBeTrue();

        return $exercise('unix://'.$socket, $trace, $directory);
    } finally {
        if (proc_get_status($process)['running']) {
            proc_terminate($process);
        }
        proc_close($process);
        foreach (glob($directory.'/*') as $temporary) {
            @unlink($temporary);
        }
        rmdir($directory);
    }
}

function clamdProtocolScan(string $socket, string $directory, string $bytes = 'clean bytes', int $timeout = 1): array
{
    $file = $directory.'/upload';
    file_put_contents($file, $bytes);
    $started = hrtime(true);
    $result = (new ClamAvScanner($socket, scanTimeoutSeconds: $timeout))->scan($file);

    return [$result, (hrtime(true) - $started) / 1_000_000_000];
}

test('clamav accepts clean only after transmitting the complete INSTREAM file', function (): void {
    $bytes = str_repeat('Complete clean bytes.', 7000);
    withClamdProtocolPeer(['parts' => [base64_encode("stream: OK\0")]], function (string $socket, string $trace, string $directory) use ($bytes): void {
        [$result] = clamdProtocolScan($socket, $directory, $bytes);
        $received = json_decode(file_get_contents($trace), true, flags: JSON_THROW_ON_ERROR);
        expect($result->isClean())->toBeTrue()
            ->and($received['command'])->toBe("zINSTREAM\0")
            ->and($received['finished'])->toBeTrue()
            ->and($received['bytes'])->toBe(strlen($bytes))
            ->and($received['sha256'])->toBe(hash('sha256', $bytes));
    });
});

test('clamav accepts a complete verdict fragmented across reads', function (): void {
    withClamdProtocolPeer(['parts' => array_map('base64_encode', ['stream:', ' OK', "\0"]), 'between_parts_us' => 20_000], function (string $socket, string $trace, string $directory): void {
        [$result] = clamdProtocolScan($socket, $directory);
        expect($result->isClean())->toBeTrue();
    });
});

test('clamav waits for EOF and rejects a later contradictory verdict', function (): void {
    withClamdProtocolPeer([
        'parts' => array_map('base64_encode', ["stream: OK\0", "stream: Eicar-Signature FOUND\0"]),
        'between_parts_us' => 20_000,
    ], function (string $socket, string $trace, string $directory): void {
        [$result] = clamdProtocolScan($socket, $directory);
        expect($result->isUnavailable())->toBeTrue();
    });
});

test('clamav rejects incomplete oversized and contradictory socket replies', function (string $response): void {
    withClamdProtocolPeer(['parts' => [base64_encode($response)]], function (string $socket, string $trace, string $directory): void {
        [$result] = clamdProtocolScan($socket, $directory);
        expect($result->isUnavailable())->toBeTrue();
    });
})->with([
    'empty EOF' => [''], 'unframed clean EOF' => ['stream: OK'], 'partial clean EOF' => ['stream: O'],
    'unframed infection' => ['stream: Eicar-Signature FOUND'], 'overflow' => [str_repeat('A', 4097)."\0"],
    'arbitrary OK suffix' => ["not a verdict OK\0"],
    'contradictory records' => ["stream: Eicar-Signature FOUND\0stream: OK\0"],
    'contradictory lines' => ["stream: Eicar-Signature FOUND\nstream: OK\0"],
    'extra after clean' => ["stream: OK\0stream: Eicar-Signature FOUND\0"],
    'trailing bytes' => ["stream: OK\0junk"], 'daemon error' => ["INSTREAM size limit exceeded ERROR\0"],
]);

test('clamav preserves early infection but rejects early clean', function (string $reply, bool $infected): void {
    withClamdProtocolPeer(['early' => true, 'parts' => [base64_encode($reply)]], function (string $socket, string $trace, string $directory) use ($infected): void {
        [$result] = clamdProtocolScan($socket, $directory, str_repeat('A', 4 * 1024 * 1024));
        expect($result->isInfected())->toBe($infected, $result->error ?? '')->and($result->isUnavailable())->toBe(! $infected);
        if ($infected) {
            expect($result->threat)->toBe('Win.Test.EICAR_HDB-1');
        }
    });
})->with(['early infection' => ["stream: Win.Test.EICAR_HDB-1 FOUND\0", true], 'early clean' => ["stream: OK\0", false]]);

test('clamav does not preserve malformed or contradictory infection after an interrupted send', function (string $reply): void {
    withClamdProtocolPeer(['early' => true, 'parts' => [base64_encode($reply)]], function (string $socket, string $trace, string $directory): void {
        [$result] = clamdProtocolScan($socket, $directory, str_repeat('A', 4 * 1024 * 1024));
        expect($result->isUnavailable())->toBeTrue()->and($result->isInfected())->toBeFalse();
    });
})->with([
    'incomplete infection' => ['stream: Win.Test.EICAR_HDB-1 FOUND'],
    'partial signature' => ["stream: Win.Test.EICAR_HDB-1 FOUN\0"],
    'contradictory records' => ["stream: Win.Test.EICAR_HDB-1 FOUND\0stream: OK\0"],
    'trailing bytes' => ["stream: Win.Test.EICAR_HDB-1 FOUND\0junk"],
    'unbounded signature' => ['stream: '.str_repeat('A', 256)." FOUND\0"],
    'oversized response' => [str_repeat('A', 4097)."\0"],
]);

test('clamav rejects partial or framed clean replies when the connection stalls', function (string $reply): void {
    withClamdProtocolPeer(['parts' => [base64_encode($reply)], 'hold_open_us' => 2_000_000], function (string $socket, string $trace, string $directory): void {
        [$result, $elapsed] = clamdProtocolScan($socket, $directory);
        expect($result->isUnavailable())->toBeTrue()->and($elapsed)->toBeLessThan(1.8);
    });
})->with(['partial timeout' => ['stream: OK'], 'framed without EOF' => ["stream: OK\0"]]);

test('clamav bounds a stalled upload send by its whole-scan deadline', function (): void {
    withClamdProtocolPeer(['early' => true, 'hold_open_us' => 2_000_000], function (string $socket, string $trace, string $directory): void {
        [$result, $elapsed] = clamdProtocolScan($socket, $directory, str_repeat('A', 4 * 1024 * 1024));
        expect($result->isUnavailable())->toBeTrue()->and($elapsed)->toBeLessThan(1.8);
    });
});

test('clamav connection time consumes the scan budget and honors the shorter connect limit', function (int $scanTimeout, int $connectTimeout, float $expectedLimit): void {
    withClamdProtocolPeer(['hold_open_us' => 4_000_000], function (string $socket, string $trace, string $directory) use ($scanTimeout, $connectTimeout, $expectedLimit): void {
        // A connector delay in an isolated process then a real socket connection
        // avoids dependence on external DNS or OS listen-backlog behavior.
        $script = $directory.'/delayed-connect.php';
        file_put_contents($script, <<<'CONNECTOR'
        <?php
        namespace App\Support\Attachments\Scanning {
            function stream_socket_client($socket, &$errno, &$errstr, $timeout) {
                $GLOBALS['observed_connect_timeout'] = $timeout;
                usleep(700000);
                return \stream_socket_client($socket, $errno, $errstr, $timeout);
            }
        }
        namespace {
            require $argv[1];
            $started = hrtime(true);
            $scanner = new \App\Support\Attachments\Scanning\ClamAvScanner($argv[2], (int)$argv[4], (int)$argv[5]);
            $result = $scanner->scan($argv[3]);
            echo json_encode(['status' => $result->status, 'elapsed' => (hrtime(true) - $started) / 1000000000,
                'connect_limit' => $GLOBALS['observed_connect_timeout']], JSON_THROW_ON_ERROR);
        }
        CONNECTOR);
        $upload = $directory.'/upload';
        file_put_contents($upload, 'clean bytes');
        $process = proc_open([PHP_BINARY, $script, dirname(__DIR__, 2).'/vendor/autoload.php', $socket, $upload, (string) $scanTimeout, (string) $connectTimeout], [
            0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        $report = json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $stderr)->and($report['status'])->toBe('unavailable')
            ->and($report['connect_limit'])->toBeLessThanOrEqual($expectedLimit)
            ->and($report['connect_limit'])->toBeGreaterThan($expectedLimit - 0.05)
            ->and($report['elapsed'])->toBeLessThan($scanTimeout + 0.5);
    });
})->with(['total shorter' => [1, 5, 1.0], 'connect shorter' => [2, 1, 1.0]]);

test('clamav PING requires a single complete framed response and EOF', function (string $reply, bool $available): void {
    withClamdProtocolPeer(['parts' => [base64_encode($reply)]], function (string $socket, string $trace) use ($available): void {
        expect((new ClamAvScanner($socket, connectTimeoutSeconds: 1))->isAvailable())->toBe($available)
            ->and(json_decode(file_get_contents($trace), true)['command'])->toBe("zPING\0");
    });
})->with(['proper' => ["PONG\0", true], 'unframed' => ['PONG', false], 'duplicate' => ["PONG\0PONG\0", false], 'trailing' => ["PONG\0bad", false], 'whitespace' => [" PONG\0", false]]);

test('clamav PING has a bounded whole-operation timeout', function (): void {
    withClamdProtocolPeer(['parts' => [base64_encode('PONG')], 'hold_open_us' => 2_000_000], function (string $socket): void {
        $started = hrtime(true);
        expect((new ClamAvScanner($socket, connectTimeoutSeconds: 1))->isAvailable())->toBeFalse()
            ->and((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(1.8);
    });
});

test('clamav permits bounded signature tokens and rejects malformed signatures', function (): void {
    $scanner = new ClamAvScanner('unix:///unused');
    expect($scanner->interpret("stream: Heuristics.Limits.Exceeded.MaxFileSize FOUND\0")->isInfected())->toBeTrue()
        ->and($scanner->interpret('stream: '.str_repeat('A', 255)." FOUND\0")->isInfected())->toBeTrue()
        ->and($scanner->interpret('stream: '.str_repeat('A', 256)." FOUND\0")->isUnavailable())->toBeTrue()
        ->and($scanner->interpret("stream: FOUND\0")->isUnavailable())->toBeTrue()
        ->and($scanner->interpret("stream: signature\nforged FOUND\0")->isUnavailable())->toBeTrue()
        ->and($scanner->interpret("stream: signature\x1b FOUND\0")->isUnavailable())->toBeTrue();
});

test('clamav does not scan a readable prefix as a complete file after a local read failure', function (): void {
    stream_wrapper_register('clamd-read-failure', ClamdReadFailureStream::class);

    try {
        withClamdProtocolPeer(['parts' => [base64_encode("stream: OK\0")]], function (string $socket): void {
            $result = (new ClamAvScanner($socket))->scan('clamd-read-failure://upload');
            expect($result->isUnavailable())->toBeTrue()
                ->and($result->error)->toContain('Could not read the complete file');
        });
    } finally {
        stream_wrapper_unregister('clamd-read-failure');
    }
});
