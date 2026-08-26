<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * Swaps everything untranslatable for a token, and puts it back afterwards.
 *
 * The alternative -- asking the engine nicely to preserve `:count` -- is what
 * the policy rules out, and for a measurable reason: `:elapsed` and `:shown` are
 * readable as words, so an engine that is merely instructed to leave them alone
 * sometimes will not. A token it has never seen before has nothing to translate
 * INTO, which is a much stronger guarantee than an instruction.
 *
 * Restoration is strict. A token that does not come back is a failed
 * translation for that string rather than a string to ship with a hole in it,
 * because the alternative is a Laravel `Missing placeholder` at render time in
 * front of an agent.
 */
final class Protector
{
    /**
     * Deliberately alphanumeric and deliberately strange.
     *
     * Punctuation gets reflowed by translation engines -- spacing changes around
     * braces, brackets get matched up and moved. A bare token that looks like a
     * product code survives because there is nothing in it to normalise.
     */
    private const TOKEN = 'WFZ';

    public function __construct(private readonly Glossary $glossary) {}

    public function mask(string $text): MaskedText
    {
        // A token must not already occur in the source. If it does, the first
        // placeholder is assigned a token the text already contains, the source
        // occurrence is counted as though it were another placeholder, and
        // restoration replaces BOTH -- turning `WFZ0 code :count` into
        // `:count code :count` while every check passes.
        //
        // Lengthening the prefix until it is absent costs nothing and removes
        // the collision rather than detecting it.
        $prefix = self::TOKEN;

        while (str_contains($text, $prefix)) {
            $prefix .= 'Z';
        }

        $map = [];
        $next = 0;

        $reserve = function (string $original) use (&$map, &$next, $prefix): string {
            $existing = array_search($original, $map, true);

            if ($existing !== false) {
                return (string) $existing;
            }

            $token = $prefix.$next++;
            $map[$token] = $original;

            return $token;
        };

        // Literals first and longest first, so `Ticket #123` is taken whole
        // rather than leaving `Ticket` behind for a pattern to find.
        $literals = $this->glossary->neverTranslate();
        usort($literals, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($literals as $literal) {
            if ($literal === '' || ! str_contains($text, $literal)) {
                continue;
            }

            $text = str_replace($literal, $reserve($literal), $text);
        }

        foreach ($this->glossary->protectPatterns() as $pattern) {
            $text = preg_replace_callback(
                $pattern,
                static fn (array $m): string => $reserve($m[0]),
                $text,
            ) ?? $text;
        }

        return new MaskedText($text, $map, $prefix);
    }

    /**
     * Put the originals back, or refuse to.
     *
     * Takes the whole MaskedText rather than just its map, because the SOURCE
     * is what says how many times each token is supposed to appear. Presence
     * alone is not the guarantee this pipeline claims: an engine that returns
     * one token twice would have written the placeholder twice into the
     * catalogue, and `:count :count` renders as a duplicated number in front of
     * an agent.
     */
    public function restore(string $translated, MaskedText $masked, string $context = ''): string
    {
        $missing = [];
        $miscounted = [];

        foreach ($masked->map as $token => $original) {
            $expected = $this->countToken($masked->text, $token);
            $actual = $this->countToken($translated, $token);

            if ($actual === 0) {
                $missing[] = $token.' ('.$original.')';

                continue;
            }

            if ($actual !== $expected) {
                $miscounted[] = $token.' ('.$original.') '.$expected.'x became '.$actual.'x';
            }
        }

        if ($missing !== []) {
            throw new TranslationFailed(
                trim($context.' lost '.implode(', ', $missing).' in translation: '.$translated)
            );
        }

        if ($miscounted !== []) {
            throw new TranslationFailed(
                trim($context.' returned protection tokens the wrong number of times: '
                    .implode(', ', $miscounted).' in translation: '.$translated)
            );
        }

        // strtr, NOT str_replace. With WFZ1 and WFZ10 both in the map,
        // str_replace applies the search array in order: WFZ1 lands first and
        // rewrites WFZ10 into "<whatever WFZ1 stood for>0". Nothing downstream
        // can see it either -- no token survives, so the leftover check below
        // passes on a corrupted string. strtr matches the longest key at each
        // position and never rescans what it has already written.
        $restored = strtr($translated, $masked->map);

        // A token the engine invented, or one it split in half and left a
        // fragment of. Either way the string is not safe to write.
        if (preg_match('/'.preg_quote($masked->prefix, '/').'\d+/', $restored) === 1) {
            throw new TranslationFailed(
                trim($context.' still carries a protection token after restore: '.$restored)
            );
        }

        return $restored;
    }

    /**
     * Occurrences of exactly this token.
     *
     * `substr_count($text, 'WFZ1')` also counts the WFZ1 inside WFZ10, so a
     * string with eleven protected items would report counts that never match
     * and fail every translation. The lookahead stops the token at its own
     * digits.
     */
    private function countToken(string $text, string $token): int
    {
        return (int) preg_match_all('/'.preg_quote($token, '/').'(?!\\d)/', $text);
    }
}
