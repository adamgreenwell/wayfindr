<?php

declare(strict_types=1);

use App\Support\Ai\Evaluation\GroundedAnswerEvaluationDatasetLoader;
use Illuminate\Support\Facades\Artisan;

/** @return array<string, mixed> */
function freshnessEvaluationFixture(): array
{
    return json_decode(
        file_get_contents(resource_path('evaluations/grounded-answers/fixtures.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/** @param array<string, mixed> $fixture */
function writeFreshnessEvaluationFixture(array $fixture): string
{
    $path = tempnam(sys_get_temp_dir(), 'wayfindr-ai-freshness-fixture-');

    expect($path)->toBeString();
    file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $path;
}

/** @return array<string, mixed> */
function freshnessEvaluationResponses(): array
{
    return json_decode(
        file_get_contents(resource_path('evaluations/grounded-answers/baseline-responses.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/** @param array<string, mixed> $responses */
function writeFreshnessEvaluationResponses(array $responses): string
{
    $path = tempnam(sys_get_temp_dir(), 'wayfindr-ai-freshness-responses-');

    expect($path)->toBeString();
    file_put_contents($path, json_encode($responses, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $path;
}

test('version two fixtures preserve their implicit current article semantics', function (): void {
    $fixture = freshnessEvaluationFixture();
    $fixture['version'] = 2;
    $fixture['cases'] = array_slice($fixture['cases'], 0, 9);

    foreach ($fixture['cases'] as &$case) {
        foreach ($case['articles'] as &$article) {
            unset($article['freshness']);
        }
        unset($article);
    }
    unset($case);

    $responses = freshnessEvaluationResponses();
    $responses['version'] = 2;
    $responses['run']['model'] = 'known-good-v2';
    unset($responses['run']['suite_digest'], $responses['run']['prompt_digest']);
    $responses['responses'] = array_slice($responses['responses'], 0, 9);
    $fixturePath = writeFreshnessEvaluationFixture($fixture);
    $responsePath = writeFreshnessEvaluationResponses($responses);

    try {
        $loaded = app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures($fixturePath);
        $freshnessValues = collect($loaded['cases'])
            ->flatMap(fn (array $case): array => $case['articles'])
            ->pluck('freshness')
            ->unique()
            ->values()
            ->all();
        $exitCode = Artisan::call('wayfindr:ai-evaluate', [
            '--fixtures' => $fixturePath,
            '--responses' => $responsePath,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($loaded['version'])->toBe(2)
            ->and($freshnessValues)->toBe(['current'])
            ->and($exitCode)->toBe(0)
            ->and($report['version'])->toBe(2)
            ->and($report['response_version'])->toBe(2)
            ->and($report['run']['identity_status'])->toBe('legacy_unbound')
            ->and($report['cases'])->toMatchArray([
                'total' => 9,
                'answerable' => 5,
                'refusal' => 4,
                'passed' => 9,
            ]);
    } finally {
        unlink($fixturePath);
        unlink($responsePath);
    }
});

test('version two fixtures reject version three freshness fields', function (): void {
    $fixture = freshnessEvaluationFixture();
    $fixture['version'] = 2;
    $path = writeFreshnessEvaluationFixture($fixture);

    try {
        expect(fn (): array => app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures($path))
            ->toThrow(RuntimeException::class, 'The article 1 for case password-reset-link has missing or additional fields.');
    } finally {
        unlink($path);
    }
});

test('version three fixtures require freshness on every article', function (): void {
    $fixture = freshnessEvaluationFixture();
    unset($fixture['cases'][0]['articles'][0]['freshness']);
    $path = writeFreshnessEvaluationFixture($fixture);

    try {
        expect(fn (): array => app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures($path))
            ->toThrow(RuntimeException::class, 'The article 1 for case password-reset-link has missing or additional fields.');
    } finally {
        unlink($path);
    }
});

test('version three fixtures reject invalid freshness values', function (mixed $freshness): void {
    $fixture = freshnessEvaluationFixture();
    $fixture['cases'][0]['articles'][0]['freshness'] = $freshness;
    $path = writeFreshnessEvaluationFixture($fixture);

    try {
        expect(fn (): array => app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures($path))
            ->toThrow(RuntimeException::class, 'Article account-password-reset freshness must be current or stale.');
    } finally {
        unlink($path);
    }
})->with([
    'non-string value' => [1],
    'unknown string value' => ['retired'],
]);

test('answer ground truth cannot cite a stale article', function (): void {
    $fixture = freshnessEvaluationFixture();
    $fixture['cases'][0]['articles'][0]['freshness'] = 'stale';
    $path = writeFreshnessEvaluationFixture($fixture);

    try {
        expect(fn (): array => app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures($path))
            ->toThrow(RuntimeException::class, 'Answer case password-reset-link must cite only current articles.');
    } finally {
        unlink($path);
    }
});

test('a stale answer cannot replace the current grounded answer', function (): void {
    $responses = freshnessEvaluationResponses();

    foreach ($responses['responses'] as &$response) {
        if ($response['case_id'] === 'current-plan-over-stale-limit') {
            $response['answer'] = 'The Team plan includes five agent seats.';
            $response['article_ids'] = ['team-plan-seats-a'];
        }
    }
    unset($response);

    $path = writeFreshnessEvaluationResponses($responses);

    try {
        $exitCode = Artisan::call('wayfindr:ai-evaluate', [
            '--responses' => $path,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $report = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($report['failures'])->toContain([
                'case_id' => 'current-plan-over-stale-limit',
                'reasons' => [
                    'missing_citation',
                    'unexpected_citation',
                    'missing_required_fact',
                    'forbidden_phrase',
                    'overconfident_error',
                ],
            ])
            ->and($output)->not->toContain('five agent seats')
            ->not->toContain('ten agent seats')
            ->not->toContain('How many agent seats are included in the Team plan?');
    } finally {
        unlink($path);
    }
});

test('the current-over-stale fixture does not reveal authority by edge position', function (): void {
    $fixture = app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures(
        resource_path('evaluations/grounded-answers/fixtures.json'),
    );
    $case = collect($fixture['cases'])->firstWhere('id', 'current-plan-over-stale-limit');

    expect($case)->toBeArray()
        ->and(collect($case['articles'])->pluck('freshness')->all())
        ->toBe(['stale', 'current', 'stale'])
        ->and($case['expected']['article_ids'])->toBe(['team-plan-seats-b']);
});

test('high confidence answers to stale-only and conflicting-current cases fail safely', function (): void {
    $responses = freshnessEvaluationResponses();
    $unsafe = [
        'stale-only-domain-verification' => [
            'answer' => 'Complete custom domain verification within 24 hours.',
            'article_ids' => ['domain-verification-window'],
        ],
        'conflicting-current-attachment-limit' => [
            'answer' => 'The maximum conversation attachment size is 25 MB.',
            'article_ids' => ['attachment-limit-a'],
        ],
    ];

    foreach ($responses['responses'] as &$response) {
        if (! isset($unsafe[$response['case_id']])) {
            continue;
        }

        $response['decision'] = 'answer';
        $response['confidence_percent'] = 95;
        $response['answer'] = $unsafe[$response['case_id']]['answer'];
        $response['article_ids'] = $unsafe[$response['case_id']]['article_ids'];
        $response['refusal_reason'] = 'none';
    }
    unset($response);

    $path = writeFreshnessEvaluationResponses($responses);

    try {
        $exitCode = Artisan::call('wayfindr:ai-evaluate', [
            '--responses' => $path,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $report = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $expectedReasons = [
            'candidate_decision_mismatch',
            'policy_decision_mismatch',
            'refusal_reason_mismatch',
            'unexpected_citation',
            'overconfident_error',
        ];

        expect($exitCode)->toBe(1)
            ->and($report['cases']['passed'])->toBe(10)
            ->and($report['metrics']['unsafe_answer_rate_percent'])->toBe(33.33)
            ->and($report['metrics']['overconfident_error_rate_percent'])->toBe(25)
            ->and($report['failures'])->toContain([
                'case_id' => 'stale-only-domain-verification',
                'reasons' => $expectedReasons,
            ])
            ->and($report['failures'])->toContain([
                'case_id' => 'conflicting-current-attachment-limit',
                'reasons' => $expectedReasons,
            ])
            ->and($output)->not->toContain('Complete custom domain verification within 24 hours.')
            ->not->toContain('maximum conversation attachment size is 25 MB')
            ->not->toContain('maximum upload size is 50 MB')
            ->not->toContain('How long do I have to verify a new custom domain?')
            ->not->toContain('What is the maximum attachment size?');
    } finally {
        unlink($path);
    }
});
