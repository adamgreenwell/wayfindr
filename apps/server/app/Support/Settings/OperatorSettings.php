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

        $stored = $this->storedValues();

        foreach (self::MANAGED as $key => $meta) {
            $value = (array_key_exists($key, $stored) && $stored[$key] !== null)
                ? $this->decode($key, $stored[$key])
                : $this->baseline[$meta['config']];

            config()->set($meta['config'], $value);
        }

        $this->refreshManagers();
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
     * rest. Busts the cache so the change is live on the next request/job.
     * Auditing is the caller's job — the controller has the actor context.
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

        Cache::forget(self::CACHE_KEY);
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
     * @return array<string, string|null>
     */
    private function storedValues(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
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
