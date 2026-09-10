<?php

declare(strict_types=1);

use App\Support\Ai\Evaluation\GroundedAnswerLanguageMatcher;
use Composer\InstalledVersions;

/** @return array{classifier: string, classifier_version: string, target_language: string, comparison_scope: string, minimum_score_margin: float, mixed_language_check: array{strategy: string, comparison_language: string, comparison_markers: list<string>, window_tokens: int, minimum_marker_occurrences: int, maximum_tokens: int}} */
function groundedAnswerLanguageContract(): array
{
    return [
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
    ];
}

test('the language contract pins the installed offline classifier', function (): void {
    expect(InstalledVersions::getPrettyVersion(GroundedAnswerLanguageMatcher::CLASSIFIER))
        ->toBe('v'.GroundedAnswerLanguageMatcher::CLASSIFIER_VERSION);
});

test('the bundled German answer clears the fixture-pinned margin', function (): void {
    $matcher = new GroundedAnswerLanguageMatcher;

    expect($matcher->answerMatches(
        'Wählen Sie „Passwort vergessen“ auf der Anmeldeseite. Der Link zum Zurücksetzen ist 15 Minuten gültig.',
        'de',
        groundedAnswerLanguageContract(),
    ))->toBeTrue();
});

test('an ambiguous terse German answer does not clear the conservative all-language margin', function (): void {
    $matcher = new GroundedAnswerLanguageMatcher;

    expect($matcher->answerMatches(
        '„Passwort vergessen“ – Reset-Link 15 Minuten gültig.',
        'de',
        groundedAnswerLanguageContract(),
    ))->toBeFalse();
});

test('a natural German paraphrase clears the fixture-pinned margin', function (): void {
    $matcher = new GroundedAnswerLanguageMatcher;

    expect($matcher->answerMatches(
        'Bitte klicken Sie auf „Passwort vergessen“, damit Ihnen per E-Mail ein neuer Link zugesendet wird; er ist 15 Minuten gültig.',
        'de',
        groundedAnswerLanguageContract(),
    ))->toBeTrue();
});

test('a German answer may contain product terms and loanwords', function (): void {
    $matcher = new GroundedAnswerLanguageMatcher;

    expect($matcher->answerMatches(
        'Wählen Sie in Wayfindr auf der Anmeldeseite „Passwort vergessen“. Der zugesendete Reset-Link ist 15 Minuten lang gültig.',
        'de',
        groundedAnswerLanguageContract(),
    ))->toBeTrue();
});

test('a German answer may contain ordinary technical phrasing', function (): void {
    $matcher = new GroundedAnswerLanguageMatcher;

    expect($matcher->answerMatches(
        'Wählen Sie auf der Anmeldeseite „Passwort vergessen“ via E-Mail, damit Sie einen neuen Reset-Link erhalten, der anschließend 15 Minuten lang gültig bleibt.',
        'de',
        groundedAnswerLanguageContract(),
    ))->toBeTrue();
});

test('a natural German answer may contain browser and login terminology', function (string $answer): void {
    $matcher = new GroundedAnswerLanguageMatcher;

    expect($matcher->answerMatches(
        $answer,
        'de',
        groundedAnswerLanguageContract(),
    ))->toBeTrue();
})->with([
    'browser before login form' => ['Zur Wiederherstellung Ihres Kontos führen Sie bitte die folgenden Schritte aus. Öffnen Sie im Browser das Login-Formular und wählen Sie „Passwort vergessen“. Der neue Link ist anschließend 15 Minuten gültig.'],
    'login form before browser' => ['Zur Wiederherstellung Ihres Kontos öffnen Sie bitte das Login-Formular im Browser. Wählen Sie dort „Passwort vergessen“. Der neue Link ist anschließend 15 Minuten gültig.'],
]);

test('normal German IT and time terms do not count as English markers', function (string $answer): void {
    $matcher = new GroundedAnswerLanguageMatcher;

    expect($matcher->answerMatches(
        $answer,
        'de',
        groundedAnswerLanguageContract(),
    ))->toBeTrue();
})->with([
    'repeated IT abbreviation' => ['Die IT prüft die IT sorgfältig. Wählen Sie auf der Anmeldeseite „Passwort vergessen“. Der Link ist 15 Minuten gültig.'],
    'repeated singular minute' => ['Eine Minute später ist eine Minute vergangen. Wählen Sie auf der Anmeldeseite „Passwort vergessen“. Der Link ist 15 Minuten gültig.'],
]);

test('the reported mixed English and German answer is rejected', function (): void {
    $matcher = new GroundedAnswerLanguageMatcher;

    expect($matcher->answerMatches(
        'Select „Passwort vergessen“. The reset link remains gültig for 15 Minuten.',
        'de',
        groundedAnswerLanguageContract(),
    ))->toBeFalse();
});

test('unlisted English glue does not bypass the fixture-pinned margin', function (): void {
    $matcher = new GroundedAnswerLanguageMatcher;

    expect($matcher->answerMatches(
        'Pick „Passwort vergessen“. Access link stays gültig during 15 Minuten.',
        'de',
        groundedAnswerLanguageContract(),
    ))->toBeFalse();
});

