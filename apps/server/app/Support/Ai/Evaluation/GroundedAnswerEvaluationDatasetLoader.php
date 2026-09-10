<?php

declare(strict_types=1);

namespace App\Support\Ai\Evaluation;

use DateTimeImmutable;
use JsonException;
use RuntimeException;
use stdClass;

/** Load a bounded, versioned offline answer-evaluation dataset. */
final class GroundedAnswerEvaluationDatasetLoader
{
    private const LEGACY_FIXTURE_VERSION = 2;

    private const FRESHNESS_FIXTURE_VERSION = 3;

    private const FIXTURE_VERSION = 4;

    private const LEGACY_RESPONSE_VERSION = 2;

    public const RESPONSE_VERSION = 3;

    private const MAX_FIXTURE_FILE_BYTES = 1_048_576;

    public const MAX_RESPONSE_FILE_BYTES = 2_097_152;

    private const MAX_CASES = 200;

    /**
     * @return array{
     *   version: int,
     *   policy: array{
     *     answer_confidence_threshold_percent: float,
     *     minimums: array{
     *       answer_accuracy_percent: float,
     *       answer_coverage_percent: float,
     *       refusal_recall_percent: float,
     *       refusal_reason_accuracy_percent: float,
     *       citation_precision_percent: float
     *     },
     *     maximums: array{
     *       unsafe_answer_rate_percent: float,
     *       overconfident_error_rate_percent: float,
     *       confidence_brier_score: float
     *     }
     *   },
     *   language_evaluation?: array{
     *     classifier: string,
     *     classifier_version: string,
     *     target_language: string,
     *     comparison_scope: string,
     *     minimum_score_margin: float,
     *     mixed_language_check: array{strategy: string, comparison_language: string, comparison_markers: list<string>, window_tokens: int, minimum_marker_occurrences: int, maximum_tokens: int}
     *   },
     *   cases: list<array{
     *     id: string,
     *     question: string,
     *     articles: list<array{id: string, title: string, body: string, freshness: 'current'|'stale'}>,
     *     expected: array{decision: 'answer'|'refuse', answer_language?: ?string, article_ids: list<string>, required_facts: list<list<string>>, forbidden_phrases: list<string>, refusal_reasons: list<string>}
     *   }>
     * }
     */
    public function fixtures(string $path): array
    {
        $root = $this->jsonObject($path, 'fixture');

        if (! property_exists($root, 'version')
            || ! in_array($root->version, [self::LEGACY_FIXTURE_VERSION, self::FRESHNESS_FIXTURE_VERSION, self::FIXTURE_VERSION], true)) {
            throw new RuntimeException('The evaluation fixture must use version 2, 3, or 4 with a policy object and an array of cases.');
        }

        $rootKeys = ['version', 'policy', 'cases'];

        if ($root->version === self::FIXTURE_VERSION) {
            $rootKeys[] = 'language_evaluation';
        }

        $this->requireKeys($root, $rootKeys, 'fixture root');

        if (! $root->policy instanceof stdClass
            || ! is_array($root->cases)) {
            throw new RuntimeException('The evaluation fixture must use version 2, 3, or 4 with a policy object and an array of cases.');
        }

        $this->requireKeys($root->policy, [
            'answer_confidence_threshold_percent',
            'minimums',
            'maximums',
        ], 'fixture policy');

        if (! $root->policy->minimums instanceof stdClass || ! $root->policy->maximums instanceof stdClass) {
            throw new RuntimeException('The evaluation policy must contain minimum and maximum objects.');
        }

        $this->requireKeys($root->policy->minimums, [
            'answer_accuracy_percent',
            'answer_coverage_percent',
            'refusal_recall_percent',
            'refusal_reason_accuracy_percent',
            'citation_precision_percent',
        ], 'fixture minimums');
        $this->requireKeys($root->policy->maximums, [
            'unsafe_answer_rate_percent',
            'overconfident_error_rate_percent',
            'confidence_brier_score',
        ], 'fixture maximums');

        $answerThreshold = $this->percentage(
            $root->policy->answer_confidence_threshold_percent,
            'answer confidence threshold',
        );

        if ($answerThreshold <= 0) {
            throw new RuntimeException('The answer confidence threshold must be greater than 0.');
        }

        $policy = [
            'answer_confidence_threshold_percent' => $answerThreshold,
            'minimums' => [
                'answer_accuracy_percent' => $this->percentage($root->policy->minimums->answer_accuracy_percent, 'answer accuracy minimum'),
                'answer_coverage_percent' => $this->percentage($root->policy->minimums->answer_coverage_percent, 'answer coverage minimum'),
                'refusal_recall_percent' => $this->percentage($root->policy->minimums->refusal_recall_percent, 'refusal recall minimum'),
                'refusal_reason_accuracy_percent' => $this->percentage($root->policy->minimums->refusal_reason_accuracy_percent, 'refusal reason accuracy minimum'),
                'citation_precision_percent' => $this->percentage($root->policy->minimums->citation_precision_percent, 'citation precision minimum'),
            ],
            'maximums' => [
                'unsafe_answer_rate_percent' => $this->percentage($root->policy->maximums->unsafe_answer_rate_percent, 'unsafe answer rate maximum'),
                'overconfident_error_rate_percent' => $this->percentage($root->policy->maximums->overconfident_error_rate_percent, 'overconfident error rate maximum'),
                'confidence_brier_score' => $this->percentage($root->policy->maximums->confidence_brier_score, 'confidence Brier score maximum'),
            ],
        ];
        $languageEvaluation = $root->version === self::FIXTURE_VERSION
            ? $this->languageEvaluation($root->language_evaluation)
            : null;

        if ($root->cases === [] || count($root->cases) > self::MAX_CASES) {
            throw new RuntimeException('The evaluation fixture must contain between 1 and 200 cases.');
        }

        $cases = [];
        $seenCaseIds = [];
        $answerCases = 0;
        $refusalCases = 0;

        foreach ($root->cases as $index => $rawCase) {
            if (! $rawCase instanceof stdClass) {
                throw new RuntimeException(sprintf('Evaluation case %d must be an object.', $index + 1));
            }

            $this->requireKeys($rawCase, ['id', 'question', 'articles', 'expected'], sprintf('evaluation case %d', $index + 1));
            $caseId = $this->identifier($rawCase->id, sprintf('evaluation case %d ID', $index + 1));

            if (isset($seenCaseIds[$caseId])) {
                throw new RuntimeException(sprintf('Evaluation case ID %s is duplicated.', $caseId));
            }

            $seenCaseIds[$caseId] = true;
            $question = $this->boundedString($rawCase->question, 3, 2_000, sprintf('question for case %s', $caseId));
            $articles = $this->articles($rawCase->articles, $caseId, $root->version);
            $expected = $this->expected(
                $rawCase->expected,
                $caseId,
                $articles,
                $root->version,
                $languageEvaluation,
            );

            if ($expected['decision'] === 'answer') {
                $answerCases++;
            } else {
                $refusalCases++;
            }

            $cases[] = [
                'id' => $caseId,
                'question' => $question,
                'articles' => $articles,
                'expected' => $expected,
            ];
        }

        if ($answerCases === 0 || $refusalCases === 0) {
            throw new RuntimeException('The evaluation fixture must contain at least one answer case and one refusal case.');
        }

        $fixtures = [
            'version' => $root->version,
            'policy' => $policy,
        ];

        if ($languageEvaluation !== null) {
            $fixtures['language_evaluation'] = $languageEvaluation;
        }

        $fixtures['cases'] = $cases;

        return $fixtures;
    }

