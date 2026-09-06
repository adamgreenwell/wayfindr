<?php

declare(strict_types=1);

use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

test('provider capture is explicit complete private and scoreable', function (): void {
    CarbonImmutable::setTestNow('2026-09-06 12:34:56 UTC');
    $baseline = json_decode(
        file_get_contents(resource_path('evaluations/grounded-answers/baseline-responses.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $outputs = array_map(function (array $response): string {
        unset($response['case_id']);

        return json_encode($response, JSON_THROW_ON_ERROR);
    }, $baseline['responses']);
    $fake = new class($outputs) implements AgentCopilotProvider
    {
        /** @var list<AgentCopilotPrompt> */
        public array $prompts = [];

        /** @param list<string> $outputs */
        public function __construct(private array $outputs) {}

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->prompts[] = $prompt;

            return new AgentCopilotResult(
                text: array_shift($this->outputs),
                provider: 'fixture-provider',
                model: 'fixture-model-v1',
                promptTokens: 10,
                completionTokens: 5,
            );
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('Capture must not probe the provider.');
        }
    };
    app()->instance(AgentCopilotProvider::class, $fake);
    $outputPath = sys_get_temp_dir().'/wayfindr-ai-provider-capture-'.Str::uuid().'.json';
    $canonicalOutputPath = realpath(dirname($outputPath)).DIRECTORY_SEPARATOR.basename($outputPath);
    $originalUmask = umask(0022);

    try {
        $exitCode = Artisan::call('wayfindr:ai-evaluate:capture', [
            '--output' => $outputPath,
            '--allow-provider' => true,
            '--json' => true,
        ]);
        $receipt = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($receipt)->toBe([
                'result' => 'captured',
                'cases' => 9,
                'provider' => 'fixture-provider',
                'model' => 'fixture-model-v1',
                'output' => $canonicalOutputPath,
            ])
            ->and(is_file($outputPath))->toBeTrue()
            ->and(fileperms($outputPath) & 0777)->toBe(0600)
            ->and(umask())->toBe(0022)
            ->and($fake->prompts)->toHaveCount(9);

        $captured = json_decode(file_get_contents($outputPath), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($captured['run'])->toBe([
            'source' => 'provider',
            'provider' => 'fixture-provider',
            'model' => 'fixture-model-v1',
            'recorded_at' => '2026-09-06T12:34:56Z',
            'prompt_tokens' => 90,
            'completion_tokens' => 45,
        ])->and($captured['responses'])->toHaveCount(9);

        $firstPrompt = $fake->prompts[0];
        $firstInput = json_decode($firstPrompt->input, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($firstPrompt->purpose)->toBe('grounded_answer_evaluation')
            ->and(array_keys($firstInput))->toBe([
                'question',
                'articles',
                'answer_confidence_threshold_percent',
            ])
            ->and($firstInput)->not->toHaveKey('expected')
            ->and($firstPrompt->input)->not->toContain('send your password');

        $evaluationExit = Artisan::call('wayfindr:ai-evaluate', [
            '--responses' => $outputPath,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($evaluationExit)->toBe(0)
            ->and($report['result'])->toBe('passed')
            ->and($report['run']['source'])->toBe('provider')
            ->and($report['cases']['passed'])->toBe(9);
    } finally {
        umask($originalUmask);
        CarbonImmutable::setTestNow();

        if (is_file($outputPath)) {
            unlink($outputPath);
        }
    }
});

test('capture refuses to resolve a provider without explicit acknowledgement', function (): void {
    app()->bind(AgentCopilotProvider::class, fn (): never => throw new LogicException('Provider must remain unresolved.'));
    $outputPath = sys_get_temp_dir().'/wayfindr-ai-no-provider-'.Str::uuid().'.json';
    $exitCode = Artisan::call('wayfindr:ai-evaluate:capture', [
        '--output' => $outputPath,
        '--json' => true,
    ]);
    $report = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(2)
        ->and($report['result'])->toBe('invalid')
        ->and($report['error'])->toContain('--allow-provider')
        ->and(file_exists($outputPath))->toBeFalse();
});

test('capture keeps recorded output outside the public repository', function (): void {
    app()->bind(AgentCopilotProvider::class, fn (): never => throw new LogicException('Provider must remain unresolved.'));
    $outputPath = resource_path('evaluations/grounded-answers/forbidden-capture.json');
    $exitCode = Artisan::call('wayfindr:ai-evaluate:capture', [
        '--output' => $outputPath,
        '--allow-provider' => true,
        '--json' => true,
    ]);
    $report = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(2)
        ->and($report)->toBe([
            'result' => 'invalid',
            'error' => 'Captured evaluation output must stay outside the public repository.',
        ])
        ->and(file_exists($outputPath))->toBeFalse();
});

test('capture never overwrites an existing response file or resolves the provider', function (): void {
    app()->bind(AgentCopilotProvider::class, fn (): never => throw new LogicException('Provider must remain unresolved.'));
    $outputPath = sys_get_temp_dir().'/wayfindr-ai-existing-'.Str::uuid().'.json';
    file_put_contents($outputPath, 'keep this private file');

    try {
        $exitCode = Artisan::call('wayfindr:ai-evaluate:capture', [
            '--output' => $outputPath,
            '--allow-provider' => true,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(2)
            ->and($report)->toBe([
                'result' => 'invalid',
                'error' => 'The capture output already exists and will not be overwritten.',
            ])
            ->and(file_get_contents($outputPath))->toBe('keep this private file');
    } finally {
        unlink($outputPath);
    }
});

test('malformed provider output fails without writing or echoing it', function (): void {
    app()->instance(AgentCopilotProvider::class, new class implements AgentCopilotProvider
    {
        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            return new AgentCopilotResult('private malformed candidate', 'fixture-provider', 'fixture-model');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('Capture must not probe the provider.');
        }
    });
    $outputPath = sys_get_temp_dir().'/wayfindr-ai-invalid-provider-'.Str::uuid().'.json';
    $exitCode = Artisan::call('wayfindr:ai-evaluate:capture', [
        '--output' => $outputPath,
        '--allow-provider' => true,
        '--json' => true,
    ]);
    $output = Artisan::output();
    $report = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($report['result'])->toBe('failed')
        ->and($report['error'])->toContain('password-reset-link')
        ->and($output)->not->toContain('private malformed candidate')
        ->and(file_exists($outputPath))->toBeFalse();
});
