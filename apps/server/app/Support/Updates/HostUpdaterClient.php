<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Support\Version\SemanticVersion;
use JsonException;
use SensitiveParameter;
use Throwable;

/** Authenticated, bounded requests to the independently supervised host helper. */
class HostUpdaterClient
{
    private const REQUEST_LIMIT = 16 * 1024;

    private const RESPONSE_LIMIT = 1024 * 1024;

    private const TIMEOUT_SECONDS = 5;

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    private const ERRORS = [
        'authentication_failed', 'request_invalid', 'protocol_unsupported', 'installation_mismatch',
        'replay_detected', 'request_expired', 'operation_busy', 'idempotency_conflict', 'operation_missing',
        'journal_unavailable', 'journal_corrupt', 'journal_full', 'helper_unavailable', 'configuration_changed',
        'prepare_unavailable', 'prepare_failed', 'prepare_timeout', 'prepare_output_invalid', 'prerequisites_unmet',
        'identity_unverified', 'no_update_required', 'execution_not_available', 'reconciliation_required', 'interrupted_prepare',
    ];

    private const PHASES = ['accepted', 'preparing', 'reconciliation_required', 'blocked'];

    private const EVENTS = ['operation_accepted', 'prepare_started', 'plan_reported', 'operation_blocked', 'reconciliation_required', 'interrupted_prepare'];

    public function capabilities(string $runtimeProfile): InstallationCapabilities
    {
        $report = $this->request('capabilities');
        $helper = $report['helper'] ?? null;

        if (($report['ownership'] ?? null) !== 'installer-managed'
            || ($report['enrolled'] ?? null) !== true
            || ($report['platform'] ?? null) !== 'linux'
            || ! in_array($report['architecture'] ?? null, ['amd64', 'arm64'], true)
            || ! is_string($report['image_reference'] ?? null)
            || ! is_array($helper)
            || ($helper['protocol'] ?? null) !== 1
            || ! is_string($helper['version'] ?? null)
            || SemanticVersion::parse($helper['version']) === null
            || ! is_array($helper['capabilities'] ?? null)
            || ! array_is_list($helper['capabilities'])) {
            throw new HostUpdaterException('helper_capabilities_invalid');
        }

        foreach ($helper['capabilities'] as $capability) {
            if (! is_string($capability) || ! in_array($capability, InstallationCapabilities::REQUIRED_HELPER_CAPABILITIES, true)) {
                throw new HostUpdaterException('helper_capabilities_invalid');
            }
        }

        $installation = InstallationCapabilities::authenticatedHelper(
            $report, $runtimeProfile, $report['platform'], $report['architecture'], $report['image_reference'],
        );

        if ($installation->imageReference === null || $installation->helperVersion === null) {
            throw new HostUpdaterException('helper_capabilities_invalid');
        }

        return $installation;
    }

    /** @return array<string, mixed> */
    public function prepare(string $tag, string $requestId): array
    {
        $version = str_starts_with($tag, 'v') ? SemanticVersion::parse(substr($tag, 1)) : null;

        if ($version === null || $version->isDevelopment() || $version->prerelease !== []
            || $version->build !== null || $tag !== 'v'.$version->canonical() || ! self::isUuid($requestId)) {
            throw new HostUpdaterException('helper_request_invalid');
        }

        return $this->request('prepare', ['request_id' => $requestId, 'release_tag' => $tag]);
    }

    /** @return array<string, mixed> */
    public function status(?string $operationId = null): array
    {
        if ($operationId !== null && ! self::isUuid($operationId)) {
            throw new HostUpdaterException('helper_request_invalid');
        }

        return $this->request('status', $operationId === null ? [] : ['operation_id' => $operationId]);
    }

