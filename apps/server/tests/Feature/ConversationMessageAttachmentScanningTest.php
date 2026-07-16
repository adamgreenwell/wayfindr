<?php

// The pluggable malware scanner (ADR 0007). Uploads are scanned synchronously
// before they are stored: clean passes, infected is rejected and audited, and
// an unreachable scanner is rejected under the default fail-closed policy (or
// accepted when fail-open). The default (no scanner) accepts with
// defense-in-depth.

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\Attachments\AttachmentUploadService;
use App\Support\Attachments\Scanning\AttachmentScanner;
use App\Support\Attachments\Scanning\ClamAvScanner;
use App\Support\Attachments\Scanning\NullScanner;
use App\Support\Attachments\Scanning\ScanResult;
use App\Support\OperatorReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('attachments');
});

function fakeScanner(ScanResult $result, bool $configured = true, bool $available = true): AttachmentScanner
{
    return new class($result, $configured, $available) implements AttachmentScanner
    {
        public function __construct(private ScanResult $result, private bool $configured, private bool $available) {}

        public function scan(string $path): ScanResult
        {
            return $this->result;
        }

        public function isConfigured(): bool
        {
            return $this->configured;
        }

        public function isAvailable(): bool
        {
            return $this->available;
        }
    };
}

function scanFixture(): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->create(['visitor_id' => $visitor->id])->load('site');

    return compact('account', 'site', 'visitor', 'conversation');
}

function uploadThroughService(Conversation $conversation, Visitor $visitor): ConversationMessageAttachment
{
    $file = UploadedFile::fake()->image('shot.png');

    return app(AttachmentUploadService::class)->store($conversation, $file, $visitor);
}

// --- Upload-time scanning -------------------------------------------------

test('a clean upload passes the scanner and is stored', function (): void {
    app()->instance(AttachmentScanner::class, fakeScanner(ScanResult::clean()));
    $f = scanFixture();

    $attachment = uploadThroughService($f['conversation'], $f['visitor']);

    expect($attachment->status)->toBe(ConversationMessageAttachment::STATUS_READY);
    Storage::disk('attachments')->assertExists($attachment->storage_key);
});

test('an infected upload is rejected, audited, and never stored', function (): void {
    app()->instance(AttachmentScanner::class, fakeScanner(ScanResult::infected('Win.Test.EICAR_HDB-1')));
    $f = scanFixture();

    expect(fn () => uploadThroughService($f['conversation'], $f['visitor']))
        ->toThrow(ValidationException::class);

    expect(ConversationMessageAttachment::count())->toBe(0)
        ->and(Storage::disk('attachments')->allFiles())->toBe([]);

    $event = AuditEvent::where('action', 'attachment.quarantined')->first();
    expect($event)->not->toBeNull()
        ->and(data_get($event->metadata, 'threat'))->toBe('Win.Test.EICAR_HDB-1');
});

test('an unreachable scanner rejects the upload when fail-closed (default)', function (): void {
    config(['wayfindr.attachments.scanner.fail_closed' => true]);
    app()->instance(AttachmentScanner::class, fakeScanner(ScanResult::unavailable('clamd down'), available: false));
    $f = scanFixture();

    expect(fn () => uploadThroughService($f['conversation'], $f['visitor']))
        ->toThrow(ValidationException::class);

    expect(ConversationMessageAttachment::count())->toBe(0)
        ->and(AuditEvent::where('action', 'attachment.scan_unavailable')->count())->toBe(1);
});

test('an unreachable scanner accepts the upload when fail-open', function (): void {
    config(['wayfindr.attachments.scanner.fail_closed' => false]);
    app()->instance(AttachmentScanner::class, fakeScanner(ScanResult::unavailable('clamd down'), available: false));
    $f = scanFixture();

    $attachment = uploadThroughService($f['conversation'], $f['visitor']);

    expect($attachment->status)->toBe(ConversationMessageAttachment::STATUS_READY);
    Storage::disk('attachments')->assertExists($attachment->storage_key);
});

test('with no scanner configured the upload is accepted (defense-in-depth)', function (): void {
    // Default binding is the NullScanner.
    $f = scanFixture();

    $attachment = uploadThroughService($f['conversation'], $f['visitor']);

    expect($attachment->status)->toBe(ConversationMessageAttachment::STATUS_READY);
});

// --- Driver selection + protocol parsing ---------------------------------

test('config selects the scanner driver', function (): void {
    config(['wayfindr.attachments.scanner.driver' => null]);
    app()->forgetInstance(AttachmentScanner::class);
    expect(app(AttachmentScanner::class))->toBeInstanceOf(NullScanner::class);

    config(['wayfindr.attachments.scanner.driver' => 'clamav']);
    app()->forgetInstance(AttachmentScanner::class);
    expect(app(AttachmentScanner::class))->toBeInstanceOf(ClamAvScanner::class);
});

test('an unknown driver throws rather than silently disabling scanning', function (): void {
    config(['wayfindr.attachments.scanner.driver' => 'clamavv']);
    app()->forgetInstance(AttachmentScanner::class);

    expect(fn () => app(AttachmentScanner::class))->toThrow(InvalidArgumentException::class);
});

test('readiness flags an unknown driver as attention', function (): void {
    config(['wayfindr.attachments.scanner.driver' => 'clamavv']);

    $check = collect(app(OperatorReadiness::class)->summary()['checks'])->firstWhere('key', 'attachment_scanning');

    expect($check['status'])->toBe('attention')
        ->and($check['summary'])->toContain('Unknown malware scanner driver');
});