    /**
     * @param  list<string>  $expectedCaseIds
     * @return array{
     *   version: int,
     *   run: array{source: 'curated'|'provider', provider: string, model: string, recorded_at: string, prompt_tokens: int, completion_tokens: int, identity_status: 'verified'|'legacy_unbound', suite_digest: ?string, prompt_digest: ?string},
     *   responses: array<string, array{case_id: string, decision: 'answer'|'refuse', confidence_percent: float, answer: string, article_ids: list<string>, refusal_reason: string}>
     * }
     */
    public function responses(
        string $path,
        array $expectedCaseIds,
        string $expectedSuiteDigest,
        string $expectedPromptDigest,
    ): array {
        $root = $this->jsonObject($path, 'response');
        $this->requireKeys($root, ['version', 'run', 'responses'], 'response root');

        if (! in_array($root->version, [self::LEGACY_RESPONSE_VERSION, self::RESPONSE_VERSION], true)
            || ! $root->run instanceof stdClass
            || ! is_array($root->responses)) {
            throw new RuntimeException('The evaluation responses must use version 2 or 3 with a run object and an array of responses.');
        }

        $run = $this->run($root->run, $root->version);

        if ($root->version === self::RESPONSE_VERSION
            && ! hash_equals($expectedSuiteDigest, (string) $run['suite_digest'])) {
            throw new RuntimeException('The evaluation response suite digest does not match the supplied fixture and policy.');
        }

        if ($root->version === self::RESPONSE_VERSION
            && ! hash_equals($expectedPromptDigest, (string) $run['prompt_digest'])) {
            throw new RuntimeException('The evaluation response prompt digest does not match the current prompt contract.');
        }

        $expected = array_fill_keys($expectedCaseIds, true);
        $responses = [];

        foreach ($root->responses as $index => $rawResponse) {
            if (! $rawResponse instanceof stdClass) {
                throw new RuntimeException(sprintf('Evaluation response %d must be an object.', $index + 1));
            }

            $this->requireKeys($rawResponse, [
                'case_id',
                'decision',
                'confidence_percent',
                'answer',
                'article_ids',
                'refusal_reason',
            ], sprintf('evaluation response %d', $index + 1));
            $caseId = $this->identifier($rawResponse->case_id, sprintf('evaluation response %d case ID', $index + 1));

            if (! isset($expected[$caseId])) {
                throw new RuntimeException(sprintf('Evaluation response case ID %s is not present in the fixture.', $caseId));
            }

            if (isset($responses[$caseId])) {
                throw new RuntimeException(sprintf('Evaluation response case ID %s is duplicated.', $caseId));
            }

            if (! is_string($rawResponse->decision) || ! in_array($rawResponse->decision, ['answer', 'refuse'], true)) {
                throw new RuntimeException(sprintf('Evaluation response %s must decide answer or refuse.', $caseId));
            }

            $responses[$caseId] = [
                'case_id' => $caseId,
                'decision' => $rawResponse->decision,
                'confidence_percent' => $this->percentage($rawResponse->confidence_percent, sprintf('confidence for response %s', $caseId)),
                'answer' => $this->boundedString($rawResponse->answer, 0, 4_000, sprintf('answer for case %s', $caseId)),
                'article_ids' => $this->identifierList($rawResponse->article_ids, 20, sprintf('article IDs for response %s', $caseId)),
                'refusal_reason' => $this->refusalReason($rawResponse->refusal_reason, sprintf('refusal reason for response %s', $caseId)),
            ];
        }

        $missing = array_values(array_diff($expectedCaseIds, array_keys($responses)));

        if ($missing !== []) {
            throw new RuntimeException('Evaluation responses are missing case IDs: '.implode(', ', $missing).'.');
        }

        return [
            'version' => $root->version,
            'run' => $run,
            'responses' => $responses,
        ];
    }

