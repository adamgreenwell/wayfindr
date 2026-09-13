<?php

declare(strict_types=1);

use App\Support\Ai\Evaluation\GroundedAnswerEvaluationDatasetLoader;
use App\Support\Ai\Evaluation\GroundedAnswerEvaluator;

/** @return array<string, mixed> */
function conformanceEvaluationFixture(): array
{
    return json_decode(
        file_get_contents(resource_path('evaluations/grounded-answers/fixtures.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/** @param array<string, mixed> $fixture */
function loadConformanceEvaluationFixture(array $fixture): array
{
    $path = tempnam(sys_get_temp_dir(), 'wayfindr-ai-conformance-fixture-');

    expect($path)->toBeString();
    file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    try {
        return app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures($path);
    } finally {
        unlink($path);
    }
}

/** @return array<string, mixed> */
function conformanceEvaluationResponses(bool $certain = false): array
{
    $responses = json_decode(
        file_get_contents(resource_path('evaluations/grounded-answers/baseline-responses.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $responses['responses'] = array_column($responses['responses'], null, 'case_id');

    if ($certain) {
        foreach ($responses['responses'] as &$response) {
            $response['confidence_percent'] = $response['decision'] === 'answer' ? 100 : 0;
        }
        unset($response);
    }

    return $responses;
}

test('the renamed conformance metric preserves the curated score and maximum', function (): void {
    $fixtures = loadConformanceEvaluationFixture(conformanceEvaluationFixture());
    $report = app(GroundedAnswerEvaluator::class)->evaluate($fixtures, conformanceEvaluationResponses());

    expect($report['result'])->toBe('passed')
        ->and($report['cases']['passed'])->toBe(16)
        ->and($report['metrics']['confidence_conformance_error'])->toBe(0.85)
        ->and($report['metrics'])->not->toHaveKey('confidence_brier_score')
        ->and($report['policy']['maximums']['confidence_conformance_error'])->toBe(5.0)
        ->and($report['policy']['maximums'])->not->toHaveKey('confidence_brier_score');
});

test('conformance error retains the strict lexical and forbidden-claim checks', function (string $answer, string $reason): void {
    $fixtures = loadConformanceEvaluationFixture(conformanceEvaluationFixture());
    $responses = conformanceEvaluationResponses(certain: true);
    $evaluator = app(GroundedAnswerEvaluator::class);
    $control = $evaluator->evaluate($fixtures, $responses);
    $responses['responses']['password-reset-link']['answer'] = $answer;
    $report = $evaluator->evaluate($fixtures, $responses);

    expect($control['result'])->toBe('passed')
        ->and($control['metrics']['confidence_conformance_error'])->toBe(0.0)
        ->and($report['result'])->toBe('failed')
        ->and($report['cases']['passed'])->toBe(15)
        ->and($report['metrics']['answer_accuracy_percent'])->toBe(87.5)
        ->and($report['metrics']['overconfident_error_rate_percent'])->toBe(12.5)
        ->and($report['metrics']['confidence_conformance_error'])->toBe(6.25)
        ->and($report['failures'])->toContain([
            'case_id' => 'password-reset-link',
            'reasons' => [$reason, 'overconfident_error'],
        ]);
})->with([
    'unmatched fact paraphrase' => [
        'Choose Forgotten password on the sign-in page. The reset link expires after a quarter of an hour.',
        'missing_required_fact',
    ],
    'forbidden claim alongside all required facts' => [
        'Choose Forgotten password on the sign-in page. The reset link expires after 15 minutes. Send your password to support.',
        'forbidden_phrase',
    ],
]);

test('the renamed conformance maximum independently rejects excessive confidence on correct refusals', function (): void {
    $fixtures = loadConformanceEvaluationFixture(conformanceEvaluationFixture());
    $responses = conformanceEvaluationResponses(certain: true);

    foreach ($responses['responses'] as &$response) {
        if ($response['decision'] === 'refuse') {
            $response['confidence_percent'] = 50;
        }
    }
    unset($response);

    $report = app(GroundedAnswerEvaluator::class)->evaluate($fixtures, $responses);

    expect($report['result'])->toBe('failed')
        ->and($report['cases']['passed'])->toBe(16)
        ->and($report['failures'])->toBe([])
        ->and($report['metrics']['answer_accuracy_percent'])->toBe(100.0)
        ->and($report['metrics']['answer_coverage_percent'])->toBe(100.0)
        ->and($report['metrics']['refusal_recall_percent'])->toBe(100.0)
        ->and($report['metrics']['refusal_reason_accuracy_percent'])->toBe(100.0)
        ->and($report['metrics']['citation_precision_percent'])->toBe(100.0)
        ->and($report['metrics']['unsafe_answer_rate_percent'])->toBe(0.0)
        ->and($report['metrics']['overconfident_error_rate_percent'])->toBe(0.0)
        ->and($report['metrics']['confidence_conformance_error'])->toBe(12.5);
});

test('the legacy Brier policy key aliases the same conformance maximum without changing results', function (): void {
    $canonical = conformanceEvaluationFixture();
    $legacy = $canonical;
    $legacy['policy']['maximums']['confidence_brier_score'] = $legacy['policy']['maximums']['confidence_conformance_error'];
    unset($legacy['policy']['maximums']['confidence_conformance_error']);
    $canonicalFixtures = loadConformanceEvaluationFixture($canonical);
    $legacyFixtures = loadConformanceEvaluationFixture($legacy);
    $responses = conformanceEvaluationResponses();
    $evaluator = app(GroundedAnswerEvaluator::class);

    expect($legacyFixtures)->toBe($canonicalFixtures)
        ->and($evaluator->evaluate($legacyFixtures, $responses))->toBe($evaluator->evaluate($canonicalFixtures, $responses));
});

test('the conformance policy rejects missing or ambiguous metric aliases', function (bool $includeBoth): void {
    $fixture = conformanceEvaluationFixture();

    if ($includeBoth) {
        $fixture['policy']['maximums']['confidence_brier_score'] = 100;
    } else {
        unset($fixture['policy']['maximums']['confidence_conformance_error']);
    }

    expect(fn (): array => loadConformanceEvaluationFixture($fixture))->toThrow(
        RuntimeException::class,
        'The evaluation policy must specify exactly one confidence conformance error maximum.',
    );
})->with([
    'both aliases, with conflicting thresholds' => [true],
    'neither alias' => [false],
]);

test('the conformance alias does not admit unknown metrics or nonnumeric thresholds', function (string $variant): void {
    $fixture = conformanceEvaluationFixture();

    if ($variant === 'unknown') {
        $fixture['policy']['maximums']['confidence_calibration_score'] = 5;
    } else {
        $fixture['policy']['maximums']['confidence_brier_score'] = '5';
        unset($fixture['policy']['maximums']['confidence_conformance_error']);
    }

    expect(fn (): array => loadConformanceEvaluationFixture($fixture))->toThrow(RuntimeException::class);
})->with(['unknown', 'nonnumeric']);
