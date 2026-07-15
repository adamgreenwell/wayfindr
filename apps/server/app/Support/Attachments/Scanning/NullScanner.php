<?php

namespace App\Support\Attachments\Scanning;

/**
 * The default when no scanner is configured: accept with defense-in-depth. It
 * reports clean without inspecting anything; the standing protections (strict
 * detected-type allowlist, private storage, forced download, nosniff) remain,
 * and readiness surfaces that no scanner is configured so the operator chooses
 * knowingly.
 */
class NullScanner implements AttachmentScanner
{
    public function scan(string $path): ScanResult
    {
        return ScanResult::clean();
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
