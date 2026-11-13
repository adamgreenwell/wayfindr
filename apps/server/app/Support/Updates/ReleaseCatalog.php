<?php

declare(strict_types=1);

namespace App\Support\Updates;

/** An immutable, source-bound release snapshot; it grants no execution authority. */
final readonly class ReleaseCatalog
{
    /**
     * @param  array<string, mixed>  $targetManifest
     * @param  list<array<string, mixed>>  $history
     * @param  array<string, mixed>  $provenance
     * @param  array<string, array{manifest_digest: string, config_digest: string}>  $platforms  Empty until an executor verifies the image index.
     */
    public function __construct(
        public array $targetManifest,
        public array $history,
        public string $tag,
        public string $imageDigest,
        public string $releaseNotes,
        public string $releaseUrl,
        public array $provenance,
        public array $platforms = [],
    ) {}
}