    /**
     * @return array{classifier: string, classifier_version: string, target_language: string, comparison_scope: string, minimum_score_margin: float, mixed_language_check: array{strategy: string, comparison_language: string, comparison_markers: list<string>, window_tokens: int, minimum_marker_occurrences: int, maximum_tokens: int}}
     */
    private function languageEvaluation(mixed $value): array
    {
        if (! $value instanceof stdClass) {
            throw new RuntimeException('The fixture language evaluation contract must be an object.');
        }

        $this->requireKeys($value, [
            'classifier',
            'classifier_version',
            'target_language',
            'comparison_scope',
            'minimum_score_margin',
            'mixed_language_check',
        ], 'fixture language evaluation contract');

        if ($value->classifier !== GroundedAnswerLanguageMatcher::CLASSIFIER
            || $value->classifier_version !== GroundedAnswerLanguageMatcher::CLASSIFIER_VERSION) {
            throw new RuntimeException('The fixture language classifier and version must match the pinned implementation.');
        }

        if ($value->target_language !== 'de' || $value->comparison_scope !== 'all_classifier_profiles') {
            throw new RuntimeException('The fixture language evaluation contract must use the pinned German all-profile policy.');
        }

        if ((! is_int($value->minimum_score_margin) && ! is_float($value->minimum_score_margin))
            || $value->minimum_score_margin <= 0
            || $value->minimum_score_margin > 1) {
            throw new RuntimeException('The fixture language score margin must be a number greater than 0 and at most 1.');
        }

        if (! $value->mixed_language_check instanceof stdClass) {
            throw new RuntimeException('The fixture mixed-language check must be an object.');
        }

        $this->requireKeys($value->mixed_language_check, [
            'strategy',
            'comparison_language',
            'comparison_markers',
            'window_tokens',
            'minimum_marker_occurrences',
            'maximum_tokens',
        ], 'fixture mixed-language check');

        if ($value->mixed_language_check->strategy !== 'english_marker_windows_v1'
            || $value->mixed_language_check->comparison_language !== 'en') {
            throw new RuntimeException('The fixture mixed-language check must use the pinned English marker-window strategy.');
        }

        $comparisonMarkers = $value->mixed_language_check->comparison_markers;

        if (! is_array($comparisonMarkers)
            || $comparisonMarkers === []
            || count($comparisonMarkers) > 100
            || array_filter(
                $comparisonMarkers,
                static fn (mixed $marker): bool => ! is_string($marker)
                    || preg_match('/\A[a-z]{2,24}\z/D', $marker) !== 1,
            ) !== []) {
            throw new RuntimeException('The fixture mixed-language comparison markers must be 1 to 100 lowercase ASCII words.');
        }

        $sortedMarkers = array_values(array_unique($comparisonMarkers));
        sort($sortedMarkers, SORT_STRING);

        if ($comparisonMarkers !== $sortedMarkers) {
            throw new RuntimeException('The fixture mixed-language comparison markers must be unique and sorted.');
        }

        if ($comparisonMarkers !== GroundedAnswerLanguageMatcher::COMPARISON_MARKERS) {
            throw new RuntimeException('The fixture mixed-language comparison markers must match the pinned implementation.');
        }

        if (! is_int($value->mixed_language_check->window_tokens)
            || $value->mixed_language_check->window_tokens < 2
            || $value->mixed_language_check->window_tokens > 20) {
            throw new RuntimeException('The fixture mixed-language window must contain between 2 and 20 tokens.');
        }

        if (! is_int($value->mixed_language_check->minimum_marker_occurrences)
            || $value->mixed_language_check->minimum_marker_occurrences < 2
            || $value->mixed_language_check->minimum_marker_occurrences > $value->mixed_language_check->window_tokens) {
            throw new RuntimeException('The fixture mixed-language marker occurrence threshold must be between 2 and the window size.');
        }

        if (! is_int($value->mixed_language_check->maximum_tokens)
            || $value->mixed_language_check->maximum_tokens < $value->mixed_language_check->window_tokens
            || $value->mixed_language_check->maximum_tokens > 500) {
            throw new RuntimeException('The fixture mixed-language token limit must be at least the window size and at most 500.');
        }

        return [
            'classifier' => $value->classifier,
            'classifier_version' => $value->classifier_version,
            'target_language' => $value->target_language,
            'comparison_scope' => $value->comparison_scope,
            'minimum_score_margin' => (float) $value->minimum_score_margin,
            'mixed_language_check' => [
                'strategy' => $value->mixed_language_check->strategy,
                'comparison_language' => $value->mixed_language_check->comparison_language,
                'comparison_markers' => $comparisonMarkers,
                'window_tokens' => $value->mixed_language_check->window_tokens,
                'minimum_marker_occurrences' => $value->mixed_language_check->minimum_marker_occurrences,
                'maximum_tokens' => $value->mixed_language_check->maximum_tokens,
            ],
        ];
    }