test('repeated English does not bypass the fixture-pinned margin', function (): void {
    $matcher = new GroundedAnswerLanguageMatcher;

    expect($matcher->answerMatches(
        'Please select „Passwort vergessen“. Please select. Please select. 15 Minuten gültig.',
        'de',
        groundedAnswerLanguageContract(),
    ))->toBeFalse();
});

test('German padding does not hide a bounded English marker window', function (string $answer): void {
    $matcher = new GroundedAnswerLanguageMatcher;

    expect($matcher->answerMatches(
        $answer,
        'de',
        groundedAnswerLanguageContract(),
    ))->toBeFalse();
})->with([
    'sentences' => ['Choose „Passwort vergessen“. The link is 15 Minuten gültig. Wählen Sie diese Option auf der Anmeldeseite.'],
    'semicolon clause' => ['Wählen Sie auf der Anmeldeseite „Passwort vergessen“; choose this option on the login page; und beachten Sie, dass der Link 15 Minuten gültig ist.'],
    'parenthetical clause' => ['Wählen Sie auf der Anmeldeseite „Passwort vergessen“ (choose this option on the login page) und beachten Sie, dass der Link 15 Minuten gültig ist.'],
    'slash-delimited clause' => ['Wählen Sie auf der Anmeldeseite „Passwort vergessen“ / choose this option on the login page / der Link ist 15 Minuten gültig.'],
    'lower-confidence English aside' => ['Wählen Sie auf der Anmeldeseite „Passwort vergessen“ (you should reset it now) und beachten Sie, dass der Link 15 Minuten gültig ist.'],
    'another lower-confidence English aside' => ['Wählen Sie auf der Anmeldeseite „Passwort vergessen“ (we should reset it now) und beachten Sie, dass der Link 15 Minuten gültig ist.'],
    'undelimited English aside' => ['Wählen Sie auf der Anmeldeseite „Passwort vergessen“ we can reset now und beachten Sie, dass der Link 15 Minuten gültig ist.'],
    'telegraphic English aside' => ['Wählen Sie „Passwort vergessen“. Reset link valid fifteen minutes. Der Link ist 15 Minuten gültig.'],
]);

test('ordinary German numbering and abbreviations do not become language fragments', function (string $answer): void {
    $matcher = new GroundedAnswerLanguageMatcher;

    expect($matcher->answerMatches(
        $answer,
        'de',
        groundedAnswerLanguageContract(),
    ))->toBeTrue();
})->with([
    'numbered steps' => ['1. Wählen Sie auf der Anmeldeseite „Passwort vergessen“. 2. Der Link ist 15 Minuten gültig.'],
    'short affirmation and abbreviation' => ['Ja, wählen Sie z. B. auf der Anmeldeseite „Passwort vergessen“. Der neue Link bleibt 15 Minuten gültig.'],
]);

test('an answer over the mixed-language token limit fails closed', function (): void {
    $matcher = new GroundedAnswerLanguageMatcher;
    $answer = str_repeat(
        'Wählen Sie „Passwort vergessen“ auf der Anmeldeseite. Der Link zum Zurücksetzen ist 15 Minuten gültig. ',
        20,
    );
    $permissiveContract = groundedAnswerLanguageContract();
    $permissiveContract['mixed_language_check']['maximum_tokens'] = 500;

    expect($matcher->answerMatches($answer, 'de', $permissiveContract))->toBeTrue()
        ->and($matcher->answerMatches($answer, 'de', groundedAnswerLanguageContract()))->toBeFalse();
});

test('another Germanic language does not pass merely because it scores above English', function (string $answer): void {
    $matcher = new GroundedAnswerLanguageMatcher;

    expect($matcher->answerMatches(
        $answer,
        'de',
        groundedAnswerLanguageContract(),
    ))->toBeFalse();
})->with([
    'Dutch' => ['Kies „Passwort vergessen“. De link blijft 15 Minuten gültig.'],
    'Swedish' => ['Välj „Passwort vergessen“. Länken är gültig i 15 Minuten.'],
]);

test('a mismatched classifier contract is rejected', function (array $changes): void {
    $matcher = new GroundedAnswerLanguageMatcher;
    $contract = array_replace(groundedAnswerLanguageContract(), $changes);

    expect(fn (): bool => $matcher->answerMatches('Eine gültige Antwort.', 'de', $contract))
        ->toThrow(RuntimeException::class, 'The evaluation language classifier contract does not match the installed implementation.');
})->with([
    'classifier' => [['classifier' => 'different/classifier']],
    'version' => [['classifier_version' => '5.3.0']],
    'target language' => [['target_language' => 'en']],
    'comparison scope' => [['comparison_scope' => 'selected_languages']],
]);

test('a mismatched English marker contract is rejected', function (array $changes): void {
    $matcher = new GroundedAnswerLanguageMatcher;
    $contract = groundedAnswerLanguageContract();
    $contract['mixed_language_check'] = array_replace($contract['mixed_language_check'], $changes);

    expect(fn (): bool => $matcher->answerMatches('Eine gültige Antwort.', 'de', $contract))
        ->toThrow(RuntimeException::class, 'The evaluation language classifier contract does not match the installed implementation.');
})->with([
    'strategy' => [['strategy' => 'different_strategy']],
    'comparison language' => [['comparison_language' => 'nl']],
    'comparison markers' => [['comparison_markers' => ['different']]],
]);
