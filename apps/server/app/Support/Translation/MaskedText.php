<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * A string with everything untranslatable swapped out for a token.
 *
 * @param  array<string, string>  $map  token => the original it stands for
 */
final class MaskedText
{
    /**
     * @param  array<string, string>  $map
     */
    public function __construct(
        public readonly string $text,
        public readonly array $map,
    ) {}

    public function isFullyMasked(): bool
    {
        return trim(str_replace(array_keys($this->map), '', $this->text)) === '';
    }
}
