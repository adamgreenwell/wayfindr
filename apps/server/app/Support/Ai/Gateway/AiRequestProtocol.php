<?php

declare(strict_types=1);

namespace App\Support\Ai\Gateway;

use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiProviderRoutes;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use SensitiveParameter;
use Throwable;

/** Validates the final SDK JSON, after all provider options have been merged. */
final class AiRequestProtocol
{
    private const ROUTES = [
        'openai' => 'responses', 'gemini' => 'interactions', 'anthropic' => 'messages',
        'ollama' => 'api/chat', 'openai-compatible' => 'chat/completions', 'openrouter' => 'chat/completions',
    ];

    /** @return array<string, string> */
    public static function headers(
        #[SensitiveParameter] ResolvedAgentCopilotConnection $connection,
        #[SensitiveParameter] string $baseUrl,
        #[SensitiveParameter] array $headers,
        #[SensitiveParameter] array $configuredHeaders,
    ): array {
        self::require($baseUrl === AiProviderRoutes::baseUrl($connection));
        $key = $connection->apiKey();
        $expected = match ($connection->provider) {
            'anthropic' => ['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'anthropic-beta' => 'web-fetch-2025-09-10'],
            'gemini' => ['x-goog-api-key' => $key],
            'openai', 'openrouter' => ['authorization' => 'Bearer '.$key],
            'ollama', 'openai-compatible' => $key === '' ? [] : ['authorization' => 'Bearer '.$key],
            default => throw new AiNetworkException('unsupported_provider'),
        };
        $actual = [];
        foreach ([$headers, $configuredHeaders] as $group) {
            foreach ($group as $name => $value) {
                self::require(is_string($name) && is_string($value) && preg_match('/[\r\n]/', $name.$value) === 0);
                $name = strtolower($name);
                self::require(array_key_exists($name, $expected) && hash_equals($expected[$name], $value));
                self::require(! isset($actual[$name]) || hash_equals($actual[$name], $value));
                $actual[$name] = $value;
            }
        }
        ksort($actual);
        ksort($expected);
        self::require($actual === $expected);

        return [...$actual, 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
    }

    /** @return array{url: string, json: string} */
    public static function request(
        #[SensitiveParameter] ResolvedAgentCopilotConnection $connection,
        int $maxOutputTokens,
        #[SensitiveParameter] string $method,
        #[SensitiveParameter] string $url,
        #[SensitiveParameter] array $options,
    ): array {
        self::require($method === 'POST' && isset(self::ROUTES[$connection->provider]) && $url === self::ROUTES[$connection->provider]);
        self::require(array_keys($options) === ['json'] && is_array($options['json']));
        try {
            // Validate what will actually cross the wire, including schema objects.
            $json = json_encode($options['json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 64);
            self::require(strlen($json) <= 65_536);
            $body = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new AiNetworkException('invalid_request');
        }
        self::require(is_array($body) && ($body['model'] ?? null) === $connection->model && $maxOutputTokens > 0);
        self::require(! array_key_exists('stream', $body) || $body['stream'] === false);

        match ($connection->provider) {
            'openai' => self::openAi($body, $maxOutputTokens),
            'gemini' => self::gemini($body, $maxOutputTokens),
            'anthropic' => self::anthropic($body, $maxOutputTokens),
            'ollama' => self::ollama($body, $maxOutputTokens),
            'openai-compatible', 'openrouter' => self::chat($body, $connection, $maxOutputTokens),
        };

        return ['url' => AiProviderRoutes::baseUrl($connection).'/'.$url, 'json' => $json];
    }

    private static function openAi(#[SensitiveParameter] array $body, int $cap): void
    {
        self::keys($body, ['model', 'input', 'max_output_tokens', 'store', 'stream', 'temperature', 'top_p', 'text', 'include']);
        self::require(($body['max_output_tokens'] ?? null) === $cap && ($body['store'] ?? null) === false);
        self::messages($body['input'] ?? null, ['input_text', 'output_text']);
        self::require(! isset($body['include']) || $body['include'] === ['reasoning.encrypted_content']);
        self::schema($body, 'text');
        self::sampling($body);
    }

    private static function gemini(#[SensitiveParameter] array $body, int $cap): void
    {
        self::keys($body, ['model', 'input', 'system_instruction', 'generation_config', 'response_format', 'store', 'stream']);
        self::require(($body['store'] ?? null) === false && is_array($body['generation_config'] ?? null));
        $config = $body['generation_config'];
        self::keys($config, ['max_output_tokens', 'temperature', 'top_p']);
        self::require(($config['max_output_tokens'] ?? null) === $cap);
        self::sampling($config);
        self::require(! isset($body['system_instruction']) || is_string($body['system_instruction']));
        self::require(is_array($body['input'] ?? null) && array_is_list($body['input']) && $body['input'] !== []);
        foreach ($body['input'] as $step) {
            self::require(is_array($step));
            self::keys($step, ['type', 'content']);
            self::require(in_array($step['type'] ?? null, ['user_input', 'model_output'], true));
            self::content($step['content'] ?? null, ['text'], strings: false);
        }
        self::schema($body, 'response_format');
    }

    private static function anthropic(#[SensitiveParameter] array $body, int $cap): void
    {
        self::keys($body, ['model', 'messages', 'max_tokens', 'system', 'temperature', 'top_p', 'output_config', 'stream']);
        self::require(($body['max_tokens'] ?? null) === $cap);
        self::messages($body['messages'] ?? null, ['text']);
        if (isset($body['system'])) {
            self::content($body['system'], ['text']);
        }
        self::schema($body, 'output_config');
        self::sampling($body);
    }

    private static function ollama(#[SensitiveParameter] array $body, int $cap): void
    {
        self::keys($body, ['model', 'messages', 'options', 'format', 'stream']);
        self::require(($body['stream'] ?? null) === false && is_array($body['options'] ?? null));
        self::keys($body['options'], ['num_predict', 'temperature', 'top_p']);
        self::require(($body['options']['num_predict'] ?? null) === $cap);
        self::sampling($body['options']);
        self::messages($body['messages'] ?? null, []);
        self::schema($body, 'format');
    }

    private static function chat(#[SensitiveParameter] array $body, #[SensitiveParameter] ResolvedAgentCopilotConnection $connection, int $cap): void
    {
        self::keys($body, ['model', 'messages', 'max_tokens', 'temperature', 'top_p', 'response_format', 'stream', ...($connection->provider === 'openrouter' ? ['provider'] : [])]);
        self::require(($body['max_tokens'] ?? null) === $cap);
        self::messages($body['messages'] ?? null, ['text']);
        self::schema($body, 'response_format');
        self::sampling($body);
        if ($connection->provider === 'openrouter') {
            $provider = $body['provider'] ?? null;
            self::require(is_array($provider) && trim($connection->openRouterProvider) !== '');
            self::keys($provider, ['order', 'allow_fallbacks', 'zdr', 'require_parameters']);
            self::require(($provider['order'] ?? null) === [strtolower(trim($connection->openRouterProvider))]
                && ($provider['allow_fallbacks'] ?? null) === false && ($provider['zdr'] ?? null) === true
                && ($provider['require_parameters'] ?? null) === true);
        }
    }

    private static function messages(#[SensitiveParameter] mixed $messages, array $types): void
    {
        self::require(is_array($messages) && array_is_list($messages) && $messages !== []);
        foreach ($messages as $message) {
            self::require(is_array($message));
            self::keys($message, ['role', 'content']);
            self::require(in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true));
            self::content($message['content'] ?? null, $types);
        }
    }

    private static function content(#[SensitiveParameter] mixed $content, array $types, bool $strings = true): void
    {
        if ($strings && is_string($content)) {
            return;
        }
        self::require(is_array($content) && array_is_list($content) && $content !== []);
        foreach ($content as $block) {
            self::require(is_array($block));
            self::keys($block, ['type', 'text']);
            self::require(in_array($block['type'] ?? null, $types, true) && is_string($block['text'] ?? null));
        }
    }

    private static function schema(#[SensitiveParameter] array $body, string $field): void
    {
        self::require(! isset($body[$field]) || is_array($body[$field]));
    }

    private static function sampling(#[SensitiveParameter] array $body): void
    {
        foreach (['temperature' => 2, 'top_p' => 1] as $field => $maximum) {
            if (array_key_exists($field, $body)) {
                $value = $body[$field];
                self::require((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= $maximum);
            }
        }
    }

    private static function keys(#[SensitiveParameter] array $values, array $allowed): void
    {
        self::require(array_diff(array_keys($values), $allowed) === []);
    }

    private static function require(bool $condition): void
    {
        if (! $condition) {
            throw new AiNetworkException('invalid_request');
        }
    }
}
