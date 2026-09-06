<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\Evaluation\GroundedAnswerEvaluationDatasetLoader;
use App\Support\Ai\Evaluation\GroundedAnswerEvaluationOutputParser;
use App\Support\Ai\Evaluation\GroundedAnswerEvaluationPromptBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Explicitly capture a complete synthetic provider run for offline scoring. */
final class CaptureAiEvaluationResponsesCommand extends Command
{
    protected $signature = 'wayfindr:ai-evaluate:capture
        {--fixtures= : Versioned fixture JSON; defaults to the bundled synthetic suite.}
        {--output= : Absolute JSON path outside the repository; the file must not exist.}
        {--allow-provider : Acknowledge that this command makes provider requests.}
        {--json : Print a machine-readable receipt.}';

    protected $description = 'Capture provider/model-tagged answers for synthetic offline evaluation.';

    public function handle(
        GroundedAnswerEvaluationDatasetLoader $loader,
        GroundedAnswerEvaluationPromptBuilder $promptBuilder,
        GroundedAnswerEvaluationOutputParser $parser,
    ): int {
        if (! $this->option('allow-provider')) {
            return $this->invalid('Provider use was not acknowledged; pass --allow-provider to make synthetic requests.');
        }

        try {
            $outputPath = $this->outputPath();
            $fixtures = $loader->fixtures($this->fixturePath());
        } catch (RuntimeException $exception) {
            return $this->invalid($exception->getMessage());
        }

        $provider = app(AgentCopilotProvider::class);
        $responses = [];
        $providerName = null;
        $modelName = null;
        $promptTokens = 0;
        $completionTokens = 0;

        foreach ($fixtures['cases'] as $case) {
            try {
                $result = $provider->generate($promptBuilder->build(
                    $case,
                    $fixtures['policy']['answer_confidence_threshold_percent'],
                ));
            } catch (Throwable $exception) {
                Log::warning('Grounded-answer evaluation provider capture failed.', [
                    'case_id' => $case['id'],
                    'exception_type' => $exception::class,
                ]);

                return $this->failed(sprintf('Provider capture failed for case %s; no output file was written.', $case['id']));
            }

            $candidate = $parser->parse($result->text);

            if ($candidate === null) {
                return $this->failed(sprintf('Provider output was invalid for case %s; no output file was written.', $case['id']));
            }

            $currentProvider = trim($result->provider);
            $currentModel = trim($result->model);

            if (! $this->validMetadata($currentProvider) || ! $this->validMetadata($currentModel)) {
                return $this->failed(sprintf('Provider metadata was incomplete for case %s; no output file was written.', $case['id']));
            }

            $providerName ??= $currentProvider;
            $modelName ??= $currentModel;

            if ($providerName !== $currentProvider || $modelName !== $currentModel) {
                return $this->failed('Provider or model identity changed during capture; no output file was written.');
            }

            if ($result->promptTokens < 0 || $result->completionTokens < 0) {
                return $this->failed(sprintf('Provider usage metadata was invalid for case %s; no output file was written.', $case['id']));
            }

            $promptTokens += $result->promptTokens;
            $completionTokens += $result->completionTokens;

            if ($promptTokens > 1_000_000_000 || $completionTokens > 1_000_000_000) {
                return $this->failed('Provider usage metadata exceeded the evaluation limit; no output file was written.');
            }

            $responses[] = [
                'case_id' => $case['id'],
                ...$candidate->toArray(),
            ];
        }

        $responseSet = [
            'version' => $fixtures['version'],
            'run' => [
                'source' => 'provider',
                'provider' => $providerName,
                'model' => $modelName,
                'recorded_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
            ],
            'responses' => $responses,
        ];

        try {
            $contents = json_encode(
                $responseSet,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ).PHP_EOL;

            if (strlen($contents) > GroundedAnswerEvaluationDatasetLoader::MAX_RESPONSE_FILE_BYTES) {
                throw new RuntimeException('The completed capture exceeds the 2 MiB response-file limit.');
            }

            $this->writeExclusive($outputPath, $contents);
        } catch (Throwable $exception) {
            Log::warning('Grounded-answer evaluation capture could not be persisted.', [
                'exception_type' => $exception::class,
            ]);

            return $this->failed('The capture output could not be encoded or written safely.');
        }

        $receipt = [
            'result' => 'captured',
            'cases' => count($responses),
            'provider' => $providerName,
            'model' => $modelName,
            'output' => $outputPath,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Captured %d synthetic evaluation cases from %s / %s.',
                $receipt['cases'],
                $providerName,
                $modelName,
            ));
            $this->line('Private output: '.$outputPath);
            $this->line('Score it with: php artisan wayfindr:ai-evaluate --responses='.$outputPath);
        }

        return self::SUCCESS;
    }

    private function fixturePath(): string
    {
        $path = trim((string) $this->option('fixtures'));

        if ($path === '') {
            return resource_path('evaluations/grounded-answers/fixtures.json');
        }

        return $this->isAbsolutePath($path) ? $path : base_path($path);
    }

    private function outputPath(): string
    {
        $requested = trim((string) $this->option('output'));

        if ($requested === '' || ! $this->isAbsolutePath($requested)) {
            throw new RuntimeException('The capture output must be an absolute JSON path outside the repository.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $requested) === 1) {
            throw new RuntimeException('The capture output path must not contain control characters.');
        }

        if (strtolower(pathinfo($requested, PATHINFO_EXTENSION)) !== 'json') {
            throw new RuntimeException('The capture output must use a .json extension.');
        }

        $directory = realpath(dirname($requested));
        $repository = realpath(base_path('../..'));

        if ($directory === false || ! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('The capture output directory must exist and be writable.');
        }

        if ($repository === false) {
            throw new RuntimeException('The repository boundary could not be resolved.');
        }

        if ($directory === $repository || str_starts_with($directory, $repository.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Captured evaluation output must stay outside the public repository.');
        }

        $path = $directory.DIRECTORY_SEPARATOR.basename($requested);

        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('The capture output already exists and will not be overwritten.');
        }

        return $path;
    }

    private function writeExclusive(string $path, string $contents): void
    {
        $previousUmask = umask(0077);

        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($previousUmask);
        }

        if ($handle === false) {
            throw new RuntimeException('The capture output could not be created exclusively.');
        }

        $written = false;

        try {
            $written = @chmod($path, 0600)
                && fwrite($handle, $contents) === strlen($contents)
                && fflush($handle);
        } finally {
            fclose($handle);

            if (! $written) {
                @unlink($path);
            }
        }

        if (! $written) {
            throw new RuntimeException('The capture output could not be written safely.');
        }
    }

    private function invalid(string $message): int
    {
        return $this->result('invalid', $message, self::INVALID);
    }

    private function failed(string $message): int
    {
        return $this->result('failed', $message, self::FAILURE);
    }

    private function result(string $result, string $message, int $exitCode): int
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'result' => $result,
                'error' => $message,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return $exitCode;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function validMetadata(string $value): bool
    {
        return $value !== ''
            && mb_strlen($value) <= 200
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
