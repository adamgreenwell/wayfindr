<?php

declare(strict_types=1);

namespace App\Enums;

/** Supported interpretations for safe host-provided visitor context. */
enum VisitorAttributeType: string
{
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function normalize(mixed $value): ?string
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = mb_substr(trim($value), 0, 160);

        if ($value === '') {
            return null;
        }

        return match ($this) {
            self::Text => $value,
            self::Number => preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/D', $value) === 1
                ? $value
                : null,
            self::Boolean => match (mb_strtolower($value)) {
                'true', '1', 'yes', 'on' => 'true',
                'false', '0', 'no', 'off' => 'false',
                default => null,
            },
            self::Date => $this->normalizeDate($value),
        };
    }

    /**
     * @return list<string>
     */
    public function storedValuesMatching(string $normalizedValue): array
    {
        if ($this !== self::Boolean) {
            return [$normalizedValue];
        }

        $aliases = match ($normalizedValue) {
            'true' => ['true', '1', 'yes', 'on'],
            'false' => ['false', '0', 'no', 'off'],
            default => [],
        };

        $values = [];

        foreach ($aliases as $alias) {
            foreach ($this->asciiCaseVariants($alias) as $variant) {
                $values[$variant] = true;
            }
        }

        return array_keys($values);
    }

    private function normalizeDate(string $value): ?string
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            ? $value
            : null;
    }

    /** @return list<string> */
    private function asciiCaseVariants(string $value): array
    {
        $variants = [''];

        foreach (str_split($value) as $character) {
            $next = [];

            foreach ($variants as $prefix) {
                $next[] = $prefix.strtolower($character);
                $next[] = $prefix.strtoupper($character);
            }

            $variants = array_values(array_unique($next));
        }

        return $variants;
    }
}
