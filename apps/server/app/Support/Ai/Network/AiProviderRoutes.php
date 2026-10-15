<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use App\Support\Ai\ResolvedAgentCopilotConnection;
use SensitiveParameter;

/** Matches the installed SDK defaults; this does not grant outbound authority. */
final class AiProviderRoutes
{
    public const DEFAULTS = [
        'anthropic' => 'https://api.anthropic.com/v1',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta',
        'ollama' => 'http://localhost:11434',
        'openai' => 'https://api.openai.com/v1',
        'openai-compatible' => null,
        'openrouter' => 'https://openrouter.ai/api/v1',
    ];

    public static function baseUrl(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection): string
    {
        if (! array_key_exists($connection->provider, self::DEFAULTS)) {
            throw new AiNetworkException('unsupported_provider');
        }

        $url = trim($connection->endpoint);
        if ($url === '') {
            $url = self::DEFAULTS[$connection->provider] ?? '';
        }
        if ($url === '') {
            throw new AiNetworkException('incomplete_configuration');
        }

        return rtrim($url, '/');
    }
}
