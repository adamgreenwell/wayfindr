<?php

namespace App\Support\Settings;

use App\Models\OperatorSetting;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Operator-set instance configuration that overrides env (ADR 0011).
 *
 * A single registry maps each managed setting key to the Laravel config path it
 * overrides, whether it is a secret (encrypted at rest, never echoed to the
 * browser), and the group (config surface) it belongs to. At boot the provider
 * calls applyOverrides() so every existing config()/Mail/Storage call sees the
 * operator's values with no code changes.
 *
 * Only registered keys can be read or written — the GUI can never override an
 * arbitrary config path. Boot-critical config (APP_KEY, database, Redis, Reverb
 * transport) is deliberately NOT managed here; it stays env-only.
 */
class OperatorSettings
{
    private const CACHE_KEY = 'wayfindr.operator_settings';

    private const VERSION_KEY = 'wayfindr.operator_settings.version';

    /**
     * The operator-managed settings. Each entry maps a setting key to the config
     * path it overrides, whether it is an encrypted secret, its group, and an
     * optional cast ('bool') for config values that are not plain strings.
     *
     * @var array<string, array{config: string, secret: bool, group: string, cast?: string}>
     */
    private const MANAGED = [
        // Mail (ADR 0011 slice 1). Setting mail.mailer to 'smtp' activates the
        // SMTP transport whose host/port/credentials the operator supplies.
        'mail.mailer' => ['config' => 'mail.default', 'secret' => false, 'group' => 'mail'],
        'mail.scheme' => ['config' => 'mail.mailers.smtp.scheme', 'secret' => false, 'group' => 'mail'],
        'mail.host' => ['config' => 'mail.mailers.smtp.host', 'secret' => false, 'group' => 'mail'],
        'mail.port' => ['config' => 'mail.mailers.smtp.port', 'secret' => false, 'group' => 'mail'],
        'mail.username' => ['config' => 'mail.mailers.smtp.username', 'secret' => false, 'group' => 'mail'],
        'mail.password' => ['config' => 'mail.mailers.smtp.password', 'secret' => true, 'group' => 'mail'],
        'mail.from_address' => ['config' => 'mail.from.address', 'secret' => false, 'group' => 'mail'],
        'mail.from_name' => ['config' => 'mail.from.name', 'secret' => false, 'group' => 'mail'],

        // Attachment storage (ADR 0011 slice 2a). Which disk NEW uploads land on
        // ('attachments' local, or 'attachments-s3'), and the S3-compatible
        // connection used when the S3 disk is chosen. Keys/secret are encrypted.
        'storage.disk' => ['config' => 'wayfindr.attachments.storage_disk', 'secret' => false, 'group' => 'storage'],
        'storage.s3_bucket' => ['config' => 'filesystems.disks.attachments-s3.bucket', 'secret' => false, 'group' => 'storage'],
        'storage.s3_region' => ['config' => 'filesystems.disks.attachments-s3.region', 'secret' => false, 'group' => 'storage'],
        // A blank endpoint must apply as null (AWS regional resolution), not '',
        // which the AWS SDK treats as a custom endpoint — but the stored blank
        // override still wins over any stale env endpoint.
        'storage.s3_endpoint' => ['config' => 'filesystems.disks.attachments-s3.endpoint', 'secret' => false, 'group' => 'storage', 'cast' => 'null_if_blank'],
        'storage.s3_key' => ['config' => 'filesystems.disks.attachments-s3.key', 'secret' => true, 'group' => 'storage'],
        'storage.s3_secret' => ['config' => 'filesystems.disks.attachments-s3.secret', 'secret' => true, 'group' => 'storage'],
        'storage.s3_use_path_style' => ['config' => 'filesystems.disks.attachments-s3.use_path_style_endpoint', 'secret' => false, 'group' => 'storage', 'cast' => 'bool'],
        // The canned ACL sent on every write. AWS wants bucket-owner-full-control
        // (the default); Cloudflare R2 and some compatible stores reject it and
        // need 'private'. Only private ACLs are offered/accepted (see the
        // controller) — attachments must never be publicly readable.
        'storage.s3_acl' => ['config' => 'filesystems.disks.attachments-s3.options.ACL', 'secret' => false, 'group' => 'storage'],
    ];

