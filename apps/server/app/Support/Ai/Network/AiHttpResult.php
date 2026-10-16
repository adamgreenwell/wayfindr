<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use JsonSerializable;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/** In-process result; the dispatch capability never belongs in UI/queue/audit. */
final readonly class AiHttpResult implements JsonSerializable
{
    public function __construct(
        #[SensitiveParameter] public ResponseInterface $response,
        public string $attemptId,
        #[SensitiveParameter] public string $dispatchNonce,
    ) {}

    public function __debugInfo(): array
    {
        return ['attempt_id' => $this->attemptId, 'status' => $this->response->getStatusCode()];
    }

    public function jsonSerialize(): array
    {
        throw new LogicException('AI transport results are server-only; expose sanitized task state instead.');
    }

    public function __serialize(): array
    {
        throw new LogicException('AI transport results cannot be serialized; queue record IDs and revisions only.');
    }
}
