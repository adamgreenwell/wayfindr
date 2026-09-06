<?php

declare(strict_types=1);

use App\Support\Ai\Evaluation\GroundedAnswerEvaluationOutputParser;

test('a strict grounded answer candidate is parsed', function (): void {
    $result = app(GroundedAnswerEvaluationOutputParser::class)->parse(json_encode([
        'refusal_reason' => 'none',
        'article_ids' => ['account-password-reset'],
        'answer' => 'Use the published reset flow.',
        'confidence_percent' => 92.5,
        'decision' => 'answer',
    ], JSON_THROW_ON_ERROR));

    expect($result)->not->toBeNull()
        ->and($result->decision)->toBe('answer')
        ->and($result->confidencePercent)->toBe(92.5)
        ->and($result->answer)->toBe('Use the published reset flow.')
        ->and($result->articleIds)->toBe(['account-password-reset'])
        ->and($result->refusalReason)->toBe('none');
});

test('malformed grounded answer candidates are rejected', function (string $output): void {
    expect(app(GroundedAnswerEvaluationOutputParser::class)->parse($output))->toBeNull();
})->with([
    'markdown fence' => ['```json\n{"decision":"refuse"}\n```'],
    'array envelope' => ['[]'],
    'missing fields' => ['{"decision":"refuse"}'],
    'additional field' => ['{"decision":"refuse","confidence_percent":0,"answer":"","article_ids":[],"refusal_reason":"unsupported","extra":true}'],
    'unknown decision' => ['{"decision":"handoff","confidence_percent":0,"answer":"","article_ids":[],"refusal_reason":"unsupported"}'],
    'string confidence' => ['{"decision":"refuse","confidence_percent":"0","answer":"","article_ids":[],"refusal_reason":"unsupported"}'],
    'negative confidence' => ['{"decision":"refuse","confidence_percent":-1,"answer":"","article_ids":[],"refusal_reason":"unsupported"}'],
    'oversized confidence' => ['{"decision":"answer","confidence_percent":101,"answer":"answer","article_ids":[],"refusal_reason":"none"}'],
    'object article ids' => ['{"decision":"answer","confidence_percent":90,"answer":"answer","article_ids":{"0":"article-id"},"refusal_reason":"none"}'],
    'duplicate article ids' => ['{"decision":"answer","confidence_percent":90,"answer":"answer","article_ids":["article-id","article-id"],"refusal_reason":"none"}'],
    'short article id' => ['{"decision":"answer","confidence_percent":90,"answer":"answer","article_ids":["a"],"refusal_reason":"none"}'],
    'unknown refusal reason' => ['{"decision":"refuse","confidence_percent":0,"answer":"","article_ids":[],"refusal_reason":"because"}'],
    'oversized output' => [str_repeat('a', 8_001)],
]);
