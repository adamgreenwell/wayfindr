<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Ai\Evaluation\GroundedAnswerEvaluationComparison;
use App\Support\Ai\Evaluation\GroundedAnswerEvaluationDatasetLoader;
use App\Support\Ai\Evaluation\GroundedAnswerEvaluationIdentity;
use App\Support\Ai\Evaluation\GroundedAnswerEvaluationPromptBuilder;
use App\Support\Ai\Evaluation\GroundedAnswerEvaluator;
use Illuminate\Console\Command;
use RuntimeException;

/** Compare equivalent recorded provider runs without exposing their content. */
final class CompareAiEvaluationRunsCommand extends Command
{
    protected $signature = 'wayfindr:ai-evaluate:compare
        {responses?* : Two to twenty recorded provider response JSON files.}
        {--fixtures= : Versioned fixture JSON; defaults to the bundled synthetic suite.}
        {--json : Print machine-readable output.}';

    protected $description = 'Compare identified provider evaluation runs without live calls or customer data.';

    public function handle(
        GroundedAnswerEvaluationDatasetLoader $loader,
        GroundedAnswerEvaluationIdentity $identity,
        GroundedAnswerEvaluationPromptBuilder $promptBuilder,
        GroundedAnswerEvaluator $evaluator,
        GroundedAnswerEvaluationComparison $comparison,
    ): int {
        try {
            $responsePaths = $this->responsePaths();
            $fixtures = $loader->fixtures($this->pathOption(
                'fixtures',
                resource_path('evaluations/grounded-answers/fixtures.json'),
            ));
            $suiteDigest = $identity->suite($fixtures);
            $promptDigest = $identity->promptContract($promptBuilder->contract(
                $fixtures['cases'],
                $fixtures['policy']['answer_confidence_threshold_percent'],
            ));
            $caseIds = array_column($fixtures['cases'], 'id');
            $reports = [];

            foreach ($responsePaths as $path) {
                $responses = $loader->responses($path, $caseIds, $suiteDigest, $promptDigest);
                $reports[] = $evaluator->evaluate($fixtures, $responses);
            }

            $report = $comparison->compare($reports);
        } catch (RuntimeException $exception) {
            return $this->invalid($exception->getMessage());
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->printReport($report);
        }

        return $report['result'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }

    private function invalid(string $message): int
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'result' => 'invalid',
                'error' => $message,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->error('AI evaluation comparison inputs are invalid: '.$message);
        }

        return self::INVALID;
    }

    /** @return list<string> */
    private function responsePaths(): array
    {
        $paths = $this->argument('responses');

        if (! is_array($paths) || count($paths) < 2 || count($paths) > 20) {
            throw new RuntimeException('The evaluation comparison requires 2 to 20 response files.');
        }

        return array_map(function (mixed $path): string {
            if (! is_string($path) || trim($path) === '') {
                throw new RuntimeException('Each evaluation comparison response path must be a non-empty string.');
            }

            $path = trim($path);

            return $this->isAbsolutePath($path) ? $path : base_path($path);
        }, $paths);
    }

    /** @param array<string, mixed> $report */
    private function printReport(array $report): void
    {
        $this->info('Wayfindr grounded-answer evaluation comparison');
        $this->line(sprintf(
            'Evidence identity: verified · suite %s · prompt %s',
            $report['identity']['suite_digest'],
            $report['identity']['prompt_digest'],
        ));

        foreach ($report['runs'] as $index => $run) {
            $this->line(sprintf(
                'Run %d: %s · %s / %s · %s · %d/%d cases · %d/%d tokens',
                $index + 1,
                strtoupper($run['result']),
                $run['run']['provider'],
                $run['run']['model'],
                $run['run']['recorded_at'],
                $run['cases']['passed'],
                $run['cases']['total'],
                $run['run']['prompt_tokens'],
                $run['run']['completion_tokens'],
            ));
        }

        foreach ($report['comparisons'] as $comparison) {
            $this->line(sprintf(
                'Delta: %s -> %s',
                $comparison['from_recorded_at'],
                $comparison['to_recorded_at'],
            ));

            foreach ($comparison['metric_deltas'] as $metric => $delta) {
                $this->line(sprintf('  %s: %+.2f', $metric, $delta));
            }

            $this->line('  Changed failures: '.$this->caseList($comparison['changed_case_ids']));
            $this->line('  Regressed cases: '.$this->caseList($comparison['regressed_case_ids']));
            $this->line('  Recovered cases: '.$this->caseList($comparison['recovered_case_ids']));
        }

        $report['result'] === 'passed'
            ? $this->info('Result: PASS')
            : $this->error('Result: FAIL');
    }

    /** @param list<string> $caseIds */
    private function caseList(array $caseIds): string
    {
        return $caseIds === [] ? 'none' : implode(', ', $caseIds);
    }

    private function pathOption(string $name, string $default): string
    {
        $path = trim((string) $this->option($name));

        if ($path === '') {
            return $default;
        }

        return $this->isAbsolutePath($path) ? $path : base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
