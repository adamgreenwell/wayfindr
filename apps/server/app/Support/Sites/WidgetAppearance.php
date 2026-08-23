<?php

namespace App\Support\Sites;

use App\Models\Site;

/**
 * How a site's widget looks and what it says, as far as an operator may decide.
 *
 * Deliberately separate from the site's ADR 0014 colour. That one exists so an
 * AGENT can tell one site from another in the queue; this one is what a
 * VISITOR reads as the brand of the company they came to. Recolouring a site so
 * a busy desk can pick it out of a rail should not restyle somebody's storefront,
 * and a real brand is rarely one of six wayfinding colours.
 *
 * Nothing here is required. A site that configures none of it renders exactly as
 * every widget did before this existed.
 */
final class WidgetAppearance
{
    public const POSITIONS = ['right', 'left'];

    /** The widget's own surfaces, which an accent has to survive being shown on. */
    private const SURFACE_LIGHT = '#FFFFFF';

    private const SURFACE_DARK = '#1B1D20';

    /**
     * Contrast an accent must reach against each surface.
     *
     * 3:1 is the WCAG AA floor for a UI component's boundary against its
     * background. Both surfaces, because the widget renders in whichever theme
     * the visitor's browser is in and the operator picks one colour for both.
     */
    public const MIN_SURFACE_CONTRAST = 3.0;

    /** And what text sitting ON the accent has to reach, which is the text floor. */
    public const MIN_INK_CONTRAST = 4.5;

    private function __construct(
        public readonly ?string $accent,
        public readonly ?string $accentLight,
        public readonly ?string $accentDark,
        public readonly ?string $accentInkLight,
        public readonly ?string $accentInkDark,
        public readonly string $position,
        public readonly ?string $greeting,
        public readonly ?string $placeholder,
    ) {}

    public static function for(Site $site): self
    {
        $config = is_array($site->settings['appearance'] ?? null) ? $site->settings['appearance'] : [];

        $accent = self::normalizeHex($config['accent'] ?? null);

        // One brand colour in, a rendering of it per theme out -- which is what
        // Wayfindr already does for its own accent (#0D6F68 light, #3FA69D
        // dark). Demanding a single hex clear both grounds would have rejected
        // that very pair, and every brand that is not mid-grey with it.
        $light = $accent === null ? null : self::rendered($accent, self::SURFACE_LIGHT);
        $dark = $accent === null ? null : self::rendered($accent, self::SURFACE_DARK);

        return new self(
            $accent,
            $light,
            $dark,
            // Derived, never configured. An operator choosing both a colour and
            // the text on it is an operator who can choose an unreadable pair.
            $light === null ? null : self::inkOn($light),
            $dark === null ? null : self::inkOn($dark),
            in_array($config['position'] ?? null, self::POSITIONS, true) ? $config['position'] : 'right',
            self::text($config['greeting'] ?? null),
            self::text($config['placeholder'] ?? null),
        );
    }

    /**
     * @return array{accent: string|null, accent_ink: string|null, position: string, greeting: string|null, placeholder: string|null}
     */
    public function toPayload(): array
    {
        return [
            'accent' => $this->accentLight,
            'accent_dark' => $this->accentDark,
            'accent_ink' => $this->accentInkLight,
            'accent_ink_dark' => $this->accentInkDark,
            'position' => $this->position,
            'greeting' => $this->greeting,
            'placeholder' => $this->placeholder,
        ];
    }

    /**
     * Why this colour cannot be used, or null if it can.
     *
     * Enforced rather than trusted: a widget that lets an operator produce
     * unreadable text is a support burden wearing a feature's clothes. The
     * message names the surface that failed, because "not enough contrast" on
     * its own leaves somebody guessing which direction to move.
     */
    public static function accentRejection(string $hex): ?string
    {
        // Only genuine nonsense is refused. Every real colour is made to work
        // rather than sent back: an operator typing their brand hex is not
        // guessing, and a form that calls their own brand invalid is a form
        // that is wrong about them.
        return self::normalizeHex($hex) === null ? 'Use a colour like #7C3AED.' : null;
    }

    /**
     * The nearest rendering of this colour that can be seen on that surface.
     *
     * Lightness is walked rather than the colour replaced, so the result stays
     * recognisably the brand: a deep navy on a dark panel becomes a lighter
     * navy, not a default blue. A colour already clear enough is returned
     * untouched, which is the common case.
     */
    /**
     * The colour as it will actually be painted on that surface.
     *
     * Two constraints, and both have to hold at once: the accent must be
     * visible against the panel, and SOMETHING readable must sit on top of it.
     * Satisfying them separately does not work -- moving a colour to clear the
     * panel can walk it straight into the band where neither white nor black
     * reaches 4.5:1, which is what #777777 does.
     */
    private static function rendered(string $hex, string $surface): string
    {
        $candidate = self::readableOn($hex, $surface);

        if (self::inkContrast($candidate) >= self::MIN_INK_CONTRAST) {
            return $candidate;
        }

        // Mid-luminance: neither ink reaches the floor. Walk both ways and take
        // the first that satisfies both, so the colour moves as little as it
        // has to.
        [$h, $sat, $l] = self::toHsl($candidate);

        for ($i = 1; $i <= 50; $i++) {
            foreach ([$l + ($i * 0.02), $l - ($i * 0.02)] as $lightness) {
                if ($lightness < 0.0 || $lightness > 1.0) {
                    continue;
                }

                $next = self::fromHsl($h, $sat, $lightness);

                if (self::contrast($next, $surface) >= self::MIN_SURFACE_CONTRAST
                    && self::inkContrast($next) >= self::MIN_INK_CONTRAST) {
                    return $next;
                }
            }
        }

        return $candidate;
    }

