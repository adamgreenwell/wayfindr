<?php

declare(strict_types=1);

use App\Support\Ai\Evaluation\GroundedAnswerScoringContract;

test('scoring source fingerprints ignore comments and whitespace between tokens', function (): void {
    $contract = new GroundedAnswerScoringContract;
    $plain = '<?php return $value + 1;';
    $formatted = "<?php\n/** A documentation edit. */\nreturn /* An implementation note. */ \$value\n    + 1; // Trailing comment.\n";

    expect($contract->sourceFingerprint($plain))
        ->toMatch('/\Asha256:[a-f0-9]{64}\z/')
        ->toBe($contract->sourceFingerprint($formatted));
});

test('scoring source fingerprints change when executable rules change', function (string $changed): void {
    $contract = new GroundedAnswerScoringContract;

    expect($contract->sourceFingerprint('<?php return $value + 1;'))
        ->not->toBe($contract->sourceFingerprint($changed));
})->with([
    'operator change' => '<?php return $value - 1;',
    'threshold change' => '<?php return $value + 2;',
    'identifier change' => '<?php return $other + 1;',
]);

test('scoring source fingerprints preserve string contents and token boundaries', function (): void {
    $contract = new GroundedAnswerScoringContract;

    expect($contract->sourceFingerprint('<?php return "a b";'))
        ->not->toBe($contract->sourceFingerprint('<?php return "ab";'))
        ->and($contract->sourceFingerprint('<?php return "/* comment */";'))
        ->not->toBe($contract->sourceFingerprint('<?php return "";'))
        ->and($contract->sourceFingerprint('<?php return new Foo;'))
        ->not->toBe($contract->sourceFingerprint('<?php return newFoo;'));
});

test('the scoring contract identifies every local scoring and input-validation component', function (): void {
    $contract = (new GroundedAnswerScoringContract)->contract();

    expect($contract['version'])->toBe(1)
        ->and(array_keys($contract['implementations']))->toBe([
            'dataset_loader',
            'evaluator',
            'language_matcher',
            'phrase_matcher',
            'refusal_reason',
        ]);

    foreach ($contract['implementations'] as $fingerprint) {
        expect($fingerprint)->toMatch('/\Asha256:[a-f0-9]{64}\z/');
    }
});
