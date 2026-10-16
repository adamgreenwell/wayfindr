<?php

declare(strict_types=1);

use App\Support\Ai\Gateway\AiReportedUsageParser;

function reportedUsageFixture(string $driver, int $input, int $output): array
{
    return match ($driver) {
        'openai' => ['usage' => ['input_tokens' => $input, 'output_tokens' => $output]],
        'gemini' => ['usage' => ['total_input_tokens' => $input, 'total_output_tokens' => $output, 'total_thought_tokens' => 0]],
        'anthropic' => ['usage' => ['input_tokens' => $input, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0, 'output_tokens' => $output]],
        'ollama' => ['prompt_eval_count' => $input, 'eval_count' => $output],
        default => ['usage' => ['prompt_tokens' => $input, 'completion_tokens' => $output]],
    };
}

dataset('reported usage drivers', ['openai', 'gemini', 'anthropic', 'ollama', 'openai-compatible', 'openrouter']);

test('raw usage preserves genuine reported zero for every driver', function (string $driver): void {
    expect(AiReportedUsageParser::parse($driver, reportedUsageFixture($driver, 0, 0)))
        ->toBe(['input_tokens' => 0, 'output_tokens' => 0]);
})->with('reported usage drivers');

test('missing or malformed usage does not inherit SDK default zeros', function (string $driver, array $data): void {
    expect(AiReportedUsageParser::parse($driver, $data))->toBe(['input_tokens' => null, 'output_tokens' => null]);
})->with('reported usage drivers')->with([[[]], [['usage' => null]], [['usage' => 'private provider text']]]);

test('raw usage requires nonnegative integers and preserves the other known component', function (mixed $invalid): void {
    expect(AiReportedUsageParser::parse('openai', ['usage' => ['input_tokens' => 5, 'output_tokens' => $invalid]]))
        ->toBe(['input_tokens' => 5, 'output_tokens' => null]);
})->with([[null], [-1], ['7'], [7.0], [false], [[]], [['private-provider-text']]]);

test('Anthropic input includes cache reads and writes without silently treating missing buckets as zero', function (): void {
    expect(AiReportedUsageParser::parse('anthropic', ['usage' => ['input_tokens' => 5, 'cache_read_input_tokens' => 2, 'cache_creation_input_tokens' => 3, 'output_tokens' => 7]]))
        ->toBe(['input_tokens' => 10, 'output_tokens' => 7])
        ->and(AiReportedUsageParser::parse('anthropic', ['usage' => ['input_tokens' => 5, 'output_tokens' => 7]]))
        ->toBe(['input_tokens' => null, 'output_tokens' => 7]);
});

test('Gemini output includes thoughts and remains unknown when that billed component is missing', function (): void {
    expect(AiReportedUsageParser::parse('gemini', ['usage' => ['total_input_tokens' => 5, 'total_output_tokens' => 7, 'total_thought_tokens' => 3]]))
        ->toBe(['input_tokens' => 5, 'output_tokens' => 10])
        ->and(AiReportedUsageParser::parse('gemini', ['usage' => ['total_input_tokens' => 5, 'total_output_tokens' => 7]]))
        ->toBe(['input_tokens' => 5, 'output_tokens' => null]);
});

test('cached and reasoning subsets are not double counted when the driver reports inclusive totals', function (string $driver): void {
    $data = reportedUsageFixture($driver, 5, 7);
    $data['usage']['input_tokens_details']['cached_tokens'] = 2;
    $data['usage']['prompt_tokens_details']['cached_tokens'] = 2;
    $data['usage']['prompt_tokens_details']['cache_write_tokens'] = 1;
    $data['usage']['output_tokens_details']['reasoning_tokens'] = 3;
    $data['usage']['completion_tokens_details']['reasoning_tokens'] = 3;
    expect(AiReportedUsageParser::parse($driver, $data))->toBe(['input_tokens' => 5, 'output_tokens' => 7]);
})->with(['openai', 'openai-compatible', 'openrouter']);

test('component addition overflow retains unknown usage rather than wrapping into free allowance', function (string $driver, array $data, array $expected): void {
    expect(AiReportedUsageParser::parse($driver, $data))->toBe($expected);
})->with([
    ['anthropic', ['usage' => ['input_tokens' => PHP_INT_MAX, 'cache_read_input_tokens' => 1, 'cache_creation_input_tokens' => 0, 'output_tokens' => 7]], ['input_tokens' => null, 'output_tokens' => 7]],
    ['gemini', ['usage' => ['total_input_tokens' => 5, 'total_output_tokens' => PHP_INT_MAX, 'total_thought_tokens' => 1]], ['input_tokens' => 5, 'output_tokens' => null]],
]);