test('a silent clamd (accepts but never answers) times out to unavailable instead of hanging', function (): void {
    // Reproduces the socket-activation failure mode: systemd accepts the
    // connection while the daemon behind it is dead, so nothing ever answers.
    // The scan must fail closed within its timeout, not hang the upload request.
    $socketPath = sys_get_temp_dir().'/wf-silent-clamd-'.uniqid().'.sock';
    $server = stream_socket_server('unix://'.$socketPath, $errno, $errstr);
    expect($server)->not->toBeFalse();

    $file = tempnam(sys_get_temp_dir(), 'wf-scan');
    file_put_contents($file, 'clean bytes');

    try {
        $scanner = new ClamAvScanner('unix://'.$socketPath, scanTimeoutSeconds: 2);

        $startedAt = microtime(true);
        $result = $scanner->scan($file);
        $elapsed = microtime(true) - $startedAt;

        expect($result->isUnavailable())->toBeTrue()
            ->and($result->error)->toContain('Timed out')
            ->and($elapsed)->toBeLessThan(6.0);
    } finally {
        fclose($server);
        @unlink($socketPath);
        @unlink($file);
    }
});

test('an early clamd verdict that closes the connection mid-stream is honored, not reported unavailable', function (): void {
    // clamd writes its verdict early and closes when it can already decide
    // (early detection or a size-limit error). The client's still-streaming
    // writes then fail — but the verdict is sitting in the receive buffer and
    // must be drained and honored. Reported by review: without this, a
    // fail-open install could accept a file clamd had flagged.
    $socketPath = sys_get_temp_dir().'/wf-early-clamd-'.uniqid().'.sock';
    $serverScript = tempnam(sys_get_temp_dir(), 'wf-clamd-srv');
    file_put_contents($serverScript, <<<PHP
    <?php
    \$server = stream_socket_server('unix://{$socketPath}');
    \$client = stream_socket_accept(\$server, 10);
    // Answer immediately with an infected verdict and close, without ever
    // reading the stream — the classic early-verdict shape.
    fwrite(\$client, "stream: Win.Test.EICAR_HDB-1 FOUND\\0");
    fclose(\$client);
    usleep(200000);
    PHP);

    $process = proc_open([PHP_BINARY, $serverScript], [], $pipes);
    expect($process)->not->toBeFalse();

    // Wait for the fake daemon's socket to exist.
    $waited = 0;
    while (! file_exists($socketPath) && $waited < 3000) {
        usleep(50_000);
        $waited += 50;
    }
    expect(file_exists($socketPath))->toBeTrue();

    // A payload comfortably larger than the socket buffer, so the client's
    // writes fail once the server has answered and closed.
    $file = tempnam(sys_get_temp_dir(), 'wf-scan');
    file_put_contents($file, str_repeat('A', 4 * 1024 * 1024));

    try {
        $scanner = new ClamAvScanner('unix://'.$socketPath, scanTimeoutSeconds: 5);
        $result = $scanner->scan($file);

        expect($result->isInfected())->toBeTrue()
            ->and($result->threat)->toBe('Win.Test.EICAR_HDB-1');
    } finally {
        proc_terminate($process);
        proc_close($process);
        @unlink($socketPath);
        @unlink($serverScript);
        @unlink($file);
    }
});

test('clamav interprets clamd verdicts', function (): void {
    $scanner = new ClamAvScanner('tcp://127.0.0.1:3310');

    expect($scanner->interpret("stream: OK\0")->isClean())->toBeTrue();

    $infected = $scanner->interpret("stream: Win.Test.EICAR_HDB-1 FOUND\0");
    expect($infected->isInfected())->toBeTrue()
        ->and($infected->threat)->toBe('Win.Test.EICAR_HDB-1');

    expect($scanner->interpret("stream: File size limit exceeded ERROR\0")->isUnavailable())->toBeTrue()
        ->and($scanner->interpret('')->isUnavailable())->toBeTrue();
});

// --- Readiness signal -----------------------------------------------------

test('readiness reports no scanner as a ready, surfaced default', function (): void {
    config(['wayfindr.attachments.scanner.driver' => null]);

    $check = collect(app(OperatorReadiness::class)->summary()['checks'])->firstWhere('key', 'attachment_scanning');

    expect($check['status'])->toBe('ready')
        ->and($check['summary'])->toContain('No malware scanner');
});

test('readiness reports a configured, reachable scanner as ready', function (): void {
    config(['wayfindr.attachments.scanner.driver' => 'clamav']);
    app()->instance(AttachmentScanner::class, fakeScanner(ScanResult::clean(), available: true));

    $check = collect(app(OperatorReadiness::class)->summary()['checks'])->firstWhere('key', 'attachment_scanning');

    expect($check['status'])->toBe('ready')
        ->and($check['summary'])->toContain('reachable');
});

test('readiness flags a configured but unreachable scanner as attention', function (): void {
    config(['wayfindr.attachments.scanner.driver' => 'clamav']);
    app()->instance(AttachmentScanner::class, fakeScanner(ScanResult::unavailable('down'), available: false));

    $check = collect(app(OperatorReadiness::class)->summary()['checks'])->firstWhere('key', 'attachment_scanning');

    expect($check['status'])->toBe('attention')
        ->and($check['summary'])->toContain('unreachable');
});