    /** How readable the better of white or near-black is on this colour. */
    private static function inkContrast(string $hex): float
    {
        return max(self::contrast($hex, '#FFFFFF'), self::contrast($hex, '#141517'));
    }

    private static function readableOn(string $hex, string $surface): string
    {
        if (self::contrast($hex, $surface) >= self::MIN_SURFACE_CONTRAST) {
            return $hex;
        }

        [$h, $sat, $l] = self::toHsl($hex);
        // Away from the surface: lighter on a dark ground, darker on a light one.
        $step = self::luminance($surface) > 0.5 ? -0.02 : 0.02;

        for ($i = 0; $i < 60; $i++) {
            $l = max(0.0, min(1.0, $l + $step));
            $candidate = self::fromHsl($h, $sat, $l);

            if (self::contrast($candidate, $surface) >= self::MIN_SURFACE_CONTRAST) {
                return $candidate;
            }

            if ($l <= 0.0 || $l >= 1.0) {
                break;
            }
        }

        // Only reachable for a colour with no lightness range left to walk.
        return self::luminance($surface) > 0.5 ? '#141517' : '#FFFFFF';
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function toHsl(string $hex): array
    {
        // Cast, because PHP's `/` returns an INT when the division comes out
        // whole: hexdec('FF') / 255 is int(1), so `$d === 0.0` was false for
        // pure white and pure black and both divided by zero below.
        $r = (float) (hexdec(substr($hex, 1, 2)) / 255);
        $g = (float) (hexdec(substr($hex, 3, 2)) / 255);
        $b = (float) (hexdec(substr($hex, 5, 2)) / 255);

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d === 0.0) {
            return [0.0, 0.0, $l];
        }

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        $h = match (true) {
            $max === $r => fmod((($g - $b) / $d) + ($g < $b ? 6 : 0), 6),
            $max === $g => (($b - $r) / $d) + 2,
            default => (($r - $g) / $d) + 4,
        };

        return [$h / 6, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        if ($s === 0.0) {
            $v = (int) round($l * 255);

            return sprintf('#%02X%02X%02X', $v, $v, $v);
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - ($l * $s);
        $p = (2 * $l) - $q;

        $channel = static function (float $t) use ($p, $q): int {
            $t = fmod($t + 1, 1);

            $value = match (true) {
                $t < 1 / 6 => $p + (($q - $p) * 6 * $t),
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + (($q - $p) * ((2 / 3) - $t) * 6),
                default => $p,
            };

            return (int) round($value * 255);
        };

        return sprintf('#%02X%02X%02X', $channel($h + 1 / 3), $channel($h), $channel($h - 1 / 3));
    }

    /**
     * Black or white, whichever a visitor can actually read on this colour.
     */
    private static function inkOn(string $hex): string
    {
        return self::contrast($hex, '#FFFFFF') >= self::contrast($hex, '#141517') ? '#FFFFFF' : '#141517';
    }

    private static function contrast(string $a, string $b): float
    {
        $one = self::luminance($a);
        $two = self::luminance($b);

        return $one > $two ? ($one + 0.05) / ($two + 0.05) : ($two + 0.05) / ($one + 0.05);
    }

    /**
     * Relative luminance, per WCAG: channels linearised before weighting, which
     * is the step a naive average leaves out and the reason a naive average
     * calls mid-blue and mid-yellow equally bright.
     */
    private static function luminance(string $hex): float
    {
        $channels = [];

        foreach ([1, 3, 5] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    }

    private static function normalizeHex(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $hex = strtoupper(trim($value));

        // Shorthand expanded rather than refused: #7C3 is what people type, and
        // refusing it teaches nothing.
        if (preg_match('/^#?([0-9A-F])([0-9A-F])([0-9A-F])$/', $hex, $match) === 1) {
            $hex = '#'.$match[1].$match[1].$match[2].$match[2].$match[3].$match[3];
        }

        if (preg_match('/^#?[0-9A-F]{6}$/', $hex) !== 1) {
            return null;
        }

        return str_starts_with($hex, '#') ? $hex : '#'.$hex;
    }

    private static function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, 120);
    }
}
