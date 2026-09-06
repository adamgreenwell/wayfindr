<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Support\Settings\OperatorSettings;

/** Validate the optional runtime provider without making a network request. */
final readonly class AgentCopilotConfiguration
{
    /** @var list<string> */
    public const PROVIDERS = [
        'anthropic',
        'gemini',
        'ollama',
        'openai',
        'openai-compatible',
        'openrouter',
    ];

    /** @var list<string> */
    private const KEY_OPTIONAL = ['ollama', 'openai-compatible'];

    public function __construct(private OperatorSettings $settings) {}

    /**
     * @return array{
     *   status: 'unset'|'incomplete'|'unsupported'|'unavailable'|'ready',
     *   provider: string,
     *   model: string,
     *   endpoint: string,
     *   has_api_key: bool,
     *   missing: list<string>
     * }
     */
    public function assessment(): array
    {
        $provider = strtolower(trim((string) config('ai.providers.wayfindr.driver')));
        $model = trim((string) config('ai.providers.wayfindr.models.text.default'));
        $endpoint = trim((string) config('ai.providers.wayfindr.url'));
        $hasApiKey = trim((string) config('ai.providers.wayfindr.key')) !== '';

        if (! $this->settings->valuesAreAuthoritative() || $this->settings->hasUnreadableValue('ai.api_key')) {
            return [
                'status' => 'unavailable',
                'provider' => $provider,
                'model' => $model,
                'endpoint' => $endpoint,
                'has_api_key' => $hasApiKey,
                'missing' => [],
            ];
        }

        return $this->assessValues($provider, $model, $endpoint, $hasApiKey);
    }

    /**
     * @return array{
     *   status: 'unset'|'incomplete'|'unsupported'|'ready',
     *   provider: string,
     *   model: string,
     *   endpoint: string,
     *   has_api_key: bool,
     *   missing: list<string>
     * }
     */
    public function assessValues(string $provider, string $model, string $endpoint, bool $hasApiKey): array
    {
        $provider = strtolower(trim($provider));
        $model = trim($model);
        $endpoint = trim($endpoint);
        $base = [
            'provider' => $provider,
            'model' => $model,
            'endpoint' => $endpoint,
            'has_api_key' => $hasApiKey,
        ];

        if ($provider === '') {
            return ['status' => 'unset', ...$base, 'missing' => []];
        }

        if (! in_array($provider, self::PROVIDERS, true)) {
            return ['status' => 'unsupported', ...$base, 'missing' => []];
        }

        $missing = [];

        if ($model === '') {
            $missing[] = 'model';
        }

        if (! in_array($provider, self::KEY_OPTIONAL, true) && ! $hasApiKey) {
            $missing[] = 'api_key';
        }

        if ($provider === 'openai-compatible' && $endpoint === '') {
            $missing[] = 'endpoint';
        }

        if ($endpoint !== '' && ! $this->validEndpoint($endpoint)) {
            $missing[] = 'valid_endpoint';
        }

        return [
            'status' => $missing === [] ? 'ready' : 'incomplete',
            ...$base,
            'missing' => $missing,
        ];
    }

    public function isReady(): bool
    {
        return $this->assessment()['status'] === 'ready';
    }

    private function validEndpoint(string $endpoint): bool
    {
        return filter_var($endpoint, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($endpoint, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
