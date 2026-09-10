<?php

declare(strict_types=1);

namespace App\Support\Ai\Evaluation;

use DateTimeImmutable;
use RuntimeException;

/** Compare content-free results from equivalent grounded-answer provider runs. */
final class GroundedAnswerEvaluationComparison
{
    private const VERSION = 1;

    private const MINIMUM_REPORTS = 2;

    private const MAXIMUM_REPORTS = 20;

    /**
     * @param  list<array<string, mixed>>  $reports
     * @return array{
     *   version: int,
     *   result: 'passed'|'failed',
     *   identity: array{identity_status: 'verified', suite_digest: string, prompt_digest: string},
     *   runs: list<array{
     *     result: 'passed'|'failed',
     *     run: array{source: 'provider', provider: string, model: string, recorded_at: string, prompt_tokens: int, completion_tokens: int},
     *     cases: array{total: int, answerable: int, refusal: int, passed: int},
     *     metrics: array<string, float>
     *   }>,
     *   comparisons: list<array{
     *     from_recorded_at: string,
     *     to_recorded_at: string,
     *     metric_deltas: array<string, float>,
     *     changed_case_ids: list<string>,
     *     regressed_case_ids: list<string>,
     *     recovered_case_ids: list<string>
     *   }>
     * }
     */
    public function compare(array $reports): array
    {
        if (! array_is_list($reports)
            || count($reports) < self::MINIMUM_REPORTS
            || count($reports) > self::MAXIMUM_REPORTS) {
            throw new RuntimeException('The evaluation comparison requires a list of 2 to 20 scored provider reports.');
        }

        $normalized = [];

        foreach ($reports as $index => $report) {
            $normalized[] = $this->report($report, $index + 1);
        }

        usort(
            $normalized,
            fn (array $left, array $right): int => $left['run']['recorded_at'] <=> $right['run']['recorded_at'],
        );

        $this->assertComparable($normalized);

        $comparisons = [];

        for ($index = 1; $index < count($normalized); $index++) {
            $comparisons[] = $this->adjacentComparison(
                $normalized[$index - 1],
                $normalized[$index],
            );
        }

        $first = $normalized[0];

        return [
            'version' => self::VERSION,
            'result' => in_array('failed', array_column($normalized, 'result'), true)
                ? 'failed'
                : 'passed',
            'identity' => [
                'identity_status' => 'verified',
                'suite_digest' => $first['identity']['suite_digest'],
                'prompt_digest' => $first['identity']['prompt_digest'],
            ],
            'runs' => array_map(
                fn (array $report): array => [
                    'result' => $report['result'],
                    'run' => $report['run'],
                    'cases' => $report['cases'],
                    'metrics' => $report['metrics'],
                ],
                $normalized,
            ),
            'comparisons' => $comparisons,
        ];
    }

    /**
     * @return array{
     *   result: 'passed'|'failed',
     *   identity: array{suite_digest: string, prompt_digest: string},
     *   run: array{source: 'provider', provider: string, model: string, recorded_at: string, prompt_tokens: int, completion_tokens: int},
     *   cases: array{total: int, answerable: int, refusal: int, passed: int},
     *   metrics: array<string, float>,
     *   failures: array<string, list<string>>
     * }
     */
    private function report(mixed $report, int $position): array
    {
        if (! is_array($report) || ! is_array($report['run'] ?? null)) {
            throw $this->malformed($position);
        }

        $run = $report['run'];

        if (($run['source'] ?? null) !== 'provider') {
            throw new RuntimeException(sprintf(
                'Evaluation comparison report %d is not a provider run; only provider runs can be compared.',
                $position,
            ));
        }

        $suiteDigest = $run['suite_digest'] ?? null;
        $promptDigest = $run['prompt_digest'] ?? null;

        if (($run['identity_status'] ?? null) !== 'verified'
            || ! $this->validDigest($suiteDigest)
            || ! $this->validDigest($promptDigest)) {
            throw new RuntimeException(sprintf(
                'Evaluation comparison report %d has no verified suite and prompt identity; capture and score it again with the current evaluator.',
                $position,
            ));
        }

        $result = $report['result'] ?? null;

        if (! is_string($result) || ! in_array($result, ['passed', 'failed'], true)) {
            throw $this->malformed($position);
        }

        $provider = $this->metadata($run['provider'] ?? null);
        $model = $this->metadata($run['model'] ?? null);
        $recordedAt = $run['recorded_at'] ?? null;
        $promptTokens = $run['prompt_tokens'] ?? null;
        $completionTokens = $run['completion_tokens'] ?? null;

        if ($provider === null
            || $model === null
            || ! $this->validTimestamp($recordedAt)
            || ! $this->validCount($promptTokens)
            || ! $this->validCount($completionTokens)) {
            throw $this->malformed($position);
        }

        return [
            'result' => $result,
            'identity' => [
                'suite_digest' => $suiteDigest,
                'prompt_digest' => $promptDigest,
            ],
            'run' => [
                'source' => 'provider',
                'provider' => $provider,
                'model' => $model,
                'recorded_at' => $recordedAt,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
            ],
            'cases' => $this->cases($report['cases'] ?? null, $position),
            'metrics' => $this->metrics($report['metrics'] ?? null, $position),
            'failures' => $this->failures($report['failures'] ?? null, $position),
        ];
    }

    private function metadata(mixed $value): ?string
    {
        if (! is_string($value)
            || trim($value) === ''
            || mb_strlen($value) > 200
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return trim($value);
    }

    private function validTimestamp(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value);

        return $timestamp !== false && $timestamp->format('Y-m-d\TH:i:s\Z') === $value;
    }

    private function validCount(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= 1_000_000_000;
    }

