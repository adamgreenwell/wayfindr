<?php

declare(strict_types=1);

namespace App\Support\Ai\Gateway;

use SensitiveParameter;

/** Raw provider evidence; missing counters must never become reported zero. */
final class AiReportedUsageParser
{
    /** @return array{input_tokens: ?int, output_tokens: ?int} */
    public static function parse(string $driver, #[SensitiveParameter] array $data): array
    {
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return match ($driver) {
            'openai' => ['input_tokens' => self::count($usage, 'input_tokens'), 'output_tokens' => self::count($usage, 'output_tokens')],
            'openai-compatible', 'openrouter' => ['input_tokens' => self::count($usage, 'prompt_tokens'), 'output_tokens' => self::count($usage, 'completion_tokens')],
            'anthropic' => [
                'input_tokens' => self::sum(self::count($usage, 'input_tokens'), self::count($usage, 'cache_read_input_tokens'), self::count($usage, 'cache_creation_input_tokens')),
                'output_tokens' => self::count($usage, 'output_tokens'),
            ],
            'gemini' => [
                'input_tokens' => self::count($usage, 'total_input_tokens'),
                'output_tokens' => self::sum(self::count($usage, 'total_output_tokens'), self::count($usage, 'total_thought_tokens')),
            ],
            'ollama' => ['input_tokens' => self::count($data, 'prompt_eval_count'), 'output_tokens' => self::count($data, 'eval_count')],
            default => ['input_tokens' => null, 'output_tokens' => null],
        };
    }

    private static function count(#[SensitiveParameter] array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) && $value >= 0 ? $value : null;
    }

    private static function sum(?int ...$counts): ?int
    {
        $total = 0;
        foreach ($counts as $count) {
            if ($count === null || $total > PHP_INT_MAX - $count) {
                return null;
            }
            $total += $count;
        }

        return $total;
    }
}