    /**
     * Config paths the operator never sets directly, but which must be kept
     * coherent with specific overrides — nulled when any of the listed keys is
     * operator-set, restored to the env baseline when none are.
     *
     * mail.smtp.url: when env has MAIL_URL set, Laravel derives the SMTP host,
     * port, and credentials FROM the url and ignores the individual fields. So
     * the url must be dropped only when a CONNECTION field is overridden — not
     * when the operator merely sets an unrelated field like the sender name,
     * which would otherwise strand the url and fall back to empty host/port.
     *
     * @var array<string, list<string>> config path => trigger keys that, when set, null it
     */
    private const DERIVED = [
        'mail.mailers.smtp.url' => ['mail.host', 'mail.port', 'mail.username', 'mail.password', 'mail.scheme'],
    ];

    /**
     * The env/config defaults for the managed paths, snapshotted once before any
     * override is applied, so a CLEARED override can be restored rather than
     * left stale on a long-running worker.
     *
     * @var array<string, mixed>|null
     */
    private ?array $baseline = null;

    /**
     * Apply the stored settings onto config for every managed key. Called at
     * boot (per web request) and before each queued job, so a change made in the
     * browser is live without a restart. Every managed path is set to the
     * operator's value if present, else the env baseline — a key whose override
     * was just cleared reverts to env on the next request/job rather than
     * keeping the old value on a persistent worker.
     */
    public function applyOverrides(): void
    {
        if ($this->baseline === null) {
            $this->captureBaseline();
        }

        // Resolve the FULL target map before mutating config: a read failure or a
        // corrupt secret falls back to the env baseline (per key, or wholesale if
        // the store is unreadable) rather than leaving config half-applied or
        // holding a stale override on a persistent worker.
        foreach ($this->resolveTargets() as $configPath => $value) {
            config()->set($configPath, $value);
        }

        $this->refreshManagers();
    }

    /**
     * The config value to apply for every managed path — the operator's value
     * when readable, else the env baseline. Never throws and never returns a
     * partial map, so applyOverrides always lands a consistent state.
     *
     * @return array<string, mixed>
     */
    private function resolveTargets(): array
    {
        try {
            $stored = $this->storedValues();
        } catch (Throwable) {
            // Store unreachable (DB/cache down, table not migrated): env baseline.
            return $this->baseline;
        }

        return $this->resolveTargetsFrom($stored);
    }

    /**
     * Re-apply overrides onto config from a FRESH, uncached database read. For
     * callers that must see the latest committed settings even within the tiny
     * window between a write's commit and its deferred cache-version bump — the
     * attachment upload path re-resolving its disk under a shared lock. Falls
     * back to the env baseline if the direct read fails.
     *
     * This updates config only; it deliberately does NOT forget built managers
     * (disks/mailers). The caller resolves and builds its disk AFTER this call,
     * so it picks up the refreshed config without a forget — which also keeps a
     * Storage::fake() disk intact in tests.
     */
    public function refreshFromDatabase(): void
    {
        if ($this->baseline === null) {
            $this->captureBaseline();
        }

        try {
            $stored = OperatorSetting::query()->pluck('value', 'key')->all();
            $targets = $this->resolveTargetsFrom($stored);
        } catch (Throwable) {
            $targets = $this->baseline;
        }

        foreach ($targets as $configPath => $value) {
            config()->set($configPath, $value);
        }
    }

    /**
     * The config value to apply for every managed path from a given stored map —
     * the operator's value when readable, else the env baseline. Never throws and
     * never returns a partial map.
     *
     * @param  array<string, string|null>  $stored
     * @return array<string, mixed>
     */
    private function resolveTargetsFrom(array $stored): array
    {
        $targets = [];

        foreach (self::MANAGED as $key => $meta) {
            $baseline = $this->baseline[$meta['config']];

            if (! array_key_exists($key, $stored) || $stored[$key] === null) {
                $targets[$meta['config']] = $baseline;

                continue;
            }

            try {
                $targets[$meta['config']] = $this->castValue($key, $this->decode($key, $stored[$key]));
            } catch (Throwable) {
                // A single corrupt/undecryptable secret reverts only its own key
                // to env; the rest of the override set still applies.
                $targets[$meta['config']] = $baseline;
            }
        }

        // Derived paths: null when any of their trigger keys is operator-set (so
        // a stray env value can't supersede the operator's fields), else the env
        // baseline.
        foreach (self::DERIVED as $path => $triggerKeys) {
            $targets[$path] = $this->anyKeySet($triggerKeys, $stored) ? null : $this->baseline[$path];
        }

        return $targets;
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, string|null>  $stored
     */
    private function anyKeySet(array $keys, array $stored): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $stored) && $stored[$key] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Snapshot the current config as the env baseline for the managed paths.
     * Called automatically before the first override is applied (when config is
     * still env-derived). Exposed so a test can establish a known baseline.
     */
    public function captureBaseline(): void
    {
        $this->baseline = [];

        foreach (self::MANAGED as $meta) {
            $this->baseline[$meta['config']] = config($meta['config']);
        }

        foreach (array_keys(self::DERIVED) as $path) {
            $this->baseline[$path] = config($path);
        }

        $this->foldMailUrlIntoBaseline();
    }

