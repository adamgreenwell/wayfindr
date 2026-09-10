<?php

declare(strict_types=1);

use App\Support\Ai\AiContextSanitizer;
use App\Support\Ai\Evaluation\GroundedAnswerEvaluationIdentity;
use App\Support\Ai\Evaluation\GroundedAnswerEvaluationPromptBuilder;

test('associative key order does not change an evaluation identity', function (): void {
    $identity = new GroundedAnswerEvaluationIdentity;
    $suite = [
        'version' => 3,
        'policy' => [
            'threshold' => 80,
            'labels' => ['answer', 'refuse'],
        ],
        'cases' => [
            [
                'id' => 'unicode-link',
                'article' => [
                    'title' => 'Ayuda 🌊',
                    'body' => 'Consulte https://example.test/ayuda/rápida.',
                ],
            ],
        ],
    ];
    $reordered = [
        'cases' => [
            [
                'article' => [
                    'body' => 'Consulte https://example.test/ayuda/rápida.',
                    'title' => 'Ayuda 🌊',
                ],
                'id' => 'unicode-link',
            ],
        ],
        'policy' => [
            'labels' => ['answer', 'refuse'],
            'threshold' => 80,
        ],
        'version' => 3,
    ];

    expect($identity->suite($suite))
        ->toMatch('/\Asha256:[a-f0-9]{64}\z/')
        ->toBe($identity->suite($reordered));
});

test('semantic scalar and list changes alter an evaluation identity', function (): void {
    $identity = new GroundedAnswerEvaluationIdentity;
    $suite = [
        'policy' => ['threshold' => 80],
        'case_ids' => ['first-case', 'second-case'],
    ];

    expect($identity->suite($suite))
        ->not->toBe($identity->suite([
            'policy' => ['threshold' => 81],
            'case_ids' => ['first-case', 'second-case'],
        ]))
        ->not->toBe($identity->suite([
            'policy' => ['threshold' => 80],
            'case_ids' => ['second-case', 'first-case'],
        ]));
});

test('suite and prompt contract namespaces cannot collide', function (): void {
    $identity = new GroundedAnswerEvaluationIdentity;
    $value = [
        'instructions' => ['Use only supplied facts.', 'Refuse when unsupported.'],
        'threshold' => 80,
    ];

    expect($identity->suite($value))
        ->not->toBe($identity->promptContract($value));
});

test('encoding failures surface as json exceptions', function (): void {
    $identity = new GroundedAnswerEvaluationIdentity;

    expect(fn (): string => $identity->suite(['invalid_utf8' => "\xB1\x31"]))
        ->toThrow(JsonException::class);
});

test('the prompt identity binds every sanitized provider request field', function (): void {
    $identity = new GroundedAnswerEvaluationIdentity;
    $builder = new GroundedAnswerEvaluationPromptBuilder(new AiContextSanitizer);
    $case = [
        'id' => 'private-example',
        'question' => 'Where is api_key=private-example-value stored?',
        'articles' => [[
            'id' => 'private-article',
            'title' => 'Private example',
            'body' => 'Use the synthetic settings page.',
        ]],
        'expected' => [],
    ];
    $contract = $builder->contract([$case], 80);
    $changedCase = $case;
    $changedCase['question'] = 'Which synthetic settings page should I use?';

    expect($contract['requests'][0])->toMatchArray([
        'purpose' => 'grounded_answer_evaluation',
        'timeout_seconds' => 75,
    ])
        ->and($contract['requests'][0]['input'])->toContain('api_key=[REDACTED]')
        ->not->toContain('private-example-value')
        ->and($identity->promptContract($contract))
        ->not->toBe($identity->promptContract($builder->contract([$changedCase], 80)))
        ->not->toBe($identity->promptContract($builder->contract([$case], 81)));
});
