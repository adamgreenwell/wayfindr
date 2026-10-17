<?php

declare(strict_types=1);

namespace App\Support\Ai\Refinement;

use App\Support\Ai\AiContextSanitizer;
use LanguageDetection\Language;
use ReflectionClass;
use SensitiveParameter;

/** Conservative refusal checks, not a semantic certificate; exact human review is still required. */
final class DraftRefinementSafetyGuard
{
    public const VERSION = 'private-rewrite-safety-v1';

    public function permits(#[SensitiveParameter] DraftRefinementContext $context, #[SensitiveParameter] DraftRefinementProposal $proposal): bool
    {
        $input = json_decode($context->providerPrompt()->input, true, 12, JSON_THROW_ON_ERROR);
        $original = $input['draft'];
        $data = $proposal->previewData();
        $candidate = $data['candidate'];
        if (($input['operation']['action'] ?? null) === 'translate'
            && (string) (new Language(['en', 'de', 'it']))->detect($candidate) !== ($input['operation']['language'] ?? null)) {
            return false;
        }
        if ($data['limitation'] !== null || app(AiContextSanitizer::class)->sanitize($candidate) !== $candidate
            || $this->matches('/\p{N}+(?:[.,]\p{N}+)*/u', $original) !== $this->matches('/\p{N}+(?:[.,]\p{N}+)*/u', $candidate)
            || $this->matches('~https?://[^\s<>"\']+~u', $original) !== $this->matches('~https?://[^\s<>"\']+~u', $candidate)
            || preg_match('/(?:ignore|disregard|override|forget).{0,80}(?:instructions?|rules?|system)|system\s+prompt|(?:reveal|send|share).{0,40}(?:secrets?|api[ _-]?keys?|passwords?)|(?:ignoriere|ignorare).{0,80}(?:regeln|istruzioni)/iu', $candidate) === 1) {
            return false;
        }
        // Cross-language categories deliberately favor refusal over guessing.
        $negation = '/\b(?:not|never|no|cannot|can\x{2019}?t|can\x27t|don\x27t|isn\x27t|won\x27t|nicht|nie|kein\p{L}*|ohne|non|mai|senza)\b/iu';
        $qualification = '/\b(?:may|might|can|could|perhaps|possibly|if|unless|subject\s+to|provided|vielleicht|kann|können|könnte\p{L}*|falls|wenn|sofern|eventuell|potrebbe\p{L}*|può|possono|forse|se|salvo)\b/iu';
        if ((preg_match($negation, $original) === 1) !== (preg_match($negation, $candidate) === 1)
            || (preg_match($qualification, $original) === 1 && preg_match($qualification, $candidate) !== 1)) {
            return false;
        }
        foreach ([
            '/\b(?:will|promise\p{L}*|guarantee\p{L}*|garantier\p{L}*|versprech\p{L}*|werden|garant\p{L}*|promett\p{L}*|sarà|saremo|provvederemo)\b/iu',
            '/\b(?:refunded|processed|approved|cancelled|canceled|deleted|completed|rimborsat\p{L}*|eliminat\p{L}*|annullat\p{L}*|approvat\p{L}*|completat\p{L}*|erstattet|gelöscht|genehmigt|abgeschlossen|storniert)\b/iu',
            '/\b(?:eligible|entitled|berechtigt|idone\p{L}*|diritto)\b/iu',
        ] as $claim) {
            if (preg_match($claim, $candidate) === 1 && preg_match($claim, $original) !== 1) {
                return false;
            }
        }

        return true;
    }

    public function identity(): string
    {
        return hash('sha256', self::VERSION.hash_file('sha256', (new ReflectionClass(self::class))->getFileName())
            .hash_file('sha256', (new ReflectionClass(DraftRefinementOutputParser::class))->getFileName()));
    }

    private function matches(string $pattern, #[SensitiveParameter] string $text): array
    {
        preg_match_all($pattern, $text, $matches);
        $values = $matches[0];
        sort($values);

        return $values;
    }
}
