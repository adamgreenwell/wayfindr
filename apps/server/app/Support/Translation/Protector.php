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
        $map = [];
        $next = 0;

        $reserve = function (string $original) use (&$map, &$next): string {
            $existing = array_search($original, $map, true);

            if ($existing !== false) {
                return (string) $existing;
            }

            $token = self::TOKEN.$next++;
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

        return new MaskedText($text, $map);
    }

    /**
     * @param  array<string, string>  $map
     */
    public function restore(string $translated, array $map, string $context = ''): string
    {
        $missing = [];

        foreach ($map as $token => $original) {
            if (! str_contains($translated, $token)) {
                $missing[] = $token.' ('.$original.')';
            }
        }

        if ($missing !== []) {
            throw new TranslationFailed(
                trim($context.' lost '.implode(', ', $missing).' in translation: '.$translated)
            );
        }

        $restored = str_replace(array_keys($map), array_values($map), $translated);

        // A token the engine invented, or one it split in half and left a
        // fragment of. Either way the string is not safe to write.
        if (preg_match('/'.self::TOKEN.'\d+/', $restored) === 1) {
            throw new TranslationFailed(
                trim($context.' still carries a protection token after restore: '.$restored)
            );
        }

        return $restored;
    }
}
