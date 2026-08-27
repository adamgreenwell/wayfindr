<?php

declare(strict_types=1);

namespace App\Support\Translation\Engines;

use App\Support\Translation\EngineBrief;
use App\Support\Translation\TranslationEngine;

/**
 * Returns the source unchanged.
 *
 * Not a joke and not only a test double: it exercises the whole pipeline --
 * masking, plural splitting, restoration, key parity, the writer -- without
 * spending a credit or depending on a network. A run against this engine that
 * produces a clean plan proves the machinery, which is the part worth proving
 * before pointing it at a paid API.
 */
final class PassthroughEngine implements TranslationEngine
{
    public function name(): string
    {
        return 'passthrough';
    }

    public function usesBrief(): bool
    {
        return false;
    }

    public function translate(array $texts, EngineBrief $brief): array
    {
        return $texts;
    }
}
