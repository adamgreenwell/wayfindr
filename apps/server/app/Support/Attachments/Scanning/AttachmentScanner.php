<?php

namespace App\Support\Attachments\Scanning;

/**
 * A pluggable malware scanner for uploaded attachments (ADR 0007). The concrete
 * driver is selected by configuration; ClamAV is the reference self-host driver
 * (it runs locally, so files never leave the trust boundary).
 */
interface AttachmentScanner
{
    /**
     * Scan the file at the given absolute path (typically an upload's temp
     * file), returning clean / infected / unavailable.
     */
    public function scan(string $path): ScanResult;

    /**
     * Whether a real scanner is configured. The null scanner returns false so
     * readiness can surface that uploads are accepted with defense-in-depth but
     * not virus-scanned.
     */
    public function isConfigured(): bool;

    /**
     * Whether the scanner is currently reachable (a cheap health probe for
     * readiness). A non-configured scanner is trivially available.
     */
    public function isAvailable(): bool;
}
