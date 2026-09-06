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
    private const VERSION = 2;

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
     *   cases: list<array{
     *     id: string,
     *     question: string,
     *     articles: list<array{id: string, title: string, body: string}>,
     *     expected: array{decision: 'answer'|'refuse', article_ids: list<string>, required_facts: list<list<string>>, forbidden_phrases: list<string>, refusal_reasons: list<string>}
     *   }>
     * }
     */
    public function fixtures(string $path): array
    {
        $root = $this->jsonObject($path, 'fixture');
        $this->requireKeys($root, ['version', 'policy', 'cases'], 'fixture root');

        if ($root->version !== self::VERSION || ! $root->policy instanceof stdClass || ! is_array($root->cases)) {
            throw new RuntimeException('The evaluation fixture must use version 2 with a policy object and an array of cases.');
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
            $articles = $this->articles($rawCase->articles, $caseId);
            $expected = $this->expected($rawCase->expected, $caseId, $articles);

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

        return [
            'version' => self::VERSION,
            'policy' => $policy,
            'cases' => $cases,
        ];
    }

    /**
     * @param  list<string>  $expectedCaseIds
     * @return array{
     *   version: int,
     *   run: array{source: 'curated'|'provider', provider: string, model: string, recorded_at: string, prompt_tokens: int, completion_tokens: int},
     *   responses: array<string, array{case_id: string, decision: 'answer'|'refuse', confidence_percent: float, answer: string, article_ids: list<string>, refusal_reason: string}>
     * }
     */
    public function responses(string $path, array $expectedCaseIds): array
    {
        $root = $this->jsonObject($path, 'response');
        $this->requireKeys($root, ['version', 'run', 'responses'], 'response root');

        if ($root->version !== self::VERSION || ! $root->run instanceof stdClass || ! is_array($root->responses)) {
            throw new RuntimeException('The evaluation responses must use version 2 with a run object and an array of responses.');
        }

        $run = $this->run($root->run);

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
            'version' => self::VERSION,
            'run' => $run,
            'responses' => $responses,
        ];
    }

    /** @return list<array{id: string, title: string, body: string}> */
    private function articles(mixed $value, string $caseId): array
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

            $this->requireKeys($rawArticle, ['id', 'title', 'body'], sprintf('article %d for case %s', $index + 1, $caseId));
            $articleId = $this->identifier($rawArticle->id, sprintf('article %d ID for case %s', $index + 1, $caseId));

            if (isset($seen[$articleId])) {
                throw new RuntimeException(sprintf('Article ID %s is duplicated in case %s.', $articleId, $caseId));
            }

            $seen[$articleId] = true;
            $articles[] = [
                'id' => $articleId,
                'title' => $this->boundedString($rawArticle->title, 1, 200, sprintf('article %s title', $articleId)),
                'body' => $this->boundedString($rawArticle->body, 1, 10_000, sprintf('article %s body', $articleId)),
            ];
        }

        return $articles;
    }

    /**
     * @param  list<array{id: string, title: string, body: string}>  $articles
     * @return array{decision: 'answer'|'refuse', article_ids: list<string>, required_facts: list<list<string>>, forbidden_phrases: list<string>, refusal_reasons: list<string>}
     */
    private function expected(mixed $value, string $caseId, array $articles): array
    {
        if (! $value instanceof stdClass) {
            throw new RuntimeException(sprintf('Expected result for case %s must be an object.', $caseId));
        }

        $this->requireKeys($value, [
            'decision',
            'article_ids',
            'required_facts',
            'forbidden_phrases',
            'refusal_reasons',
        ], sprintf('expected result for case %s', $caseId));

        if (! is_string($value->decision) || ! in_array($value->decision, ['answer', 'refuse'], true)) {
            throw new RuntimeException(sprintf('Expected result for case %s must decide answer or refuse.', $caseId));
        }

        $articleIds = array_column($articles, 'id');
        $expectedArticleIds = $this->identifierList($value->article_ids, 20, sprintf('expected article IDs for case %s', $caseId));
        $unknownArticleIds = array_diff($expectedArticleIds, $articleIds);

        if ($unknownArticleIds !== []) {
            throw new RuntimeException(sprintf('Expected result for case %s cites an article outside that case.', $caseId));
        }

        $requiredFacts = $this->phraseGroups($value->required_facts, $caseId);
        $forbiddenPhrases = $this->phraseList($value->forbidden_phrases, 20, sprintf('forbidden phrases for case %s', $caseId));
        $refusalReasons = $this->refusalReasonList($value->refusal_reasons, $caseId);

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

        return [
            'decision' => $value->decision,
            'article_ids' => $expectedArticleIds,
            'required_facts' => $requiredFacts,
            'forbidden_phrases' => $forbiddenPhrases,
            'refusal_reasons' => $refusalReasons,
        ];
    }

    /**
     * @return array{source: 'curated'|'provider', provider: string, model: string, recorded_at: string, prompt_tokens: int, completion_tokens: int}
     */
    private function run(stdClass $value): array
    {
        $this->requireKeys($value, [
            'source',
            'provider',
            'model',
            'recorded_at',
            'prompt_tokens',
            'completion_tokens',
        ], 'evaluation run');

        if (! is_string($value->source) || ! in_array($value->source, ['curated', 'provider'], true)) {
            throw new RuntimeException('The evaluation run source must be curated or provider.');
        }

        $recordedAt = $this->boundedString($value->recorded_at, 20, 20, 'evaluation run recorded_at');

        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $recordedAt);

        if ($timestamp === false || $timestamp->format('Y-m-d\TH:i:s\Z') !== $recordedAt) {
            throw new RuntimeException('The evaluation run recorded_at must be a UTC second timestamp.');
        }

        return [
            'source' => $value->source,
            'provider' => $this->metadataString($value->provider, 'evaluation run provider'),
            'model' => $this->metadataString($value->model, 'evaluation run model'),
            'recorded_at' => $recordedAt,
            'prompt_tokens' => $this->nonNegativeInteger($value->prompt_tokens, 'evaluation run prompt tokens'),
            'completion_tokens' => $this->nonNegativeInteger($value->completion_tokens, 'evaluation run completion tokens'),
        ];
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

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value)));
    }

    private function containsPhrase(string $normalizedText, string $phrase): bool
    {
        return str_contains(' '.$normalizedText.' ', ' '.$this->normalize($phrase).' ');
    }
}