    /**
     * When mail is configured through MAIL_URL, the individual smtp fields are
     * their empty defaults — the real host/port/credentials live in the url. So
     * fold the url's parsed values into the individual-field baselines (using
     * Laravel's own ConfigurationUrlParser), so overriding one connection field
     * and dropping the url leaves the OTHER fields holding the url's real
     * values instead of losing them to the empty defaults.
     */
    private function foldMailUrlIntoBaseline(): void
    {
        $url = $this->baseline['mail.mailers.smtp.url'] ?? null;

        if (! is_string($url) || trim($url) === '') {
            return;
        }

        try {
            $parsed = (new ConfigurationUrlParser)->parseConfiguration(['url' => $url]);
        } catch (Throwable) {
            return; // a malformed MAIL_URL must not break baseline capture
        }

        $map = [
            'host' => 'mail.mailers.smtp.host',
            'port' => 'mail.mailers.smtp.port',
            'username' => 'mail.mailers.smtp.username',
            'password' => 'mail.mailers.smtp.password',
            'driver' => 'mail.mailers.smtp.scheme',
        ];

        foreach ($map as $parsedKey => $configPath) {
            if (array_key_exists($parsedKey, $parsed)) {
                $this->baseline[$configPath] = $parsed[$parsedKey];
            }
        }
    }

    /**
     * The operator's stored value for a key (decrypted for secrets), or null if
     * unset. Do NOT expose a secret's value to the browser — use isSet() there.
     */
    public function get(string $key): ?string
    {
        $this->assertManaged($key);

        $stored = $this->storedValues();

        return array_key_exists($key, $stored) ? $this->decode($key, $stored[$key]) : null;
    }

    /**
     * The effective value: the operator's stored value if set, else the config
     * (env) default. For non-secret display / form pre-fill only.
     */
    public function effective(string $key): mixed
    {
        $this->assertManaged($key);

        return $this->get($key) ?? config(self::MANAGED[$key]['config']);
    }

    /** Whether the operator has stored a value for this key (vs. the env default). */
    public function isSet(string $key): bool
    {
        $this->assertManaged($key);

        $stored = $this->storedValues();

        return array_key_exists($key, $stored) && $stored[$key] !== null;
    }

    public function isSecret(string $key): bool
    {
        $this->assertManaged($key);

        return self::MANAGED[$key]['secret'];
    }

    /**
     * The display status of a stored value WITHOUT exposing a secret's plaintext
     * or letting a decryption failure escape:
     *  - 'none': nothing stored, or stored empty;
     *  - 'set': a non-empty value is stored (and, for a secret, decrypts);
     *  - 'unreadable': a secret is stored but its ciphertext cannot be decrypted
     *    (e.g. after an APP_KEY rotation or row corruption).
     *
     * A status-rendering caller must use this rather than get(): opening the
     * settings form must never 500 on a bad stored secret, or the operator loses
     * the very UI they need to re-enter or clear it. At runtime applyOverrides()
     * already falls back to the env value for an undecryptable secret.
     */
    public function secretStatus(string $key): string
    {
        $this->assertManaged($key);

        if (! $this->isSet($key)) {
            return 'none';
        }

        try {
            return (string) $this->get($key) !== '' ? 'set' : 'none';
        } catch (Throwable) {
            return 'unreadable';
        }
    }

    /**
     * Like secretStatus(), but reflects the EFFECTIVE credential: with no stored
     * override, a non-empty env/baseline value still counts as 'set'. Use this
     * for a write-only status display so an existing environment credential
     * (MAIL_PASSWORD, or a credential-bearing MAIL_URL folded into config by
     * applyOverrides) is not shown as "none" — which could tempt an operator
     * into blanking a working password.
     *
     * An explicit override always wins: a stored value ('set'), a deliberate
     * empty no-password ('none'), or an unreadable ciphertext ('unreadable')
     * is never masked by the env fallback.
     */
    public function effectiveSecretStatus(string $key): string
    {
        $override = $this->secretStatus($key);

        if ($override !== 'none' || $this->isSet($key)) {
            return $override;
        }

        return trim((string) config(self::MANAGED[$key]['config'])) !== '' ? 'set' : 'none';
    }