    /** @return array<string, mixed> */
    public function logs(string $operationId, int $cursor = 0, int $limit = 50): array
    {
        if (! self::isUuid($operationId) || $cursor < 0 || $limit < 1 || $limit > 100) {
            throw new HostUpdaterException('helper_request_invalid');
        }

        return $this->request('logs', ['operation_id' => $operationId, 'cursor' => $cursor, 'limit' => $limit]);
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function request(string $action, array $parameters = []): array
    {
        if (config('wayfindr.updates.helper_enabled', false) !== true) {
            throw new HostUpdaterException('helper_disabled');
        }

        try {
            $credentials = $this->credentials();
            $this->validateCredentials($credentials);
            $nonce = bin2hex(random_bytes(16));
            $payload = base64_encode(json_encode([
                'protocol' => 1,
                'installation_id' => $credentials['installation_id'],
                'nonce' => $nonce,
                'issued_at' => time(),
                'action' => $action,
            ] + $parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $request = json_encode([
                'payload' => $payload,
                'mac' => hash_hmac('sha256', "wayfindr-updater-v1:request\n".$payload, $credentials['token']),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

            if (strlen($request) > self::REQUEST_LIMIT) {
                throw new HostUpdaterException('helper_request_invalid');
            }

            $line = $this->exchange($request);

            $result = $this->verifyResponse($line, $nonce, $credentials, $action);
            $this->validateResult($result, $action, $credentials['installation_id'], $parameters);

            return $result;
        } catch (HostUpdaterException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new HostUpdaterException('helper_unavailable');
        }
    }

    /**
     * Protected seams let tests provide a transport and credential fixture.
     * Production always validates root-owned paths; no config bypass exists.
     *
     * @return array<string, mixed>
     */
    protected function credentials(): array
    {
        $path = config('wayfindr.updates.helper_credentials');
        $before = $this->trustedPath($path, 'credential');
        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            throw new HostUpdaterException('helper_credentials_unavailable');
        }

        try {
            $opened = fstat($stream);

            if ($opened === false || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']) {
                throw new HostUpdaterException('helper_credentials_untrusted');
            }

            $body = stream_get_contents($stream, 4097);

            if ($body === false || strlen($body) > 4096) {
                throw new HostUpdaterException('helper_credentials_invalid');
            }

            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            throw new HostUpdaterException('helper_credentials_invalid');
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, mixed> $credentials */
    private function validateCredentials(#[SensitiveParameter] array $credentials): void
    {
        $keys = array_keys($credentials);
        sort($keys);

        if ($keys !== ['installation_id', 'schema', 'token'] || $credentials['schema'] !== 1
            || ! self::isUuid($credentials['installation_id'])
            || ! is_string($credentials['token']) || preg_match('/\A[0-9a-f]{64}\z/', $credentials['token']) !== 1) {
            throw new HostUpdaterException('helper_credentials_invalid');
        }
    }

    /** @return array<string|int, int> */
    protected function trustedPath(mixed $path, string $kind): array
    {
        $reason = $kind === 'socket' ? 'helper_socket_untrusted' : 'helper_credentials_untrusted';

        if (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, "\0")
            || str_contains($path, '//') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path) === 1) {
            throw new HostUpdaterException($reason);
        }

        $parts = explode('/', ltrim($path, '/'));
        $current = '';

        foreach ($parts as $index => $part) {
            $current .= '/'.$part;
            clearstatcache(true, $current);
            $stat = @lstat($current);
            $last = $index === count($parts) - 1;

            if ($stat === false || $stat['uid'] !== 0 || ($stat['mode'] & 0170000) === 0120000
                || (($stat['mode'] & 0022) !== 0 && ! ($last && $kind === 'socket'))) {
                throw new HostUpdaterException($reason);
            }

            $expectedType = $last ? ($kind === 'socket' ? 0140000 : 0100000) : 0040000;

            if (($stat['mode'] & 0170000) !== $expectedType
                || ($last && $kind === 'socket' && (($stat['mode'] & 0777) !== 0660 || $stat['gid'] !== 1000))
                || ($last && $kind !== 'socket' && ($stat['mode'] & 0007) !== 0)) {
                throw new HostUpdaterException($reason);
            }
        }

        return $stat;
    }

    protected function exchange(#[SensitiveParameter] string $request): string
    {
        $path = config('wayfindr.updates.helper_socket');
        $this->trustedPath($path, 'socket');
        $stream = @stream_socket_client('unix://'.$path, $errno, $error, self::TIMEOUT_SECONDS);

        if ($stream === false) {
            throw new HostUpdaterException('helper_unavailable');
        }

        stream_set_blocking($stream, false);
        $deadline = hrtime(true) / 1_000_000_000 + self::TIMEOUT_SECONDS;

        try {
            $offset = 0;

            while ($offset < strlen($request)) {
                $this->waitForStream($stream, $deadline, true);
                $written = @fwrite($stream, substr($request, $offset));

                if ($written === false || $written === 0) {
                    throw new HostUpdaterException('helper_unavailable');
                }

                $offset += $written;
            }

            $line = '';

            while (! str_contains($line, "\n")) {
                $this->waitForStream($stream, $deadline, false);
                $chunk = @fread($stream, 8192);

                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    throw new HostUpdaterException('helper_response_invalid');
                }

                $line .= $chunk;

                if (strlen($line) > self::RESPONSE_LIMIT) {
                    throw new HostUpdaterException('helper_response_too_large');
                }
            }

            return $line;
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function waitForStream($stream, float $deadline, bool $write): void
    {
        $remaining = $deadline - hrtime(true) / 1_000_000_000;

        if ($remaining <= 0) {
            throw new HostUpdaterException('helper_timeout');
        }

        $read = $write ? [] : [$stream];
        $writes = $write ? [$stream] : [];
        $except = [];
        $seconds = (int) $remaining;
        $ready = @stream_select($read, $writes, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000));

        if ($ready === 0) {
            throw new HostUpdaterException('helper_timeout');
        }

        if ($ready === false) {
            throw new HostUpdaterException('helper_unavailable');
        }
    }

    /** @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    private function verifyResponse(#[SensitiveParameter] string $line, string $nonce, #[SensitiveParameter] array $credentials, string $action): array
    {
        if (strlen($line) > self::RESPONSE_LIMIT) {
            throw new HostUpdaterException('helper_response_too_large');
        }

        if (! str_ends_with($line, "\n") || substr_count($line, "\n") !== 1) {
            throw new HostUpdaterException('helper_response_invalid');
        }

        try {
            $envelope = json_decode(substr($line, 0, -1), true, flags: JSON_THROW_ON_ERROR);
            $keys = is_array($envelope) ? array_keys($envelope) : [];
            sort($keys);

            if ($keys !== ['mac', 'payload'] || ! is_string($envelope['payload']) || ! is_string($envelope['mac'])
                || preg_match('/\A[0-9a-f]{64}\z/', $envelope['mac']) !== 1
                || ! hash_equals(hash_hmac('sha256', "wayfindr-updater-v1:response\n".$envelope['payload'], $credentials['token']), $envelope['mac'])) {
                throw new HostUpdaterException('helper_authentication_failed');
            }

            $bytes = base64_decode($envelope['payload'], true);

            if ($bytes === false || base64_encode($bytes) !== $envelope['payload']) {
                throw new HostUpdaterException('helper_response_invalid');
            }

            $response = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            $keys = is_array($response) ? array_keys($response) : [];
            sort($keys);
            $expected = ($response['ok'] ?? null) === true
                ? ['installation_id', 'nonce', 'ok', 'protocol', 'result']
                : ['error', 'installation_id', 'nonce', 'ok', 'protocol'];

            if ($keys !== $expected || ($response['protocol'] ?? null) !== 1
                || ($response['installation_id'] ?? null) !== $credentials['installation_id']
                || ($response['nonce'] ?? null) !== $nonce || ! is_bool($response['ok'] ?? null)) {
                throw new HostUpdaterException('helper_response_invalid');
            }

            if (! $response['ok']) {
                $error = $response['error'];

                if (! is_string($error) || ! in_array($error, self::ERRORS, true)) {
                    throw new HostUpdaterException('helper_response_invalid');
                }

                throw new HostUpdaterException('helper_refused:'.$error);
            }

            if (! is_array($response['result']) || ($response['result'] !== [] && array_is_list($response['result']))) {
                throw new HostUpdaterException('helper_response_invalid');
            }

            if ($action === 'capabilities' && ($response['result']['installation_id'] ?? null) !== $credentials['installation_id']) {
                throw new HostUpdaterException('helper_response_invalid');
            }

            return $response['result'];
        } catch (JsonException) {
            throw new HostUpdaterException('helper_response_invalid');
        }
    }

    private static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::UUID, $value) === 1;
    }

    /** @param array<string, mixed> $result
     * @param  array<string, mixed>  $parameters
     */
    private function validateResult(array $result, string $action, string $installationId, array $parameters): void
    {
        if ($action === 'capabilities') {
            $this->exactKeys($result, ['ownership', 'installation_id', 'enrolled', 'platform', 'architecture', 'image_reference', 'helper', 'managed_policy']);
            $helper = $result['helper'];
            $policy = $result['managed_policy'];

            if (! is_array($helper) || ! is_array($policy) || ($result['installation_id'] ?? null) !== $installationId) {
                throw new HostUpdaterException('helper_response_invalid');
            }

            $this->exactKeys($helper, ['protocol', 'version', 'capabilities']);

            foreach ($policy as $key => $value) {
                if (! in_array($key, ['require_remote_backup', 'require_restore_proof'], true) || ! is_bool($value)) {
                    throw new HostUpdaterException('helper_response_invalid');
                }
            }

            return;
        }

        if ($action === 'logs') {
            $this->exactKeys($result, ['operation_id', 'events', 'next_cursor', 'has_more']);

            if ($result['operation_id'] !== ($parameters['operation_id'] ?? null)
                || ! self::nonnegativeInteger($result['next_cursor']) || ! is_bool($result['has_more'])
                || ! is_array($result['events']) || ! array_is_list($result['events'])
                || count($result['events']) > ($parameters['limit'] ?? 0)) {
                throw new HostUpdaterException('helper_response_invalid');
            }

            $this->validateEvents($result['events']);

            $previous = $parameters['cursor'];

            foreach ($result['events'] as $event) {
                if ($event['revision'] <= $previous) {
                    throw new HostUpdaterException('helper_response_invalid');
                }

                $previous = $event['revision'];
            }

            if ($result['next_cursor'] !== $previous) {
                throw new HostUpdaterException('helper_response_invalid');
            }

            return;
        }

        $this->exactKeys($result, ['schema', 'installation_id', 'revision', 'helper_version', 'generation', 'heartbeat_at', 'active_operation', 'operation']);

        if ($result['schema'] !== 1 || $result['installation_id'] !== $installationId
            || ! self::nonnegativeInteger($result['revision']) || ! self::nonnegativeInteger($result['heartbeat_at'])
            || ! self::version($result['helper_version'])
            || ! self::nullableUuid($result['generation']) || ! self::nullableUuid($result['active_operation'])) {
            throw new HostUpdaterException('helper_response_invalid');
        }

        if ($result['operation'] !== null) {
            if (! is_array($result['operation'])) {
                throw new HostUpdaterException('helper_response_invalid');
            }

            $this->validateOperation($result['operation']);

            if (isset($parameters['operation_id']) && $result['operation']['operation_id'] !== $parameters['operation_id']) {
                throw new HostUpdaterException('helper_response_invalid');
            }

            if ($action === 'prepare' && ($result['operation']['request_id'] !== $parameters['request_id']
                || $result['operation']['release_tag'] !== $parameters['release_tag'])) {
                throw new HostUpdaterException('helper_response_invalid');
            }
        } elseif (isset($parameters['operation_id']) || $action === 'prepare') {
            throw new HostUpdaterException('helper_response_invalid');
        }
    }

    /** @param array<string, mixed> $operation */
    private function validateOperation(array $operation): void
    {
        $this->exactKeys($operation, ['operation_id', 'request_id', 'release_tag', 'phase', 'checkpoint', 'executor_generation', 'executor_version', 'mutation_started', 'created_at', 'updated_at', 'revision', 'error', 'source', 'target', 'plan_id', 'events']);

        if (! self::isUuid($operation['operation_id']) || ! self::isUuid($operation['request_id'])
            || ! self::isUuid($operation['executor_generation']) || ! self::version($operation['executor_version'])
            || ! self::stableTag($operation['release_tag']) || ! in_array($operation['phase'], self::PHASES, true)
            || ! in_array($operation['checkpoint'], ['accepted', 'prepare_started', 'plan_reported'], true)
            || $operation['mutation_started'] !== false || ! self::nonnegativeInteger($operation['created_at'])
            || ! self::nonnegativeInteger($operation['updated_at']) || ! self::nonnegativeInteger($operation['revision'])
            || ($operation['error'] !== null && ! in_array($operation['error'], self::ERRORS, true))
            || ($operation['plan_id'] !== null && ! self::hex($operation['plan_id'], 64))
            || ! is_array($operation['events']) || ! array_is_list($operation['events'])) {
            throw new HostUpdaterException('helper_response_invalid');
        }

        $this->validateEvents($operation['events']);

        if ($operation['source'] !== null) {
            if (! is_array($operation['source'])) {
                throw new HostUpdaterException('helper_response_invalid');
            }

            $this->exactKeys($operation['source'], ['version', 'commit']);

            if (! self::version($operation['source']['version']) || ! self::hex($operation['source']['commit'], 40)) {
                throw new HostUpdaterException('helper_response_invalid');
            }
        }

        if ($operation['target'] !== null) {
            if (! is_array($operation['target'])) {
                throw new HostUpdaterException('helper_response_invalid');
            }

            $target = $operation['target'];
            $this->exactKeys($target, ['tag', 'version', 'commit', 'image_digest']);

            if (! self::stableTag($target['tag']) || $target['tag'] !== $operation['release_tag'] || ! self::version($target['version'])
                || $target['tag'] !== 'v'.SemanticVersion::parse($target['version'])->canonical()
                || ! self::hex($target['commit'], 40) || ! is_string($target['image_digest'])
                || preg_match('/\Asha256:[0-9a-f]{64}\z/', $target['image_digest']) !== 1) {
                throw new HostUpdaterException('helper_response_invalid');
            }
        }
    }

    /** @param list<mixed> $events */
    private function validateEvents(array $events): void
    {
        foreach ($events as $event) {
            if (! is_array($event)) {
                throw new HostUpdaterException('helper_response_invalid');
            }

            $this->exactKeys($event, ['revision', 'at', 'code', 'phase']);

            if (! self::nonnegativeInteger($event['revision']) || ! self::nonnegativeInteger($event['at'])
                || ! in_array($event['code'], self::EVENTS, true) || ! in_array($event['phase'], self::PHASES, true)) {
                throw new HostUpdaterException('helper_response_invalid');
            }
        }
    }

    /** @param array<string, mixed> $value
     * @param  list<string>  $expected
     */
    private function exactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        if ($keys !== $expected) {
            throw new HostUpdaterException('helper_response_invalid');
        }
    }

    private static function nullableUuid(mixed $value): bool
    {
        return $value === null || self::isUuid($value);
    }

    private static function nonnegativeInteger(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private static function hex(mixed $value, int $length): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{'.$length.'}\z/', $value) === 1;
    }

    private static function version(mixed $value): bool
    {
        return is_string($value) && SemanticVersion::parse($value) !== null;
    }

    private static function stableTag(mixed $value): bool
    {
        if (! is_string($value) || ! str_starts_with($value, 'v')) {
            return false;
        }

        $version = SemanticVersion::parse($value);

        return $version !== null && $version->prerelease === [] && $version->build === null && $value === 'v'.$version->canonical();
    }
}
