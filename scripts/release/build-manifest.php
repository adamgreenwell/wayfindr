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

function readFileOrFail(string $path): string
{
    $contents = @file_get_contents($path);

    if ($contents === false) {
        fail("could not read {$path}");
    }

    return $contents;
}

/**
 * Replace one generated file only after every byte has reached a sibling temp
 * file. A warning from file_put_contents is not a failed process by itself, and
 * callers use this command's exit status as their pre-publication/deploy gate.
 */
function writeFileOrFail(string $path, string $contents): void
{
    $directory = dirname($path);

    if (! is_dir($directory)) {
        fail("output directory does not exist for {$path}");
    }

    $temporary = @tempnam($directory, '.wayfindr-release-');

    if ($temporary === false) {
        fail("could not create a temporary file beside {$path}");
    }

    $permissions = @fileperms($path);
    $mode = is_int($permissions) ? ($permissions & 0777) : 0644;
    $written = @file_put_contents($temporary, $contents, LOCK_EX);

    if ($written !== strlen($contents)) {
        @unlink($temporary);
        fail("could not write the complete output for {$path}");
    }

    if (! @chmod($temporary, $mode)) {
        @unlink($temporary);
        fail("could not set output permissions for {$path}");
    }

    if (! @rename($temporary, $path)) {
        @unlink($temporary);
        fail("could not replace {$path}");
    }

    if (@file_get_contents($path) !== $contents) {
        fail("could not verify the complete output at {$path}");
    }
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
$declaration = json_decode(readFileOrFail($declarationPath), true);

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
    writeFileOrFail($options['out'], $encode($manifest));
}

if (isset($options['history'])) {
    $existing = [];

    if (is_file($options['history'])) {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode(readFileOrFail($options['history']), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fail("{$options['history']} is not valid JSON: {$exception->getMessage()}");
        }

        if (! is_array($decoded)
            || ($decoded['schema'] ?? null) !== ReleaseManifest::SCHEMA
            || ! is_array($decoded['releases'] ?? null)
            || ! array_is_list($decoded['releases'])) {
            fail("{$options['history']} is not a valid release history");
        }

        $seenVersions = [];

        foreach ($decoded['releases'] as $index => $entry) {
            if (! is_array($entry)) {
                fail("{$options['history']} release #{$index} is not an object");
            }

            try {
                ReleaseManifest::assertPublished($entry);
            } catch (Throwable $throwable) {
                fail("{$options['history']} release #{$index} is invalid: {$throwable->getMessage()}");
            }

            $entryVersion = $entry['version'];

            if (isset($seenVersions[$entryVersion])) {
                fail("{$options['history']} repeats release {$entryVersion}");
            }

            $seenVersions[$entryVersion] = true;
        }

        $existing = $decoded['releases'];
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

    writeFileOrFail($options['history'], $encode([
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
    $authored = json_decode(readFileOrFail($declarationPath), true);

    if (! is_array($authored)) {
        fail("{$declarationPath} is not valid JSON.");
    }

    $authored['actions'] = [];

    writeFileOrFail($declarationPath, $encode($authored));
}

if (! isset($options['out']) && ! isset($options['history'])) {
    echo $encode($manifest);
}
