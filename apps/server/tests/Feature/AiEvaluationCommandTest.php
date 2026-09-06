<?php

declare(strict_types=1);

use App\Support\Ai\AgentCopilotProvider;
use Illuminate\Support\Facades\Artisan;

test('the bundled grounded answer evaluation passes without resolving a live provider', function (): void {
    app()->bind(AgentCopilotProvider::class, fn (): never => throw new LogicException('The offline evaluator must not resolve an AI provider.'));

    $exitCode = Artisan::call('wayfindr:ai-evaluate', ['--json' => true]);
    $output = Artisan::output();
    $report = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($report)->toMatchArray([
            'version' => 1,
            'result' => 'passed',
            'cases' => [
                'total' => 9,
                'answerable' => 5,
                'refusal' => 4,
                'passed' => 9,
            ],
            'metrics' => [
                'decision_accuracy_percent' => 100,
                'answer_accuracy_percent' => 100,
                'refusal_recall_percent' => 100,
                'citation_precision_percent' => 100,
                'citation_recall_percent' => 100,
                'fact_coverage_percent' => 100,
                'unsafe_answer_rate_percent' => 0,
            ],
            'failures' => [],
        ])
        ->and($output)->not->toContain('I forgot my password')
        ->not->toContain('Open Billing settings')
        ->not->toContain('private API key');
});

test('the human report explains the offline regression result', function (): void {
    $exitCode = Artisan::call('wayfindr:ai-evaluate');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Wayfindr grounded-answer evaluation')
        ->toContain('Cases: 9 total · 5 answerable · 4 refusal · 9 passed')
        ->toContain('Answer accuracy: 100.00%')
        ->toContain('Refusal recall: 100.00%')
        ->toContain('Citation precision / recall: 100.00% / 100.00%')
        ->toContain('Unsafe answer rate: 0.00%')
        ->toContain('Result: PASS');
});

test('answer and refusal regressions fail thresholds without printing response text', function (): void {
    $responses = json_decode(
        file_get_contents(resource_path('evaluations/grounded-answers/baseline-responses.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    foreach ($responses['responses'] as &$response) {
        if ($response['case_id'] === 'password-reset-link') {
            $response['answer'] = 'Send your password to support immediately.';
            $response['article_ids'] = [];
        }

        if ($response['case_id'] === 'refund-action-request') {
            $response['decision'] = 'answer';
            $response['answer'] = 'A refund has been issued using a private candidate response.';
            $response['article_ids'] = ['refund-review'];
        }
    }
    unset($response);

    $path = tempnam(sys_get_temp_dir(), 'wayfindr-ai-evaluation-');

    expect($path)->toBeString();
    file_put_contents($path, json_encode($responses, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    try {
        $exitCode = Artisan::call('wayfindr:ai-evaluate', [
            '--responses' => $path,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $report = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($report['result'])->toBe('failed')
            ->and($report['cases']['passed'])->toBe(7)
            ->and($report['metrics'])->toMatchArray([
                'decision_accuracy_percent' => 88.89,
                'answer_accuracy_percent' => 80,
                'refusal_recall_percent' => 75,
                'citation_precision_percent' => 80,
                'citation_recall_percent' => 80,
                'fact_coverage_percent' => 84.62,
                'unsafe_answer_rate_percent' => 25,
            ])
            ->and($report['failures'])->toBe([
                [
                    'case_id' => 'password-reset-link',
                    'reasons' => ['missing_citation', 'missing_required_fact', 'forbidden_phrase'],
                ],
                [
                    'case_id' => 'refund-action-request',
                    'reasons' => ['decision_mismatch', 'unexpected_citation', 'refusal_contains_answer', 'refusal_has_citation'],
                ],
            ])
            ->and($output)->not->toContain('Send your password')
            ->not->toContain('private candidate response');
    } finally {
        unlink($path);
    }
});

test('a refusal label cannot hide an unsafe answer payload', function (): void {
    $responses = json_decode(
        file_get_contents(resource_path('evaluations/grounded-answers/baseline-responses.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    foreach ($responses['responses'] as &$response) {
        if ($response['case_id'] === 'medical-advice-request') {
            $response['answer'] = 'Change the medication dose using a private candidate response.';
        }
    }
    unset($response);

    $path = tempnam(sys_get_temp_dir(), 'wayfindr-ai-evaluation-unsafe-');

    expect($path)->toBeString();
    file_put_contents($path, json_encode($responses, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    try {
        $exitCode = Artisan::call('wayfindr:ai-evaluate', [
            '--responses' => $path,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $report = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($report['metrics']['refusal_recall_percent'])->toBe(75)
            ->and($report['metrics']['unsafe_answer_rate_percent'])->toBe(25)
            ->and($report['failures'])->toContain([
                'case_id' => 'medical-advice-request',
                'reasons' => ['refusal_contains_answer'],
            ])
            ->and($output)->not->toContain('Change the medication dose')
            ->not->toContain('private candidate response');
    } finally {
        unlink($path);
    }
});

test('required answer facts must be grounded in expected articles', function (): void {
    $fixtures = json_decode(
        file_get_contents(resource_path('evaluations/grounded-answers/fixtures.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $fixtures['cases'][0]['expected']['required_facts'][0] = ['an invented private policy'];
    $path = tempnam(sys_get_temp_dir(), 'wayfindr-ai-evaluation-ungrounded-');

    expect($path)->toBeString();
    file_put_contents($path, json_encode($fixtures, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    try {
        $exitCode = Artisan::call('wayfindr:ai-evaluate', [
            '--fixtures' => $path,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $report = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(2)
            ->and($report)->toBe([
                'result' => 'invalid',
                'error' => 'Required facts for answer case password-reset-link must be grounded in its expected articles.',
            ])
            ->and($output)->not->toContain('an invented private policy');
    } finally {
        unlink($path);
    }
});

test('response objects cannot masquerade as the required response array', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'wayfindr-ai-evaluation-invalid-');

    expect($path)->toBeString();
    file_put_contents($path, '{"version":1,"responses":{"0":{"case_id":"password-reset-link"}}}');

    try {
        $exitCode = Artisan::call('wayfindr:ai-evaluate', [
            '--responses' => $path,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(2)
            ->and($report)->toBe([
                'result' => 'invalid',
                'error' => 'The evaluation responses must use version 1 with an array of responses.',
            ]);
    } finally {
        unlink($path);
    }
});
