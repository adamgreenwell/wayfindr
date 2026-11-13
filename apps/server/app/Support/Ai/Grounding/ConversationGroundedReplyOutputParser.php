<?php

declare(strict_types=1);

namespace App\Support\Ai\Grounding;

use JsonException;
use stdClass;

/** Verify only output shape, bounds and membership in supplied article guidance. */
final class ConversationGroundedReplyOutputParser
{
    public const MAX_OUTPUT_BYTES = 32_768;

    public const MAX_REPLY_CHARACTERS = 4_000;

    private const MAX_SOURCES = 3;

    /** @param list<array{ref:string,title:string,passage:string}> $suppliedSources Server-selected passages, never a provider-supplied catalog. */
    public function parse(string $output, array $suppliedSources): ?ConversationGroundedReply
    {
        if (strlen($output) > self::MAX_OUTPUT_BYTES || ! $this->validSources($suppliedSources)) {
            return null;
        }

        try {
            $decoded = json_decode(trim($output), false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! $decoded instanceof stdClass || $this->hasDuplicateRootKeys($output)) {
            return null;
        }
        $keys = array_keys(get_object_vars($decoded));
        sort($keys);
        if ($keys !== ['citations', 'decision', 'limitation', 'missing_information', 'reply']
            || ! is_string($decoded->decision) || ! in_array($decoded->decision, ['supported', 'insufficient', 'conflicting'], true)
            || ! $this->validText($decoded->reply, self::MAX_REPLY_CHARACTERS)) {
            return null;
        }

        if (! is_array($decoded->citations) || ! array_is_list($decoded->citations) || count($decoded->citations) > self::MAX_SOURCES
            || ! is_array($decoded->missing_information) || ! array_is_list($decoded->missing_information) || count($decoded->missing_information) > 8) {
            return null;
        }
        $sourcesByReference = array_column($suppliedSources, null, 'ref');
        $citations = [];
        $citedSources = [];
        foreach ($decoded->citations as $reference) {
            if (! $this->validReference($reference) || ! isset($sourcesByReference[$reference]) || in_array($reference, $citations, true)) {
                return null;
            }
            $citations[] = $reference;
            $citedSources[] = $sourcesByReference[$reference];
        }

        $missingInformation = [];
        foreach ($decoded->missing_information as $missing) {
            if (! $this->validText($missing, 250)) {
                return null;
            }
            $missingInformation[] = trim($missing);
        }
        $limitation = $decoded->limitation;
        if ($limitation !== null && ! $this->validText($limitation, 1_000)) {
            return null;
        }
        if (($decoded->decision === 'supported' && ($citations === [] || $limitation !== null || $missingInformation !== []))
            || ($decoded->decision === 'insufficient' && ($citations !== [] || $limitation === null || $missingInformation === []))
            || ($decoded->decision === 'conflicting' && (count($citations) < 2 || $limitation === null))) {
            return null;
        }

        return new ConversationGroundedReply(
            decision: $decoded->decision,
            reply: trim($decoded->reply),
            citations: $citations,
            limitation: $limitation === null ? null : trim($limitation),
            missingInformation: $missingInformation,
            citedSources: $citedSources,
        );
    }

    private function validSources(array $sources): bool
    {
        if (! array_is_list($sources) || count($sources) > self::MAX_SOURCES) {
            return false;
        }
        $seen = [];
        $bytes = 0;
        foreach ($sources as $source) {
            if (! is_array($source)) {
                return false;
            }
            $keys = array_keys($source);
            sort($keys);
            if ($keys !== ['passage', 'ref', 'title'] || ! $this->validReference($source['ref'])
                || isset($seen[$source['ref']]) || ! $this->validSourceText($source['title'], 500) || ! $this->validSourceText($source['passage'], 3_000)) {
                return false;
            }
            $seen[$source['ref']] = true;
            $bytes += strlen($source['ref']) + strlen($source['title']) + strlen($source['passage']);
            if ($bytes > self::MAX_OUTPUT_BYTES) {
                return false;
            }
        }

        return true;
    }

    private function validReference(mixed $value): bool
    {
        return is_string($value) && preg_match('/\Asrc-[a-f0-9]{24}\z/', $value) === 1;
    }

    private function validText(mixed $value, int $maximum): bool
    {
        return is_string($value) && strlen($value) <= self::MAX_OUTPUT_BYTES && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value) <= $maximum && trim($value) !== '' && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) !== 1;
    }

    private function validSourceText(mixed $value, int $maximum): bool
    {
        return is_string($value) && strlen($value) <= self::MAX_OUTPUT_BYTES && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value) <= $maximum && trim($value) !== '';
    }

    /** json_decode otherwise silently retains the last occurrence of a duplicate key. */
    private function hasDuplicateRootKeys(string $json): bool
    {
        $depth = 0;
        $expectsKey = false;
        $seen = [];
        $length = strlen($json);
        for ($index = 0; $index < $length; $index++) {
            $character = $json[$index];
            if ($character === '"') {
                $end = $index + 1;
                while ($end < $length && $json[$end] !== '"') {
                    $end += $json[$end] === '\\' ? 2 : 1;
                }
                if ($depth === 1 && $expectsKey) {
                    $key = json_decode(substr($json, $index, $end - $index + 1), true, 2, JSON_THROW_ON_ERROR);
                    if (isset($seen[$key])) {
                        return true;
                    }
                    $seen[$key] = true;
                    $expectsKey = false;
                }
                $index = $end;
            } elseif ($character === '{' || $character === '[') {
                $depth++;
                if ($depth === 1) {
                    $expectsKey = true;
                }
            } elseif ($character === '}' || $character === ']') {
                $depth--;
            } elseif ($character === ',' && $depth === 1) {
                $expectsKey = true;
            }
        }

        return false;
    }
}
