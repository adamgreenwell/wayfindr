<?php

declare(strict_types=1);

namespace App\Support\Ai\Execution;

use App\Support\Ai\Gateway\AiRequestProtocol;
use App\Support\Ai\Network\AiHttpResult;
use App\Support\Ai\Network\AiHttpTransport;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiProviderRoutes;
use App\Support\Ai\Network\AiRetryAdvice;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Single-use in-process capability. No prompt/body is retained after send. */
final class AiProviderRequestSession implements AiGuardedSession
{
    private bool $requested = false;

    private ?AiHttpResult $receipt = null;

    private ?int $inputTokens = null;

    private ?int $outputTokens = null;

    private ?int $retryAfter = null;

    public function __construct(#[SensitiveParameter] private readonly ResolvedAgentCopilotConnection $connection,
        private readonly AiExecutionContext $context, private readonly int $outputCap,
        private readonly AiUsageLedger $ledger, private readonly AiRequestAuthority $authority,
        private readonly AiHttpTransport $transport, private readonly AiUsageClock $clock) {}

    public function connection(): ResolvedAgentCopilotConnection
    {
        return $this->connection;
    }

    public function maxOutputTokens(): int
    {
        return $this->outputCap;
    }

    public function usage(): array
    {
        return ['input' => $this->inputTokens, 'output' => $this->outputTokens];
    }

    public function dispatch(#[SensitiveParameter] string $url, #[SensitiveParameter] array $headers, #[SensitiveParameter] string $json): AiHttpResult
    {
        return $this->request('POST', $url, $headers, $json);
    }

    public function request(#[SensitiveParameter] string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] array $headers, #[SensitiveParameter] string $body = ''): AiHttpResult
    {
        if ($this->requested) {
            throw new AiNetworkException('usage_attempt_dispatched');
        }
        $this->requested = true;
        try {
            if (strlen($body) > 65_536 || ($this->context->kind === 'discovery' ? ($method !== 'GET' || $body !== '') : $method !== 'POST')) {
                throw new AiNetworkException('invalid_request');
            }
            $base = AiProviderRoutes::baseUrl($this->connection);
            if ($this->context->kind === 'discovery') {
                $route = $this->connection->provider === 'ollama' ? '/api/tags' : '/models';
                if ($url !== $base.$route) {
                    throw new AiNetworkException('invalid_request');
                }
            } else {
                if (! str_starts_with($url, $base.'/')) {
                    throw new AiNetworkException('invalid_request');
                }
                $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                $checked = AiRequestProtocol::request($this->connection, $this->outputCap, $method,
                    substr($url, strlen($base) + 1), ['json' => $decoded]);
                if ($checked['url'] !== $url) {
                    throw new AiNetworkException('invalid_request');
                }
            }
            $stored = DB::table('ai_usage_attempts')->where('attempt_id', $this->context->attemptId)->first();
            if ($this->context->kind === 'support') {
                if ($stored === null || strlen($body) + 1_024 > (int) $stored->input_token_bound) {
                    throw new AiNetworkException('usage_input_limit');
                }
                $bound = (int) $stored->input_token_bound;
                $fingerprint = $stored->work_fingerprint;
                $replacement = null;
            } else {
                $bound = $this->context->kind === 'discovery' ? 0 : strlen($body) + 1_024;
                $fingerprint = hash_hmac('sha256', json_encode([
                    'direct-v1', $this->context->kind, $this->connection->revision, $method, $body,
                ], JSON_THROW_ON_ERROR), (string) config('app.key'));
                $replacement = DB::table('ai_usage_attempts')->where('account_id', $this->connection->accountId)
                    ->where('source', $this->connection->source)->where('connection_revision', $this->connection->revision)
                    ->where('purpose', $this->context->purpose)->where('work_fingerprint', $fingerprint)
                    ->orderByDesc('id')->value('attempt_id');
            }
            $reservation = $this->ledger->reserve($this->connection, $this->context->purpose, $this->context->attemptId,
                $bound, $this->outputCap, $fingerprint, $this->context->actorId, $replacement,
                fn () => $this->authority->assertAllowed($this->connection, $this->context, $this->context->kind === 'support'));
            if ($reservation->attemptId !== $this->context->attemptId || $reservation->state !== 'reserved') {
                throw new AiNetworkException('usage_attempt_reused');
            }

            return $this->receipt = $this->transport->send($this->connection, $this->context->purpose,
                $this->context->attemptId, $method, $url, $headers, $body);
        } catch (AiNetworkException $exception) {
            $this->cancelReserved();
            try {
                $advice = $this->adviceSeconds($exception->retryAfterSeconds, $exception->retryAtUnix);
                if ($advice !== null) {
                    $attempt = DB::table('ai_usage_attempts')->where('attempt_id', $this->context->attemptId)->first();
                    if ($attempt?->dispatch_nonce !== null) {
                        $this->ledger->reconcile($attempt->attempt_id, $attempt->dispatch_nonce, null, null, 'provider_failed', $advice);
                    }
                }
            } catch (Throwable) {
                throw new AiNetworkException('usage_reconciliation_unavailable');
            }
            throw new AiNetworkException($exception->reason);
        } catch (Throwable) {
            $this->cancelReserved();
            throw new AiNetworkException('provider_unavailable');
        }
    }

    public function recordResponse(#[SensitiveParameter] AiHttpResult $receipt, ?int $inputTokens, ?int $outputTokens): void
    {
        if ($this->receipt !== $receipt || $receipt->attemptId !== $this->context->attemptId) {
            throw new AiNetworkException('usage_receipt_mismatch');
        }
        $advice = AiRetryAdvice::parse($receipt->response);
        $this->retryAfter = $this->adviceSeconds($advice['seconds'], $advice['at']);
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $bound = DB::table('ai_usage_attempts')->where('attempt_id', $this->context->attemptId)->value('input_token_bound');
        if (($inputTokens !== null && $inputTokens > (int) $bound) || ($outputTokens !== null && $outputTokens > $this->outputCap)) {
            throw new AiNetworkException('provider_limits_ignored');
        }
    }

    public function settle(#[SensitiveParameter] string $reason = 'completed'): void
    {
        if ($this->receipt === null) {
            $this->cancelReserved();

            return;
        }
        $this->ledger->reconcile($this->receipt->attemptId, $this->receipt->dispatchNonce,
            $this->inputTokens, $this->outputTokens, $reason, $this->retryAfter);
    }

    public function __serialize(): array
    {
        throw new LogicException('AI request sessions are server-only.');
    }

    public function __debugInfo(): array
    {
        return ['attempt_id' => $this->context->attemptId];
    }

    private function adviceSeconds(?int $seconds, ?int $at): ?int
    {
        if ($seconds === null && $at === null) {
            return null;
        }
        $terminal = DB::table('ai_usage_attempts')->where('attempt_id', $this->context->attemptId)->value('terminal_at');
        $anchor = $terminal === null ? $this->clock->now()->utc() : CarbonImmutable::parse($terminal, 'UTC');
        $dated = $at === null ? 0 : max(0, $at - $anchor->getTimestamp());

        return min(31_536_000, max($seconds ?? 0, $dated));
    }

    private function cancelReserved(): void
    {
        try {
            $this->ledger->cancelReserved($this->context->attemptId, 'known_unsent');
        } catch (Throwable) {
        }
    }
}
