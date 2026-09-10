<?php

declare(strict_types=1);

namespace App\Support\Ai\Evaluation;

use Composer\InstalledVersions;
use LanguageDetection\Language;
use RuntimeException;

/** Apply the fixture-pinned offline language classifier to selected answers. */
final class GroundedAnswerLanguageMatcher
{
    public const CLASSIFIER = 'patrickschur/language-detection';

    public const CLASSIFIER_VERSION = '5.3.1';

    /**
     * High-signal English words used by the bounded mixed-language regression gate.
     *
     * @var list<string>
     */
    public const COMPARISON_MARKERS = [
        'address',
        'again',
        'and',
        'are',
        'back',
        'because',
        'been',
        'being',
        'but',
        'choose',
        'click',
        'could',
        'did',
        'do',
        'does',
        'during',
        'enter',
        'expires',
        'fifteen',
        'first',
        'follow',
        'for',
        'forgot',
        'forgotten',
        'from',
        'go',
        'had',
        'has',
        'have',
        'he',
        'here',
        'his',
        'is',
        'its',
        'minutes',
        'must',
        'next',
        'now',
        'open',
        'our',
        'ours',
        'pick',
        'please',
        'remains',
        'right',
        'select',
        'send',
        'she',
        'should',
        'soon',
        'stays',
        'that',
        'the',
        'their',
        'theirs',
        'them',
        'then',
        'there',
        'these',
        'they',
        'this',
        'those',
        'try',
        'use',
        'valid',
        'we',
        'were',
        'when',
        'with',
        'without',
        'works',
        'would',
        'you',
        'your',
        'yours',
    ];

    private ?Language $detector = null;

    /**
     * @param array{
     *   classifier: string,
     *   classifier_version: string,
     *   target_language: string,
     *   comparison_scope: string,
     *   minimum_score_margin: float,
     *   mixed_language_check: array{
     *     strategy: string,
     *     comparison_language: string,
     *     comparison_markers: list<string>,
     *     window_tokens: int,
     *     minimum_marker_occurrences: int,
     *     maximum_tokens: int
     *   }
     * } $contract
     */
    public function answerMatches(string $answer, string $expectedLanguage, array $contract): bool
    {
        $this->assertContract($expectedLanguage, $contract);
        $answerLead = $this->targetLead($answer, $expectedLanguage);

        if ($answerLead === null || $answerLead < $contract['minimum_score_margin']) {
            return false;
        }

        $tokens = $this->tokens($answer);
        $mixedLanguageCheck = $contract['mixed_language_check'];

        if ($tokens === [] || count($tokens) > $mixedLanguageCheck['maximum_tokens']) {
            return false;
        }

        $markerLookup = array_fill_keys($mixedLanguageCheck['comparison_markers'], true);
        $windowTokens = min(count($tokens), $mixedLanguageCheck['window_tokens']);
        $lastWindowStart = count($tokens) - $windowTokens;

        for ($start = 0; $start <= $lastWindowStart; $start++) {
            $window = array_slice($tokens, $start, $windowTokens);
            $markerCount = count(array_filter(
                $window,
                static fn (string $token): bool => isset($markerLookup[$token]),
            ));

            if ($markerCount >= $mixedLanguageCheck['minimum_marker_occurrences']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{
     *   classifier: string,
     *   classifier_version: string,
     *   target_language: string,
     *   comparison_scope: string,
     *   minimum_score_margin: float,
     *   mixed_language_check: array{
     *     strategy: string,
     *     comparison_language: string,
     *     comparison_markers: list<string>,
     *     window_tokens: int,
     *     minimum_marker_occurrences: int,
     *     maximum_tokens: int
     *   }
     * } $contract
     */
    private function assertContract(string $expectedLanguage, array $contract): void
    {
        $installedVersion = ltrim((string) InstalledVersions::getPrettyVersion(self::CLASSIFIER), 'v');

        if ($contract['classifier'] !== self::CLASSIFIER
            || $contract['classifier_version'] !== self::CLASSIFIER_VERSION
            || $installedVersion !== self::CLASSIFIER_VERSION
            || $contract['comparison_scope'] !== 'all_classifier_profiles'
            || $expectedLanguage !== $contract['target_language']
            || $contract['mixed_language_check']['strategy'] !== 'english_marker_windows_v1'
            || $contract['mixed_language_check']['comparison_language'] !== 'en'
            || $contract['mixed_language_check']['comparison_markers'] !== self::COMPARISON_MARKERS) {
            throw new RuntimeException('The evaluation language classifier contract does not match the installed implementation.');
        }
    }

    private function detector(): Language
    {
        return $this->detector ??= new Language;
    }

    private function targetLead(string $text, string $expectedLanguage): ?float
    {
        $scores = $this->detector()->detect($text)->close();

        if (! array_key_exists($expectedLanguage, $scores)) {
            return null;
        }

        $targetScore = (float) $scores[$expectedLanguage];
        unset($scores[$expectedLanguage]);

        return $scores === [] ? null : $targetScore - max($scores);
    }

    /** @return list<string> */
    private function tokens(string $answer): array
    {
        $matched = preg_match_all('/\p{L}+/u', mb_strtolower($answer), $matches);

        if ($matched === false) {
            return [];
        }

        return $matches[0];
    }
}