    /** @return list<array{id: string, title: string, body: string, freshness: 'current'|'stale'}> */
    private function articles(mixed $value, string $caseId, int $fixtureVersion): array
    {
        if (! is_array($value) || $value === [] || count($value) > 20) {
            throw new RuntimeException(sprintf('Evaluation case %s must contain between 1 and 20 articles.', $caseId));
        }

        $articles = [];
        $seen = [];

        foreach ($value as $index => $rawArticle) {
            if (! $rawArticle instanceof stdClass) {
                throw new RuntimeException(sprintf('Article %d for case %s must be an object.', $index + 1, $caseId));
            }

            $keys = ['id', 'title', 'body'];

            if ($fixtureVersion >= self::FRESHNESS_FIXTURE_VERSION) {
                $keys[] = 'freshness';
            }

            $this->requireKeys($rawArticle, $keys, sprintf('article %d for case %s', $index + 1, $caseId));
            $articleId = $this->identifier($rawArticle->id, sprintf('article %d ID for case %s', $index + 1, $caseId));

            if (isset($seen[$articleId])) {
                throw new RuntimeException(sprintf('Article ID %s is duplicated in case %s.', $articleId, $caseId));
            }

            $seen[$articleId] = true;
            $articles[] = [
                'id' => $articleId,
                'title' => $this->boundedString($rawArticle->title, 1, 200, sprintf('article %s title', $articleId)),
                'body' => $this->boundedString($rawArticle->body, 1, 10_000, sprintf('article %s body', $articleId)),
                'freshness' => $fixtureVersion >= self::FRESHNESS_FIXTURE_VERSION
                    ? $this->freshness($rawArticle->freshness, $articleId)
                    : 'current',
            ];
        }

        return $articles;
    }

