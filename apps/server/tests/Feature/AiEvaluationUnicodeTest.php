<?php

declare(strict_types=1);

use App\Support\Ai\Evaluation\GroundedAnswerEvaluationDatasetLoader;
use App\Support\Ai\Evaluation\GroundedAnswerEvaluator;

test('canonically equivalent German answers satisfy the same required facts and language gate', function (?string $answer): void {
    $fixtures = app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures(resource_path('evaluations/grounded-answers/fixtures.json'));
    $responses = json_decode(file_get_contents(resource_path('evaluations/grounded-answers/baseline-responses.json')), true, flags: JSON_THROW_ON_ERROR);
    $responses['responses'] = array_column($responses['responses'], null, 'case_id');
    $responses['responses']['german-password-reset-link']['answer'] = $answer
        ?? $responses['responses']['german-password-reset-link']['answer'];
    $evaluator = app(GroundedAnswerEvaluator::class);
    $original = $evaluator->evaluate($fixtures, $responses);
    $responses['responses']['german-password-reset-link']['answer'] = Normalizer::normalize(
        $responses['responses']['german-password-reset-link']['answer'],
        Normalizer::FORM_D,
    );

    $decomposed = $evaluator->evaluate($fixtures, $responses);

    expect($original['result'])->toBe('passed')
        ->and($decomposed['failures'])->toBe([])
        ->and($decomposed['cases']['passed'])->toBe(16)
        ->and($decomposed['metrics'])->toBe($original['metrics']);
})->with([
    'full bundled answer' => [null],
    'short German answer' => ['Passwort vergessen auswählen – für 15 Minuten gültig.'],
]);

test('grounding accepts canonical equivalents in either article or required phrase', function (bool $decomposeArticle): void {
    $fixtures = json_decode(file_get_contents(resource_path('evaluations/grounded-answers/fixtures.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($fixtures['cases'] as &$case) {
        if ($case['id'] !== 'german-password-reset-link') {
            continue;
        }

        if ($decomposeArticle) {
            foreach ($case['articles'] as &$article) {
                $article['body'] = Normalizer::normalize($article['body'], Normalizer::FORM_D);
            }
            unset($article);
        } else {
            foreach ($case['expected']['required_facts'] as &$group) {
                $group = array_map(fn (string $phrase): string => Normalizer::normalize($phrase, Normalizer::FORM_D), $group);
            }
            unset($group);
        }
    }
    unset($case);

    $path = tempnam(sys_get_temp_dir(), 'wayfindr-unicode-fixture-');
    file_put_contents($path, json_encode($fixtures, JSON_THROW_ON_ERROR));

    try {
        $loaded = app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures($path);
        expect($loaded['cases'])->toHaveCount(16);
    } finally {
        unlink($path);
    }
})->with(['decomposed article' => true, 'decomposed required phrase' => false]);

test('decomposition does not evade a forbidden phrase', function (): void {
    $fixtures = app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures(resource_path('evaluations/grounded-answers/fixtures.json'));
    $responses = json_decode(file_get_contents(resource_path('evaluations/grounded-answers/baseline-responses.json')), true, flags: JSON_THROW_ON_ERROR);
    $responses['responses'] = array_column($responses['responses'], null, 'case_id');

    foreach ($fixtures['cases'] as &$case) {
        if ($case['id'] === 'german-password-reset-link') {
            // This synthetic forbidden phrase is already in the complete answer.
            $case['expected']['forbidden_phrases'] = [Normalizer::normalize('gültig', Normalizer::FORM_D)];
        }
    }
    unset($case);

    $report = app(GroundedAnswerEvaluator::class)->evaluate($fixtures, $responses);
    $failure = collect($report['failures'])->firstWhere('case_id', 'german-password-reset-link');

    expect($report['result'])->toBe('failed')
        ->and($failure['reasons'] ?? [])->toContain('forbidden_phrase', 'overconfident_error');
});
