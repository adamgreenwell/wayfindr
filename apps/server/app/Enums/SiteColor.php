<?php

namespace App\Enums;

/**
 * The colour an operator assigns to a site (ADR 0014).
 *
 * Wayfindr's model is one desk covering many sites, so the orientation question
 * an agent asks all day is "whose visitor is this?". A colour answers it faster
 * than a repeated site name, and the same choice reaches three surfaces: the
 * rail on a queue row, the chip in a transcript, and the accent a visitor sees
 * in the widget.
 *
 * The stored value is a TOKEN KEY, never a hex. Every consumer resolves it
 * through `--wf-site-<key>` from packages/design-tokens/tokens.json, so the
 * theme-tuned dark variants apply automatically and a hue can be retuned in one
 * place without a data migration.
 */
enum SiteColor: string
{
    case Red = 'red';
    case Blue = 'blue';
    case Ochre = 'ochre';
    case Pine = 'pine';
    case Violet = 'violet';
    case Rust = 'rust';

    public function label(): string
    {
        return match ($this) {
            self::Red => 'Red',
            self::Blue => 'Blue',
            self::Ochre => 'Ochre',
            self::Pine => 'Pine',
            self::Violet => 'Violet',
            self::Rust => 'Rust',
        };
    }

    /**
     * The custom property this colour resolves through.
     */
    public function cssVariable(): string
    {
        return '--wf-site-'.$this->value;
    }

    /**
     * The colour a site falls back to when none is stored.
     *
     * Deterministic rather than random: a site must not change colour between
     * two page loads, because the whole point is that an agent learns it.
     */
    public static function forPosition(int $position): self
    {
        $cases = self::cases();

        return $cases[abs($position) % count($cases)];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
