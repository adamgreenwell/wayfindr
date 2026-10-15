<?php

namespace App\Actions;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\Visitors\VisitorIdentityVerification;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BootstrapOwner
{
    private const FAILURE_CODES = [
        'owner_bootstrap_invalid_request', 'owner_bootstrap_configuration_refused',
        'owner_bootstrap_claim_file_refused', 'owner_bootstrap_existing_installation',
        'owner_bootstrap_request_conflict', 'owner_bootstrap_replay_refused',
        'owner_bootstrap_not_completed', 'owner_bootstrap_outcome_unknown',
    ];

    public static function failureCode(Throwable $exception): string
    {
        return $exception instanceof RuntimeException && in_array($exception->getMessage(), self::FAILURE_CODES, true)
            ? $exception->getMessage() : 'owner_bootstrap_outcome_unknown';
    }

    /** @return array<string, mixed> */
    public function handle(array $input): array
    {
        $request = $this->normalize($input);
        $connection = DB::connection();

        if ($connection->transactionLevel() !== 0) {
            $this->refuse('owner_bootstrap_configuration_refused');
        }

        [$broker, $brokerConfig, $origin] = $this->configuration();
        $parent = $this->privateParent($request['claim_file']);
        $requestHash = hash('sha256', $this->json([
            'version' => 1, 'request' => $request, 'origin' => $origin,
            'broker' => config('auth.defaults.passwords'), 'broker_configuration' => $brokerConfig,
        ]));
        $handle = null;
        $created = false;

        try {
            $receipt = $connection->transaction(function () use ($connection, $request, $requestHash, $broker, $brokerConfig, $origin, $parent, &$handle, &$created): array {
                // Unique singleton + insert-on-conflict + row lock serializes
                // competing initial requests even before an account exists.
                $connection->table('owner_bootstrap_requests')->insertOrIgnore([
                    'id' => 1, 'request_id' => $request['request_id'], 'request_hash' => $requestHash,
                    'status' => 'reserved', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = $connection->table('owner_bootstrap_requests')->where('id', 1)->lockForUpdate()->first();

                if ($row === null || $row->request_id !== $request['request_id'] || $row->request_hash !== $requestHash) {
                    $this->refuse('owner_bootstrap_request_conflict');
                }

                if ($row->status === 'completed') {
                    $receipt = $this->storedReceipt($row);
                    $handle = $this->openClaim($request['claim_file'], false, $created);
                    $bytes = $this->checkFile($request['claim_file'], $handle, $parent, $row->claim_file_sha256);
                    $this->checkClaim($bytes, $receipt, $request, $origin);

                    return $receipt;
                }

                if ($row->status !== 'reserved' || Account::query()->exists() || User::query()->exists() || Site::query()->exists()) {
                    $this->refuse('owner_bootstrap_existing_installation');
                }

                $handle = $this->openClaim($request['claim_file'], true, $created);
                $this->checkFile($request['claim_file'], $handle, $parent, hash('sha256', ''));

                $account = Account::query()->create(['name' => $request['account'], 'slug' => $request['slug']]);
                $owner = User::query()->create([
                    'account_id' => $account->id, 'account_role' => AccountRole::Owner,
                    'platform_role' => null, 'name' => $request['name'], 'email' => $request['email'],
                    'password' => Hash::make(Str::password(64)),
                ]);
                $site = Site::query()->create([
                    'account_id' => $account->id, 'name' => $request['site'], 'domain' => $request['domain'],
                    'public_key' => 'site_'.Str::lower(Str::random(32)),
                    'settings' => ['mask_selectors' => ['input[type="password"]', '[data-wayfindr-mask]']],
                    ...VisitorIdentityVerification::newSiteDefaults(),
                ]);
                $site->supportAgents()->attach($owner->id);

                $token = $broker->createToken($owner);
                $tokenRow = $connection->table($brokerConfig['table'])->where('email', $owner->email)->first();

                if ($tokenRow === null || ! $broker->tokenExists($owner, $token)) {
                    $this->refuse('owner_bootstrap_outcome_unknown');
                }

                $expires = CarbonImmutable::parse($tokenRow->created_at)->addMinutes($brokerConfig['expire'])->utc()->toIso8601String();
                // Route shape comes from the existing password-reset route;
                // the explicitly admitted app origin is never the CLI host.
                $path = route('password.reset', ['token' => $token], false);
                if ($path !== '/reset-password/'.$token) {
                    $this->refuse('owner_bootstrap_configuration_refused');
                }
                $url = $origin.$path.'?'.http_build_query(['email' => $owner->email], '', '&', PHP_QUERY_RFC3986);
                $receipt = [
                    'version' => 1, 'status' => 'completed', 'request_id' => $request['request_id'],
                    'request_sha256' => $requestHash, 'account_id' => (int) $account->id,
                    'owner_id' => (int) $owner->id, 'site_id' => (int) $site->id,
                    'platform_operator_granted' => false, 'claim_issued' => true,
                    'claim_expires_at' => $expires, 'claim_consumption' => 'not_observed',
                ];
                $claim = $this->json(['version' => 1, 'receipt' => $receipt, 'password_reset_url' => $url])."\n";
                $this->writeClaim($handle, $claim);
                $digest = hash('sha256', $claim);
                $this->checkFile($request['claim_file'], $handle, $parent, $digest);
                $connection->table('owner_bootstrap_requests')->where('id', 1)->update([
                    'status' => 'completed', 'account_id' => $account->id, 'user_id' => $owner->id,
                    'site_id' => $site->id, 'claim_file_sha256' => $digest,
                    'receipt' => $this->json($receipt), 'updated_at' => now(),
                ]);

                return $receipt;
            }, 1); // Never automatically retry an attempt that wrote a claim.

            $row = $connection->table('owner_bootstrap_requests')->where('id', 1)->first();
            if ($row === null || $this->storedReceipt($row) !== $receipt) {
                $this->refuse('owner_bootstrap_outcome_unknown');
            }
            $this->checkFile($request['claim_file'], $handle, $parent, $row->claim_file_sha256);

            return $receipt;
        } catch (Throwable $exception) {
            // A private file may outlive a failed/ambiguous commit. Preserve it
            // for operator reconciliation; never delete or rotate on a retry.
            if ($created) {
                throw new RuntimeException('owner_bootstrap_outcome_unknown');
            }

            throw $exception;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /** Read-only. No token-store or claim-file access and no inference of consumption. */
    public function status(string $requestId): array
    {
        $this->requestId($requestId);
        $row = DB::table('owner_bootstrap_requests')->where('id', 1)->first();
        if ($row === null || $row->status !== 'completed' || $row->request_id !== $requestId) {
            $this->refuse('owner_bootstrap_not_completed');
        }
        $receipt = $this->storedReceipt($row);
        $owner = User::query()->find($receipt['owner_id']);
        $site = Site::query()->find($receipt['site_id']);
        $binding = $owner !== null && $site !== null
            && Account::query()->whereKey($receipt['account_id'])->exists()
            && (int) $owner->account_id === $receipt['account_id']
            && (int) $site->account_id === $receipt['account_id']
            && $owner->isOwner() && $owner->platform_role === null && ! $owner->isDeactivated()
            && $site->supportAgents()->whereKey($owner->id)->exists();

        return [
            'version' => 1, 'status' => 'completed', 'request_id' => $requestId,
            'bootstrap_receipt' => $receipt, 'initial_binding_ready' => $binding,
            'current_initial_owner_platform_operator' => $owner === null ? null : $owner->platform_role !== null,
            'claim_consumption' => 'not_observed', 'management_authority' => false,
        ];
    }

    private function normalize(array $input): array
    {
        $expected = ['request_id', 'email', 'claim_file', 'account', 'slug', 'name', 'site', 'domain'];
        if (array_keys($input) !== $expected) {
            $this->refuse('owner_bootstrap_invalid_request');
        }
        foreach ($input as $value) {
            if ($value !== null && ! is_string($value)) {
                $this->refuse('owner_bootstrap_invalid_request');
            }
        }
        $input = array_map(fn ($value) => trim($value ?? ''), $input);
        $this->requestId($input['request_id']);
        $input['email'] = mb_strtolower($input['email']);
        $input['slug'] = $input['slug'] ?: Str::slug($input['account']);
        foreach (['account', 'slug', 'name', 'site', 'email'] as $key) {
            if ($input[$key] === '' || mb_strlen($input[$key]) > 255 || preg_match('/[\x00-\x1f\x7f]/', $input[$key])) {
                $this->refuse('owner_bootstrap_invalid_request');
            }
        }
        if (! filter_var($input['email'], FILTER_VALIDATE_EMAIL) || ! preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $input['slug'])
            || strlen($input['claim_file']) > 4096 || mb_strlen($input['domain']) > 255 || preg_match('/[\x00-\x1f\x7f]/', $input['domain'])) {
            $this->refuse('owner_bootstrap_invalid_request');
        }
        $input['domain'] = $input['domain'] === '' ? null : $input['domain'];

        return $input;
    }

    private function requestId(string $id): void
    {
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $id)) {
            $this->refuse('owner_bootstrap_invalid_request');
        }
    }

    private function configuration(): array
    {
        $name = config('auth.defaults.passwords');
        $config = is_string($name) ? config('auth.passwords.'.$name) : null;
        $provider = is_array($config) ? config('auth.providers.'.($config['provider'] ?? '')) : null;
        $origin = config('app.url');
        $parts = is_string($origin) ? parse_url($origin) : false;
        if (! is_array($config) || ($config['driver'] ?? 'database') !== 'database'
            || ! is_int($config['expire'] ?? null) || $config['expire'] < 1 || $config['expire'] > 60
            || ! is_string($config['table'] ?? null) || ! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $config['table'])
            || ! is_array($provider) || ($provider['driver'] ?? null) !== 'eloquent' || ($provider['model'] ?? null) !== User::class
            || DB::connection($config['connection'] ?? null) !== DB::connection()
            || (new User)->getConnection() !== DB::connection() || (new Account)->getConnection() !== DB::connection() || (new Site)->getConnection() !== DB::connection()
            || ! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []
            || ($parts['scheme'] !== 'https' && ! ($parts['scheme'] === 'http' && app()->environment('local', 'testing') && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)))) {
            $this->refuse('owner_bootstrap_configuration_refused');
        }
        $canonical = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if ($canonical !== $origin) {
            $this->refuse('owner_bootstrap_configuration_refused');
        }
        // A fresh manager binds this admitted configuration, rather than an
        // earlier broker cached before a configuration change in this process.
        $broker = (new PasswordBrokerManager(app()))->broker($name);
        if (! $broker->getRepository() instanceof DatabaseTokenRepository || $broker->getRepository()->getConnection() !== DB::connection()) {
            $this->refuse('owner_bootstrap_configuration_refused');
        }

