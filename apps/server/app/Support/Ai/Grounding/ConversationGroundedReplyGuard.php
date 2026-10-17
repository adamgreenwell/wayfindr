<?php

declare(strict_types=1);

namespace App\Support\Ai\Grounding;

use App\Support\Ai\ConversationReplyDraftContext;
use App\Support\Ai\ConversationReplyDraftPromptBuilder;
use App\Support\Ai\Evaluation\GroundedAnswerScoringContract;
use Composer\InstalledVersions;
use LanguageDetection\Language;
use ReflectionClass;

/** Conservative extractive evidence check; published text never grants authority to act. */
final class ConversationGroundedReplyGuard
{
    public const VERSION = 'private-grounded-reply-v1';

    public function propose(string $output, ConversationReplyDraftContext $context): ?ConversationGroundedReply
    {
        $proposal = app(ConversationGroundedReplyOutputParser::class)->parse($output, $context->groundingSources);
        if ($proposal === null) {
            return null;
        }
        $conflict = $this->numericConflict($context->groundingSources) || $this->negationConflict($context->groundingSources);
        if ($proposal->decision !== 'supported' || $conflict || ! $this->supported($proposal)) {
            return $this->clarification($context, $conflict || $proposal->decision === 'conflicting');
        }
        $locale = $this->locale($context);
        $reply = trans('ai_assist.grounding.attribution', [], $locale)."\n\n".$proposal->reply;
        if (mb_strlen($reply) > ConversationGroundedReplyOutputParser::MAX_REPLY_CHARACTERS) {
            return $this->clarification($context, false);
        }

        return new ConversationGroundedReply('supported', $reply, $proposal->citations, null, [], $proposal->citedSources);
    }

    /** Current local fixture/scorer identity is distinct from historical model evaluation captures. */
    public function identity(): string
    {
        $sources = [];
        foreach ([self::class, ConversationGroundedReplyOutputParser::class, ConversationGroundingSelector::class, ConversationReplyDraftPromptBuilder::class] as $class) {
            $sources[$class] = app(GroundedAnswerScoringContract::class)->sourceFingerprint(file_get_contents((new ReflectionClass($class))->getFileName()));
        }

        return hash('sha256', json_encode([self::VERSION, $sources, InstalledVersions::getPrettyVersion('patrickschur/language-detection')], JSON_THROW_ON_ERROR));
    }

    private function supported(ConversationGroundedReply $proposal): bool
    {
        $sentences = $this->sentences($proposal->reply);
        if ($sentences === [] || preg_match('/(?:ignore|disregard|override|forget).{0,80}(?:instructions?|rules?|policy|system)|system\s+prompt|(?:reveal|send|share|display).{0,40}(?:secrets?|api[ _-]?keys?|passwords?|bearer)|(?:ignoriere|ignorare).{0,80}(?:regeln|istruzioni)|(?:we|I)\s+(?:will|promise|guarantee)|(?:is|was|has|have|already|successfully).{0,35}(?:refunded|cancelled|deleted|processed|completed|approved)|wir\s+(?:werden|garantieren)|(?:rimborso|azione).{0,25}(?:completat|eseguit)/iu', $proposal->reply) === 1) {
            return false;
        }
        $used = [];
        foreach ($sentences as $sentence) {
            $found = false;
            foreach ($proposal->citedSources as $source) {
                if (in_array($sentence, $this->sentences($source['passage']), true)) {
                    $found = true;
                    $used[$source['ref']] = true;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return count($used) === count($proposal->citations);
    }

    /** Different numeric limits in selected guidance require clarification, never a silently chosen policy. */
    private function numericConflict(array $sources): bool
    {
        $limits = [];
        foreach ($sources as $source) {
            preg_match_all('/\b(\d+(?:[.,]\d+)?)\s*(days?|weeks?|months?|hours?|minutes?|tage?|tagen|wochen?|monate?|stunden?|giorni?|settimane?|mesi|ore|minuti|%)/iu', $source['passage'], $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $unit = mb_strtolower($match[2]);
                $unit = match (true) {
                    preg_match('/day|tag|giorn/u', $unit) === 1 => 'day',
                    preg_match('/week|woch|settiman/u', $unit) === 1 => 'week',
                    preg_match('/month|monat|mesi/u', $unit) === 1 => 'month',
                    preg_match('/hour|stund|ore/u', $unit) === 1 => 'hour',
                    preg_match('/minut/u', $unit) === 1 => 'minute',
                    default => $unit,
                };
                $limits[$unit][$source['ref']][$match[1]] = true;
            }
        }
        foreach ($limits as $bySource) {
            if (count($bySource) > 1) {
                $values = array_map(function ($numbers): string {
                    ksort($numbers);

                    return implode(',', array_keys($numbers));
                }, $bySource);
                if (count(array_unique($values)) > 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Opposing complete statements cannot be silently reduced to the favorable quotation. */
    private function negationConflict(array $sources): bool
    {
        $claims = [];
        foreach ($sources as $source) {
            foreach ($this->sentences($source['passage']) as $sentence) {
                $text = mb_strtolower($sentence);
                $negative = preg_match('/\b(?:not|never|no|nicht|kein(?:e|en|er|es)?|non|mai)\b/u', $text) === 1;
                $key = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', preg_replace('/\b(?:not|never|no|nicht|kein(?:e|en|er|es)?|non|mai)\b/u', '', $text)));
                $claims[$key][$negative ? 'negative' : 'positive'][$source['ref']] = true;
            }
        }
        foreach ($claims as $claim) {
            if (! empty($claim['negative']) && ! empty($claim['positive'])
                && array_keys($claim['negative']) !== array_keys($claim['positive'])) {
                return true;
            }
        }

        return false;
    }

    /** Full sentences only: substring cuts cannot drop a negation or change a number. */
    private function sentences(string $text): array
    {
        return array_values(array_filter(array_map(fn ($sentence) => trim((string) preg_replace('/\s+/u', ' ', $sentence)),
            preg_split('/(?<=[.!?])\s+|\R+/u', $text) ?: []), fn ($sentence) => $sentence !== ''));
    }

    private function clarification(ConversationReplyDraftContext $context, bool $conflicting): ConversationGroundedReply
    {
        $locale = $this->locale($context);
        $sources = $conflicting ? $context->groundingSources : [];
        $conflicting = $conflicting && count($sources) >= 2;

        return new ConversationGroundedReply($conflicting ? 'conflicting' : 'insufficient',
            trans('ai_assist.grounding.clarifying_reply', [], $locale), array_column($sources, 'ref'),
            trans('ai_assist.grounding.'.($conflicting ? 'conflicting' : 'insufficient'), [], $locale),
            [trans('ai_assist.grounding.clarify_request', [], $locale)], $sources);
    }

    private function locale(ConversationReplyDraftContext $context): string
    {
        $input = json_decode($context->prompt->input, true, 12, JSON_THROW_ON_ERROR);
        $messages = $input['conversation']['messages'] ?? [];
        $text = implode(' ', array_column(array_filter($messages, fn ($message) => ($message['role'] ?? '') === 'visitor'), 'body'));
        if (trim($text) === '') {
            return 'en';
        }
        $locale = (string) (new Language(['en', 'de', 'it']))->detect(mb_substr($text, -4000));

        return in_array($locale, ['en', 'de', 'it'], true) ? $locale : 'en';
    }
}
