<?php

declare(strict_types=1);

use App\Support\Ai\AgentCopilotProvider;
use Illuminate\Support\Facades\Artisan;

/** @return array<string, mixed> */
function groundedAnswerIdentifiedProviderResponses(
    string $recordedAt,
    string $provider = 'openrouter/azure',
    string $model = 'openai/gpt-5.2',
): array {
    $responses = json_decode(
        file_get_contents(resource_path('evaluations/grounded-answers/baseline-responses.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $responses['run']['source'] = 'provider';
    $responses['run']['provider'] = $provider;
    $responses['run']['model'] = $model;
    $responses['run']['recorded_at'] = $recordedAt;
    $responses['run']['prompt_tokens'] = 1_200;
    $responses['run']['completion_tokens'] = 320;

    return $responses;
}

/** @param array<string, mixed> $responses */
function writeGroundedAnswerComparisonResponses(array $responses): string
{
    $path = tempnam(sys_get_temp_dir(), 'wayfindr-ai-comparison-');

    if (! is_string($path)) {
        throw new RuntimeException('Could not create an evaluation comparison fixture.');
    }

    file_put_contents($path, json_encode($responses, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $path;
}

test('identified provider runs are sorted and compared without resolving a provider', function (): void {
    app()->bind(AgentCopilotProvider::class, fn (): never => throw new LogicException('The offline comparison must not resolve an AI provider.'));

    $first = writeGroundedAnswerComparisonResponses(groundedAnswerIdentifiedProviderResponses(
        '2026-09-01T10:00:00Z',
    ));
    $second = writeGroundedAnswerComparisonResponses(groundedAnswerIdentifiedProviderResponses(
        '2026-09-02T10:00:00Z',
        model: 'openai/gpt-5.3',
    ));

    try {
        $exitCode = Artisan::call('wayfindr:ai-evaluate:compare', [
            'responses' => [$second, $first],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $report = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($report)->toMatchArray([
                'version' => 1,
                'result' => 'passed',
                'identity' => [
                    'identity_status' => 'verified',
                    'suite_digest' => 'sha256:b7f1b0fad2a2ee37c12d9098987b2f491f1dced43e891aee972ac94615a7be48',
                    'prompt_digest' => 'sha256:422a6c9714f1cfa67ab3d324b38193f47169cb144d5f2a55ed46cfa24af11292',
                ],
            ])
            ->and($report['runs'][0]['run'])->toMatchArray([
                'model' => 'openai/gpt-5.2',
                'recorded_at' => '2026-09-01T10:00:00Z',
            ])
            ->and($report['runs'][1]['run'])->toMatchArray([
                'model' => 'openai/gpt-5.3',
                'recorded_at' => '2026-09-02T10:00:00Z',
            ])
            ->and($report['comparisons'][0])->toMatchArray([
                'from_recorded_at' => '2026-09-01T10:00:00Z',
                'to_recorded_at' => '2026-09-02T10:00:00Z',
                'changed_case_ids' => [],
                'regressed_case_ids' => [],
                'recovered_case_ids' => [],
            ])
            ->and(array_filter(
                $report['comparisons'][0]['metric_deltas'],
                fn (int|float $delta): bool => $delta !== 0,
            ))->toBe([])
            ->and($output)->not->toContain('Choose Forgotten password')
            ->not->toContain('Open Billing settings');
    } finally {
        unlink($first);
        unlink($second);
    }
});

test('a newly failing provider run returns failure and names only the regressed case', function (): void {
    $firstResponses = groundedAnswerIdentifiedProviderResponses('2026-09-01T10:00:00Z');
    $secondResponses = groundedAnswerIdentifiedProviderResponses('2026-09-02T10:00:00Z');
    $secondResponses['responses'][0]['answer'] = '';
    $first = writeGroundedAnswerComparisonResponses($firstResponses);
    $second = writeGroundedAnswerComparisonResponses($secondResponses);

    try {
        $exitCode = Artisan::call('wayfindr:ai-evaluate:compare', [
            'responses' => [$first, $second],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $report = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($report['result'])->toBe('failed')
            ->and($report['runs'][0]['result'])->toBe('passed')
            ->and($report['runs'][1]['result'])->toBe('failed')
            ->and($report['comparisons'][0]['regressed_case_ids'])->toBe(['password-reset-link'])
            ->and($output)->not->toContain('reset link expires')
            ->not->toContain('private API key');
    } finally {
        unlink($first);
        unlink($second);
    }
});

test('the comparison command rejects a response outside the current prompt contract', function (): void {
    $firstResponses = groundedAnswerIdentifiedProviderResponses('2026-09-01T10:00:00Z');
    $secondResponses = groundedAnswerIdentifiedProviderResponses('2026-09-02T10:00:00Z');
    $secondResponses['run']['prompt_digest'] = 'sha256:'.str_repeat('c', 64);
    $first = writeGroundedAnswerComparisonResponses($firstResponses);
    $second = writeGroundedAnswerComparisonResponses($secondResponses);

    try {
        $exitCode = Artisan::call('wayfindr:ai-evaluate:compare', [
            'responses' => [$first, $second],
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(2)
            ->and($report)->toBe([
                'result' => 'invalid',
                'error' => 'The evaluation response prompt digest does not match the current prompt contract.',
            ]);
    } finally {
        unlink($first);
        unlink($second);
    }
});

test('legacy response files remain scoreable alone but are rejected from comparisons', function (): void {
    $firstResponses = groundedAnswerIdentifiedProviderResponses('2026-09-01T10:00:00Z');
    $secondResponses = groundedAnswerIdentifiedProviderResponses('2026-09-02T10:00:00Z');

    foreach ([&$firstResponses, &$secondResponses] as &$responses) {
        $responses['version'] = 2;
        unset($responses['run']['suite_digest'], $responses['run']['prompt_digest']);
    }
    unset($responses);

    $first = writeGroundedAnswerComparisonResponses($firstResponses);
    $second = writeGroundedAnswerComparisonResponses($secondResponses);

    try {
        $exitCode = Artisan::call('wayfindr:ai-evaluate:compare', [
            'responses' => [$first, $second],
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('has no verified suite and prompt identity')
            ->not->toContain('Choose Forgotten password');
    } finally {
        unlink($first);
        unlink($second);
    }
});

test('the human comparison report stays content free', function (): void {
    $first = writeGroundedAnswerComparisonResponses(groundedAnswerIdentifiedProviderResponses(
        '2026-09-01T10:00:00Z',
    ));
    $second = writeGroundedAnswerComparisonResponses(groundedAnswerIdentifiedProviderResponses(
        '2026-09-02T10:00:00Z',
        provider: 'openrouter/bedrock',
        model: 'anthropic/claude-sonnet-5',
    ));

    try {
        $exitCode = Artisan::call('wayfindr:ai-evaluate:compare', [
            'responses' => [$first, $second],
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Wayfindr grounded-answer evaluation comparison')
            ->toContain('Run 1: PASSED · openrouter/azure / openai/gpt-5.2 · 2026-09-01T10:00:00Z')
            ->toContain('Run 2: PASSED · openrouter/bedrock / anthropic/claude-sonnet-5 · 2026-09-02T10:00:00Z')
            ->toContain('Regressed cases: none')
            ->toContain('Result: PASS')
            ->not->toContain('Allowed origins')
            ->not->toContain('30-day limited warranty');
    } finally {
        unlink($first);
        unlink($second);
    }
});

test('too few response files return the machine-readable invalid-input contract', function (): void {
    $exitCode = Artisan::call('wayfindr:ai-evaluate:compare', [
        '--json' => true,
    ]);
    $report = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(2)
        ->and($report)->toBe([
            'result' => 'invalid',
            'error' => 'The evaluation comparison requires 2 to 20 response files.',
        ]);
});
