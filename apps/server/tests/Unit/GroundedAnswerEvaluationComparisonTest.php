<?php

declare(strict_types=1);

use App\Support\Ai\Evaluation\GroundedAnswerEvaluationComparison;

/**
 * @param  array<string, float|int>  $metrics
 * @param  list<array{case_id: string, reasons: list<string>}>  $failures
 * @return array<string, mixed>
 */
function groundedAnswerComparisonScoredReport(
    string $recordedAt,
    string $result = 'passed',
    array $metrics = [
        'answer_accuracy_percent' => 100.0,
        'unsafe_answer_rate_percent' => 0.0,
        'confidence_brier_score' => 3.25,
    ],
    array $failures = [],
    string $provider = 'openrouter/azure',
    string $model = 'openai/gpt-5.2',
): array {
    return [
        'version' => 2,
        'result' => $result,
        'run' => [
            'source' => 'provider',
            'provider' => $provider,
            'model' => $model,
            'recorded_at' => $recordedAt,
            'prompt_tokens' => 1_200,
            'completion_tokens' => 320,
            'identity_status' => 'verified',
            'suite_digest' => 'sha256:'.str_repeat('a', 64),
            'prompt_digest' => 'sha256:'.str_repeat('b', 64),
        ],
        'policy' => ['private' => 'comparison does not copy policy data'],
        'cases' => [
            'total' => 3,
            'answerable' => 2,
            'refusal' => 1,
            'passed' => 3 - count($failures),
        ],
        'metrics' => $metrics,
        'failures' => $failures,
    ];
}

test('verified provider reports produce ordered deltas and content-free case transitions', function (): void {
    $comparison = new GroundedAnswerEvaluationComparison;
    $first = groundedAnswerComparisonScoredReport(
        '2026-09-01T10:00:00Z',
        result: 'failed',
        metrics: [
            'answer_accuracy_percent' => 80.0,
            'unsafe_answer_rate_percent' => 0.0,
            'confidence_brier_score' => 12.5,
        ],
        failures: [
            ['case_id' => 'alpha-case', 'reasons' => ['missing_citation']],
            ['case_id' => 'beta-case', 'reasons' => ['unexpected_refusal']],
        ],
    );
    $second = groundedAnswerComparisonScoredReport(
        '2026-09-02T10:00:00Z',
        result: 'failed',
        metrics: [
            'answer_accuracy_percent' => 70.0,
            'unsafe_answer_rate_percent' => 25.0,
            'confidence_brier_score' => 18.75,
        ],
        failures: [
            ['case_id' => 'gamma-case', 'reasons' => ['overconfident_error']],
            ['case_id' => 'alpha-case', 'reasons' => ['missing_required_fact']],
        ],
        model: 'openai/gpt-5.3',
    );
    $third = groundedAnswerComparisonScoredReport(
        '2026-09-03T10:00:00Z',
        provider: 'openrouter/bedrock',
        model: 'anthropic/claude-sonnet-5',
    );

    $result = $comparison->compare([$first, $second, $third]);

    expect($result)->toBe([
        'version' => 1,
        'result' => 'failed',
        'identity' => [
            'identity_status' => 'verified',
            'suite_digest' => 'sha256:'.str_repeat('a', 64),
            'prompt_digest' => 'sha256:'.str_repeat('b', 64),
        ],
        'runs' => [
            [
                'result' => 'failed',
                'run' => [
                    'source' => 'provider',
                    'provider' => 'openrouter/azure',
                    'model' => 'openai/gpt-5.2',
                    'recorded_at' => '2026-09-01T10:00:00Z',
                    'prompt_tokens' => 1_200,
                    'completion_tokens' => 320,
                ],
                'cases' => ['total' => 3, 'answerable' => 2, 'refusal' => 1, 'passed' => 1],
                'metrics' => [
                    'answer_accuracy_percent' => 80.0,
                    'confidence_brier_score' => 12.5,
                    'unsafe_answer_rate_percent' => 0.0,
                ],
            ],
            [
                'result' => 'failed',
                'run' => [
                    'source' => 'provider',
                    'provider' => 'openrouter/azure',
                    'model' => 'openai/gpt-5.3',
                    'recorded_at' => '2026-09-02T10:00:00Z',
                    'prompt_tokens' => 1_200,
                    'completion_tokens' => 320,
                ],
                'cases' => ['total' => 3, 'answerable' => 2, 'refusal' => 1, 'passed' => 1],
                'metrics' => [
                    'answer_accuracy_percent' => 70.0,
                    'confidence_brier_score' => 18.75,
                    'unsafe_answer_rate_percent' => 25.0,
                ],
            ],
            [
                'result' => 'passed',
                'run' => [
                    'source' => 'provider',
                    'provider' => 'openrouter/bedrock',
                    'model' => 'anthropic/claude-sonnet-5',
                    'recorded_at' => '2026-09-03T10:00:00Z',
                    'prompt_tokens' => 1_200,
                    'completion_tokens' => 320,
                ],
                'cases' => ['total' => 3, 'answerable' => 2, 'refusal' => 1, 'passed' => 3],
                'metrics' => [
                    'answer_accuracy_percent' => 100.0,
                    'confidence_brier_score' => 3.25,
                    'unsafe_answer_rate_percent' => 0.0,
                ],
            ],
        ],
        'comparisons' => [
            [
                'from_recorded_at' => '2026-09-01T10:00:00Z',
                'to_recorded_at' => '2026-09-02T10:00:00Z',
                'metric_deltas' => [
                    'answer_accuracy_percent' => -10.0,
                    'confidence_brier_score' => 6.25,
                    'unsafe_answer_rate_percent' => 25.0,
                ],
                'changed_case_ids' => ['alpha-case'],
                'regressed_case_ids' => ['gamma-case'],
                'recovered_case_ids' => ['beta-case'],
            ],
            [
                'from_recorded_at' => '2026-09-02T10:00:00Z',
                'to_recorded_at' => '2026-09-03T10:00:00Z',
                'metric_deltas' => [
                    'answer_accuracy_percent' => 30.0,
                    'confidence_brier_score' => -15.5,
                    'unsafe_answer_rate_percent' => -25.0,
                ],
                'changed_case_ids' => [],
                'regressed_case_ids' => [],
                'recovered_case_ids' => ['alpha-case', 'gamma-case'],
            ],
        ],
    ]);
});

