<?php

declare(strict_types=1);

namespace App\Support\Ai\Evaluation;

use Normalizer;
use RuntimeException;

/** Share canonical Unicode phrase matching between grounding and scoring. */
final class GroundedAnswerPhraseMatcher
{
    public function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', mb_strtolower($this->canonicalize($value))));
    }

    /** Preserve punctuation and casing for consumers such as language detection. */
    public function canonicalize(string $value): string
    {
        $canonical = Normalizer::normalize($value, Normalizer::FORM_C);

        if ($canonical === false) {
            throw new RuntimeException('Evaluation phrase normalization requires valid Unicode.');
        }

        return $canonical;
    }

    public function containsPhrase(string $normalizedText, string $phrase): bool
    {
        return str_contains(' '.$normalizedText.' ', ' '.$this->normalize($phrase).' ');
    }
}