    private function validDigest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\Asha256:[a-f0-9]{64}\z/', $value) === 1;
    }

    /** @return array{total: int, answerable: int, refusal: int, passed: int} */
    private function cases(mixed $cases, int $position): array
    {
        if (! is_array($cases)) {
            throw $this->malformed($position);
        }

        $total = $cases['total'] ?? null;
        $answerable = $cases['answerable'] ?? null;
        $refusal = $cases['refusal'] ?? null;
        $passed = $cases['passed'] ?? null;

        if (! is_int($total)
            || $total < 1
            || ! is_int($answerable)
            || $answerable < 1
            || ! is_int($refusal)
            || $refusal < 1
            || ! is_int($passed)
            || $passed < 0
            || $passed > $total
            || $answerable + $refusal !== $total) {
            throw $this->malformed($position);
        }

        return [
            'total' => $total,
            'answerable' => $answerable,
            'refusal' => $refusal,
            'passed' => $passed,
        ];
    }

    /** @return array<string, float> */
    private function metrics(mixed $metrics, int $position): array
    {
        if (! is_array($metrics) || $metrics === [] || array_is_list($metrics)) {
            throw $this->malformed($position);
        }

        $normalized = [];

        foreach ($metrics as $name => $value) {
            if (! is_string($name)
                || preg_match('/\A[a-z][a-z0-9_]{1,79}\z/', $name) !== 1
                || (! is_int($value) && ! is_float($value))
                || ! is_finite((float) $value)) {
                throw $this->malformed($position);
            }

            $normalized[$name] = (float) $value;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @return array<string, list<string>> */
    private function failures(mixed $failures, int $position): array
    {
        if (! is_array($failures) || ! array_is_list($failures)) {
            throw $this->malformed($position);
        }

        $normalized = [];

        foreach ($failures as $failure) {
            if (! is_array($failure)
                || ! is_string($failure['case_id'] ?? null)
                || preg_match('/\A[a-z0-9][a-z0-9-]{1,62}[a-z0-9]\z/', $failure['case_id']) !== 1
                || ! is_array($failure['reasons'] ?? null)
                || ! array_is_list($failure['reasons'])
                || $failure['reasons'] === []) {
                throw $this->malformed($position);
            }

            $caseId = $failure['case_id'];
            $reasons = $failure['reasons'];

            foreach ($reasons as $reason) {
                if (! is_string($reason) || preg_match('/\A[a-z][a-z0-9_]{1,79}\z/', $reason) !== 1) {
                    throw $this->malformed($position);
                }
            }

            if (isset($normalized[$caseId]) || count(array_unique($reasons)) !== count($reasons)) {
                throw $this->malformed($position);
            }

            sort($reasons, SORT_STRING);
            $normalized[$caseId] = $reasons;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @param list<array<string, mixed>> $reports */
    private function assertComparable(array $reports): void
    {
        $first = $reports[0];
        $identity = $first['identity'];
        $caseTotals = $this->caseTotals($first['cases']);
        $metricNames = array_keys($first['metrics']);
        $previousTimestamp = null;

        foreach ($reports as $report) {
            if ($report['identity'] !== $identity) {
                throw new RuntimeException('Evaluation comparison reports must share the same suite_digest and prompt_digest.');
            }

            if ($this->caseTotals($report['cases']) !== $caseTotals) {
                throw new RuntimeException('Evaluation comparison reports must share the same total, answerable, and refusal case counts.');
            }

            if (array_keys($report['metrics']) !== $metricNames) {
                throw new RuntimeException('Evaluation comparison reports must contain the same numeric metrics.');
            }

            if ($report['run']['recorded_at'] === $previousTimestamp) {
                throw new RuntimeException('Evaluation comparison reports must have unique recorded_at timestamps.');
            }

            $previousTimestamp = $report['run']['recorded_at'];
        }
    }

    /** @param array{total: int, answerable: int, refusal: int, passed: int} $cases */
    private function caseTotals(array $cases): array
    {
        return [
            'total' => $cases['total'],
            'answerable' => $cases['answerable'],
            'refusal' => $cases['refusal'],
        ];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return array{
     *   from_recorded_at: string,
     *   to_recorded_at: string,
     *   metric_deltas: array<string, float>,
     *   changed_case_ids: list<string>,
     *   regressed_case_ids: list<string>,
     *   recovered_case_ids: list<string>
     * }
     */
    private function adjacentComparison(array $previous, array $current): array
    {
        $metricDeltas = [];

        foreach ($previous['metrics'] as $metric => $value) {
            $delta = round($current['metrics'][$metric] - $value, 10);
            $metricDeltas[$metric] = $delta === -0.0 ? 0.0 : $delta;
        }

        $previousFailures = $previous['failures'];
        $currentFailures = $current['failures'];
        $changed = [];

        foreach (array_intersect(array_keys($previousFailures), array_keys($currentFailures)) as $caseId) {
            if ($previousFailures[$caseId] !== $currentFailures[$caseId]) {
                $changed[] = $caseId;
            }
        }

        $regressed = array_values(array_diff(array_keys($currentFailures), array_keys($previousFailures)));
        $recovered = array_values(array_diff(array_keys($previousFailures), array_keys($currentFailures)));
        sort($changed, SORT_STRING);
        sort($regressed, SORT_STRING);
        sort($recovered, SORT_STRING);

        return [
            'from_recorded_at' => $previous['run']['recorded_at'],
            'to_recorded_at' => $current['run']['recorded_at'],
            'metric_deltas' => $metricDeltas,
            'changed_case_ids' => $changed,
            'regressed_case_ids' => $regressed,
            'recovered_case_ids' => $recovered,
        ];
    }

    private function malformed(int $position): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Evaluation comparison report %d is malformed; score it again with the current evaluator.',
            $position,
        ));
    }
}
