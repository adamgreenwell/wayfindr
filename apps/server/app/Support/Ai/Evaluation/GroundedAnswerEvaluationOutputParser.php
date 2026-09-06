<?php

declare(strict_types=1);

namespace App\Support\Ai\Evaluation;

use JsonException;
use stdClass;

/** Reject malformed provider candidates without guessing at safety fields. */
final class GroundedAnswerEvaluationOutputParser
{
    private const MAX_OUTPUT_BYTES = 8_000;

    public function parse(string $output): ?GroundedAnswerEvaluationOutput
    {
        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
            return null;
        }

        try {
            $decoded = json_decode(trim($output), false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! $decoded instanceof stdClass) {
            return null;
        }

        $keys = array_keys(get_object_vars($decoded));
        sort($keys);

        if ($keys !== ['answer', 'article_ids', 'confidence_percent', 'decision', 'refusal_reason']) {
            return null;
        }

        if (! is_string($decoded->decision) || ! in_array($decoded->decision, ['answer', 'refuse'], true)) {
            return null;
        }

        if (! is_int($decoded->confidence_percent) && ! is_float($decoded->confidence_percent)) {
            return null;
        }

        $confidence = (float) $decoded->confidence_percent;

        if ($confidence < 0 || $confidence > 100) {
            return null;
        }

        if (! is_string($decoded->answer) || mb_strlen($decoded->answer) > 4_000) {
            return null;
        }

        if (! is_array($decoded->article_ids) || ! array_is_list($decoded->article_ids) || count($decoded->article_ids) > 20) {
            return null;
        }

        $articleIds = [];

        foreach ($decoded->article_ids as $articleId) {
            if (! is_string($articleId) || preg_match('/\A[a-z0-9][a-z0-9-]{1,62}[a-z0-9]\z/', $articleId) !== 1) {
                return null;
            }

            $articleIds[] = $articleId;
        }

        if (count(array_unique($articleIds)) !== count($articleIds)) {
            return null;
        }

        if (! is_string($decoded->refusal_reason) || ! in_array($decoded->refusal_reason, GroundedAnswerRefusalReason::values(), true)) {
            return null;
        }

        return new GroundedAnswerEvaluationOutput(
            decision: $decoded->decision,
            confidencePercent: $confidence,
            answer: trim($decoded->answer),
            articleIds: $articleIds,
            refusalReason: $decoded->refusal_reason,
        );
    }
}
