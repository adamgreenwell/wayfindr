<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use Aws\CommandInterface;
use Aws\Middleware;
use Illuminate\Filesystem\FilesystemAdapter;
use Psr\Http\Message\RequestInterface;

/**
 * In-process S3 request-attempt accounting for the disposable #864 harness.
 *
 * Disabled in every ordinary application and scheduler process. The explicit
 * measurement command enables it, and the sweep attaches it to the filesystem
 * instance it resolves so nested Artisan commands are measured too.
 */
final class AttachmentRetentionRequestCounter
{
    private const MIDDLEWARE = 'wayfindr-attachment-retention-request-counter';

    private static bool $enabled = false;

    private static string $phase = 'setup';

    /** @var array<string, array<string, int>> */
    private static array $counts = [];

    public static function start(): void
    {
        self::$enabled = true;
        self::$phase = 'setup';
        self::$counts = [];
    }

    public static function stop(): void
    {
        self::$enabled = false;
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    public static function phase(string $phase): void
    {
        self::$phase = $phase;
    }

    /** @return array<string, array<string, int>> */
    public static function counts(): array
    {
        return self::$counts;
    }

    public static function attach(FilesystemAdapter $disk): void
    {
        if (! self::$enabled || ! method_exists($disk, 'getClient')) {
            return;
        }

        $handlers = $disk->getClient()->getHandlerList();

        if ($handlers->hasMiddleware(self::MIDDLEWARE)) {
            return;
        }

        $handlers->appendAttempt(
            Middleware::tap(function (CommandInterface $command, ?RequestInterface $request): void {
                $key = sprintf('%s %s', $request?->getMethod() ?? 'UNKNOWN', $command->getName());
                self::$counts[self::$phase][$key] = (self::$counts[self::$phase][$key] ?? 0) + 1;
            }),
            self::MIDDLEWARE,
        );
    }
}
