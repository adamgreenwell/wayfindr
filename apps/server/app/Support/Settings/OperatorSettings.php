<?php

namespace App\Support\Settings;

use App\Models\OperatorSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

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
     * The operator-managed settings.
     *
     * @var array<string, array{config: string, secret: bool, group: string}>
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
    ];

    /**
     * Config paths the operator never sets directly, but which must be kept
     * coherent with a group's overrides — nulled when that group is configured,
     * restored to the env baseline when it is not.
     *
     * mail.smtp.url: when env has MAIL_URL set, Laravel derives the SMTP host,
     * port, and credentials FROM the url and ignores the individual fields. So
     * once an operator configures mail, the url must be dropped or their
     * host/port/credentials would be silently superseded.
     *
     * @var array<string, string> config path => group that, when configured, nulls it
     */
    private const DERIVED = [
        'mail.mailers.smtp.url' => 'mail',
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
        } catch (\Throwable) {
            // Store unreachable (DB/cache down, table not migrated): env baseline.
            return $this->baseline;
        }

        $targets = [];

        foreach (self::MANAGED as $key => $meta) {
            $baseline = $this->baseline[$meta['config']];

            if (! array_key_exists($key, $stored) || $stored[$key] === null) {
                $targets[$meta['config']] = $baseline;

                continue;
            }

            try {
                $targets[$meta['config']] = $this->decode($key, $stored[$key]);
            } catch (\Throwable) {
                // A single corrupt/undecryptable secret reverts only its own key
                // to env; the rest of the override set still applies.
                $targets[$meta['config']] = $baseline;
            }
        }

        // Derived paths: null when their group is operator-configured (so a stray
        // env value can't supersede the operator's fields), else the env baseline.
        foreach (self::DERIVED as $path => $group) {
            $targets[$path] = $this->groupIsConfigured($group, $stored) ? null : $this->baseline[$path];
        }

        return $targets;
    }

    /**
     * @param  array<string, string|null>  $stored
     */
    private function groupIsConfigured(string $group, array $stored): bool
    {
        foreach (self::MANAGED as $key => $meta) {
            if ($meta['group'] === $group && array_key_exists($key, $stored) && $stored[$key] !== null) {
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

        // Bump the version AFTER the row is committed. Readers key their cache
        // entry by the version they observed, so a slow reader that fetched the
        // old rows before this write can only ever store under the previous
        // version — it can never poison the new one (which would otherwise stick
        // forever in a permanent cache).
        //
        // add() first: on the database and memcached stores, increment() on a
        // MISSING key returns false without creating it, which would leave the
        // version pinned at 0 and the cache never invalidated. add() seeds the
        // key so increment() always advances it.
        Cache::add(self::VERSION_KEY, 0);
        Cache::increment(self::VERSION_KEY);
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
    }

    private function decode(string $key, ?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        return self::MANAGED[$key]['secret'] ? Crypt::decryptString($raw) : $raw;
    }

    private function assertManaged(string $key): void
    {
        if (! $this->isManaged($key)) {
            throw new InvalidArgumentException("Unknown operator setting [{$key}].");
        }
    }
}
