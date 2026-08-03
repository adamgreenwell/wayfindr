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
 *               bounded at the declared floor, and baked into the image for the
 *               artifact guard, which must evaluate a skipped span offline
 *
 *   --reset-declaration
 *               empty the authored `actions` once recorded, so the next release
 *               does not re-declare them under a new version
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
require __DIR__.'/../../apps/server/app/Support/Version/VersionComparator.php';

use App\Support\Release\ReleaseManifest;
use App\Support\Version\VersionComparator;

/** @return array<string, string> */
function options(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $m) === 1) {
            $options[$m[1]] = $m[2];

            continue;
        }

        // Valueless flags. Without this a bare `--reset-declaration` parses as
        // nothing and is silently ignored, which is the worst outcome for a flag
        // whose whole job is preventing a stale declaration from being reused.
        if (preg_match('/^--([a-z-]+)$/', $argument, $m) === 1) {
            $options[$m[1]] = '';
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

    // Compare against the CANONICAL version the manifest carries, not the raw
    // argument. The release workflow passes the git tag (`v0.2.0`) while the
    // committed history was written from `0.2.0`, so filtering on the argument
    // matches nothing and appends a second entry — duplicating the release and
    // every requirement in it, in every official image.
    $canonical = $manifest['version'];

    $existing = array_values(array_filter(
        $existing,
        static fn (array $entry): bool => ($entry['version'] ?? null) !== $canonical,
    ));

    $existing[] = $manifest;

    // Bound the history at this release's floor. An upgrade from below the floor
    // is refused outright, so declarations older than it can never be needed —
    // and the legacy path, which evaluates the WHOLE baked history when it cannot
    // tell where an upgrade started, would otherwise demand obsolete actions that
    // no supported upgrade can reach.
    //
    // An entry whose order against the floor cannot be determined is KEPT. That
    // is the safe direction: dropping something we cannot prove is obsolete would
    // silently discard a requirement.
    $floor = $manifest['minimum_upgrade_from'] ?? null;

    if (is_string($floor)) {
        $existing = array_values(array_filter(
            $existing,
            static function (array $entry) use ($floor, $canonical): bool {
                $version = $entry['version'] ?? null;

                if (! is_string($version)) {
                    return true;
                }

                // The release being built always survives its own floor. A
                // declaration can legitimately set a floor above its own version
                // during a renumbering, and nothing stops a typo doing it by
                // accident - either way, dropping the entry this run just
                // recorded would publish an image whose history omits the very
                // release it is.
                if ($version === $canonical) {
                    return true;
                }

                $comparison = VersionComparator::compare($version, $floor);

                return $comparison === null || $comparison >= 0;
            },
        ));
    }

    file_put_contents($options['history'], $encode([
        'schema' => ReleaseManifest::SCHEMA,
        'releases' => $existing,
    ]));
}

// Clear the authored actions once they are recorded in history. Without this the
// next release rebuilds the previous release's actions and stamps them with the
// new version, so an operator who already acknowledged `0.2.0/thing` is asked
// again for `0.3.0/thing` — work they have demonstrably already done.
if (isset($options['reset-declaration'])) {
    /** @var mixed $authored */
    $authored = json_decode((string) file_get_contents($declarationPath), true);

    if (! is_array($authored)) {
        fail("{$declarationPath} is not valid JSON.");
    }

    $authored['actions'] = [];

    file_put_contents($declarationPath, $encode($authored));
}

if (! isset($options['out']) && ! isset($options['history'])) {
    echo $encode($manifest);
}