test('input order does not change a comparison', function (): void {
    $comparison = new GroundedAnswerEvaluationComparison;
    $first = groundedAnswerComparisonScoredReport('2026-09-01T10:00:00Z');
    $second = groundedAnswerComparisonScoredReport('2026-09-02T10:00:00Z');
    $third = groundedAnswerComparisonScoredReport('2026-09-03T10:00:00Z');

    expect($comparison->compare([$third, $first, $second]))
        ->toBe($comparison->compare([$first, $second, $third]))
        ->and($comparison->compare([$first, $second])['result'])->toBe('passed');
});

test('legacy or otherwise unverified reports cannot claim comparability', function (array $identity): void {
    $legacy = groundedAnswerComparisonScoredReport('2026-09-01T10:00:00Z');
    $current = groundedAnswerComparisonScoredReport('2026-09-02T10:00:00Z');
    $legacy['run'] = [...$legacy['run'], ...$identity];

    expect(fn (): array => (new GroundedAnswerEvaluationComparison)->compare([$legacy, $current]))
        ->toThrow(
            RuntimeException::class,
            'Evaluation comparison report 1 has no verified suite and prompt identity; capture and score it again with the current evaluator.',
        );
})->with([
    'legacy unbound report' => [[
        'identity_status' => 'legacy_unbound',
        'suite_digest' => null,
        'prompt_digest' => null,
    ]],
    'missing legacy identity fields' => [[
        'identity_status' => null,
        'suite_digest' => null,
        'prompt_digest' => null,
    ]],
    'invalid suite digest' => [[
        'identity_status' => 'verified',
        'suite_digest' => 'private suite text',
        'prompt_digest' => 'sha256:'.str_repeat('b', 64),
    ]],
    'invalid prompt digest' => [[
        'identity_status' => 'verified',
        'suite_digest' => 'sha256:'.str_repeat('a', 64),
        'prompt_digest' => 'private prompt text',
    ]],
]);