    /**
     * @param  list<array{id: string, title: string, body: string, freshness: 'current'|'stale'}>  $articles
     * @param  ?array{classifier: string, classifier_version: string, target_language: string, comparison_scope: string, minimum_score_margin: float, mixed_language_check: array{strategy: string, comparison_language: string, comparison_markers: list<string>, window_tokens: int, minimum_marker_occurrences: int, maximum_tokens: int}}  $languageEvaluation
     * @return array{decision: 'answer'|'refuse', answer_language?: ?string, article_ids: list<string>, required_facts: list<list<string>>, forbidden_phrases: list<string>, refusal_reasons: list<string>}
     */
    private function expected(
        mixed $value,
        string $caseId,
        array $articles,
        int $fixtureVersion,
        ?array $languageEvaluation,
    ): array {
        if (! $value instanceof stdClass) {
            throw new RuntimeException(sprintf('Expected result for case %s must be an object.', $caseId));
        }

        $keys = [
            'decision',
            'article_ids',
            'required_facts',
            'forbidden_phrases',
            'refusal_reasons',
        ];

        if ($fixtureVersion === self::FIXTURE_VERSION) {
            $keys[] = 'answer_language';
        }

        $this->requireKeys($value, $keys, sprintf('expected result for case %s', $caseId));

        if (! is_string($value->decision) || ! in_array($value->decision, ['answer', 'refuse'], true)) {
            throw new RuntimeException(sprintf('Expected result for case %s must decide answer or refuse.', $caseId));
        }

        $articleIds = array_column($articles, 'id');
        $expectedArticleIds = $this->identifierList($value->article_ids, 20, sprintf('expected article IDs for case %s', $caseId));
        $unknownArticleIds = array_diff($expectedArticleIds, $articleIds);

        if ($unknownArticleIds !== []) {
            throw new RuntimeException(sprintf('Expected result for case %s cites an article outside that case.', $caseId));
        }

        $articlesById = array_column($articles, null, 'id');
        $staleExpectedArticleIds = array_filter(
            $expectedArticleIds,
            fn (string $articleId): bool => $articlesById[$articleId]['freshness'] === 'stale',
        );

        if ($value->decision === 'answer' && $staleExpectedArticleIds !== []) {
            throw new RuntimeException(sprintf('Answer case %s must cite only current articles.', $caseId));
        }

        $requiredFacts = $this->phraseGroups($value->required_facts, $caseId);
        $forbiddenPhrases = $this->phraseList($value->forbidden_phrases, 20, sprintf('forbidden phrases for case %s', $caseId));
        $refusalReasons = $this->refusalReasonList($value->refusal_reasons, $caseId);
        $answerLanguage = null;

        if ($fixtureVersion === self::FIXTURE_VERSION) {
            $answerLanguage = $value->answer_language;

            if ($answerLanguage !== null
                && (! is_string($answerLanguage)
                    || $answerLanguage !== $languageEvaluation['target_language'])) {
                throw new RuntimeException(sprintf(
                    'Answer language for case %s must be null or %s.',
                    $caseId,
                    $languageEvaluation['target_language'],
                ));
            }
        }

        if ($value->decision === 'answer' && ($expectedArticleIds === [] || $requiredFacts === [] || $refusalReasons !== [])) {
            throw new RuntimeException(sprintf('Answer case %s must cite an article, require at least one fact, and leave refusal reasons empty.', $caseId));
        }

        if ($value->decision === 'answer') {
            $sourceTexts = collect($articles)
                ->filter(fn (array $article): bool => in_array($article['id'], $expectedArticleIds, true))
                ->flatMap(fn (array $article): array => [
                    $this->normalize($article['title']),
                    $this->normalize($article['body']),
                ]);

            foreach ($requiredFacts as $phraseGroup) {
                $isGrounded = collect($phraseGroup)
                    ->contains(fn (string $phrase): bool => $sourceTexts->contains(
                        fn (string $sourceText): bool => $this->containsPhrase($sourceText, $phrase),
                    ));

                if (! $isGrounded) {
                    throw new RuntimeException(sprintf('Required facts for answer case %s must be grounded in its expected articles.', $caseId));
                }
            }
        }

        if ($value->decision === 'refuse' && ($expectedArticleIds !== [] || $requiredFacts !== [] || $forbiddenPhrases !== [] || $refusalReasons === [])) {
            throw new RuntimeException(sprintf('Refusal case %s must name a refusal reason and leave answer-only expectations empty.', $caseId));
        }

        if ($value->decision === 'refuse' && $answerLanguage !== null) {
            throw new RuntimeException(sprintf('Refusal case %s must leave answer language null.', $caseId));
        }

        $expected = [
            'decision' => $value->decision,
            'article_ids' => $expectedArticleIds,
            'required_facts' => $requiredFacts,
            'forbidden_phrases' => $forbiddenPhrases,
            'refusal_reasons' => $refusalReasons,
        ];

        if ($fixtureVersion === self::FIXTURE_VERSION) {
            $expected['answer_language'] = $answerLanguage;
        }

        return $expected;
    }

