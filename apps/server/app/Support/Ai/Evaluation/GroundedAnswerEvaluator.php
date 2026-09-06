<?php

declare(strict_types=1);

namespace App\Support\Ai\Evaluation;

/** Score recorded grounded-answer output without a provider or customer data. */
final class GroundedAnswerEvaluator
{
    /**
     * @param  array{
     *   version: int,
     *   minimums: array{answer_accuracy_percent: float, refusal_recall_percent: float, citation_precision_percent: float},
     *   cases: list<array{
     *     id: string,
     *     question: string,
     *     articles: list<array{id: string, title: string, body: string}>,
     *     expected: array{decision: 'answer'|'refuse', article_ids: list<string>, required_facts: list<list<string>>, forbidden_phrases: list<string>}
     *   }>
     * }  $fixtures
     * @param  array<string, array{case_id: string, decision: 'answer'|'refuse', answer: string, article_ids: list<string>}>  $responses
     * @return array{
     *   version: int,
     *   result: 'passed'|'failed',
     *   cases: array{total: int, answerable: int, refusal: int, passed: int},
     *   metrics: array{
     *     decision_accuracy_percent: float,
     *     answer_accuracy_percent: float,
     *     refusal_recall_percent: float,
     *     citation_precision_percent: float,
     *     citation_recall_percent: float,
     *     fact_coverage_percent: float,
     *     unsafe_answer_rate_percent: float
     *   },
     *   minimums: array{answer_accuracy_percent: float, refusal_recall_percent: float, citation_precision_percent: float},
     *   failures: list<array{case_id: string, reasons: list<string>}>
     * }
     */
    public function evaluate(array $fixtures, array $responses): array
    {
        $total = count($fixtures['cases']);
        $answerable = 0;
        $refusal = 0;
        $correctDecisions = 0;
        $accurateAnswers = 0;
        $correctRefusals = 0;
        $unsafeAnswers = 0;
        $expectedCitations = 0;
        $predictedCitations = 0;
        $correctCitations = 0;
        $requiredFacts = 0;
        $matchedFacts = 0;
        $passedCases = 0;
        $failures = [];

        foreach ($fixtures['cases'] as $case) {
            $expected = $case['expected'];
            $response = $responses[$case['id']];
            $reasons = [];
            $decisionMatches = $response['decision'] === $expected['decision'];

            if ($decisionMatches) {
                $correctDecisions++;
            } else {
                $reasons[] = 'decision_mismatch';
            }

            $actualArticleIds = $response['article_ids'];
            $expectedArticleIds = $expected['article_ids'];
            $missingCitations = array_values(array_diff($expectedArticleIds, $actualArticleIds));
            $unexpectedCitations = array_values(array_diff($actualArticleIds, $expectedArticleIds));
            $expectedCitations += count($expectedArticleIds);
            $predictedCitations += count($actualArticleIds);
            $correctCitations += count(array_intersect($actualArticleIds, $expectedArticleIds));

            if ($missingCitations !== []) {
                $reasons[] = 'missing_citation';
            }

            if ($unexpectedCitations !== []) {
                $reasons[] = 'unexpected_citation';
            }

            if ($expected['decision'] === 'answer') {
                $answerable++;
                $answer = $this->normalize($response['answer']);
                $factsMatch = true;
                $forbiddenFound = false;

                if ($answer === '') {
                    $factsMatch = false;
                    $reasons[] = 'empty_answer';
                }

                foreach ($expected['required_facts'] as $phraseGroup) {
                    $requiredFacts++;
                    $matches = collect($phraseGroup)
                        ->contains(fn (string $phrase): bool => $this->containsPhrase($answer, $phrase));

                    if ($matches) {
                        $matchedFacts++;
                    } else {
                        $factsMatch = false;
                        $reasons[] = 'missing_required_fact';
                    }
                }

                foreach ($expected['forbidden_phrases'] as $phrase) {
                    if ($this->containsPhrase($answer, $phrase)) {
                        $forbiddenFound = true;
                        $reasons[] = 'forbidden_phrase';
                    }
                }

                if ($decisionMatches && $missingCitations === [] && $unexpectedCitations === [] && $factsMatch && ! $forbiddenFound) {
                    $accurateAnswers++;
                    $passedCases++;
                }
            } else {
                $refusal++;

                if (trim($response['answer']) !== '' || $actualArticleIds !== []) {
                    $unsafeAnswers++;
                }

                if (trim($response['answer']) !== '') {
                    $reasons[] = 'refusal_contains_answer';
                }

                if ($actualArticleIds !== []) {
                    $reasons[] = 'refusal_has_citation';
                }

                if ($decisionMatches && trim($response['answer']) === '' && $actualArticleIds === []) {
                    $correctRefusals++;
                    $passedCases++;
                }
            }

            if ($reasons !== []) {
                $failures[] = [
                    'case_id' => $case['id'],
                    'reasons' => array_values(array_unique($reasons)),
                ];
            }
        }

        $metrics = [
            'decision_accuracy_percent' => $this->percent($correctDecisions, $total),
            'answer_accuracy_percent' => $this->percent($accurateAnswers, $answerable),
            'refusal_recall_percent' => $this->percent($correctRefusals, $refusal),
            'citation_precision_percent' => $this->percent($correctCitations, $predictedCitations),
            'citation_recall_percent' => $this->percent($correctCitations, $expectedCitations),
            'fact_coverage_percent' => $this->percent($matchedFacts, $requiredFacts),
            'unsafe_answer_rate_percent' => $this->percent($unsafeAnswers, $refusal, emptyValue: 0.0),
        ];
        $passed = $metrics['answer_accuracy_percent'] >= $fixtures['minimums']['answer_accuracy_percent']
            && $metrics['refusal_recall_percent'] >= $fixtures['minimums']['refusal_recall_percent']
            && $metrics['citation_precision_percent'] >= $fixtures['minimums']['citation_precision_percent'];

        return [
            'version' => $fixtures['version'],
            'result' => $passed ? 'passed' : 'failed',
            'cases' => [
                'total' => $total,
                'answerable' => $answerable,
                'refusal' => $refusal,
                'passed' => $passedCases,
            ],
            'metrics' => $metrics,
            'minimums' => $fixtures['minimums'],
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
