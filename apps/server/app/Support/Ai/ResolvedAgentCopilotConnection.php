<?php

declare(strict_types=1);

namespace App\Support\Ai;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** In-process connection context. Queue only record IDs and revision stamps. */
final readonly class ResolvedAgentCopilotConnection implements JsonSerializable
{
    public function __construct(
        public int $accountId,
        public string $source,
        public string $revision,
        public string $provider,
        public string $model,
        public string $endpoint,
        public string $openRouterProvider,
        #[SensitiveParameter] private string $apiKey,
    ) {}

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /** @return array<string, int|string|bool> */
    public function jsonSerialize(): array
    {
        return [
            'account_id' => $this->accountId,
            'source' => $this->source,
            'revision' => $this->revision,
            'provider' => $this->provider,
            'model' => $this->model,
            'has_api_key' => $this->apiKey !== '',
        ];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new LogicException('Resolved AI connections cannot be serialized; queue IDs and revisions only.');
    }
}