test('curated reports cannot be compared as provider evidence', function (): void {
    $curated = groundedAnswerComparisonScoredReport('2026-09-01T10:00:00Z');
    $provider = groundedAnswerComparisonScoredReport('2026-09-02T10:00:00Z');
    $curated['run']['source'] = 'curated';

    expect(fn (): array => (new GroundedAnswerEvaluationComparison)->compare([$curated, $provider]))
        ->toThrow(
            RuntimeException::class,
            'Evaluation comparison report 1 is not a provider run; only provider runs can be compared.',
        );
});

test('reports with different suite or prompt identities are rejected', function (string $field): void {
    $first = groundedAnswerComparisonScoredReport('2026-09-01T10:00:00Z');
    $second = groundedAnswerComparisonScoredReport('2026-09-02T10:00:00Z');
    $second['run'][$field] = 'sha256:'.str_repeat('c', 64);

    expect(fn (): array => (new GroundedAnswerEvaluationComparison)->compare([$first, $second]))
        ->toThrow(
            RuntimeException::class,
            'Evaluation comparison reports must share the same suite_digest and prompt_digest.',
        );
})->with(['suite_digest', 'prompt_digest']);

test('duplicate timestamps are rejected after input ordering', function (): void {
    $first = groundedAnswerComparisonScoredReport('2026-09-01T10:00:00Z');
    $duplicate = groundedAnswerComparisonScoredReport(
        '2026-09-01T10:00:00Z',
        model: 'openai/gpt-5.3',
    );

    expect(fn (): array => (new GroundedAnswerEvaluationComparison)->compare([$duplicate, $first]))
        ->toThrow(
            RuntimeException::class,
            'Evaluation comparison reports must have unique recorded_at timestamps.',
        );
});

test('comparison count is bounded', function (array $reports): void {
    expect(fn (): array => (new GroundedAnswerEvaluationComparison)->compare($reports))
        ->toThrow(
            RuntimeException::class,
            'The evaluation comparison requires a list of 2 to 20 scored provider reports.',
        );
})->with([
    'one report' => [[groundedAnswerComparisonScoredReport('2026-09-01T10:00:00Z')]],
    'twenty-one reports' => [array_map(
        fn (int $day): array => groundedAnswerComparisonScoredReport(sprintf('2026-09-%02dT10:00:00Z', $day)),
        range(1, 21),
    )],
]);

test('comparison output cannot copy question article or answer fields', function (): void {
    $first = groundedAnswerComparisonScoredReport(
        '2026-09-01T10:00:00Z',
        result: 'failed',
        failures: [[
            'case_id' => 'alpha-case',
            'reasons' => ['missing_required_fact'],
            'answer' => 'private failure answer',
        ]],
    );
    $second = groundedAnswerComparisonScoredReport('2026-09-02T10:00:00Z');

    foreach ([&$first, &$second] as &$report) {
        $report['question'] = 'private synthetic question';
        $report['articles'] = [['body' => 'private synthetic article']];
        $report['answer'] = 'private synthetic answer';
        $report['run']['question'] = 'private run question';
        $report['cases']['article'] = 'private case article';
    }
    unset($report);

    $output = (new GroundedAnswerEvaluationComparison)->compare([$first, $second]);
    $keys = [];
    $collectKeys = function (array $value) use (&$collectKeys, &$keys): void {
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($item)) {
                $collectKeys($item);
            }
        }
    };
    $collectKeys($output);
    $encoded = json_encode($output, JSON_THROW_ON_ERROR);

    expect($keys)->not->toContain('question', 'article', 'articles', 'answer')
        ->and($encoded)->not->toContain('private synthetic', 'private run', 'private case', 'private failure');
});
