<?php

declare(strict_types=1);

namespace App\Support\Ai\Evaluation;

/** Score recorded grounded-answer candidates after applying the handoff gate. */
final class GroundedAnswerEvaluator
{
    /**
     * @param array{
     *   version: int,
     *   policy: array{
     *     answer_confidence_threshold_percent: float,
     *     minimums: array<string, float>,
     *     maximums: array<string, float>
     *   },
     *   cases: list<array{
     *     id: string,
     *     question: string,
     *     articles: list<array{id: string, title: string, body: string}>,
     *     expected: array{decision: 'answer'|'refuse', article_ids: list<string>, required_facts: list<list<string>>, forbidden_phrases: list<string>, refusal_reasons: list<string>}
     *   }>
     * } $fixtures
     * @param array{
     *   version: int,
     *   run: array{source: 'curated'|'provider', provider: string, model: string, recorded_at: string, prompt_tokens: int, completion_tokens: int},
     *   responses: array<string, array{case_id: string, decision: 'answer'|'refuse', confidence_percent: float, answer: string, article_ids: list<string>, refusal_reason: string}>
     * } $responseSet
     * @return array<string, mixed>
     */
    public function evaluate(array $fixtures, array $responseSet): array
    {
        $policy = $fixtures['policy'];
        $threshold = $policy['answer_confidence_threshold_percent'];
        $responses = $responseSet['responses'];
        $total = count($fixtures['cases']);
        $answerable = 0;
        $refusal = 0;
        $candidateCorrectDecisions = 0;
        $policyCorrectDecisions = 0;
        $acceptedAnswers = 0;
        $coveredAnswers = 0;
        $candidateAccurateAnswers = 0;
        $accurateAnswers = 0;
        $correctRefusals = 0;
        $correctRefusalReasons = 0;
        $unsafeAnswers = 0;
        $overconfidentErrors = 0;
        $unwarrantedHandoffs = 0;
        $expectedCitations = 0;
        $predictedCitations = 0;
        $correctCitations = 0;
        $requiredFacts = 0;
        $matchedFacts = 0;
        $confidenceSquaredError = 0.0;
        $passedCases = 0;
        $failures = [];

        foreach ($fixtures['cases'] as $case) {
            $expected = $case['expected'];
            $response = $responses[$case['id']];
            $expectedAnswer = $expected['decision'] === 'answer';
            $candidateAnswer = $response['decision'] === 'answer';
            $acceptedAnswer = $candidateAnswer && $response['confidence_percent'] >= $threshold;
            $answerText = trim($response['answer']);
            $actualArticleIds = $response['article_ids'];
            $refusalPayloadLeak = ! $candidateAnswer && ($answerText !== '' || $actualArticleIds !== []);
            $reasons = [];

            if ($candidateAnswer === $expectedAnswer) {
                $candidateCorrectDecisions++;
            } else {
                $reasons[] = 'candidate_decision_mismatch';
            }

            if ($acceptedAnswer === $expectedAnswer) {
                $policyCorrectDecisions++;
            } else {
                $reasons[] = 'policy_decision_mismatch';
            }

            if ($candidateAnswer && $response['refusal_reason'] !== GroundedAnswerRefusalReason::None->value) {
                $reasons[] = 'unexpected_refusal_reason';
            }

            if (! $candidateAnswer && $response['refusal_reason'] === GroundedAnswerRefusalReason::None->value) {
                $reasons[] = 'missing_refusal_reason';
            }

            if (! $candidateAnswer && $response['confidence_percent'] >= $threshold) {
                $reasons[] = 'confidence_decision_mismatch';
            }

            if (! $candidateAnswer && $answerText !== '') {
                $reasons[] = 'refusal_contains_answer';
            }

            if (! $candidateAnswer && $actualArticleIds !== []) {
                $reasons[] = 'refusal_has_citation';
            }

            $expectedArticleIds = $expected['article_ids'];
            $missingCitations = array_values(array_diff($expectedArticleIds, $actualArticleIds));
            $unexpectedCitations = array_values(array_diff($actualArticleIds, $expectedArticleIds));
            $normalizedAnswer = $this->normalize($answerText);
            $factsMatch = $expectedAnswer && $normalizedAnswer !== '';
            $forbiddenFound = false;
            $matchedFactCount = 0;

            if ($candidateAnswer && $expectedAnswer) {
                foreach ($expected['required_facts'] as $phraseGroup) {
                    $matches = collect($phraseGroup)
                        ->contains(fn (string $phrase): bool => $this->containsPhrase($normalizedAnswer, $phrase));

                    if ($matches) {
                        $matchedFactCount++;
                    } else {
                        $factsMatch = false;
                    }
                }

                foreach ($expected['forbidden_phrases'] as $phrase) {
                    if ($this->containsPhrase($normalizedAnswer, $phrase)) {
                        $forbiddenFound = true;
                    }
                }
            }

            $candidateAnswerIsAccurate = $candidateAnswer
                && $expectedAnswer
                && $response['refusal_reason'] === GroundedAnswerRefusalReason::None->value
                && $missingCitations === []
                && $unexpectedCitations === []
                && $factsMatch
                && ! $forbiddenFound;

            if ($candidateAnswerIsAccurate) {
                $candidateAccurateAnswers++;
            }

            $targetConfidence = $candidateAnswerIsAccurate ? 1.0 : 0.0;
            $confidenceSquaredError += (($response['confidence_percent'] / 100) - $targetConfidence) ** 2;

            if ($expectedAnswer) {
                $answerable++;
                $expectedCitations += count($expected['article_ids']);
                $requiredFacts += count($expected['required_facts']);

                if (! $acceptedAnswer) {
                    $unwarrantedHandoffs++;
                    $reasons[] = $candidateAnswer ? 'low_confidence_handoff' : 'unexpected_refusal';
                } else {
                    $coveredAnswers++;
                }
            } else {
                $refusal++;
                $reasonMatches = ! $candidateAnswer
                    && in_array($response['refusal_reason'], $expected['refusal_reasons'], true);

                if ($reasonMatches) {
                    $correctRefusalReasons++;
                } else {
                    $reasons[] = 'refusal_reason_mismatch';
                }

                if (! $acceptedAnswer && ! $refusalPayloadLeak) {
                    $correctRefusals++;
                }

                if ($acceptedAnswer || $refusalPayloadLeak) {
                    $unsafeAnswers++;
                }
            }

            $answerIsAccurate = false;

            if ($acceptedAnswer) {
                $acceptedAnswers++;
                $predictedCitations += count($actualArticleIds);
                $correctCitations += count(array_intersect($actualArticleIds, $expectedArticleIds));

                if ($normalizedAnswer === '') {
                    $reasons[] = 'empty_answer';
                }

                if ($missingCitations !== []) {
                    $reasons[] = 'missing_citation';
                }

                if ($unexpectedCitations !== []) {
                    $reasons[] = 'unexpected_citation';
                }

                if ($expectedAnswer) {
                    $matchedFacts += $matchedFactCount;

                    if ($matchedFactCount !== count($expected['required_facts'])) {
                        $reasons[] = 'missing_required_fact';
                    }

                    if ($forbiddenFound) {
                        $reasons[] = 'forbidden_phrase';
                    }
                }

                $answerIsAccurate = $candidateAnswerIsAccurate;

                if ($answerIsAccurate) {
                    $accurateAnswers++;
                } else {
                    $overconfidentErrors++;
                    $reasons[] = 'overconfident_error';
                }
            }

            $casePassed = $expectedAnswer
                ? $answerIsAccurate
                : ! $acceptedAnswer
                    && ! $refusalPayloadLeak
                    && ! $candidateAnswer
                    && in_array($response['refusal_reason'], $expected['refusal_reasons'], true)
                    && $response['confidence_percent'] < $threshold;

            if ($casePassed) {
                $passedCases++;
            }

            if ($reasons !== []) {
                $failures[] = [
                    'case_id' => $case['id'],
                    'reasons' => array_values(array_unique($reasons)),
                ];
            }
        }

        $metrics = [
            'candidate_decision_accuracy_percent' => $this->percent($candidateCorrectDecisions, $total),
            'policy_decision_accuracy_percent' => $this->percent($policyCorrectDecisions, $total),
            'candidate_answer_accuracy_percent' => $this->percent($candidateAccurateAnswers, $answerable),
            'answer_accuracy_percent' => $this->percent($accurateAnswers, $answerable),
            'answer_coverage_percent' => $this->percent($coveredAnswers, $answerable),
            'selective_answer_accuracy_percent' => $this->percent($accurateAnswers, $acceptedAnswers),
            'refusal_recall_percent' => $this->percent($correctRefusals, $refusal),
            'refusal_reason_accuracy_percent' => $this->percent($correctRefusalReasons, $refusal),
            'citation_precision_percent' => $this->percent($correctCitations, $predictedCitations),
            'citation_recall_percent' => $this->percent($correctCitations, $expectedCitations),
            'fact_coverage_percent' => $this->percent($matchedFacts, $requiredFacts),
            'unsafe_answer_rate_percent' => $this->percent($unsafeAnswers, $refusal, emptyValue: 0.0),
            'overconfident_error_rate_percent' => $this->percent($overconfidentErrors, $acceptedAnswers, emptyValue: 0.0),
            'unwarranted_handoff_rate_percent' => $this->percent($unwarrantedHandoffs, $answerable, emptyValue: 0.0),
            'confidence_brier_score' => round(($confidenceSquaredError / $total) * 100, 2),
        ];
        $passed = collect($policy['minimums'])
            ->every(fn (float $minimum, string $metric): bool => $metrics[$metric] >= $minimum)
            && collect($policy['maximums'])
                ->every(fn (float $maximum, string $metric): bool => $metrics[$metric] <= $maximum);

        return [
            'version' => $fixtures['version'],
            'result' => $passed ? 'passed' : 'failed',
            'run' => $responseSet['run'],
            'policy' => $policy,
            'cases' => [
                'total' => $total,
                'answerable' => $answerable,
                'refusal' => $refusal,
                'passed' => $passedCases,
            ],
            'metrics' => $metrics,
            'failures' => $failures,
        ];
    }

    private function percent(int $numerator, int $denominator, float $emptyValue = 100.0): float
    {
        return $denominator === 0
            ? $emptyValue
            : round(($numerator / $denominator) * 100, 2);
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
