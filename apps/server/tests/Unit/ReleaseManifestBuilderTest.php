<?php

declare(strict_types=1);

use App\Support\Release\ReleaseManifest;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @param list<string> $arguments */
function runReleaseManifestBuilder(array $arguments): Process
{
    $root = dirname(__DIR__, 4);
    $process = new Process([
        PHP_BINARY,
        $root.'/scripts/release/build-manifest.php',
        ...$arguments,
    ], $root);
    $process->run();

    return $process;
}

function releaseManifestBuilderFixture(): string
{
    $directory = sys_get_temp_dir().'/wayfindr-manifest-builder-'.bin2hex(random_bytes(4));
    (new Filesystem)->makeDirectory($directory, 0700, true);

    return $directory;
}

test('the manifest builder exits nonzero when its output cannot be replaced', function (): void {
    $directory = releaseManifestBuilderFixture();

    try {
        $process = runReleaseManifestBuilder([
            '--version=0.8.0',
            '--commit=abc123',
            '--out='.$directory,
        ]);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('could not replace');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

test('the manifest builder refuses malformed retained history without replacing it', function (
    callable $history,
    string $message,
): void {
    $directory = releaseManifestBuilderFixture();
    $path = $directory.'/history.json';
    $contents = json_encode($history(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    file_put_contents($path, $contents);

    try {
        $process = runReleaseManifestBuilder([
            '--version=0.8.0',
            '--commit=abc123',
            '--history='.$path,
        ]);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain($message)
            ->and(file_get_contents($path))->toBe($contents);
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with([
    'invalid manifest' => [
        fn (): array => ['schema' => 1, 'releases' => [['version' => '0.7.0']]],
        'release #0 is invalid',
    ],
    'duplicate release' => [
        function (): array {
            $release = ReleaseManifest::build(['actions' => []], '0.7.0', 'abc');

            return ['schema' => 1, 'releases' => [$release, $release]];
        },
        'repeats release 0.7.0',
    ],
]);
