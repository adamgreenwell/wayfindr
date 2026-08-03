#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build a release manifest from the authored declaration plus a release identity.
 *
 *   build-manifest.php --version=<v> --commit=<sha> [--declaration=<path>]
 *                      [--history=<path>] [--out=<path>]
 *
 * Two artifacts come out of this one builder so they can never disagree
 * (ADR 0013):
 *
 *   --out       one manifest for this release, published as a release asset for
 *               the installer preflight, which reads releases it never pulls
 *   --history   this manifest appended to the declarations already published,
 *               baked into the image for the artifact guard, which must evaluate
 *               a skipped span offline
 *
 * Run from the repository root during the image build and by the release
 * workflow. Deliberately standalone: the image build has no booted application,
 * and the guard reads the output before migrations have run.
 */
// The class file directly, not the application autoloader. ReleaseManifest is
// deliberately framework-free, and requiring vendor/ would mean a composer
// install in the release workflow and a build ordering constraint in the image
// for a validator that needs neither.
require __DIR__.'/../../apps/server/app/Support/Version/SemanticVersion.php';
require __DIR__.'/../../apps/server/app/Support/Release/ReleaseManifest.php';

use App\Support\Release\ReleaseManifest;

/** @return array<string, string> */
function options(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $m) === 1) {
            $options[$m[1]] = $m[2];
        }
    }

    return $options;
}

function fail(string $message): never
{
    fwrite(STDERR, "build-manifest: {$message}\n");
    exit(1);
}

$options = options($argv);
$root = dirname(__DIR__, 2);

$version = $options['version'] ?? '';
$commit = $options['commit'] ?? '';

if ($version === '') {
    fail('--version is required.');
}

$declarationPath = $options['declaration'] ?? $root.'/release.json';

if (! is_file($declarationPath)) {
    fail("no declaration at {$declarationPath}");
}

/** @var mixed $declaration */
$declaration = json_decode((string) file_get_contents($declarationPath), true);

if (! is_array($declaration)) {
    fail("{$declarationPath} is not valid JSON.");
}

try {
    $manifest = ReleaseManifest::build($declaration, $version, $commit);
} catch (InvalidArgumentException $e) {
    // A malformed declaration must break the build, not ship a manifest that
    // silently under-declares what an operator has to do.
    fail($e->getMessage());
}

$encode = static fn (array $value): string => json_encode(
    $value,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
)."\n";

if (isset($options['out'])) {
    file_put_contents($options['out'], $encode($manifest));
}

if (isset($options['history'])) {
    $existing = [];

    if (is_file($options['history'])) {
        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($options['history']), true);
        $existing = is_array($decoded['releases'] ?? null) ? $decoded['releases'] : [];
    }

    // Replace rather than append when rebuilding the same version, so a rebuild
    // cannot leave two entries claiming one release.
    $existing = array_values(array_filter(
        $existing,
        static fn (array $entry): bool => ($entry['version'] ?? null) !== $version,
    ));

    $existing[] = $manifest;

    file_put_contents($options['history'], $encode([
        'schema' => ReleaseManifest::SCHEMA,
        'releases' => $existing,
    ]));
}

if (! isset($options['out']) && ! isset($options['history'])) {
    echo $encode($manifest);
}
