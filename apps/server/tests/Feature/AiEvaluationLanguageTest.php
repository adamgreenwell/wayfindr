<?php

declare(strict_types=1);

use App\Support\Ai\Evaluation\GroundedAnswerEvaluationDatasetLoader;
use App\Support\Ai\Evaluation\GroundedAnswerEvaluationIdentity;
use App\Support\Ai\Evaluation\GroundedAnswerLanguageMatcher;

/** @return array<string, mixed> */
function languageEvaluationFixtureData(): array
{
    return json_decode(
        file_get_contents(resource_path('evaluations/grounded-answers/fixtures.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/** @param array<string, mixed> $fixture */
function writeLanguageEvaluationFixture(array $fixture): string
{
    $path = tempnam(sys_get_temp_dir(), 'wayfindr-ai-language-fixture-');

    expect($path)->toBeString();
    file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $path;
}

test('fixture version four pins the bounded German language contract', function (): void {
    $fixture = app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures(
        resource_path('evaluations/grounded-answers/fixtures.json'),
    );
    $germanCase = collect($fixture['cases'])->firstWhere('id', 'german-password-reset-link');
    $otherLanguageGates = collect($fixture['cases'])
        ->reject(fn (array $case): bool => $case['id'] === 'german-password-reset-link')
        ->pluck('expected.answer_language')
        ->filter()
        ->values()
        ->all();

    expect($fixture['version'])->toBe(4)
        ->and($fixture['language_evaluation'])->toBe([
            'classifier' => GroundedAnswerLanguageMatcher::CLASSIFIER,
            'classifier_version' => GroundedAnswerLanguageMatcher::CLASSIFIER_VERSION,
            'target_language' => 'de',
            'comparison_scope' => 'all_classifier_profiles',
            'minimum_score_margin' => 0.05,
            'mixed_language_check' => [
                'strategy' => 'english_marker_windows_v1',
                'comparison_language' => 'en',
                'comparison_markers' => GroundedAnswerLanguageMatcher::COMPARISON_MARKERS,
                'window_tokens' => 5,
                'minimum_marker_occurrences' => 2,
                'maximum_tokens' => 200,
            ],
        ])
        ->and($germanCase['expected']['answer_language'])->toBe('de')
        ->and($otherLanguageGates)->toBe([]);
});

test('fixture version three remains loadable with its original identity', function (): void {
    $fixture = languageEvaluationFixtureData();
    $fixture['version'] = 3;
    unset($fixture['language_evaluation']);

    foreach ($fixture['cases'] as &$case) {
        unset($case['expected']['answer_language']);
    }
    unset($case);

    $path = writeLanguageEvaluationFixture($fixture);

    try {
        $loaded = app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures($path);

        expect($loaded['version'])->toBe(3)
            ->and($loaded)->not->toHaveKey('language_evaluation')
            ->and(collect($loaded['cases'])->every(
                fn (array $case): bool => ! array_key_exists('answer_language', $case['expected']),
            ))->toBeTrue()
            ->and(app(GroundedAnswerEvaluationIdentity::class)->suite($loaded))
            ->toBe('sha256:effcddefbdba6cfcc1ee4ae87b19c32993318742e47c456d51f08cd40dad51fb');
    } finally {
        unlink($path);
    }
});

test('every language classifier setting and case selection change the suite identity', function (): void {
    $identity = app(GroundedAnswerEvaluationIdentity::class);
    $fixture = app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures(
        resource_path('evaluations/grounded-answers/fixtures.json'),
    );
    $contractChanges = [
        ['classifier' => 'different/classifier'],
        ['classifier_version' => '5.3.2'],
        ['target_language' => 'en'],
        ['comparison_scope' => 'selected_profiles'],
        ['minimum_score_margin' => 0.06],
    ];
    $variants = [$fixture];

    foreach ($contractChanges as $change) {
        $variant = $fixture;
        $variant['language_evaluation'] = array_replace($variant['language_evaluation'], $change);
        $variants[] = $variant;
    }

    foreach ([
        ['strategy' => 'different_strategy'],
        ['comparison_language' => 'nl'],
        ['comparison_markers' => array_merge(GroundedAnswerLanguageMatcher::COMPARISON_MARKERS, ['zebra'])],
        ['window_tokens' => 6],
        ['minimum_marker_occurrences' => 3],
        ['maximum_tokens' => 201],
    ] as $change) {
        $variant = $fixture;
        $variant['language_evaluation']['mixed_language_check'] = array_replace(
            $variant['language_evaluation']['mixed_language_check'],
            $change,
        );
        $variants[] = $variant;
    }

    $ungatedFixture = $fixture;

    foreach ($ungatedFixture['cases'] as &$case) {
        if ($case['id'] === 'german-password-reset-link') {
            $case['expected']['answer_language'] = null;
        }
    }
    unset($case);
    $variants[] = $ungatedFixture;

    $digests = array_map(
        fn (array $variant): string => $identity->suite($variant),
        $variants,
    );

    expect(array_unique($digests))->toHaveCount(count($variants));
});

test('fixture version four rejects a mismatched classifier contract', function (array $changes, string $message): void {
    $fixture = languageEvaluationFixtureData();
    $fixture['language_evaluation'] = array_replace($fixture['language_evaluation'], $changes);
    $path = writeLanguageEvaluationFixture($fixture);

    try {
        expect(fn (): array => app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures($path))
            ->toThrow(RuntimeException::class, $message);
    } finally {
        unlink($path);
    }
})->with([
    'classifier' => [
        ['classifier' => 'different/classifier'],
        'The fixture language classifier and version must match the pinned implementation.',
    ],
    'classifier version' => [
        ['classifier_version' => '5.3.0'],
        'The fixture language classifier and version must match the pinned implementation.',
    ],
    'target language' => [
        ['target_language' => 'en'],
        'The fixture language evaluation contract must use the pinned German all-profile policy.',
    ],
    'comparison scope' => [
        ['comparison_scope' => 'selected_languages'],
        'The fixture language evaluation contract must use the pinned German all-profile policy.',
    ],
    'zero margin' => [
        ['minimum_score_margin' => 0],
        'The fixture language score margin must be a number greater than 0 and at most 1.',
    ],
    'mixed language check object' => [
        ['mixed_language_check' => null],
        'The fixture mixed-language check must be an object.',
    ],
    'oversized margin' => [
        ['minimum_score_margin' => 1.01],
        'The fixture language score margin must be a number greater than 0 and at most 1.',
    ],
]);

test('fixture version four rejects invalid mixed-language settings', function (array $changes, string $message): void {
    $fixture = languageEvaluationFixtureData();
    $fixture['language_evaluation']['mixed_language_check'] = array_replace(
        $fixture['language_evaluation']['mixed_language_check'],
        $changes,
    );
    $path = writeLanguageEvaluationFixture($fixture);

    try {
        expect(fn (): array => app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures($path))
            ->toThrow(RuntimeException::class, $message);
    } finally {
        unlink($path);
    }
})->with([
    'strategy' => [
        ['strategy' => 'different_strategy'],
        'The fixture mixed-language check must use the pinned English marker-window strategy.',
    ],
    'comparison language' => [
        ['comparison_language' => 'nl'],
        'The fixture mixed-language check must use the pinned English marker-window strategy.',
    ],
    'marker shape' => [
        ['comparison_markers' => ['English']],
        'The fixture mixed-language comparison markers must be 1 to 100 lowercase ASCII words.',
    ],
    'duplicate markers' => [
        ['comparison_markers' => array_merge(['address'], GroundedAnswerLanguageMatcher::COMPARISON_MARKERS)],
        'The fixture mixed-language comparison markers must be unique and sorted.',
    ],
    'different marker set' => [
        ['comparison_markers' => array_merge(
            array_slice(GroundedAnswerLanguageMatcher::COMPARISON_MARKERS, 0, -1),
            ['zebra'],
        )],
        'The fixture mixed-language comparison markers must match the pinned implementation.',
    ],
    'short marker window' => [
        ['window_tokens' => 1],
        'The fixture mixed-language window must contain between 2 and 20 tokens.',
    ],
    'small marker threshold' => [
        ['minimum_marker_occurrences' => 1],
        'The fixture mixed-language marker occurrence threshold must be between 2 and the window size.',
    ],
    'threshold above window' => [
        ['minimum_marker_occurrences' => 6],
        'The fixture mixed-language marker occurrence threshold must be between 2 and the window size.',
    ],
    'small mixed language token limit' => [
        ['maximum_tokens' => 4],
        'The fixture mixed-language token limit must be at least the window size and at most 500.',
    ],
]);

test('fixture version four rejects unsupported case language settings', function (string $caseId, mixed $language, string $message): void {
    $fixture = languageEvaluationFixtureData();

    foreach ($fixture['cases'] as &$case) {
        if ($case['id'] === $caseId) {
            $case['expected']['answer_language'] = $language;
        }
    }
    unset($case);

    $path = writeLanguageEvaluationFixture($fixture);

    try {
        expect(fn (): array => app(GroundedAnswerEvaluationDatasetLoader::class)->fixtures($path))
            ->toThrow(RuntimeException::class, $message);
    } finally {
        unlink($path);
    }
})->with([
    'unknown answer language' => [
        'german-password-reset-link',
        'fr',
        'Answer language for case german-password-reset-link must be null or de.',
    ],
    'language on refusal' => [
        'german-unsupported-phone-hours',
        'de',
        'Refusal case german-unsupported-phone-hours must leave answer language null.',
    ],
]);