        return [$broker, $config, $origin];
    }

    private function privateParent(string $path): array
    {
        if (! function_exists('posix_geteuid') || ! function_exists('fsync') || ! str_starts_with($path, '/')
            || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\z/D', basename($path))) {
            $this->refuse('owner_bootstrap_claim_file_refused');
        }
        $parent = dirname($path);
        clearstatcache(true);
        $stat = @lstat($parent);
        if (realpath($parent) !== $parent || ! is_array($stat) || ($stat['mode'] & 0177777) !== 0040700 || $stat['uid'] !== posix_geteuid()) {
            $this->refuse('owner_bootstrap_claim_file_refused');
        }

        return $this->identity($stat);
    }

    private function openClaim(string $path, bool $create, bool &$created)
    {
        if (! $create) {
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (! is_array($stat) || ($stat['mode'] & 0177777) !== 0100600 || $stat['uid'] !== posix_geteuid() || $stat['nlink'] !== 1) {
                $this->refuse('owner_bootstrap_replay_refused');
            }
        }
        $mask = umask(0077);
        try {
            $handle = @fopen($path, $create ? 'x+b' : 'rb');
        } finally {
            umask($mask);
        }
        // Record exclusive creation before the next fallible operation. A
        // failed lock must still preserve and identify the partial file.
        if ($create && is_resource($handle)) {
            $created = true;
        }
        if (! is_resource($handle) || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $this->refuse($create ? 'owner_bootstrap_claim_file_refused' : 'owner_bootstrap_replay_refused');
        }

        return $handle;
    }

    private function identity(array $stat): array
    {
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid', 'nlink']));
    }

    private function stableFile(array $stat): array
    {
        // Reading can update atime. Identity, content size and write/change
        // times must remain stable; access time is not a mutation signal.
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime']));
    }

    private function checkFile(string $path, $handle, array $parent, string $expectedHash): string
    {
        clearstatcache(true);
        $fd = is_resource($handle) ? fstat($handle) : false;
        $disk = @lstat($path);
        if (! is_array($fd) || ! is_array($disk) || $this->privateParent($path) !== $parent
            || $this->identity($fd) !== $this->identity($disk) || ($fd['mode'] & 0177777) !== 0100600
            || $fd['uid'] !== posix_geteuid() || $fd['nlink'] !== 1 || $fd['size'] > 8192 || $fd['size'] < 0
            || ! rewind($handle)) {
            $this->refuse('owner_bootstrap_replay_refused');
        }
        $bytes = stream_get_contents($handle, 8193);
        clearstatcache(true, $path);
        $afterFd = fstat($handle);
        $afterDisk = @lstat($path);
        if (! is_string($bytes) || strlen($bytes) !== $fd['size'] || ! hash_equals($expectedHash, hash('sha256', $bytes))
            || ! is_array($afterFd) || ! is_array($afterDisk)
            || $this->stableFile($afterFd) !== $this->stableFile($fd) || $this->stableFile($afterDisk) !== $this->stableFile($disk)
            || $this->privateParent($path) !== $parent) {
            $this->refuse('owner_bootstrap_replay_refused');
        }

        return $bytes;
    }

    private function checkClaim(#[\SensitiveParameter] string $bytes, array $receipt, array $request, string $origin): void
    {
        $claim = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($claim) || array_keys($claim) !== ['version', 'receipt', 'password_reset_url']
            || $claim['version'] !== 1 || $claim['receipt'] !== $receipt || ! is_string($claim['password_reset_url'])
            || $this->json($claim)."\n" !== $bytes) {
            $this->refuse('owner_bootstrap_replay_refused');
        }
        $token = substr($claim['password_reset_url'], strlen($origin.'/reset-password/'), 64);
        if (! preg_match('/\A[0-9a-f]{64}\z/D', $token)
            || $claim['password_reset_url'] !== $origin.'/reset-password/'.$token.'?'.http_build_query(['email' => $request['email']], '', '&', PHP_QUERY_RFC3986)) {
            $this->refuse('owner_bootstrap_replay_refused');
        }
    }

    private function writeClaim($handle, #[\SensitiveParameter] string $bytes): void
    {
        if (strlen($bytes) > 8192) {
            $this->refuse('owner_bootstrap_outcome_unknown');
        }
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                $this->refuse('owner_bootstrap_outcome_unknown');
            }
            $offset += $written;
        }
        if (! fflush($handle) || ! fsync($handle)) {
            $this->refuse('owner_bootstrap_outcome_unknown');
        }
    }

    private function storedReceipt(object $row): array
    {
        $receipt = is_string($row->receipt) && strlen($row->receipt) <= 8192 ? json_decode($row->receipt, true, 32, JSON_THROW_ON_ERROR) : null;
        $keys = ['version', 'status', 'request_id', 'request_sha256', 'account_id', 'owner_id', 'site_id', 'platform_operator_granted', 'claim_issued', 'claim_expires_at', 'claim_consumption'];
        if ($row->status !== 'completed' || ! is_array($receipt) || array_keys($receipt) !== $keys
            || $receipt['version'] !== 1 || $receipt['status'] !== 'completed' || $receipt['request_id'] !== $row->request_id
            || $receipt['request_sha256'] !== $row->request_hash || ! preg_match('/\A[0-9a-f]{64}\z/D', $row->request_hash)
            || ! preg_match('/\A[0-9a-f]{64}\z/D', $row->claim_file_sha256 ?? '')
            || $receipt['platform_operator_granted'] !== false || $receipt['claim_issued'] !== true || $receipt['claim_consumption'] !== 'not_observed'
            || ! is_string($receipt['claim_expires_at']) || ! preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00\z/D', $receipt['claim_expires_at'])) {
            $this->refuse('owner_bootstrap_replay_refused');
        }
        foreach (['account_id' => 'account_id', 'owner_id' => 'user_id', 'site_id' => 'site_id'] as $key => $column) {
            if (! is_int($receipt[$key]) || $receipt[$key] < 1 || $receipt[$key] !== (int) $row->{$column}) {
                $this->refuse('owner_bootstrap_replay_refused');
            }
        }
        $this->requestId($receipt['request_id']);
        if (CarbonImmutable::parse($receipt['claim_expires_at'])->utc()->toIso8601String() !== $receipt['claim_expires_at']) {
            $this->refuse('owner_bootstrap_replay_refused');
        }

        return $receipt;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function refuse(string $code): never
    {
        throw new RuntimeException($code);
    }
}