    /**
     * Store (or clear, on null) a managed setting. Secrets are encrypted at
     * rest. Bumps the cache version so the change is live on the next
     * request/job. Auditing is the caller's job — the controller has the actor
     * context.
     */
    public function set(string $key, ?string $value): void
    {
        $this->assertManaged($key);

        if ($value === null) {
            OperatorSetting::query()->where('key', $key)->delete();
        } else {
            OperatorSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => self::MANAGED[$key]['secret'] ? Crypt::encryptString($value) : $value],
            );
        }

        // Bump the version so the next read misses and refetches. Readers key
        // their cache entry by the version they observed, so a slow reader that
        // fetched the old rows can only ever store under the previous version —
        // never poisoning the new one (which would otherwise stick forever).
        //
        // Deferred to AFTER commit (runs immediately when there is no
        // transaction): if set() is wrapped in a transaction — e.g. to commit
        // the setting and its audit event atomically — bumping before commit
        // would let a concurrent reader cache the pre-commit rows under the new
        // version. add() first because increment() on a missing key returns
        // false without creating it on the database/memcached stores, which
        // would pin the version at 0.
        DB::afterCommit(function (): void {
            Cache::add(self::VERSION_KEY, 0);
            Cache::increment(self::VERSION_KEY);
        });
    }

    /**
     * The managed keys in a group (a config surface), in registry order.
     *
     * @return list<string>
     */
    public function keysForGroup(string $group): array
    {
        return array_keys(array_filter(self::MANAGED, fn (array $meta): bool => $meta['group'] === $group));
    }

    public function isManaged(string $key): bool
    {
        return array_key_exists($key, self::MANAGED);
    }

    /**
     * The raw stored values (encrypted-for-secrets), cached so the table is not
     * read on every request. The cache holds ciphertext for secrets — secrets
     * are only decrypted per read, never cached in the clear.
     *
     * Keyed by the version observed at read time: a write bumps the version, so
     * the next read misses and refetches (instant propagation), and a slow
     * reader can only store under the version it saw — never poison the current
     * one. A modest TTL lets orphaned version entries fall out.
     *
     * @return array<string, string|null>
     */
    private function storedValues(): array
    {
        $version = (int) Cache::get(self::VERSION_KEY, 0);

        return Cache::remember(
            self::CACHE_KEY.':'.$version,
            now()->addDay(),
            fn (): array => OperatorSetting::query()->pluck('value', 'key')->all(),
        );
    }

    /**
     * Framework managers cache the instances they build from config (the
     * MailManager caches the SMTP transport with its host, credentials, and
     * global sender). Forget them after applying overrides so refreshed config —
     * including a cleared override — takes effect on a long-running worker
     * without a restart. Future managed groups (storage, backup) add their own
     * invalidation here.
     */
    private function refreshManagers(): void
    {
        Mail::forgetMailers();

        // Storage caches each built disk with its config (endpoint, credentials).
        // Forget the attachment disks so a changed disk choice or S3 connection
        // takes effect on a long-running worker without a restart.
        Storage::forgetDisk(['attachments', 'attachments-s3']);
    }

    private function decode(string $key, ?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        return self::MANAGED[$key]['secret'] ? Crypt::decryptString($raw) : $raw;
    }

    /**
     * Cast a decoded (string) override to the config value's real type. Config
     * values are stored as strings but some paths need a native type — a boolean
     * disk flag applied as the string "false" would read truthy. Applied only to
     * override values; the env baseline is already its native type.
     */
    private function castValue(string $key, ?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (self::MANAGED[$key]['cast'] ?? 'string') {
            'bool' => in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true),
            // A blank override applies as null, not '' — some config consumers
            // (the AWS SDK's endpoint) treat an empty string as a real value.
            // The stored override stays '' so it still wins over a stale env
            // value; only the applied config is nulled.
            'null_if_blank' => trim($value) === '' ? null : $value,
            default => $value,
        };
    }

    private function assertManaged(string $key): void
    {
        if (! $this->isManaged($key)) {
            throw new InvalidArgumentException("Unknown operator setting [{$key}].");
        }
    }
}