    /**
     * @return array{source: 'curated'|'provider', provider: string, model: string, recorded_at: string, prompt_tokens: int, completion_tokens: int, identity_status: 'verified'|'legacy_unbound', suite_digest: ?string, prompt_digest: ?string}
     */
    private function run(stdClass $value, int $responseVersion): array
    {
        $keys = [
            'source',
            'provider',
            'model',
            'recorded_at',
            'prompt_tokens',
            'completion_tokens',
        ];

        if ($responseVersion === self::RESPONSE_VERSION) {
            $keys[] = 'suite_digest';
            $keys[] = 'prompt_digest';
        }

        $this->requireKeys($value, $keys, 'evaluation run');

        if (! is_string($value->source) || ! in_array($value->source, ['curated', 'provider'], true)) {
            throw new RuntimeException('The evaluation run source must be curated or provider.');
        }

        $recordedAt = $this->boundedString($value->recorded_at, 20, 20, 'evaluation run recorded_at');

        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $recordedAt);

        if ($timestamp === false || $timestamp->format('Y-m-d\TH:i:s\Z') !== $recordedAt) {
            throw new RuntimeException('The evaluation run recorded_at must be a UTC second timestamp.');
        }

        $run = [
            'source' => $value->source,
            'provider' => $this->metadataString($value->provider, 'evaluation run provider'),
            'model' => $this->metadataString($value->model, 'evaluation run model'),
            'recorded_at' => $recordedAt,
            'prompt_tokens' => $this->nonNegativeInteger($value->prompt_tokens, 'evaluation run prompt tokens'),
            'completion_tokens' => $this->nonNegativeInteger($value->completion_tokens, 'evaluation run completion tokens'),
            'identity_status' => $responseVersion === self::RESPONSE_VERSION ? 'verified' : 'legacy_unbound',
            'suite_digest' => null,
            'prompt_digest' => null,
        ];

