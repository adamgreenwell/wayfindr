<?php

namespace App\Support\Attachments\Scanning;

/**
 * The outcome of scanning an uploaded file (ADR 0007).
 *
 * - clean: the scanner inspected the bytes and found nothing.
 * - infected: a threat was detected (the signature name is carried for the
 *   audit trail).
 * - unavailable: the scanner could not be reached or errored, so the file's
 *   safety is unknown — the caller applies its fail-open/closed policy.
 */
final class ScanResult
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $status,
        public readonly ?string $threat = null,
        public readonly ?string $error = null,
    ) {}

    public static function clean(): self
    {
        return new self(self::CLEAN);
    }

    public static function infected(string $threat): self
    {
        return new self(self::INFECTED, threat: $threat);
    }

    public static function unavailable(string $error): self
    {
        return new self(self::UNAVAILABLE, error: $error);
    }

    public function isClean(): bool
    {
        return $this->status === self::CLEAN;
    }

    public function isInfected(): bool
    {
        return $this->status === self::INFECTED;
    }

    public function isUnavailable(): bool
    {
        return $this->status === self::UNAVAILABLE;
    }
}
