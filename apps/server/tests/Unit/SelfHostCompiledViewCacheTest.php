<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Symfony\Component\Process\Process;

test('container startup ignores newer compiled views retained from an older image', function (string $configuration): void {
    $root = dirname(__DIR__, 4);
    $fixture = sys_get_temp_dir().'/wayfindr-compiled-views-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($fixture.'/resources/views', 0700, true);
    $files->makeDirectory($fixture.'/storage/framework/views', 0700, true);
    $source = $fixture.'/resources/views/example.blade.php';
    $files->put($source, 'PREVIOUS_RELEASE_TEMPLATE');
    $previousCompiler = new BladeCompiler($files, $fixture.'/storage/framework/views');
    $previousCompiler->compile($source);
    $previousCompiled = $previousCompiler->getCompiledPath($source);
    $previousContents = $files->get($previousCompiled);
    $files->put($source, 'CURRENT_RELEASE_TEMPLATE');
    touch($source, time() - 600);
    touch($previousCompiled, time() + 600);

    // Map the image's application root into an isolated fixture. Execute the
    // shipped entrypoint itself; no migration or application service is started.
    $entrypoint = str_replace(
        'cd /app/apps/server',
        'cd "$WAYFINDR_TEST_APP_ROOT"',
        $files->get($root.'/docker/self-hosting/docker-entrypoint.sh'),
    );
    $files->put($fixture.'/entrypoint.sh', $entrypoint);
    $files->put($fixture.'/render.php', <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
new Illuminate\Foundation\Application($argv[2]);
$view = require $argv[3];
$directoryExists = is_dir($view['compiled']);
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $view['compiled']);
$engine = new Illuminate\View\Engines\CompilerEngine($compiler);
echo json_encode([
    'output' => $engine->get($argv[2].'/resources/views/example.blade.php'),
    'compiled_path' => $view['compiled'],
    'directory_exists_before_render' => $directoryExists,
], JSON_THROW_ON_ERROR);
PHP);

    $configuredPath = false;
    $imageHasCompiledPath = false;

    if ($configuration === 'empty') {
        $configuredPath = '';
    } elseif ($configuration === 'image') {
        $imageHasCompiledPath = preg_match('/^\s+VIEW_COMPILED_PATH=([^\s\\\\]+)/m', $files->get($root.'/docker/self-hosting/server.Dockerfile'), $match) === 1;
        $configuredPath = isset($match[1])
            ? str_replace('/app/apps/server', $fixture, $match[1])
            : false;
    } elseif ($configuration === 'custom') {
        $configuredPath = $fixture.'/custom cache/views';
    }

    $process = new Process([
        'bash',
        $fixture.'/entrypoint.sh',
        PHP_BINARY,
        $fixture.'/render.php',
        dirname(__DIR__, 2),
        $fixture,
        $root.'/docker/self-hosting/view.php',
    ], $fixture, [
        'WAYFINDR_TEST_APP_ROOT' => $fixture,
        'WAYFINDR_AUTO_MIGRATE' => '0',
        'VIEW_COMPILED_PATH' => $configuredPath,
    ]);

    try {
        if ($configuration === 'image') {
            expect($imageHasCompiledPath)->toBeTrue('The image must set the compiled view path for commands that bypass its entrypoint.');
            expect($files->get($root.'/docker/self-hosting/server.Dockerfile'))
                ->toContain('COPY docker/self-hosting/view.php /app/apps/server/config/view.php');
        }

        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($result['output'])->toBe('CURRENT_RELEASE_TEMPLATE')
            ->and($result['directory_exists_before_render'])->toBeTrue()
            ->and($result['compiled_path'])->toBe($configuration === 'custom'
                ? $configuredPath
                : $fixture.'/bootstrap/cache/views')
            ->and($files->get($previousCompiled))->toBe($previousContents);

        // docker exec does not rerun the entrypoint or inherit its exports.
        // Its original container environment must work even when the override
        // was empty; the entrypoint's child alone cannot repair that case.
        $direct = new Process([
            PHP_BINARY,
            $fixture.'/render.php',
            dirname(__DIR__, 2),
            $fixture,
            $root.'/docker/self-hosting/view.php',
        ], $fixture, ['VIEW_COMPILED_PATH' => $configuredPath]);
        $direct->run();

        expect($direct->isSuccessful())
            ->toBeTrue('Direct-exec views must resolve a valid cache path even with an empty override.');
        $directResult = json_decode($direct->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($directResult['output'])->toBe('CURRENT_RELEASE_TEMPLATE')
            ->and($directResult['compiled_path'])->toBe($configuration === 'custom'
                ? $configuredPath
                : $fixture.'/bootstrap/cache/views');
    } finally {
        $files->deleteDirectory($fixture);
    }
})->with(['unset', 'empty', 'image', 'custom']);