        if ($responseVersion === self::RESPONSE_VERSION) {
            $run['suite_digest'] = $this->digest($value->suite_digest, 'evaluation run suite digest');
            $run['prompt_digest'] = $this->digest($value->prompt_digest, 'evaluation run prompt digest');
        }

        return $run;
    }

    /** @return list<string> */
    private function refusalReasonList(mixed $value, string $caseId): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > count(GroundedAnswerRefusalReason::cases())) {
            throw new RuntimeException(sprintf('Refusal reasons for case %s must be a JSON array.', $caseId));
        }

        $reasons = array_map(
            fn (mixed $reason): string => $this->refusalReason($reason, sprintf('refusal reason for case %s', $caseId)),
            $value,
        );

        if (in_array(GroundedAnswerRefusalReason::None->value, $reasons, true) || count(array_unique($reasons)) !== count($reasons)) {
            throw new RuntimeException(sprintf('Refusal reasons for case %s must be unique handoff reasons.', $caseId));
        }

        return $reasons;
    }

    /** @return list<list<string>> */
    private function phraseGroups(mixed $value, string $caseId): array
    {
        if (! is_array($value) || count($value) > 20) {
            throw new RuntimeException(sprintf('Required facts for case %s must be an array with at most 20 groups.', $caseId));
        }

        $groups = [];

        foreach ($value as $index => $group) {
            $groups[] = $this->phraseList($group, 10, sprintf('required fact group %d for case %s', $index + 1, $caseId), requireOne: true);
        }

        return $groups;
    }

    /** @return list<string> */
    private function phraseList(mixed $value, int $maximum, string $label, bool $requireOne = false): array
    {
        if (! is_array($value) || count($value) > $maximum || ($requireOne && $value === [])) {
            throw new RuntimeException(sprintf('%s must be an array with %s%d phrases.', ucfirst($label), $requireOne ? 'between 1 and ' : 'at most ', $maximum));
        }

        $phrases = [];

        foreach ($value as $phrase) {
            $phrase = $this->boundedString($phrase, 2, 200, $label);

            if ($this->normalize($phrase) === '') {
                throw new RuntimeException(sprintf('%s must contain searchable text.', ucfirst($label)));
            }

            $phrases[] = $phrase;
        }

        if (count(array_unique(array_map($this->normalize(...), $phrases))) !== count($phrases)) {
            throw new RuntimeException(sprintf('%s must not contain duplicate phrases.', ucfirst($label)));
        }

        return $phrases;
    }

    /** @return list<string> */
    private function identifierList(mixed $value, int $maximum, string $label): array
    {
        if (! is_array($value) || count($value) > $maximum) {
            throw new RuntimeException(sprintf('%s must be an array with at most %d IDs.', ucfirst($label), $maximum));
        }

        $identifiers = array_map(fn (mixed $identifier): string => $this->identifier($identifier, $label), $value);

        if (count(array_unique($identifiers)) !== count($identifiers)) {
            throw new RuntimeException(sprintf('%s must not contain duplicate IDs.', ucfirst($label)));
        }

        return $identifiers;
    }

    private function jsonObject(string $path, string $kind): stdClass
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(sprintf('The evaluation %s file is not readable.', $kind));
        }

        $size = filesize($path);

        $maximumBytes = $kind === 'fixture'
            ? self::MAX_FIXTURE_FILE_BYTES
            : self::MAX_RESPONSE_FILE_BYTES;
        $maximumLabel = $kind === 'fixture' ? '1 MiB' : '2 MiB';

        if (! is_int($size) || $size < 2 || $size > $maximumBytes) {
            throw new RuntimeException(sprintf('The evaluation %s file must be between 2 bytes and %s.', $kind, $maximumLabel));
        }

        $json = file_get_contents($path);

        if (! is_string($json)) {
            throw new RuntimeException(sprintf('The evaluation %s file could not be read.', $kind));
        }

        try {
            $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(sprintf('The evaluation %s file is not valid JSON.', $kind));
        }

        if (! $decoded instanceof stdClass) {
            throw new RuntimeException(sprintf('The evaluation %s file must contain a JSON object.', $kind));
        }

        return $decoded;
    }

    /** @param list<string> $expected */
    private function requireKeys(stdClass $value, array $expected, string $label): void
    {
        $actual = array_keys(get_object_vars($value));
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new RuntimeException(sprintf('The %s has missing or additional fields.', $label));
        }
    }

    private function boundedString(mixed $value, int $minimum, int $maximum, string $label): string
    {
        if (! is_string($value) || mb_strlen(trim($value)) < $minimum || mb_strlen($value) > $maximum) {
            throw new RuntimeException(sprintf('The %s must be a string between %d and %d characters.', $label, $minimum, $maximum));
        }

        return $value;
    }

    private function identifier(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/\A[a-z0-9][a-z0-9-]{1,62}[a-z0-9]\z/', $value) !== 1) {
            throw new RuntimeException(sprintf('The %s must be a lowercase hyphenated ID between 3 and 64 characters.', $label));
        }

        return $value;
    }

    private function percentage(mixed $value, string $label): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new RuntimeException(sprintf('The %s must be a number from 0 to 100.', $label));
        }

        $percentage = (float) $value;

        if ($percentage < 0 || $percentage > 100) {
            throw new RuntimeException(sprintf('The %s must be a number from 0 to 100.', $label));
        }

        return $percentage;
    }

    private function refusalReason(mixed $value, string $label): string
    {
        if (! is_string($value) || ! in_array($value, GroundedAnswerRefusalReason::values(), true)) {
            throw new RuntimeException(sprintf('The %s is not recognized.', $label));
        }

        return $value;
    }

    private function nonNegativeInteger(mixed $value, string $label): int
    {
        if (! is_int($value) || $value < 0 || $value > 1_000_000_000) {
            throw new RuntimeException(sprintf('The %s must be an integer from 0 to 1000000000.', $label));
        }

        return $value;
    }

    private function metadataString(mixed $value, string $label): string
    {
        $value = $this->boundedString($value, 1, 200, $label);

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException(sprintf('The %s must be a single printable line.', $label));
        }

        return trim($value);
    }

    private function freshness(mixed $value, string $articleId): string
    {
        if (! is_string($value) || ! in_array($value, ['current', 'stale'], true)) {
            throw new RuntimeException(sprintf('Article %s freshness must be current or stale.', $articleId));
        }

        return $value;
    }

    private function digest(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/\Asha256:[a-f0-9]{64}\z/', $value) !== 1) {
            throw new RuntimeException(sprintf('The %s must be a lowercase SHA-256 digest.', $label));
        }

        return $value;
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value)));
    }

    private function containsPhrase(string $normalizedText, string $phrase): bool
    {
        return str_contains(' '.$normalizedText.' ', ' '.$this->normalize($phrase).' ');
    }
}
