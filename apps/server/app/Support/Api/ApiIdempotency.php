<?php

namespace App\Support\Api;

use App\Models\Account;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiToken;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use LogicException;

/**
 * Turns an Idempotency-Key into one durable public-API write.
 *
 * The account row joins the application's account-first write protocol before
 * the token row serialises one credential's short transactions. That gives
 * every supported database the same check-then-insert behaviour without
 * driver-specific advisory locks while avoiding token/site lock inversions.
 */
final class ApiIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public const RETENTION_HOURS = 24;

    /**
     * @param  array<string, mixed>  $input
     * @param  Closure(ApiToken): Model  $write
     * @param  Closure(int): ?Model  $resolve
     *
     * @throws JsonException
     */
    public function run(
        Request $request,
        array $input,
        string $resourceType,
        Closure $write,
        Closure $resolve,
    ): IdempotentWrite {
        $key = $this->key($request);
        $scope = ApiScope::fromRequest($request);
        $keyHash = hash('sha256', $key);
        $requestHash = $this->requestHash($request, $input);

        return DB::transaction(function () use (
            $scope,
            $keyHash,
            $requestHash,
            $resourceType,
            $write,
            $resolve,
        ): IdempotentWrite {
            // Site availability and archival mutations take account then site.
            // API writes must enter that order before their token and site
            // locks; the created-model observers may re-enter this account
            // lock when they snapshot the active SLA policy.
            Account::query()->whereKey($scope->accountId())->lockForUpdate()->firstOrFail();
            $token = ApiToken::query()
                ->whereKey($scope->token->getKey())
                ->lockForUpdate()
                ->first();

            // Revocation takes this same row lock. Whichever operation gets it
            // first wins cleanly: a committed write precedes revocation, or a
            // committed revocation refuses the write. There is no write in the
            // gap after a credential was disabled.
            if ($token === null || ! $token->isUsable()) {
                throw new HttpResponseException(response()->json([
                    'message' => 'That API token is not valid.',
                ], 401));
            }

            $existing = ApiIdempotencyKey::query()
                ->where('api_token_id', $token->id)
                ->where('key_hash', $keyHash)
                ->first();

            if ($existing?->expires_at?->isPast()) {
                $existing->delete();
                $existing = null;
            }

            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $requestHash)
                    || $existing->resource_type !== $resourceType) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'That idempotency key was already used for a different request.',
                    ], 409));
                }

                $resource = $resolve((int) $existing->resource_id);

                if (! $resource instanceof Model) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'The resource created by that idempotency key no longer exists.',
                    ], 409));
                }

                return new IdempotentWrite($resource, true);
            }

            $resource = $write($token);

            if (! $resource->exists || $resource->getKey() === null) {
                throw new LogicException('An idempotent API write must return a persisted model.');
            }

            ApiIdempotencyKey::query()->create([
                'api_token_id' => $token->id,
                'key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'resource_type' => $resourceType,
                'resource_id' => $resource->getKey(),
                'expires_at' => now()->addHours(self::RETENTION_HOURS),
            ]);

            return new IdempotentWrite($resource, false);
        });
    }

    private function key(Request $request): string
    {
        $value = $request->header(self::HEADER);
        $value = is_string($value) ? trim($value) : '';

        // Visible ASCII only. Header folding, control characters and invisible
        // whitespace do not belong in a key somebody has to log and retry.
        if ($value === '' || strlen($value) > 255 || preg_match('/^[\x21-\x7E]+$/', $value) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'The Idempotency-Key header is required and must be 1 to 255 visible ASCII characters.',
            ]);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws JsonException
     */
    private function requestHash(Request $request, array $input): string
    {
        return hash('sha256', json_encode([
            'method' => $request->getMethod(),
            // The concrete path is load-bearing: the same key and body sent to
            // two conversations must conflict rather than replay the first.
            'path' => $request->getPathInfo(),
            'input' => $this->canonical($input),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonical(...), $value);
        }

        ksort($value);

        return array_map($this->canonical(...), $value);
    }
}
