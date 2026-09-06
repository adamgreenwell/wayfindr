<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Ai\Evaluation\GroundedAnswerEvaluationDatasetLoader;
use App\Support\Ai\Evaluation\GroundedAnswerEvaluator;
use Illuminate\Console\Command;
use RuntimeException;

/** Run the recorded, provider-free grounded-answer regression suite. */
final class EvaluateAiAnswersCommand extends Command
{
    protected $signature = 'wayfindr:ai-evaluate
        {--fixtures= : Versioned fixture JSON; defaults to the bundled synthetic suite.}
        {--responses= : Recorded response JSON; defaults to the bundled baseline.}
        {--json : Print machine-readable output.}';

    protected $description = 'Score recorded grounded-answer output without live provider keys or customer data.';

    public function handle(
        GroundedAnswerEvaluationDatasetLoader $loader,
        GroundedAnswerEvaluator $evaluator,
    ): int {
        try {
            $fixtures = $loader->fixtures($this->pathOption(
                'fixtures',
                resource_path('evaluations/grounded-answers/fixtures.json'),
            ));
            $responses = $loader->responses(
                $this->pathOption('responses', resource_path('evaluations/grounded-answers/baseline-responses.json')),
                array_column($fixtures['cases'], 'id'),
            );
            $report = $evaluator->evaluate($fixtures, $responses);
        } catch (RuntimeException $exception) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'result' => 'invalid',
                    'error' => $exception->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                $this->error('AI evaluation inputs are invalid: '.$exception->getMessage());
            }

            return self::INVALID;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->printReport($report);
        }

        return $report['result'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{
     *   result: 'passed'|'failed',
     *   run: array{source: string, provider: string, model: string, recorded_at: string},
     *   policy: array{answer_confidence_threshold_percent: float},
     *   cases: array{total: int, answerable: int, refusal: int, passed: int},
     *   metrics: array<string, float>,
     *   failures: list<array{case_id: string, reasons: list<string>}>
     * }  $report
     */
    private function printReport(array $report): void
    {
        $this->info('Wayfindr grounded-answer evaluation');
        $this->line(sprintf(
            'Run: %s · %s / %s · %s',
            $report['run']['source'],
            $report['run']['provider'],
            $report['run']['model'],
            $report['run']['recorded_at'],
        ));
        $this->line(sprintf(
            'Answer confidence threshold: %.2f%%',
            $report['policy']['answer_confidence_threshold_percent'],
        ));
        $this->line(sprintf(
            'Cases: %d total · %d answerable · %d refusal · %d passed',
            $report['cases']['total'],
            $report['cases']['answerable'],
            $report['cases']['refusal'],
            $report['cases']['passed'],
        ));
        $this->line(sprintf('Candidate / policy decision accuracy: %.2f%% / %.2f%%',
            $report['metrics']['candidate_decision_accuracy_percent'],
            $report['metrics']['policy_decision_accuracy_percent'],
        ));
        $this->line(sprintf('Candidate answer accuracy: %.2f%%', $report['metrics']['candidate_answer_accuracy_percent']));
        $this->line(sprintf('Answer accuracy: %.2f%%', $report['metrics']['answer_accuracy_percent']));
        $this->line(sprintf('Answer coverage: %.2f%%', $report['metrics']['answer_coverage_percent']));
        $this->line(sprintf('Selective answer accuracy: %.2f%%', $report['metrics']['selective_answer_accuracy_percent']));
        $this->line(sprintf('Refusal recall: %.2f%%', $report['metrics']['refusal_recall_percent']));
        $this->line(sprintf('Refusal reason accuracy: %.2f%%', $report['metrics']['refusal_reason_accuracy_percent']));
        $this->line(sprintf('Citation precision / recall: %.2f%% / %.2f%%',
            $report['metrics']['citation_precision_percent'],
            $report['metrics']['citation_recall_percent'],
        ));
        $this->line(sprintf('Fact coverage: %.2f%%', $report['metrics']['fact_coverage_percent']));
        $this->line(sprintf('Unsafe answer rate: %.2f%%', $report['metrics']['unsafe_answer_rate_percent']));
        $this->line(sprintf('Overconfident error rate: %.2f%%', $report['metrics']['overconfident_error_rate_percent']));
        $this->line(sprintf('Unwarranted handoff rate: %.2f%%', $report['metrics']['unwarranted_handoff_rate_percent']));
        $this->line(sprintf('Confidence Brier score: %.2f', $report['metrics']['confidence_brier_score']));

        foreach ($report['failures'] as $failure) {
            $this->warn(sprintf('%s: %s', $failure['case_id'], implode(', ', $failure['reasons'])));
        }

        $report['result'] === 'passed'
            ? $this->info('Result: PASS')
            : $this->error('Result: FAIL');
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
